<?php

declare(strict_types=1);

namespace SocialBot\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Shared Guzzle factory: retries, sane timeouts and structured logging.
 */
final class Http
{
    /**
     * @param array<string, mixed> $options extra Guzzle options; pass 'retries' => 0
     *                                      to disable the retry middleware (used by
     *                                      cheap connectivity probes)
     */
    public static function client(array $options = []): Client
    {
        if (!class_exists(Client::class)) {
            throw new \RuntimeException(
                'guzzlehttp/guzzle is not installed. Run `composer install` (see README section 1.2).'
            );
        }

        $retries = (int) ($options['retries'] ?? 3);
        unset($options['retries']);

        $stack = HandlerStack::create();

        if ($retries > 0) {
            $stack->push(Middleware::retry(
                static function (int $attempts, RequestInterface $request, ?ResponseInterface $response = null, ?Throwable $exception = null) use ($retries): bool {
                    if ($attempts >= $retries) {
                        return false;
                    }

                    if ($exception instanceof ConnectException) {
                        return true;
                    }

                    $status = $response?->getStatusCode() ?? 0;

                    return $status === 429 || $status >= 500;
                },
                static fn (int $attempts): int => (int) (250 * (2 ** $attempts))
            ));
        }

        return new Client(array_merge([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'handler' => $stack,
            'headers' => [
                'User-Agent' => 'social-bot/1.0 (+php-' . PHP_VERSION . ')',
                'Accept' => 'application/json',
            ],
        ], $options));
    }

    /**
     * Perform a JSON request and decode the response body when possible.
     *
     * @param array<string, mixed> $options
     * @return array{status:int, body:mixed, raw:string, headers:array<string, array<int, string>>}
     */
    public static function json(string $method, string $url, array $options = [], ?Client $client = null): array
    {
        $client ??= self::client();
        $context = ['method' => $method, 'url' => $url];

        try {
            $response = $client->request($method, $url, $options);
        } catch (Throwable $e) {
            Logger::error('http request failed', $context + ['error' => $e->getMessage()]);

            throw $e;
        }

        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $decoded = $raw === '' ? [] : ['_raw' => $raw];
        }

        $level = $response->getStatusCode() >= 400 ? 'warning' : 'debug';

        Logger::channel('http')->write($level, 'http response', $context + [
            'status' => $response->getStatusCode(),
            'bytes' => strlen($raw),
        ]);

        return [
            'status' => $response->getStatusCode(),
            'body' => $decoded,
            'raw' => $raw,
            'headers' => $response->getHeaders(),
        ];
    }

    public static function isSuccess(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Best-effort error message extraction from an API payload.
     *
     * @param mixed $body
     */
    public static function errorMessage(mixed $body): string
    {
        if (is_string($body)) {
            return substr($body, 0, 500);
        }

        if (!is_array($body)) {
            return 'unknown error';
        }

        foreach (['message', 'error', 'error_description', 'detail', 'title'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        if (isset($body['error']) && is_array($body['error'])) {
            return self::errorMessage($body['error']);
        }

        if (isset($body['errors'][0]) && is_array($body['errors'][0])) {
            return self::errorMessage($body['errors'][0]);
        }

        return substr(json_encode($body, JSON_UNESCAPED_SLASHES) ?: 'unknown error', 0, 500);
    }

    /**
     * RequestException helper used by tests / callers that want the response body.
     */
    public static function exceptionBody(RequestException $e): array
    {
        $body = $e->getResponse()?->getBody();

        if ($body === null) {
            return [];
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
