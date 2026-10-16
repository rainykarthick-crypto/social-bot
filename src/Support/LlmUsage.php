<?php

declare(strict_types=1);

namespace SocialBot\Support;

use Throwable;

/**
 * Token-budget guard + audit trail for every LLM call.
 *
 * Rule 7 of the token-budget rules: log model, prompt_tokens, completion_tokens,
 * cached_tokens and a cost estimate for every single call.  This class writes
 * that to the `llm_usage` table (falling back to a log file when the table is
 * not migrated yet) and answers "are we over budget today?" so the classifier
 * can fall back to Ollama.
 */
final class LlmUsage
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{
     *     provider?:string, model?:string, purpose?:string,
     *     prompt_tokens?:int, completion_tokens?:int, cached_tokens?:int,
     *     latency_ms?:int, ok?:bool, fallback?:bool, error?:string, items?:int
     * } $usage
     */
    public function record(array $usage): void
    {
        $provider = (string) ($usage['provider'] ?? 'deepseek');
        $model = (string) ($usage['model'] ?? 'unknown');
        $promptTokens = max(0, (int) ($usage['prompt_tokens'] ?? 0));
        $completionTokens = max(0, (int) ($usage['completion_tokens'] ?? 0));
        $cachedTokens = max(0, (int) ($usage['cached_tokens'] ?? 0));

        $cost = $this->estimateCost($provider, $promptTokens, $completionTokens, $cachedTokens);

        $row = [
            'provider' => $provider,
            'model' => $model,
            'purpose' => (string) ($usage['purpose'] ?? 'classification'),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost_estimate' => $cost,
            'latency_ms' => max(0, (int) ($usage['latency_ms'] ?? 0)),
            'items' => max(0, (int) ($usage['items'] ?? 0)),
            'ok' => (int) (($usage['ok'] ?? true) ? 1 : 0),
            'fallback' => (int) (($usage['fallback'] ?? false) ? 1 : 0),
            'error' => isset($usage['error']) ? mb_substr((string) $usage['error'], 0, 500) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $context = $row + ['cache_hit_rate' => $promptTokens > 0 ? round($cachedTokens / $promptTokens, 3) : 0.0];

        if ($provider === 'deepseek') {
            Logger::channel('deepseek')->write('info', 'llm call', $context);
        }

        try {
            $this->db->insert('llm_usage', $row);
        } catch (Throwable $e) {
            Logger::warning('llm_usage not persisted', ['error' => $e->getMessage()]);
        }
    }

    public function estimateCost(string $provider, int $promptTokens, int $completionTokens, int $cachedTokens): float
    {
        if ($provider !== 'deepseek') {
            return 0.0;
        }

        $priceIn = (float) Config::get('deepseek.price_in', 0.28);
        $priceCached = (float) Config::get('deepseek.price_cached_in', 0.028);
        $priceOut = (float) Config::get('deepseek.price_out', 0.42);

        $freshPrompt = max(0, $promptTokens - $cachedTokens);

        return round(
            ($freshPrompt / 1_000_000) * $priceIn
            + ($cachedTokens / 1_000_000) * $priceCached
            + ($completionTokens / 1_000_000) * $priceOut,
            8
        );
    }

    public function todayTokens(string $provider = 'deepseek'): int
    {
        try {
            $row = $this->db->first(
                'SELECT COALESCE(SUM(`total_tokens`), 0) AS aggregate FROM `' . $this->db->table('llm_usage') . '`
                 WHERE `provider` = ? AND `created_at` >= ? AND `created_at` < ?',
                [$provider, date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))]
            );

            return (int) ($row['aggregate'] ?? 0);
        } catch (Throwable $e) {
            Logger::warning('token budget lookup failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    public function overBudget(?int $budget = null): bool
    {
        $budget ??= (int) Config::get('deepseek.daily_token_budget', 2000000);

        if ($budget <= 0) {
            return false;
        }

        return $this->todayTokens() >= $budget;
    }

    public function remainingBudget(?int $budget = null): int
    {
        $budget ??= (int) Config::get('deepseek.daily_token_budget', 2000000);

        return max(0, $budget - $this->todayTokens());
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $days = 7): array
    {
        $since = date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));

        try {
            $rows = $this->db->select(
                'SELECT DATE(`created_at`) AS day, `provider`, `model`,
                        SUM(`prompt_tokens`) AS prompt_tokens,
                        SUM(`completion_tokens`) AS completion_tokens,
                        SUM(`cached_tokens`) AS cached_tokens,
                        SUM(`total_tokens`) AS total_tokens,
                        SUM(`cost_estimate`) AS cost,
                        COUNT(*) AS calls,
                        SUM(`fallback`) AS fallbacks
                 FROM `' . $this->db->table('llm_usage') . '`
                 WHERE `created_at` >= ?
                 GROUP BY day, provider, model
                 ORDER BY day DESC, total_tokens DESC',
                [$since]
            );
        } catch (Throwable $e) {
            Logger::warning('usage summary failed', ['error' => $e->getMessage()]);

            return ['rows' => [], 'totals' => ['calls' => 0, 'total_tokens' => 0, 'cost' => 0.0, 'cache_hit_rate' => 0.0]];
        }

        $calls = 0;
        $tokens = 0;
        $cache = 0;
        $cost = 0.0;

        foreach ($rows as $row) {
            $calls += (int) $row['calls'];
            $tokens += (int) $row['total_tokens'];
            $cache += (int) $row['cached_tokens'];
            $cost += (float) $row['cost'];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'calls' => $calls,
                'total_tokens' => $tokens,
                'prompt_cached_tokens' => $cache,
                'cost' => round($cost, 6),
                'cache_hit_rate' => $tokens > 0 ? round($cache / max(1, $tokens), 3) : 0.0,
            ],
        ];
    }

    /**
     * Calls that produced no cache hits at all - the prompt-caching smoke test.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cacheMisses(int $limit = 20): array
    {
        try {
            return $this->db->select(
                'SELECT `id`, `model`, `purpose`, `prompt_tokens`, `cached_tokens`, `created_at`
                 FROM `' . $this->db->table('llm_usage') . '`
                 WHERE `provider` = ? AND `prompt_tokens` > 500 AND `cached_tokens` = 0
                 ORDER BY `id` DESC LIMIT ' . max(1, $limit),
                ['deepseek']
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
