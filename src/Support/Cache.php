<?php

declare(strict_types=1);

namespace SocialBot\Support;

use Throwable;

/**
 * Cache / rate-limit / dedupe store.
 *
 * Prefers the Redis instance from the stack (ext-redis or predis/predis), and
 * falls back to a TTL'd JSON file store under storage/cache so the bot keeps
 * working on a single box without Redis running.
 */
final class Cache
{
    private mixed $redis = null;

    private readonly string $prefix;

    private readonly string $directory;

    public function __construct()
    {
        $this->prefix = 'socialbot:';
        $this->directory = (string) Config::get('paths.cache', sys_get_temp_dir());
        $this->redis = $this->connect();
    }

    private function connect(): mixed
    {
        $host = (string) Config::get('redis.host', '127.0.0.1');
        $port = (int) Config::get('redis.port', 6379);
        $password = (string) Config::get('redis.password', '');
        $database = (int) Config::get('redis.database', 0);

        try {
            if (class_exists(\Redis::class)) {
                $redis = new \Redis();
                $redis->connect($host, $port, 1.5);

                if ($password !== '') {
                    $redis->auth($password);
                }

                $redis->select($database);

                return $redis;
            }

            if (class_exists(\Predis\Client::class)) {
                return new \Predis\Client([
                    'scheme' => 'tcp',
                    'host' => $host,
                    'port' => $port,
                    'password' => $password !== '' ? $password : null,
                    'database' => $database,
                    'timeout' => 1.5,
                ]);
            }
        } catch (Throwable $e) {
            Logger::warning('redis unavailable, using file cache', ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function usingRedis(): bool
    {
        return $this->redis !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prefix . $key;

        if ($this->redis !== null) {
            try {
                $value = $this->redis->get($key);

                return $value === null || $value === false ? $default : $this->decode((string) $value, $default);
            } catch (Throwable $e) {
                Logger::warning('cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        $file = $this->file($key);

        if (!is_file($file)) {
            return $default;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        if (!is_array($payload) || ($payload['expires_at'] ?? 0) < time()) {
            @unlink($file);

            return $default;
        }

        return $payload['value'] ?? $default;
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 300): void
    {
        $key = $this->prefix . $key;

        if ($this->redis !== null) {
            try {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if ($ttlSeconds > 0) {
                    $this->redis->setex($key, $ttlSeconds, (string) $encoded);
                } else {
                    $this->redis->set($key, (string) $encoded);
                }

                return;
            } catch (Throwable $e) {
                Logger::warning('cache set failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        @file_put_contents($this->file($key), json_encode([
            'expires_at' => $ttlSeconds > 0 ? time() + $ttlSeconds : PHP_INT_MAX,
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $cached = $this->get($key, '__miss__');

        if ($cached !== '__miss__') {
            /** @var T $cached */
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttlSeconds);

        return $value;
    }

    public function forget(string $key): void
    {
        $key = $this->prefix . $key;

        if ($this->redis !== null) {
            try {
                $this->redis->del($key);

                return;
            } catch (Throwable) {
                // fall through to the file store
            }
        }

        @unlink($this->file($key));
    }

    /**
     * Fixed-window counter used for rate limiting outbound API calls.
     */
    public function increment(string $key, int $ttlSeconds = 60): int
    {
        $key = $this->prefix . $key;

        if ($this->redis !== null) {
            try {
                $value = (int) $this->redis->incr($key);

                if ($value === 1) {
                    $this->redis->expire($key, $ttlSeconds);
                }

                return $value;
            } catch (Throwable) {
                // fall through to the file store
            }
        }

        $current = (int) $this->get($key, 0);
        $current++;
        $this->set($key, $current, $ttlSeconds);

        return $current;
    }

    /**
     * True the first time a key is seen within the window; used to drop
     * duplicate webhook deliveries.
     */
    public function once(string $key, int $ttlSeconds = 86400): bool
    {
        $seenKey = $key . ':seen';

        if ($this->get($seenKey, false) === true) {
            return false;
        }

        $this->set($seenKey, true, $ttlSeconds);

        return true;
    }

    public function allows(string $key, int $maxPerWindow, int $windowSeconds = 60): bool
    {
        return $this->increment($key, $windowSeconds) <= $maxPerWindow;
    }

    private function file(string $key): string
    {
        return rtrim($this->directory, '/\\') . '/' . sha1($key) . '.cache';
    }

    private function decode(string $raw, mixed $default): mixed
    {
        $decoded = json_decode($raw, true);

        return $decoded === null && $raw !== 'null' ? $default : $decoded;
    }
}
