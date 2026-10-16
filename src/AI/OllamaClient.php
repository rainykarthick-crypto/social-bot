<?php

declare(strict_types=1);

namespace SocialBot\AI;

use GuzzleHttp\Client;
use SocialBot\Support\Config;
use SocialBot\Support\Http;
use SocialBot\Support\LlmUsage;

/**
 * Ollama fallback (localhost:11434).
 *
 * Used when DEEPSEEK_API_KEY is missing, when the daily token budget is spent,
 * or when the DeepSeek call fails.  Same JSON-mode contract, zero marginal cost.
 */
final class OllamaClient implements LlmClient
{
    private string $url;

    private string $model;

    private int $timeout;

    private ?Client $http = null;

    public function __construct(
        ?string $url = null,
        ?string $model = null,
        ?int $timeout = null,
        private ?LlmUsage $usage = null,
    ) {
        $this->url = rtrim((string) ($url ?? Config::get('ollama.url', 'http://localhost:11434')), '/');
        $this->model = (string) ($model ?? Config::get('ollama.model', 'llama3.2:3b'));
        $this->timeout = $timeout ?? (int) Config::get('ollama.timeout', 120);
    }

    public function provider(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Available == the daemon answers.  Cached for five minutes so a batch of
     * comments never probes the socket repeatedly, and probed with a 1 second
     * budget and no retries: when Ollama is not running this must fail fast and
     * hand over to the local rule engine, not slow the worker down.
     */
    public function available(): bool
    {
        $cache = \SocialBot\Support\App::cache();
        $key = 'ollama:available';

        $cached = $cache->get($key, '__miss__');

        if ($cached !== '__miss__') {
            return (bool) $cached;
        }

        $available = false;

        try {
            $response = Http::client([
                'timeout' => 1,
                'connect_timeout' => 1,
                'retries' => 0,
            ])->get($this->url . '/api/tags');

            $available = Http::isSuccess($response->getStatusCode());
        } catch (\Throwable) {
            $available = false;
        }

        $cache->set($key, $available, 300);

        return $available;
    }

    /**
     * @param array{json?:bool, max_tokens?:int, temperature?:float, purpose?:string, items?:int} $options
     * @return array{content:string, provider:string, model:string, latency_ms:int, usage:array{prompt_tokens:int, completion_tokens:int, cached_tokens:int}}
     */
    public function chat(string $systemPrompt, string $userMessage, array $options = []): array
    {
        $json = (bool) ($options['json'] ?? false);
        $maxTokens = (int) ($options['max_tokens'] ?? Config::get('deepseek.max_tokens', 400));
        $temperature = (float) ($options['temperature'] ?? 0.2);
        $purpose = (string) ($options['purpose'] ?? 'classification');
        $items = (int) ($options['items'] ?? 0);

        $payload = [
            'model' => $this->model,
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'options' => [
                'temperature' => $temperature,
                'num_predict' => $maxTokens,
            ],
        ];

        if ($json) {
            $payload['format'] = 'json';
        }

        $startedAt = microtime(true);

        $response = $this->http()->post($this->url . '/api/chat', [
            'json' => $payload,
            'timeout' => $this->timeout,
        ]);

        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        $raw = (string) $response->getBody();
        $status = $response->getStatusCode();

        if (!Http::isSuccess($status)) {
            $this->usage?->record([
                'provider' => 'ollama',
                'model' => $this->model,
                'purpose' => $purpose,
                'latency_ms' => $latency,
                'items' => $items,
                'ok' => false,
                'fallback' => true,
                'error' => 'HTTP ' . $status . ': ' . substr($raw, 0, 300),
            ]);

            throw new \RuntimeException('Ollama error (HTTP ' . $status . '): ' . substr($raw, 0, 300));
        }

        $decoded = json_decode($raw, true);
        $content = is_array($decoded) ? (string) ($decoded['message']['content'] ?? '') : '';

        $promptTokens = (int) ($decoded['prompt_eval_count'] ?? 0);
        $completionTokens = (int) ($decoded['eval_count'] ?? 0);

        $this->usage?->record([
            'provider' => 'ollama',
            'model' => $this->model,
            'purpose' => $purpose,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => 0,
            'latency_ms' => $latency,
            'items' => $items,
            'ok' => true,
            'fallback' => true,
        ]);

        return [
            'content' => $content,
            'provider' => 'ollama',
            'model' => $this->model,
            'latency_ms' => $latency,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cached_tokens' => 0,
            ],
        ];
    }

    private function http(): Client
    {
        return $this->http ??= Http::client([
            'timeout' => $this->timeout,
            'connect_timeout' => 2,
        ]);
    }
}
