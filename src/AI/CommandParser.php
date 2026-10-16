<?php

declare(strict_types=1);

namespace SocialBot\AI;

use SocialBot\Support\App;
use SocialBot\Support\Config;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;
use SocialBot\Support\LlmUsage;
use Throwable;

/**
 * Natural language -> structured bot command.
 *
 * Uses the static prompts/command.txt system prompt (thinking off, JSON mode,
 * max_tokens=400) and degrades to a keyword parser when no LLM is reachable, so
 * operators can always list pending posts or trigger an approval.
 */
final class CommandParser
{
    public const ACTIONS = ['create_post', 'schedule_post', 'list_pending', 'approve_post', 'check_analytics'];

    public const PLATFORMS = ['instagram', 'linkedin', 'twitter', 'facebook'];

    private string $systemPrompt;

    public function __construct(
        private readonly LlmClient $primary,
        private readonly ?LlmClient $fallback,
        private readonly LlmUsage $usage,
        private readonly BrandVoiceRAG $brandVoice,
        ?string $systemPrompt = null,
    ) {
        $this->systemPrompt = $systemPrompt ?? DeepSeekClassifier::loadPrompt('command.txt');
    }

    public static function fromConfig(): self
    {
        $usage = new LlmUsage(App::db());

        return new self(
            new DeepSeekClient(usage: $usage),
            new OllamaClient(usage: $usage),
            $usage,
            App::brandVoice()
        );
    }

    public function systemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /**
     * @param array<string, mixed> $context optional keys: requested_by, default_platforms
     * @return array<string, mixed>
     */
    public function parse(string $command, array $context = []): array
    {
        $command = trim($command);

        if ($command === '') {
            return $this->heuristicParse('', 'empty_command');
        }

        $userMessage = $this->buildUserMessage($command, $context);

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
                    'temperature' => 0.1,
                    'purpose' => 'command_parse',
                    'thinking' => false,
                ]);

                $decoded = Json::extractObject((string) $response['content']);

                if ($decoded === null) {
                    continue;
                }

                return $this->normalise($decoded, $command, (string) $response['provider']);
            } catch (Throwable $e) {
                Logger::error('command parse failed', [
                    'provider' => $client->provider(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->heuristicParse($command, 'llm_unavailable');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildUserMessage(string $command, array $context): string
    {
        $lines = ['Operator command: ' . $command];

        $guidance = $this->brandVoice->guidance($command);

        if ($guidance !== '') {
            // Brand voice is retrieved content, so it stays out of the static
            // system prompt and never breaks prompt caching.
            $lines[] = '';
            $lines[] = $guidance;
        }

        if (($context['requested_by'] ?? '') !== '') {
            $lines[] = 'Requested by: ' . (string) $context['requested_by'];
        }

        $defaultPlatforms = $context['default_platforms'] ?? array_keys((array) Config::get('platform_accounts', []));

        if (is_array($defaultPlatforms) && $defaultPlatforms !== []) {
            $lines[] = 'Known platforms: ' . implode(', ', array_map('strval', $defaultPlatforms));
        }

        $lines[] = 'Return the JSON object described in the system prompt.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalise(array $raw, string $command, string $provider): array
    {
        $action = strtolower(trim((string) ($raw['action'] ?? '')));

        if (!in_array($action, self::ACTIONS, true)) {
            $action = 'create_post';
        }

        $platforms = [];

        foreach ((array) ($raw['platforms'] ?? []) as $platform) {
            $platform = strtolower(trim((string) $platform));

            if (in_array($platform, self::PLATFORMS, true)) {
                $platforms[] = $platform;
            }
        }

        if ($platforms === []) {
            $platforms = array_keys((array) Config::get('platform_accounts', ['instagram' => 1]));
            $platforms = array_values(array_filter(array_map('strval', $platforms)));
        }

        $schedule = is_array($raw['schedule'] ?? null) ? $raw['schedule'] : [];
        $date = $schedule['date'] ?? null;
        $time = $schedule['time'] ?? null;

        $confidence = (int) round((float) ($raw['confidence'] ?? 70));

        return [
            'action' => $action,
            'content' => trim((string) ($raw['content'] ?? '')),
            'platforms' => array_values(array_unique($platforms)),
            'schedule' => [
                'date' => self::validDate($date),
                'time' => self::validTime($time),
                'immediate' => filter_var($schedule['immediate'] ?? ($date === null && $time === null), FILTER_VALIDATE_BOOLEAN),
            ],
            'requires_approval' => array_key_exists('requires_approval', $raw)
                ? filter_var($raw['requires_approval'], FILTER_VALIDATE_BOOLEAN)
                : true,
            'confidence' => max(0, min(100, $confidence)),
            'command' => $command,
            'provider' => $provider,
            'degraded' => $provider !== 'deepseek',
        ];
    }

    private static function validDate(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private static function validTime(mixed $time): ?string
    {
        if (!is_string($time) || trim($time) === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}/', trim($time), $matches) !== 1) {
            return null;
        }

        $timestamp = strtotime($time);

        return $timestamp === false ? null : date('H:i', $timestamp);
    }

    /**
     * Keyword parser used when no LLM is reachable.  Keeps the operator console
     * functional during an outage.
     *
     * @return array<string, mixed>
     */
    public function heuristicParse(string $command, string $reason = 'fallback'): array
    {
        $lower = mb_strtolower($command);

        $action = 'create_post';

        if (preg_match('/\b(list|show|pending|pending posts)\b/', $lower) === 1) {
            $action = 'list_pending';
        } elseif (preg_match('/\b(approve|approval|publish)\b/', $lower) === 1) {
            $action = 'approve_post';
        } elseif (preg_match('/\b(analytics|stats|report|performance|insight)\b/', $lower) === 1) {
            $action = 'check_analytics';
        } elseif (preg_match('/\b(schedule|later|tomorrow|at \d)/', $lower) === 1) {
            $action = 'schedule_post';
        }

        $platforms = [];

        foreach (self::PLATFORMS as $platform) {
            if (str_contains($lower, $platform)) {
                $platforms[] = $platform;
            }
        }

        if (str_contains($lower, 'x/twitter') || preg_match('/\bx\b/', $lower) === 1) {
            $platforms[] = 'twitter';
        }

        if ($platforms === []) {
            $platforms = array_values(array_filter(array_map('strval', array_keys((array) Config::get('platform_accounts', [])))));
        }

        $content = $command;

        if (preg_match('/(?:post|publish|share|announce|say)\s+(?:about\s+|that\s+)?(.+)$/i', $command, $matches) === 1) {
            $content = trim($matches[1]);
        }

        return [
            'action' => $action,
            'content' => trim($content),
            'platforms' => array_values(array_unique($platforms)),
            'schedule' => ['date' => null, 'time' => null, 'immediate' => true],
            'requires_approval' => true,
            'confidence' => 35,
            'command' => $command,
            'provider' => 'heuristic',
            'degraded' => true,
            'degraded_reason' => $reason,
        ];
    }
}
