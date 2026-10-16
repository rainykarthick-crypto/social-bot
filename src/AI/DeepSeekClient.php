<?php

declare(strict_types=1);

namespace SocialBot\AI;

use GuzzleHttp\Client;
use SocialBot\Support\Config;
use SocialBot\Support\Http;
use SocialBot\Support\Logger;
use SocialBot\Support\LlmUsage;
use Throwable;

/**
 * DeepSeek chat-completions client (OpenAI-compatible transport).
 *
 * Why a hand-rolled transport when deepseek-php/deepseek-php-client is
 * installed?  Because the cost guard needs per-call `usage` counters
 * (prompt_tokens / completion_tokens / prompt_cache_hit_tokens) and the SDK's
 * `run()` returns only the content string.  The SDK is still first class: set
 * DEEPSEEK_TRANSPORT=sdk and every call goes through it (token counts are then
 * estimated).  See README "DeepSeek transport".
 *
 * Fluent surface mirrors the client API used across this codebase:
 *
 *   $client->withThinkingMode(false)->withMaxTokens(400)
 *          ->withResponseFormat('json_object')
 *          ->chat($staticSystemPrompt, $dynamicUserMessage);
 */
final class DeepSeekClient implements LlmClient
{
    /** Fast, cheap model - used for classification, sentiment and routing. */
    public const MODEL_V4_FLASH = 'deepseek-v4-flash';

    /** Flagship reasoning model - only for explicit thinking-mode requests. */
    public const MODEL_V4_PRO = 'deepseek-v4-pro';

    private string $apiKey;

    private string $baseUrl;

    private string $model;

    private int $timeout;

    private bool $thinking = false;

    private int $maxTokens;

    private float $temperature;

    private string $responseFormat = 'text';

    private string $purpose = 'classification';

    private int $items = 0;

    private ?Client $http = null;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        ?int $timeout = null,
        private ?LlmUsage $usage = null,
    ) {
        $this->apiKey = trim((string) ($apiKey ?? Config::get('deepseek.api_key', '')));
        $this->baseUrl = rtrim((string) ($baseUrl ?? Config::get('deepseek.base_url', 'https://api.deepseek.com')), '/');
        $this->model = $model ?? (string) Config::get('deepseek.model_fast', self::MODEL_V4_FLASH);
        $this->timeout = $timeout ?? (int) Config::get('deepseek.timeout', 60);
        $this->maxTokens = (int) Config::get('deepseek.max_tokens', 400);
        $this->temperature = (float) Config::get('deepseek.temperature', 1.0);
    }

    /**
     * Fluent: enable/disable reasoning ("thinking") mode.
     *
     * Thinking mode is expressed through model selection, exactly as the
     * DeepSeek API and the official SDK do it: off => V4_FLASH (fast/cheap),
     * on => V4_PRO.  Every classification, sentiment and routing call in this
     * codebase passes false.
     */
    public function withThinkingMode(bool $enabled = false): self
    {
        $this->thinking = $enabled;

        $this->model = $enabled
            ? (string) Config::get('deepseek.model_reasoning', self::MODEL_V4_PRO)
            : (string) Config::get('deepseek.model_fast', self::MODEL_V4_FLASH);

        return $this;
    }

    public function withModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function withMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = max(1, $maxTokens);

        return $this;
    }

    /**
     * 'json_object' enables the provider's JSON mode (the prompt must mention
     * JSON, which every prompt in /prompts does).
     */
    public function withResponseFormat(string $format): self
    {
        $this->responseFormat = $format === 'json_object' ? 'json_object' : 'text';

        return $this;
    }

    public function withTemperature(float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function withPurpose(string $purpose, int $items = 0): self
    {
        $this->purpose = $purpose;
        $this->items = $items;

        return $this;
    }

    public function provider(): string
    {
        return 'deepseek';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function available(): bool
    {
        return $this->apiKey !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array{json?:bool, max_tokens?:int, temperature?:float, purpose?:string, items?:int, thinking?:bool} $options
     * @return array{content:string, provider:string, model:string, latency_ms:int, usage:array{prompt_tokens:int, completion_tokens:int, cached_tokens:int}}
     */
    public function chat(string $systemPrompt, string $userMessage, array $options = []): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('DEEPSEEK_API_KEY is not configured');
        }

        $json = (bool) ($options['json'] ?? ($this->responseFormat === 'json_object'));
        $maxTokens = (int) ($options['max_tokens'] ?? $this->maxTokens);
        $temperature = (float) ($options['temperature'] ?? $this->temperature);
        $purpose = (string) ($options['purpose'] ?? $this->purpose);
        $items = (int) ($options['items'] ?? $this->items);
        $thinking = (bool) ($options['thinking'] ?? $this->thinking);

        // JSON mode requires the word "json" somewhere in the prompt.  The
        // static system prompts already contain it; this guard keeps a future
        // prompt edit from breaking every call, and only ever touches the
        // DYNAMIC user message - never the cacheable system prompt.
        if ($json && stripos($systemPrompt . $userMessage, 'json') === false) {
            $userMessage .= "\n\nRespond with valid JSON only.";
        }

        if (Config::get('deepseek.transport', 'http') === 'sdk' && class_exists(\DeepSeek\DeepSeekClient::class)) {
            return $this->sdkChat($systemPrompt, $userMessage, $json, $maxTokens, $temperature, $purpose, $items);
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                // Byte-identical system prompt on every call => prompt cache hits.
                ['role' => 'system', 'content' => $systemPrompt],
                // Everything dynamic lives here.
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => false,
            'thinking' => $thinking,
        ];

        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $startedAt = microtime(true);

        $response = $this->http()->post($this->baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if (!Http::isSuccess($status)) {
            $errorBody = json_decode($raw, true);
            $errorMessage = 'HTTP ' . $status . ': ' . Http::errorMessage(is_array($errorBody) ? $errorBody : $raw);

            $this->usage?->record([
                'provider' => 'deepseek',
                'model' => $this->model,
                'purpose' => $purpose,
                'latency_ms' => $latency,
                'items' => $items,
                'ok' => false,
                'error' => $errorMessage,
            ]);

            throw new \RuntimeException('DeepSeek API error (HTTP ' . $status . '): ' . substr($raw, 0, 400));
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('DeepSeek API returned a non-JSON body: ' . substr($raw, 0, 200));
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        // DeepSeek reports cache hits in two field names across API revisions.
        $cachedTokens = (int) ($usage['prompt_cache_hit_tokens'] ?? $usage['prompt_tokens_cached'] ?? 0);

        $this->usage?->record([
            'provider' => 'deepseek',
            'model' => $this->model,
            'purpose' => $purpose,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'latency_ms' => $latency,
            'items' => $items,
            'ok' => true,
        ]);

        return [
            'content' => $content,
            'provider' => 'deepseek',
            'model' => $this->model,
            'latency_ms' => $latency,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cached_tokens' => $cachedTokens,
            ],
        ];
    }

    /**
     * Route the call through deepseek-php/deepseek-php-client (DEEPSEEK_TRANSPORT=sdk).
     *
     * @return array{content:string, provider:string, model:string, latency_ms:int, usage:array{prompt_tokens:int, completion_tokens:int, cached_tokens:int}}
     */
    private function sdkChat(
        string $systemPrompt,
        string $userMessage,
        bool $json,
        int $maxTokens,
        float $temperature,
        string $purpose,
        int $items,
    ): array {
        $model = $this->model;

        if (class_exists(\DeepSeek\Enums\Models::class)) {
            $model = $this->thinking
                ? \DeepSeek\Enums\Models::V4_PRO->value
                : \DeepSeek\Enums\Models::V4_FLASH->value;
        }

        $startedAt = microtime(true);

        try {
            /** @var string $content */
            $content = \DeepSeek\DeepSeekClient::build($this->apiKey, $this->baseUrl, $this->timeout, 'guzzle')
                ->withModel($model)
                ->withStream(false)
                ->setTemperature($temperature)
                ->setMaxTokens($maxTokens)
                ->setResponseFormat($json ? 'json_object' : 'text')
                ->resetQueries()
                ->query($systemPrompt, 'system')
                ->query($userMessage, 'user')
                ->run();
        } catch (Throwable $e) {
            Logger::error('deepseek sdk call failed', ['error' => $e->getMessage()]);
            $this->usage?->record([
                'provider' => 'deepseek',
                'model' => $model,
                'purpose' => $purpose,
                'items' => $items,
                'ok' => false,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('DeepSeek SDK error: ' . $e->getMessage(), 0, $e);
        }

        $latency = (int) round((microtime(true) - $startedAt) * 1000);

        // The SDK does not surface usage counters; estimate at ~4 chars/token so
        // the budget guard still has data (and the audit flags it as estimated).
        $promptTokens = (int) ceil((strlen($systemPrompt) + strlen($userMessage)) / 4);
        $completionTokens = (int) ceil(strlen($content) / 4);

        $this->usage?->record([
            'provider' => 'deepseek',
            'model' => $model,
            'purpose' => $purpose,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => 0,
            'latency_ms' => $latency,
            'items' => $items,
            'ok' => true,
        ]);

        return [
            'content' => $content,
            'provider' => 'deepseek',
            'model' => $model,
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
            'connect_timeout' => 10,
        ]);
    }
}
