<?php

declare(strict_types=1);

namespace SocialBot\Ingestion;

use GuzzleHttp\Client;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Http;
use SocialBot\Support\Logger;
use Throwable;

/**
 * Instagram Graph integration.
 *
 * Prefers amirsarhang/instagram-php-sdk (v4) when it is installed:
 *   $ig->comments()->reply($commentId, $message)
 *   $ig->comments()->get($commentId, ['media', 'like_count'])
 *   $ig->webhooks()->subscribe(['comments'])
 *   $ig->get('/me/media', ['fields' => '...'])
 * and falls back to direct Graph API calls through Guzzle when the SDK is not
 * present - so the module always works.
 *
 * Public method names follow the project spec: subscribeToComments(),
 * replyToComment(), getPostComments().
 */
final class InstagramIngestor
{
    private ?Client $http = null;

    private mixed $sdk = null;

    private bool $sdkResolved = false;

    public function __construct(
        private readonly Database $db,
        private readonly ?Cache $cache = null,
        private readonly ?AuditLog $audit = null,
    ) {
    }

    public static function make(): self
    {
        return new self(
            \SocialBot\Support\App::db(),
            \SocialBot\Support\App::cache(),
            new AuditLog(\SocialBot\Support\App::db())
        );
    }

    public function configured(): bool
    {
        return trim((string) Config::get('instagram.access_token', '')) !== '';
    }

    public function graphBase(): string
    {
        return rtrim((string) Config::get('instagram.graph_base', 'https://graph.facebook.com'), '/');
    }

    public function graphVersion(): string
    {
        return (string) Config::get('instagram.graph_version', 'v21.0');
    }

    /**
     * Webhook subscription for comment events (comments + optional messages).
     *
     * @param array<int, string> $fields
     * @return array{ok:bool, message:string, data:array<string, mixed>}
     */
    public function subscribeToComments(array $fields = ['comments']): array
    {
        $sdk = $this->sdk();

        if ($sdk !== null) {
            try {
                /** @var mixed $result */
                $result = $sdk->webhooks()->subscribe($fields);

                $this->audit?->info('instagram.subscribed', 'Instagram comment subscription registered', [
                    'fields' => $fields,
                    'sdk' => true,
                ]);

                return [
                    'ok' => true,
                    'message' => 'Subscribed via amirsarhang/instagram-php-sdk.',
                    'data' => is_array($result) ? $result : ['result' => $result],
                ];
            } catch (Throwable $e) {
                Logger::warning('instagram sdk subscribe failed, falling back to graph api', ['error' => $e->getMessage()]);
            }
        }

        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'credentials pending: INSTAGRAM_ACCESS_TOKEN is empty', 'data' => []];
        }

        $accountId = (string) Config::get('instagram.business_account_id', '');

        if ($accountId === '') {
            return ['ok' => false, 'message' => 'INSTAGRAM_BUSINESS_ACCOUNT_ID is required for the Graph API fallback.', 'data' => []];
        }

        $response = $this->graph('POST', '/' . $accountId . '/subscribed_apps', [
            'subscribed_fields' => implode(',', $fields),
        ]);

        $ok = $response['status'] >= 200 && $response['status'] < 300;

        $this->audit?->record(
            'instagram.subscribed',
            $ok ? 'info' : 'warning',
            null,
            null,
            $ok ? 'Instagram subscription registered' : 'Instagram subscription failed',
            ['fields' => $fields, 'status' => $response['status']]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Subscribed via Graph API.' : 'Subscription failed: ' . Http::errorMessage($response['body']),
            'data' => is_array($response['body']) ? $response['body'] : [],
        ];
    }

    /**
     * Public reply to a comment (the dashboard's "Reply via platform" action).
     *
     * @return array{ok:bool, message:string, data:array<string, mixed>}
     */
    public function replyToComment(string $commentId, string $message): array
    {
        $message = trim($message);

        if ($commentId === '' || $message === '') {
            return ['ok' => false, 'message' => 'Both comment id and message are required.', 'data' => []];
        }

        if ($this->cache !== null && !$this->cache->allows('instagram:reply', 60, 3600)) {
            return ['ok' => false, 'message' => 'Reply rate limit reached (60/hour). Try again later.', 'data' => []];
        }

        $sdk = $this->sdk();

        if ($sdk !== null) {
            try {
                /** @var mixed $result */
                $result = $sdk->comments()->reply($commentId, $message);

                $this->audit?->info('instagram.replied', 'Replied to Instagram comment', [
                    'comment_id' => $commentId,
                    'sdk' => true,
                ], 'instagram_comment', $commentId);

                return [
                    'ok' => true,
                    'message' => 'Reply sent via amirsarhang/instagram-php-sdk.',
                    'data' => is_array($result) ? $result : ['result' => $result],
                ];
            } catch (Throwable $e) {
                Logger::warning('instagram sdk reply failed, falling back to graph api', ['error' => $e->getMessage()]);
            }
        }

        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'credentials pending: INSTAGRAM_ACCESS_TOKEN is empty', 'data' => []];
        }

        $response = $this->graph('POST', '/' . rawurlencode($commentId) . '/replies', ['message' => $message]);
        $ok = $response['status'] >= 200 && $response['status'] < 300;

        $this->audit?->record(
            'instagram.replied',
            $ok ? 'info' : 'warning',
            'instagram_comment',
            $commentId,
            $ok ? 'Reply sent' : 'Reply failed: ' . Http::errorMessage($response['body']),
            ['status' => $response['status']]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Reply sent via Graph API.' : 'Reply failed: ' . Http::errorMessage($response['body']),
            'data' => is_array($response['body']) ? $response['body'] : [],
        ];
    }

    /**
     * Fetch comments for a media object.
     *
     * @return array{ok:bool, comments:array<int, array<string, mixed>>, message:string}
     */
    public function getPostComments(string $mediaId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        if ($mediaId === '') {
            return ['ok' => false, 'comments' => [], 'message' => 'media id is required'];
        }

        $sdk = $this->sdk();

        if ($sdk !== null) {
            try {
                /** @var mixed $result */
                $result = $sdk->get('/' . rawurlencode($mediaId) . '/comments', [
                    'fields' => 'id,text,from,like_count,timestamp',
                    'limit' => $limit,
                ]);

                $comments = $this->normaliseCollection($result, $mediaId);

                return ['ok' => true, 'comments' => $comments, 'message' => 'Fetched via SDK.'];
            } catch (Throwable $e) {
                Logger::warning('instagram sdk comment fetch failed, falling back to graph api', ['error' => $e->getMessage()]);
            }
        }

        if (!$this->configured()) {
            return ['ok' => false, 'comments' => [], 'message' => 'credentials pending: INSTAGRAM_ACCESS_TOKEN is empty'];
        }

        $response = $this->graph('GET', '/' . rawurlencode($mediaId) . '/comments', [
            'fields' => 'id,text,from,like_count,timestamp',
            'limit' => $limit,
        ]);

        $comments = $this->normaliseCollection($response['body'], $mediaId);
        $ok = $response['status'] >= 200 && $response['status'] < 300;

        return [
            'ok' => $ok,
            'comments' => $comments,
            'message' => $ok ? 'Fetched via Graph API.' : 'Fetch failed: ' . Http::errorMessage($response['body']),
        ];
    }

    /**
     * Recent media for the business account (used by SocialListeningAgent).
     *
     * @return array{ok:bool, media:array<int, array<string, mixed>>, message:string}
     */
    public function recentMedia(int $limit = 10): array
    {
        $accountId = (string) Config::get('instagram.business_account_id', '');

        if ($accountId === '') {
            return ['ok' => false, 'media' => [], 'message' => 'INSTAGRAM_BUSINESS_ACCOUNT_ID is not configured'];
        }

        if (!$this->configured()) {
            return ['ok' => false, 'media' => [], 'message' => 'credentials pending: INSTAGRAM_ACCESS_TOKEN is empty'];
        }

        $response = $this->graph('GET', '/' . rawurlencode($accountId) . '/media', [
            'fields' => 'id,caption,permalink,timestamp,comments_count',
            'limit' => max(1, min(50, $limit)),
        ]);

        $body = is_array($response['body']) ? $response['body'] : [];
        $media = array_values(array_filter((array) ($body['data'] ?? []), 'is_array'));
        $ok = $response['status'] >= 200 && $response['status'] < 300;

        return [
            'ok' => $ok,
            'media' => $media,
            'message' => $ok ? 'ok' : 'Fetch failed: ' . Http::errorMessage($body),
        ];
    }

    /**
     * Insert fetched comments into comment_queue (idempotent).
     *
     * @param array<int, array<string, mixed>> $comments
     */
    public function enqueue(array $comments, string $platform = 'instagram'): int
    {
        $queued = 0;

        foreach ($comments as $comment) {
            $commentId = (string) ($comment['comment_id'] ?? $comment['id'] ?? '');
            $text = (string) ($comment['text'] ?? '');

            if ($commentId === '' || $text === '') {
                continue;
            }

            $id = $this->db->insertIgnore('comment_queue', [
                'platform' => $platform,
                'post_id' => $comment['post_id'] ?? null,
                'comment_id' => $commentId,
                'author' => $comment['author'] ?? null,
                'author_profile_url' => $comment['author_profile_url'] ?? null,
                'text' => $text,
                'post_context' => $comment['post_context'] ?? null,
                'likes' => (int) ($comment['likes'] ?? 0),
                'author_followers' => (int) ($comment['author_followers'] ?? 0),
                'author_verified' => (int) ($comment['author_verified'] ?? 0),
                'status' => 'pending',
                'received_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($id !== null) {
                $queued++;
            }
        }

        if ($queued > 0) {
            $this->audit?->info('ingestion.instagram.enqueued', 'Instagram comments enqueued', ['count' => $queued]);
        }

        return $queued;
    }

    /**
     * Fetch + queue the comments of the most recent media objects.
     *
     * @return array{fetched:int, queued:int, media_scanned:int, errors:array<int, string>}
     */
    public function syncRecentComments(int $maxPosts = 10, int $commentsPerPost = 50): array
    {
        $media = $this->recentMedia($maxPosts);
        $errors = [];
        $fetched = 0;
        $queued = 0;

        foreach ($media['media'] as $item) {
            $mediaId = (string) ($item['id'] ?? '');

            if ($mediaId === '') {
                continue;
            }

            $comments = $this->getPostComments($mediaId, $commentsPerPost);

            if (!$comments['ok']) {
                $errors[] = $mediaId . ': ' . $comments['message'];

                continue;
            }

            $fetched += count($comments['comments']);
            $queued += $this->enqueue($comments['comments']);
        }

        if (!$media['ok'] && $media['message'] !== '') {
            $errors[] = $media['message'];
        }

        return [
            'fetched' => $fetched,
            'queued' => $queued,
            'media_scanned' => count($media['media']),
            'errors' => $errors,
        ];
    }

    public function usingSdk(): bool
    {
        return $this->sdk() !== null;
    }

    /**
     * @return mixed the SDK client, or null when unavailable
     */
    public function sdk(): mixed
    {
        if ($this->sdkResolved) {
            return $this->sdk;
        }

        $this->sdkResolved = true;

        if (!class_exists(\Amirsarhang\Instagram::class) || !$this->configured()) {
            return $this->sdk = null;
        }

        try {
            $this->sdk = new \Amirsarhang\Instagram((string) Config::get('instagram.access_token', ''));
        } catch (Throwable $e) {
            Logger::warning('instagram sdk init failed, using graph api', ['error' => $e->getMessage()]);
            $this->sdk = null;
        }

        return $this->sdk;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status:int, body:mixed}
     */
    private function graph(string $method, string $path, array $params = []): array
    {
        $url = $this->graphBase() . '/' . $this->graphVersion() . $path;

        $options = ['headers' => ['Authorization' => 'Bearer ' . (string) Config::get('instagram.access_token', '')]];

        if ($method === 'GET') {
            $options['query'] = $params;
        } else {
            $options['form_params'] = $params;
        }

        try {
            $response = ($this->http ??= Http::client([
                'timeout' => (int) Config::get('instagram.timeout', 30),
                'http_errors' => false,
            ]))->request($method, $url, $options);

            $raw = (string) $response->getBody();
            $decoded = json_decode($raw, true);

            return [
                'status' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : ['_raw' => $raw],
            ];
        } catch (Throwable $e) {
            Logger::error('instagram graph call failed', ['path' => $path, 'error' => $e->getMessage()]);

            return ['status' => 0, 'body' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * @param mixed $payload
     * @return array<int, array<string, mixed>>
     */
    private function normaliseCollection(mixed $payload, string $mediaId): array
    {
        $items = [];

        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            $items = $payload['data'];
        } elseif (is_array($payload) && array_is_list($payload)) {
            $items = $payload;
        }

        $comments = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $from = is_array($item['from'] ?? null) ? $item['from'] : [];

            $comments[] = [
                'comment_id' => (string) ($item['id'] ?? ''),
                'post_id' => $mediaId,
                'author' => (string) ($from['username'] ?? $from['name'] ?? 'instagram_user'),
                'author_profile_url' => isset($from['username']) ? 'https://instagram.com/' . (string) $from['username'] : null,
                'text' => (string) ($item['text'] ?? ''),
                'likes' => (int) ($item['like_count'] ?? 0),
                'post_context' => 'instagram media ' . $mediaId,
                'timestamp' => (string) ($item['timestamp'] ?? ''),
            ];
        }

        return $comments;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function commentsForLead(string $commentId): array
    {
        $sdk = $this->sdk();

        if ($sdk === null) {
            return [];
        }

        try {
            /** @var mixed $result */
            $result = $sdk->comments()->get($commentId, ['media', 'like_count', 'timestamp']);

            return is_array($result) ? $result : [];
        } catch (Throwable $e) {
            Logger::warning('instagram comment lookup failed', ['comment_id' => $commentId, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
