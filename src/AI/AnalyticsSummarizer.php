<?php

declare(strict_types=1);

namespace SocialBot\AI;

use SocialBot\Support\App;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;
use SocialBot\Support\LlmUsage;
use Throwable;

/**
 * Aggregates the analytics numbers from SQL, then asks the LLM for one
 * actionable recommendation using the static prompts/analytics.txt system
 * prompt (JSON mode, thinking off).
 *
 * The SQL aggregation always runs, so check_analytics and digest.php produce a
 * useful answer even with no LLM configured.
 */
final class AnalyticsSummarizer
{
    private const INTENTS = DeepSeekClassifier::INTENTS;

    private string $systemPrompt;

    public function __construct(
        private readonly LlmClient $primary,
        private readonly ?LlmClient $fallback,
        private readonly Database $db,
        private readonly LlmUsage $usage,
        ?string $systemPrompt = null,
    ) {
        $this->systemPrompt = $systemPrompt ?? DeepSeekClassifier::loadPrompt('analytics.txt');
    }

    public static function fromConfig(): self
    {
        $usage = new LlmUsage(App::db());

        return new self(
            new DeepSeekClient(usage: $usage),
            new OllamaClient(usage: $usage),
            App::db(),
            $usage
        );
    }

    /**
     * Deterministic numbers pulled straight from the database.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $days = 7): array
    {
        $days = max(1, $days);
        $since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $totals = $this->db->first(
            'SELECT COUNT(*) AS total_leads,
                    COALESCE(SUM(`is_qualified`), 0) AS qualified,
                    COALESCE(SUM(`status` = \'booked\'), 0) AS booked,
                    COALESCE(SUM(`status` = \'contacted\'), 0) AS contacted,
                    COALESCE(AVG(`lead_score`), 0) AS avg_score
             FROM `' . $this->db->table('leads') . '`
             WHERE `created_at` >= ?',
            [$since]
        ) ?? [];

        $byPlatform = $this->db->select(
            'SELECT `platform`, COUNT(*) AS total, COALESCE(SUM(`is_qualified`), 0) AS qualified
             FROM `' . $this->db->table('leads') . '`
             WHERE `created_at` >= ?
             GROUP BY `platform` ORDER BY total DESC',
            [$since]
        );

        $byIntent = $this->db->select(
            'SELECT `intent`, COUNT(*) AS total
             FROM `' . $this->db->table('leads') . '`
             WHERE `created_at` >= ?
             GROUP BY `intent` ORDER BY total DESC',
            [$since]
        );

        $bySentiment = $this->db->select(
            'SELECT `sentiment`, COUNT(*) AS total
             FROM `' . $this->db->table('leads') . '`
             WHERE `created_at` >= ?
             GROUP BY `sentiment`',
            [$since]
        );

        $sentiment = ['positive' => 0, 'neutral' => 0, 'negative' => 0];

        foreach ($bySentiment as $row) {
            $key = (string) $row['sentiment'];

            if (array_key_exists($key, $sentiment)) {
                $sentiment[$key] = (int) $row['total'];
            }
        }

        $posts = $this->db->select(
            'SELECT `status`, COUNT(*) AS total FROM `' . $this->db->table('social_posts') . '` GROUP BY `status`'
        );

        $queue = $this->db->first(
            'SELECT
                COALESCE(SUM(`status` = \'pending\'), 0) AS pending,
                COALESCE(SUM(`status` = \'failed\'), 0) AS failed,
                COALESCE(SUM(`status` = \'skipped\'), 0) AS skipped,
                COALESCE(SUM(`status` = \'processed\'), 0) AS processed
             FROM `' . $this->db->table('comment_queue') . '`'
        ) ?? [];

        return [
            'period' => 'last ' . $days . ' day(s)',
            'since' => $since,
            'total_leads' => (int) ($totals['total_leads'] ?? 0),
            'qualified' => (int) ($totals['qualified'] ?? 0),
            'booked' => (int) ($totals['booked'] ?? 0),
            'contacted' => (int) ($totals['contacted'] ?? 0),
            'average_score' => round((float) ($totals['avg_score'] ?? 0), 1),
            'conversion_rate' => ($totals['total_leads'] ?? 0) > 0
                ? round(((int) $totals['booked'] / (int) $totals['total_leads']) * 100, 1)
                : 0.0,
            'top_platform' => (string) ($byPlatform[0]['platform'] ?? 'n/a'),
            'top_intent' => (string) ($byIntent[0]['intent'] ?? 'n/a'),
            'sentiment_breakdown' => $sentiment,
            'by_platform' => $byPlatform,
            'by_intent' => $byIntent,
            'posts' => $posts,
            'queue' => [
                'pending' => (int) ($queue['pending'] ?? 0),
                'failed' => (int) ($queue['failed'] ?? 0),
                'skipped' => (int) ($queue['skipped'] ?? 0),
                'processed' => (int) ($queue['processed'] ?? 0),
            ],
        ];
    }

    /**
     * Snapshot + LLM narrative (falls back to a deterministic recommendation).
     *
     * @return array<string, mixed>
     */
    public function summarize(int $days = 7): array
    {
        $snapshot = $this->snapshot($days);
        $llm = $this->narrate($snapshot);
        $breakdown = $snapshot['sentiment_breakdown'];

        return [
            'period' => $snapshot['period'],
            'total_leads' => $snapshot['total_leads'],
            'qualified' => $snapshot['qualified'],
            'top_platform' => $snapshot['top_platform'],
            'top_intent' => $snapshot['top_intent'],
            'sentiment_breakdown' => $breakdown,
            'recommendation' => $llm['recommendation'] ?? $this->deterministicRecommendation($snapshot),
            'provider' => $llm['provider'] ?? 'rules',
            'degraded' => $llm['degraded'] ?? true,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function narrate(array $snapshot): array
    {
        $userMessage = "Analytics snapshot (JSON):\n" . Json::encode($snapshot)
            . "\n\nSummarise it and return the JSON object described in the system prompt.";

        foreach ([$this->primary, $this->fallback] as $client) {
            if ($client === null || !$client->available()) {
                continue;
            }

            if ($client === $this->primary && $this->usage->overBudget()) {
                continue;
            }

            try {
                $response = $client->chat($this->systemPrompt, $userMessage, [
                    'json' => true,
                    'max_tokens' => 400,
                    'temperature' => 0.3,
                    'purpose' => 'analytics',
                ]);

                $decoded = Json::extractObject((string) $response['content']);

                if ($decoded === null) {
                    continue;
                }

                return [
                    'recommendation' => (string) ($decoded['recommendation'] ?? ''),
                    'provider' => (string) $response['provider'],
                    'degraded' => ($response['provider'] ?? 'deepseek') !== 'deepseek',
                ];
            } catch (Throwable $e) {
                Logger::error('analytics narration failed', [
                    'provider' => $client->provider(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['provider' => 'rules', 'degraded' => true];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function deterministicRecommendation(array $snapshot): string
    {
        $total = (int) ($snapshot['total_leads'] ?? 0);

        if ($total === 0) {
            return 'No leads captured in this window - verify the platform webhooks are receiving events (audit.php prints the subscription status).';
        }

        $queue = (array) ($snapshot['queue'] ?? []);

        if (($queue['failed'] ?? 0) > 0) {
            return sprintf(
                '%d comment(s) failed processing - inspect worker.log and re-queue them before scaling spend.',
                (int) $queue['failed']
            );
        }

        $negative = (int) ($snapshot['sentiment_breakdown']['negative'] ?? 0);

        if ($negative / max(1, $total) >= 0.3) {
            return 'Negative sentiment is above 30% - route the support queue to a human within the same business day.';
        }

        if ((int) ($snapshot['conversion_rate'] ?? 0) < 5 && (int) ($snapshot['contacted'] ?? 0) === 0) {
            return 'Leads are captured but none are contacted - work the P1 sales_priority queue first.';
        }

        return sprintf(
            'Double down on %s (top intent "%s", top platform "%s"): repeat the posts that produced them.',
            (string) ($snapshot['top_platform'] ?? 'the top platform'),
            (string) ($snapshot['top_intent'] ?? 'n/a'),
            (string) ($snapshot['top_platform'] ?? 'n/a')
        );
    }

    /**
     * Intent distribution helper used by digest.php.
     *
     * @return array<int, string>
     */
    public function knownIntents(): array
    {
        return self::INTENTS;
    }
}
