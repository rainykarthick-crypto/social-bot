<?php

declare(strict_types=1);

namespace SocialBot\Support;

/**
 * Local (zero-cost) pre-filter that runs BEFORE any LLM call.
 *
 * Three jobs:
 *   1. drop comments that carry no business signal at all (pure emoji, "nice",
 *      promo spam, one-word reactions, bare links),
 *   2. return a VADER compound sentiment score so the LLM never has to be asked
 *      for sentiment on the easy cases,
 *   3. report how many comments were skipped, so we can verify the 40-60%
 *      skip target from the token-budget rules.
 */
final class SentimentFilter
{
    public const MIN_LENGTH = 15;

    /** Verbatim from the spec: short/low-value reactions. */
    private const LOW_VALUE_PATTERNS = [
        '/^(nice|great|love it|awesome|beautiful|😍|🔥)+$/iu',
        '/(follow me|check my|dm for promo|buy followers)/iu',
    ];

    /** Additional high-precision noise rules (emoji-only, links-only, 1-2 words). */
    private const EXTRA_NOISE_PATTERNS = [
        '/^[\s\p{Emoji_Presentation}\p{Extended_Pictographic}]+$/u',
        '/^[\s\p{P}\p{S}]*$|^(https?:\/\/\S+|\S+\.\S{2,}\/\S*)[\s\p{P}]*$/iu',
        '/^(first|wow+|lol+|haha+|nice one|cool|👍+|❤️+|🙌+)[\s\p{P}\p{Emoji_Presentation}]*$/iu',
    ];

    private object|null $vader = null;

    /** @var array<string, int> */
    private array $stats = ['seen' => 0, 'skipped' => 0, 'kept' => 0, 'llm_needed' => 0];

    /** @var array<string, int> */
    private array $skipReasons = [];

    private const LEXICON = [
        'good' => 1.9, 'great' => 3.1, 'excellent' => 2.7, 'amazing' => 2.8, 'awesome' => 3.1,
        'love' => 3.2, 'like' => 1.5, 'best' => 3.2, 'perfect' => 2.7, 'happy' => 2.7,
        'thanks' => 1.4, 'thank' => 1.4, 'interested' => 1.5, 'need' => 1.0, 'want' => 1.0,
        'buy' => 1.5, 'order' => 1.5, 'please' => 1.0, 'help' => 0.8, 'fast' => 1.4,
        'beautiful' => 2.9, 'recommend' => 1.7, 'brilliant' => 2.7, 'fantastic' => 2.6,
        'bad' => -2.5, 'terrible' => -2.1, 'awful' => -2.0, 'worst' => -3.1, 'hate' => -2.7,
        'poor' => -1.9, 'broken' => -1.9, 'refund' => -1.4, 'scam' => -2.7, 'angry' => -2.3,
        'disappointed' => -2.1, 'slow' => -1.5, 'expensive' => -1.3, 'waiting' => -0.8,
        'never' => -1.2, 'problem' => -1.7, 'issue' => -1.4, 'useless' => -2.1, 'waste' => -2.4,
    ];

    private const NEGATIONS = ['not', 'no', 'never', 'cannot', "can't", "don't", "doesn't", "isn't", 'without'];

    public function __construct(private readonly int $minLength = self::MIN_LENGTH)
    {
        if (class_exists(\VaderSentiment\SentimentIntensityAnalyzer::class)) {
            try {
                $this->vader = new \VaderSentiment\SentimentIntensityAnalyzer();
            } catch (\Throwable $e) {
                Logger::warning('VADER unavailable, using built-in lexicon', ['error' => $e->getMessage()]);
                $this->vader = null;
            }
        }
    }

    public function usingVader(): bool
    {
        return $this->vader !== null;
    }

    public function shouldSkip(string $text): bool
    {
        return $this->skipReason($text) !== null;
    }

    /**
     * Returns the machine-readable skip reason, or null when the comment should
     * be sent to the LLM.
     */
    public function skipReason(string $text): ?string
    {
        $trimmed = trim($text);
        $normalised = trim(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);

        if ($normalised === '') {
            return 'empty';
        }

        if (mb_strlen($normalised) < $this->minLength) {
            return 'too_short';
        }

        foreach (self::LOW_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return 'low_value_reaction';
            }
        }

        foreach (self::EXTRA_NOISE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return 'noise';
            }
        }

        // Promo spam beyond the spec's fixed list: repeated handles/links + CTA.
        if (preg_match_all('/https?:\/\//i', $normalised) >= 2) {
            return 'link_spam';
        }

        if (preg_match('/(whatsapp|telegram)\s*[:+]/iu', $normalised) === 1
            && preg_match('/\d{7,}/', $normalised) === 1
        ) {
            return 'contact_spam';
        }

        return null;
    }

    /**
     * VADER compound score in [-1, 1]; falls back to a small built-in lexicon
     * when jbuncle/php-vadersentiment is not installed.
     */
    public function compound(string $text): float
    {
        if ($this->vader !== null) {
            try {
                // VADER is a social-media lexicon: phone numbers, URLs and long
                // digit runs only produce noise (and PHP warnings), so they are
                // removed before scoring.
                $scoreable = $this->stripNonLexical($text);

                $result = $this->withSuppressedWarnings(fn (): array|false => $this->vader->getSentiment($scoreable));

                if (is_array($result) && isset($result['compound'])) {
                    return round((float) $result['compound'], 4);
                }
            } catch (\Throwable $e) {
                Logger::warning('VADER scoring failed, using lexicon fallback', ['error' => $e->getMessage()]);
            }
        }

        return $this->lexiconCompound($text);
    }

    /**
     * Remove the tokens that carry no sentiment signal but do trip the VADER
     * port (phone numbers, emails, URLs, @handles).
     */
    private function stripNonLexical(string $text): string
    {
        $patterns = [
            '/\bhttps?:\/\/\S+/iu',
            '/\bwww\.\S+/iu',
            '/[\w.+-]+@[\w-]+\.[\w.]+/u',
            '/\+?\d[\d\s().-]{6,}\d/u',
            '/(?<!\w)@[A-Za-z0-9._]{2,}/u',
        ];

        $cleaned = preg_replace($patterns, ' ', $text) ?? $text;
        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned);

        return $cleaned === '' ? $text : $cleaned;
    }

    /**
     * Run a callable with PHP warnings silenced, restoring the previous handler
     * afterwards (the VADER port warns on unknown tokens).
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withSuppressedWarnings(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Map a compound score to the label the leads pipeline understands.
     */
    public function label(float $compound): string
    {
        if ($compound >= 0.05) {
            return 'positive';
        }

        if ($compound <= -0.05) {
            return 'negative';
        }

        return 'neutral';
    }

    public function sentiment(string $text): string
    {
        return $this->label($this->compound($text));
    }

    /**
     * Partition a batch of comments.
     *
     * @param array<int, array<string, mixed>> $comments rows with at least a 'text' key
     * @return array{keep: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    public function filter(array $comments): array
    {
        $keep = [];
        $skipped = [];

        foreach ($comments as $comment) {
            $text = (string) ($comment['text'] ?? $comment['comment_text'] ?? '');
            $this->stats['seen']++;

            $reason = $this->skipReason($text);

            if ($reason !== null) {
                $this->stats['skipped']++;
                $this->skipReasons[$reason] = ($this->skipReasons[$reason] ?? 0) + 1;
                $comment['skip_reason'] = $reason;
                $comment['sentiment'] = $this->sentiment($text);
                $comment['sentiment_score'] = $this->compound($text);
                $skipped[] = $comment;

                continue;
            }

            $this->stats['kept']++;
            $comment['sentiment'] = $this->sentiment($text);
            $comment['sentiment_score'] = $this->compound($text);
            $keep[] = $comment;
        }

        return ['keep' => $keep, 'skipped' => $skipped];
    }

    /**
     * @return array{seen:int, skipped:int, kept:int, skip_rate:float, reasons:array<string,int>, vader:bool}
     */
    public function stats(): array
    {
        $seen = max(1, $this->stats['seen']);

        return [
            'seen' => $this->stats['seen'],
            'skipped' => $this->stats['skipped'],
            'kept' => $this->stats['kept'],
            'skip_rate' => round($this->stats['skipped'] / $seen, 3),
            'reasons' => $this->skipReasons,
            'vader' => $this->usingVader(),
        ];
    }

    public function resetStats(): void
    {
        $this->stats = ['seen' => 0, 'skipped' => 0, 'kept' => 0, 'llm_needed' => 0];
        $this->skipReasons = [];
    }

    private function lexiconCompound(string $text): float
    {
        $words = preg_split('/[^\p{L}\']+/u', mb_strtolower($text)) ?: [];
        $score = 0.0;
        $hits = 0;

        foreach ($words as $index => $word) {
            if ($word === '' || !isset(self::LEXICON[$word])) {
                continue;
            }

            $value = self::LEXICON[$word];
            $previous = $words[$index - 1] ?? '';

            if (in_array($previous, self::NEGATIONS, true)) {
                $value *= -0.74;
            }

            $score += $value;
            $hits++;
        }

        if ($hits === 0) {
            return 0.0;
        }

        // Normalise roughly the way VADER does, then clamp to [-1, 1].
        $normalised = $score / sqrt($score * $score + 15);

        return round(max(-1.0, min(1.0, $normalised)), 4);
    }
}
