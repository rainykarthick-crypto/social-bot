<?php

declare(strict_types=1);

namespace SocialBot\Support;

/**
 * Thin wrapper around the incoming HTTP request, so webhook entry points do not
 * touch superglobals directly (and so they are testable from the CLI).
 */
final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    public function __construct(
        private readonly ?string $rawBody = null,
        /** @var array<string, string> */
        private readonly array $headers = [],
        /** @var array<string, mixed> */
        private readonly array $query = [],
        private readonly string $method = 'GET',
    ) {
    }

    /**
     * Build from the current PHP request (web SAPI).
     */
    public static function capture(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }

        return new self(
            (string) file_get_contents('php://input'),
            $headers,
            $_GET,
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))
        );
    }

    /**
     * Build from explicit values (CLI, tests, queue replays).
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public static function make(string $method, string $rawBody = '', array $headers = [], array $query = []): self
    {
        return new self($rawBody, array_change_key_case($headers, CASE_LOWER), $query, strtoupper($method));
    }

    public function method(): string
    {
        return $this->method;
    }

    public function raw(): string
    {
        return (string) ($this->rawBody ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $decoded = Json::decode($this->raw());

        return $this->jsonCache = is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;

        return $value === null ? null : (string) $value;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Path relative to the webhook entry point: /webhook/meta -> "meta".
     */
    public function route(?string $basePath = '/webhook'): string
    {
        $uri = $this->query['__uri'] ?? null;

        if (is_string($uri) && $uri !== '') {
            return trim(str_replace($basePath, '', $uri), '/');
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';

        return trim(str_replace($basePath, '', (string) $path), '/');
    }
}
