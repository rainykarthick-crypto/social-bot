<?php

declare(strict_types=1);

namespace SocialBot\Support;

/**
 * Environment loader.
 *
 * Uses vlucas/phpdotenv when it is installed and falls back to a tiny parser
 * otherwise, so the application still boots (with defaults / Ollama fallback)
 * on a machine where `composer install` has not been run yet.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (!is_file($path)) {
            return;
        }

        if (class_exists(\Dotenv\Dotenv::class)) {
            try {
                \Dotenv\Dotenv::createImmutable(dirname($path), basename($path))->safeLoad();
            } catch (\Throwable $e) {
                Logger::warning('phpdotenv failed, using built-in parser', ['error' => $e->getMessage()]);
                self::parse($path);
            }

            return;
        }

        self::parse($path);
    }

    private static function parse(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Strip a single layer of matching quotes, keep everything else verbatim.
            if (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[strlen($value) - 1] === $value[0]
            ) {
                $value = substr($value, 1, -1);
            }

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '' && $_ENV[$key] !== null) {
            return (string) $_ENV[$key];
        }

        $value = getenv($key);

        if ($value !== false && $value !== '') {
            return $value;
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (float) $value;
    }

    /**
     * Decode a JSON object/array environment variable.
     *
     * @return array<mixed>
     */
    public static function json(string $key, array $default = []): array
    {
        $value = self::get($key);

        if ($value === null || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Comma separated list helper.
     *
     * @return array<int, string>
     */
    public static function list(string $key, array $default = []): array
    {
        $value = self::get($key);

        if ($value === null || trim($value) === '') {
            return $default;
        }

        $parts = array_map('trim', explode(',', $value));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
