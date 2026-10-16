<?php

declare(strict_types=1);

namespace SocialBot\Leads;

/**
 * Deterministic 0-100 lead score.
 *
 * Weights (max 100):
 *   intent      40   purchase=40 pricing=35 demo=35 availability=30 support=15 complaint=10 spam=-50 none=0
 *   sentiment   20   positive=20 neutral=10 negative=-10
 *   authority   20   verified=20 followers>1000=15 followers>100=10
 *   engagement  10   min(10, likes*2)
 *   recency     10   max(0, 10 - floor(hours_old / 2))
 *
 * The score is intentionally explainable: every call returns the full
 * breakdown so the dashboard and the audit log can show WHY a lead is P1.
 * The LLM's own 0-100 confidence is stored separately as `intent_score`.
 */
final class LeadScorer
{
    /** @var array<string, int> */
    private const INTENT_WEIGHTS = [
        'purchase' => 40,
        'pricing' => 35,
        'demo' => 35,
        'availability' => 30,
        'support' => 15,
        'complaint' => 10,
        'spam' => -50,
        'none' => 0,
    ];

    /** @var array<string, int> */
    private const SENTIMENT_WEIGHTS = [
        'positive' => 20,
        'neutral' => 10,
        'negative' => -10,
    ];

    public const QUALIFY_THRESHOLD = 40;

    public const P1_THRESHOLD = 70;

    /**
     * @param array<string, mixed> $signal    intent / sentiment / likes / author_verified / author_followers / created_at (or hours_old)
     * @return array{
     *     lead_score:int, priority:string, is_qualified:bool,
     *     breakdown:array<string, int|float|string>
     * }
     */
    public function score(array $signal): array
    {
        $intent = strtolower((string) ($signal['intent'] ?? 'none'));
        $sentiment = strtolower((string) ($signal['sentiment'] ?? 'neutral'));

        if (!array_key_exists($intent, self::INTENT_WEIGHTS)) {
            $intent = 'none';
        }

        if (!array_key_exists($sentiment, self::SENTIMENT_WEIGHTS)) {
            $sentiment = 'neutral';
        }

        $intentWeight = $this->intentWeight($intent);
        $sentimentWeight = $this->sentimentWeight($sentiment);
        $authorityWeight = $this->authority(
            (bool) ($signal['author_verified'] ?? $signal['verified'] ?? false),
            (int) ($signal['author_followers'] ?? $signal['followers'] ?? 0)
        );
        $engagementWeight = $this->engagement((int) ($signal['likes'] ?? 0));
        $recencyWeight = $this->recency($this->hoursOld($signal));

        $raw = $intentWeight + $sentimentWeight + $authorityWeight + $engagementWeight + $recencyWeight;
        $score = (int) max(0, min(100, $raw));

        $priority = self::priorityFor($score);

        // A spam/none comment is never a qualified lead, whatever the non-intent
        // signals say - this is the spec's explicit rule.
        $isQualified = $score >= self::QUALIFY_THRESHOLD && !in_array($intent, ['spam', 'none'], true);

        return [
            'lead_score' => $score,
            'priority' => $priority,
            'is_qualified' => $isQualified,
            'breakdown' => [
                'intent' => $intent,
                'intent_weight' => $intentWeight,
                'sentiment' => $sentiment,
                'sentiment_weight' => $sentimentWeight,
                'authority_weight' => $authorityWeight,
                'engagement_weight' => $engagementWeight,
                'recency_weight' => $recencyWeight,
                'raw_total' => $raw,
                'clamped' => $raw !== $score,
                'hours_old' => $this->hoursOld($signal),
            ],
        ];
    }

    /**
     * Score a pipeline result (classification + queue row).
     *
     * @param array<string, mixed> $classification keys: intent, sentiment, score
     * @param array<string, mixed> $source         queue row: likes, author_followers, author_verified, created_at
     * @return array{lead_score:int, priority:string, is_qualified:bool, breakdown:array<string, mixed>}
     */
    public function scoreFromComment(array $classification, array $source = []): array
    {
        return $this->score([
            'intent' => $classification['intent'] ?? 'none',
            'sentiment' => $classification['sentiment'] ?? ($source['sentiment'] ?? 'neutral'),
            'likes' => $source['likes'] ?? 0,
            'author_followers' => $source['author_followers'] ?? 0,
            'author_verified' => $source['author_verified'] ?? 0,
            'created_at' => $source['created_at'] ?? $source['received_at'] ?? null,
        ]);
    }

    public function intentWeight(string $intent): int
    {
        return self::INTENT_WEIGHTS[strtolower($intent)] ?? 0;
    }

    public function sentimentWeight(string $sentiment): int
    {
        return self::SENTIMENT_WEIGHTS[strtolower($sentiment)] ?? 0;
    }

    public function authority(bool $verified, int $followers): int
    {
        if ($verified) {
            return 20;
        }

        if ($followers > 1000) {
            return 15;
        }

        if ($followers > 100) {
            return 10;
        }

        return 0;
    }

    public function engagement(int $likes): int
    {
        return (int) min(10, max(0, $likes) * 2);
    }

    public function recency(int $hoursOld): int
    {
        if ($hoursOld < 0) {
            $hoursOld = 0;
        }

        return (int) max(0, 10 - (int) floor($hoursOld / 2));
    }

    public static function priorityFor(int $score): string
    {
        if ($score >= self::P1_THRESHOLD) {
            return 'P1';
        }

        if ($score >= self::QUALIFY_THRESHOLD) {
            return 'P2';
        }

        return 'P3';
    }

    public function isQualified(int $score, string $intent): bool
    {
        return $score >= self::QUALIFY_THRESHOLD && !in_array(strtolower($intent), ['spam', 'none'], true);
    }

    /**
     * Reads `hours_old` when supplied, otherwise derives it from created_at.
     *
     * @param array<string, mixed> $signal
     */
    private function hoursOld(array $signal): int
    {
        if (isset($signal['hours_old']) && is_numeric($signal['hours_old'])) {
            return max(0, (int) $signal['hours_old']);
        }

        $createdAt = $signal['created_at'] ?? null;

        if (!is_string($createdAt) || trim($createdAt) === '') {
            // No timestamp: treat as fresh rather than punishing the lead.
            return 0;
        }

        $timestamp = strtotime($createdAt);

        if ($timestamp === false) {
            return 0;
        }

        return max(0, (int) floor((time() - $timestamp) / 3600));
    }
}
