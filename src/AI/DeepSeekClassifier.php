<?php

declare(strict_types=1);

namespace SocialBot\AI;

use SocialBot\Support\Config;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;
use SocialBot\Support\LlmUsage;
use SocialBot\Support\SentimentFilter;
use Throwable;

/**
 * Comment -> structured lead signal, in batches, as cheaply as possible.
 *
 * Token-budget rules enforced here:
 *   1. thinking mode OFF on every call (=> deepseek-v4-flash),
 *   2. JSON mode + max_tokens=400,
 *   3. 10-20 comments per API call, never one per call,
 *   4. the system prompt is read from prompts/classify.txt exactly once, at
 *      construction, and is byte-identical on every request (all dynamic data
 *      goes into the user message) so DeepSeek's disk cache actually hits,
 *   6. Ollama is the fallback when the key is missing / budget is spent /
 *      DeepSeek errors, and a local rule engine is the last resort so the queue
 *      never stalls.
 */
final class DeepSeekClassifier
{
    public const INTENTS = ['purchase', 'pricing', 'availability', 'demo', 'support', 'complaint', 'spam', 'none'];

    public const SENTIMENTS = ['positive', 'neutral', 'negative'];

    public const MIN_BATCH = 10;

    public const MAX_BATCH = 20;

    private string $systemPrompt;

    private int $batchSize;

    private int $maxTokens;

    private SentimentFilter $sentimentFilter;

    private string $lastProvider = 'none';

    private bool $lastDegraded = false;

    public function __construct(
        private readonly LlmClient $primary,
        private readonly ?LlmClient $fallback,
        private readonly LlmUsage $usage,
        ?string $systemPrompt = null,
        ?int $batchSize = null,
        ?int $maxTokens = null,
        ?SentimentFilter $sentimentFilter = null,
    ) {
        $this->systemPrompt = $systemPrompt ?? self::loadPrompt('classify.txt');
        $this->batchSize = max(self::MIN_BATCH, min(self::MAX_BATCH, $batchSize ?? (int) Config::get('deepseek.batch_size', 15)));
        $this->maxTokens = $maxTokens ?? (int) Config::get('deepseek.max_tokens', 400);
        $this->sentimentFilter = $sentimentFilter ?? new SentimentFilter();
    }

    public static function fromConfig(): self
    {
        $usage = new LlmUsage(\SocialBot\Support\App::db());

        return new self(
            new DeepSeekClient(usage: $usage),
            new OllamaClient(usage: $usage),
            $usage
        );
    }

    public static function loadPrompt(string $file): string
    {
        $path = Config::path('prompts/' . $file);

        if (!is_file($path)) {
            throw new \RuntimeException('Missing prompt file: ' . $path);
        }

        return rtrim((string) file_get_contents($path), "\n");
    }

    public function systemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function lastProvider(): string
    {
        return $this->lastProvider;
    }

    public function lastWasDegraded(): bool
    {
        return $this->lastDegraded;
    }

    /**
     * Classify a batch of comments.
     *
     * @param array<int, array<string, mixed>> $comments rows with 'text' plus optional id/platform/author/likes
     * @return array<int, array{
     *     intent:string, score:int, sentiment:string, is_lead:bool, priority:string,
     *     summary:string, reply:string, provider:string, degraded:bool
     * }>
     */
    public function classifyBatch(array $comments): array
    {
        if ($comments === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($comments, $this->batchSize) as $chunk) {
            foreach ($this->classifyChunk(array_values($chunk)) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Convenience wrapper for the dashboard ("re-classify this lead").
     *
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    public function classifyOne(array $comment): array
    {
        $results = $this->classifyBatch([$comment]);

        return $results[0] ?? $this->heuristicResult($comment, 'empty_response');
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @return array<int, array<string, mixed>>
     */
    private function classifyChunk(array $chunk): array
    {
        $userMessage = $this->buildUserMessage($chunk);
        $payload = $this->runWithFallback($userMessage, count($chunk));

        if ($payload === null) {
            return array_map(
                fn (array $comment): array => $this->heuristicResult($comment, 'llm_unavailable'),
                $chunk
            );
        }

        $decoded = Json::extractArray($payload['content']) ?? [];
        $normalised = [];

        foreach ($chunk as $index => $comment) {
            $raw = $this->pickResult($decoded, $index, $comment);

            $normalised[] = $raw === null
                ? $this->heuristicResult($comment, 'missing_result', $payload['provider'])
                : $this->normaliseResult($raw, $comment, $payload['provider']);
        }

        return $normalised;
    }

    /**
     * @param array<int, mixed> $decoded
     * @param array<string, mixed> $comment
     * @return array<string, mixed>|null
     */
    private function pickResult(array $decoded, int $index, array $comment): ?array
    {
        if (isset($decoded[$index]) && is_array($decoded[$index])) {
            return $decoded[$index];
        }

        // The model may answer with an explicit index/id; honour it when present.
        foreach ($decoded as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateIndex = $candidate['index'] ?? $candidate['i'] ?? null;
            $candidateId = $candidate['id'] ?? $candidate['comment_id'] ?? null;

            if ($candidateIndex !== null && (int) $candidateIndex === $index + 1) {
                return $candidate;
            }

            if ($candidateId !== null && (string) $candidateId === (string) ($comment['comment_id'] ?? $comment['id'] ?? '')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     */
    private function buildUserMessage(array $chunk): string
    {
        $lines = [];
        $total = count($chunk);

        $lines[] = sprintf(
            'Classify %d social media comment(s). Return a JSON array of exactly %d object(s) in the same order as the input.',
            $total,
            $total
        );
        $lines[] = '';

        foreach ($chunk as $index => $comment) {
            $lines[] = sprintf('[%d] %s', $index + 1, Json::encode([
                'id' => (string) ($comment['comment_id'] ?? $comment['id'] ?? $index + 1),
                'platform' => (string) ($comment['platform'] ?? 'unknown'),
                'author' => (string) ($comment['author'] ?? ''),
                'likes' => (int) ($comment['likes'] ?? 0),
                'post_context' => mb_substr((string) ($comment['post_context'] ?? ''), 0, 300),
                'text' => mb_substr((string) ($comment['text'] ?? $comment['comment_text'] ?? ''), 0, 1200),
            ]));
        }

        $lines[] = '';
        $lines[] = 'Respond with the JSON array only.';

        return implode("\n", $lines);
    }

    /**
     * Primary -> fallback -> (null => caller uses the local rule engine).
     *
     * @return array{content:string, provider:string}|null
     */
    private function runWithFallback(string $userMessage, int $items): ?array
    {
        $verdicts = [];

        if ($this->primary->available() && !$this->usage->overBudget()) {
            $verdicts[] = $this->primary;
        } elseif ($this->primary->available()) {
            Logger::warning('deepseek daily token budget exhausted, using local fallback', [
                'used_today' => $this->usage->todayTokens(),
                'budget' => (int) Config::get('deepseek.daily_token_budget', 0),
            ]);
        }

        if ($this->fallback !== null && $this->fallback->available()) {
            $verdicts[] = $this->fallback;
        }

        foreach ($verdicts as $client) {
            try {
                $response = $client->chat($this->systemPrompt, $userMessage, [
                    'json' => true,
                    'max_tokens' => $this->maxTokens,
                    'temperature' => 0.2,
                    'purpose' => 'classification',
                    'items' => $items,
                    'thinking' => false,
                ]);

                $this->lastProvider = $client->provider();
                $this->lastDegraded = $client->provider() !== 'deepseek';

                return [
                    'content' => (string) $response['content'],
                    'provider' => (string) $response['provider'],
                ];
            } catch (Throwable $e) {
                Logger::error('classification call failed', [
                    'provider' => $client->provider(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->lastProvider = 'heuristic';
        $this->lastDegraded = true;
        $this->usage->record([
            'provider' => 'heuristic',
            'model' => 'local-rules',
            'purpose' => 'classification',
            'items' => $items,
            'ok' => false,
            'fallback' => true,
            'error' => 'no LLM provider available, used local rule engine',
        ]);

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function normaliseResult(array $raw, array $comment, string $provider): array
    {
        $text = (string) ($comment['text'] ?? $comment['comment_text'] ?? '');

        $intent = strtolower(trim((string) ($raw['intent'] ?? 'none')));

        if (!in_array($intent, self::INTENTS, true)) {
            $intent = 'none';
        }

        $score = (int) round((float) ($raw['score'] ?? 0));
        $score = max(0, min(100, $score));

        $sentiment = strtolower(trim((string) ($raw['sentiment'] ?? '')));

        if (!in_array($sentiment, self::SENTIMENTS, true)) {
            $sentiment = isset($comment['sentiment'])
                ? (string) $comment['sentiment']
                : $this->sentimentFilter->sentiment($text);
        }

        $isLead = array_key_exists('is_lead', $raw)
            ? filter_var($raw['is_lead'], FILTER_VALIDATE_BOOLEAN)
            : ($score >= 40 && !in_array($intent, ['spam', 'none'], true));

        $priority = strtoupper(trim((string) ($raw['priority'] ?? '')));

        if (!in_array($priority, ['P1', 'P2', 'P3'], true)) {
            $priority = self::priorityFor($score, $intent);
        }

        $summary = mb_substr(trim((string) ($raw['summary'] ?? '')), 0, 500);
        $reply = mb_substr(trim((string) ($raw['reply'] ?? '')), 0, 2000);

        if ($summary === '') {
            $summary = mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 200);
        }

        return [
            'intent' => $intent,
            'score' => $score,
            'sentiment' => $sentiment,
            'is_lead' => $isLead,
            'priority' => $priority,
            'summary' => $summary,
            'reply' => $reply,
            'provider' => $provider,
            'degraded' => $provider !== 'deepseek',
        ];
    }

    /**
     * Local rule engine: no network, no tokens.  Deliberately conservative -
     * it never invents a purchase intent, it only recognises explicit keywords.
     *
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    public function heuristicResult(array $comment, string $reason = 'fallback', string $provider = 'heuristic'): array
    {
        $text = (string) ($comment['text'] ?? $comment['comment_text'] ?? '');
        $lower = mb_strtolower($text);

        $intent = 'none';
        $score = 10;

        $rules = [
            'spam' => ['/(follow me|check my|dm for promo|buy followers|click here|free followers)/i', 5],
            'complaint' => ['/(refund|worst|terrible|scam|complaint|not working|broken|disappointed|no response|nobody (replies|responds)|still not (delivered|received))/i', 55],
            'support' => ['/(not working|help me|how do i|issue|error|problem|support)/i', 45],
            'purchase' => ['/(i want to buy|want to buy|want to order|i want to order|place an order|order now|need this|book now|i want this|send me the (link|payment link))/i', 75],
            'pricing' => ['/(price|pricing|cost|how much|rate|charges|quote|discount|offer|quotation)/i', 70],
            'availability' => ['/(in stock|available|availability|do you have|do you deliver|do you ship|shipping|delivery|deliver to|location|pincode|cash on delivery|\bcod\b)/i', 60],
            'demo' => ['/(demo|trial|consultation|appointment|book a call|schedule a call|site visit)/i', 70],
        ];

        foreach ($rules as $candidate => [$pattern, $candidateScore]) {
            if (preg_match($pattern, $lower) === 1) {
                $intent = $candidate;
                $score = $candidateScore;

                break;
            }
        }

        // Strong purchase phrasing outranks a generic pricing/availability question.
        if ($intent !== 'spam' && preg_match('/(dm me|order now|buy now|book now|want to order|want to buy)/i', $lower) === 1) {
            $intent = 'purchase';
            $score = max($score, 80);
        }

        $sentiment = isset($comment['sentiment'])
            ? (string) $comment['sentiment']
            : $this->sentimentFilter->sentiment($text);

        if ($sentiment === 'negative' && $intent === 'none') {
            $intent = 'complaint';
            $score = max($score, 45);
        }

        // A keyword-identified complaint is negative by definition; VADER can be
        // fooled by phrasing such as "I want a refund and nobody replies".
        if ($intent === 'complaint' && $sentiment !== 'negative') {
            $sentiment = 'negative';
        }

        if ($sentiment === 'positive' && $intent !== 'complaint') {
            $score = min(100, $score + 5);
        }

        $isLead = $score >= 40 && !in_array($intent, ['spam', 'none'], true);

        return [
            'intent' => $intent,
            'score' => $score,
            'sentiment' => $sentiment,
            'is_lead' => $isLead,
            'priority' => self::priorityFor($score, $intent),
            'summary' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 200),
            'reply' => '',
            'provider' => $provider,
            'degraded' => true,
            'degraded_reason' => $reason,
        ];
    }

    public static function priorityFor(int $score, string $intent): string
    {
        if ($score >= 70 || $intent === 'purchase') {
            return 'P1';
        }

        if ($score >= 40) {
            return 'P2';
        }

        return 'P3';
    }
}
