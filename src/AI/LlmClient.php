<?php

declare(strict_types=1);

namespace SocialBot\AI;

/**
 * Contract every LLM transport implements (DeepSeek, Ollama, ...).
 */
interface LlmClient
{
    /**
     * Run a single chat completion.
     *
     * @param array{json?:bool, max_tokens?:int, temperature?:float, purpose?:string, items?:int, thinking?:bool} $options
     * @return array{
     *     content:string,
     *     provider:string,
     *     model:string,
     *     latency_ms:int,
     *     usage:array{prompt_tokens:int, completion_tokens:int, cached_tokens:int}
     * }
     *
     * @throws \RuntimeException when the provider is unavailable or returns an error
     */
    public function chat(string $systemPrompt, string $userMessage, array $options = []): array;

    public function provider(): string;

    public function model(): string;

    public function available(): bool;
}
