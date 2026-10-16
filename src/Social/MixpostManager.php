<?php

declare(strict_types=1);

namespace SocialBot\Social;

use GuzzleHttp\Client;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Http;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;
use Throwable;

/**
 * Mixpost multi-account publisher.
 *
 * Base URL: {MIXPOST_URL}{MIXPOST_API_PREFIX}/{workspaceUuid}/  (default prefix
 * /mixpost/api) with Bearer token auth.
 *
 * Post status values used by Mixpost: draft, scheduled, published, failed,
 * needs_approval, publishing.
 */
final class MixpostManager
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_APPROVAL = 'needs_approval';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_APPROVAL,
        self::STATUS_PUBLISHING,
    ];

    private ?Client $http;

    private AuditLog $audit;

    public function __construct(
        private readonly Database $db,
        ?Client $http = null,
        ?AuditLog $audit = null,
    ) {
        // Lazy, like KrayinClient: constructing the manager must never require
        // the vendor directory to be installed.
        $this->http = $http;
        $this->audit = $audit ?? new AuditLog($db);
    }

    private function http(): Client
    {
        return $this->http ??= Http::client(['timeout' => (int) Config::get('mixpost.timeout', 30)]);
    }

    public function configured(): bool
    {
        return trim((string) Config::get('mixpost.token', '')) !== '';
    }

    public function workspaceUuid(): string
    {
        return trim((string) Config::get('mixpost.workspace_uuid', ''));
    }

    public function baseUrl(string $workspaceUuid): string
    {
        $prefix = (string) Config::get('mixpost.api_prefix', '/mixpost/api');

        return rtrim((string) Config::get('mixpost.url', ''), '/') . $prefix . '/' . trim($workspaceUuid, '/');
    }

    /**
     * Browser URL for a workspace (used by the dashboard).
     */
    public function workspaceUrl(?string $workspaceUuid = null): string
    {
        return rtrim((string) Config::get('mixpost.url', ''), '/') . '/mixpost/' . trim($workspaceUuid ?? $this->workspaceUuid(), '/') . '/posts';
    }

    /**
     * POST /posts
     *
     * Accepts either a fully formed Mixpost payload or the convenience shape:
     *   ['content' => '...', 'accounts' => [1,2], 'date' => 'Y-m-d', 'time' => 'H:i',
     *    'schedule' => true, 'timezone' => 'Asia/Kolkata', 'tags' => [...],
     *    'versions' => [...]]
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, uuid:string|null, status:string, data:array<string, mixed>, error:string|null}
     */
    public function createPost(string $workspaceUuid, array $data): array
    {
        $payload = $this->buildPayload($data);

        $response = $this->request($workspaceUuid, 'POST', '/posts', $payload, 'post.create');

        $body = $response['body'];
        $uuid = $this->extractUuid($body);
        $status = (string) ($this->extractData($body)['status'] ?? self::STATUS_DRAFT);

        if ($response['ok']) {
            $this->audit->info('mixpost.post.created', 'Mixpost post created', [
                'workspace' => $workspaceUuid,
                'post_uuid' => $uuid,
                'status' => $status,
                'accounts' => $payload['accounts'] ?? [],
            ], 'mixpost_post', (string) $uuid);
        }

        return [
            'ok' => $response['ok'],
            'uuid' => $uuid,
            'status' => $status,
            'data' => $body,
            'error' => $response['error'],
        ];
    }

    /**
     * POST /posts/approve/{postUuid}
     *
     * @return array{ok:bool, status:string, data:array<string, mixed>, error:string|null}
     */
    public function approvePost(string $workspaceUuid, string $postUuid): array
    {
        $response = $this->request($workspaceUuid, 'POST', '/posts/approve/' . rawurlencode($postUuid), [], 'post.approve');

        $status = (string) ($this->extractData($response['body'])['status'] ?? ($response['ok'] ? self::STATUS_SCHEDULED : self::STATUS_NEEDS_APPROVAL));

        if ($response['ok']) {
            $this->audit->info('mixpost.post.approved', 'Mixpost post approved', [
                'workspace' => $workspaceUuid,
                'post_uuid' => $postUuid,
                'status' => $status,
            ], 'mixpost_post', $postUuid);
        }

        return [
            'ok' => $response['ok'],
            'status' => $status,
            'data' => $response['body'],
            'error' => $response['error'],
        ];
    }

    /**
     * GET /posts/{postUuid}
     *
     * @return array{ok:bool, status:string, data:array<string, mixed>, error:string|null}
     */
    public function getPost(string $workspaceUuid, string $postUuid): array
    {
        $response = $this->request($workspaceUuid, 'GET', '/posts/' . rawurlencode($postUuid), [], 'post.get');

        return [
            'ok' => $response['ok'],
            'status' => (string) ($this->extractData($response['body'])['status'] ?? 'unknown'),
            'data' => $response['body'],
            'error' => $response['error'],
        ];
    }

    /**
     * GET /posts?status=needs_approval
     *
     * @return array{ok:bool, posts:array<int, array<string, mixed>>, error:string|null}
     */
    public function listPendingApprovals(string $workspaceUuid): array
    {
        $response = $this->request($workspaceUuid, 'GET', '/posts?status=' . self::STATUS_NEEDS_APPROVAL, [], 'post.list_pending');

        return [
            'ok' => $response['ok'],
            'posts' => $this->extractCollection($response['body']),
            'error' => $response['error'],
        ];
    }

    /**
     * GET /posts
     *
     * @return array{ok:bool, posts:array<int, array<string, mixed>>, error:string|null}
     */
    public function listPosts(string $workspaceUuid, array $query = []): array
    {
        $queryString = $query === [] ? '' : '?' . http_build_query($query);
        $response = $this->request($workspaceUuid, 'GET', '/posts' . $queryString, [], 'post.list');

        return [
            'ok' => $response['ok'],
            'posts' => $this->extractCollection($response['body']),
            'error' => $response['error'],
        ];
    }

    /**
     * GET /accounts
     *
     * @return array{ok:bool, accounts:array<int, array<string, mixed>>, error:string|null}
     */
    public function listAccounts(string $workspaceUuid): array
    {
        $response = $this->request($workspaceUuid, 'GET', '/accounts', [], 'account.list');

        return [
            'ok' => $response['ok'],
            'accounts' => $this->extractCollection($response['body']),
            'error' => $response['error'],
        ];
    }

    /**
     * DELETE /posts/{postUuid}
     *
     * @return array{ok:bool, error:string|null}
     */
    public function deletePost(string $workspaceUuid, string $postUuid): array
    {
        $response = $this->request($workspaceUuid, 'DELETE', '/posts/' . rawurlencode($postUuid), [], 'post.delete');

        return ['ok' => $response['ok'], 'error' => $response['error']];
    }

    /**
     * Connectivity probe for audit.php.
     *
     * @return array{ok:bool, status:int, error:string|null}
     */
    public function ping(): array
    {
        $workspace = $this->workspaceUuid();

        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'error' => 'MIXPOST_API_TOKEN is not configured'];
        }

        if ($workspace === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'MIXPOST_WORKSPACE_UUID is not configured'];
        }

        $response = $this->request($workspace, 'GET', '/accounts', [], 'ping');

        return ['ok' => $response['ok'], 'status' => $response['status'], 'error' => $response['error']];
    }

    /**
     * Build the Mixpost post payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function buildPayload(array $data): array
    {
        $accounts = array_values(array_map('intval', (array) ($data['accounts'] ?? [])));
        $content = (string) ($data['content'] ?? '');
        $timezone = (string) ($data['timezone'] ?? Config::get('mixpost.timezone', 'Asia/Kolkata'));
        $schedule = (bool) ($data['schedule'] ?? false);

        $date = (string) ($data['date'] ?? '');
        $time = (string) ($data['time'] ?? '');

        if ($schedule && ($date === '' || $time === '')) {
            $scheduledFor = (string) ($data['scheduled_for'] ?? date('Y-m-d H:i:s', strtotime('+1 hour')));
            $timestamp = strtotime($scheduledFor) ?: time() + 3600;
            $date = date('Y-m-d', $timestamp);
            $time = date('H:i', $timestamp);
        }

        $versions = $data['versions'] ?? null;

        if (!is_array($versions) || $versions === []) {
            $versions = [];

            foreach ($accounts as $index => $accountId) {
                $versions[] = [
                    'account_id' => $accountId,
                    'is_original' => $index === 0,
                    'content' => [[
                        'body' => $content,
                        'media' => [],
                        'url' => (string) ($data['url'] ?? ''),
                    ]],
                ];
            }
        }

        return array_filter([
            'date' => $date,
            'time' => $time,
            'timezone' => $timezone,
            'schedule' => $schedule,
            'accounts' => $accounts,
            'versions' => $versions,
            'tags' => array_values(array_map('intval', (array) ($data['tags'] ?? []))),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Resolve the workspace row (account_workspaces) for publishing.
     *
     * @return array<string, mixed>|null
     */
    public function workspaceFor(?string $name = null): ?array
    {
        try {
            if ($name !== null) {
                return $this->db->findWhere('account_workspaces', ['name' => $name]);
            }

            $default = $this->db->findWhere('account_workspaces', ['is_default' => 1]);

            if ($default !== null) {
                return $default;
            }

            $rows = $this->db->all('account_workspaces', [], '`id` ASC', 1);

            return $rows[0] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, int>|array<string, mixed>
     */
    public function accountsFromWorkspace(?array $workspace): array
    {
        if ($workspace === null) {
            return [];
        }

        return Json::fromColumn($workspace['platform_accounts'] ?? null, []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool, status:int, body:array<string, mixed>, error:string|null}
     */
    private function request(string $workspaceUuid, string $method, string $path, array $payload, string $purpose): array
    {
        if (!$this->configured()) {
            $error = 'credentials pending: MIXPOST_API_TOKEN is empty';
            Logger::notice('mixpost call skipped', ['purpose' => $purpose, 'reason' => $error]);

            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => $error];
        }

        if (trim($workspaceUuid) === '') {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'MIXPOST_WORKSPACE_UUID is empty'];
        }

        $url = $this->baseUrl($workspaceUuid) . $path;

        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) Config::get('mixpost.token', ''),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ];

            if ($method !== 'GET' && $payload !== []) {
                $options['json'] = $payload;
            }

            $response = $this->http()->request($method, $url, $options);

            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $body = json_decode($raw, true);
            $body = is_array($body) ? $body : [];

            if (!Http::isSuccess($status)) {
                $error = 'HTTP ' . $status . ': ' . Http::errorMessage($body !== [] ? $body : $raw);

                Logger::warning('mixpost request failed', [
                    'purpose' => $purpose,
                    'status' => $status,
                    'error' => $error,
                ]);
                $this->audit->warning('mixpost.' . $purpose . '.failed', $error, ['status' => $status, 'path' => $path]);

                return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => $error];
            }

            return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => null];
        } catch (Throwable $e) {
            Logger::error('mixpost request threw', ['purpose' => $purpose, 'error' => $e->getMessage()]);
            $this->audit->error('mixpost.' . $purpose . '.exception', $e->getMessage(), ['path' => $path]);

            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractData(array $body): array
    {
        $data = $body['data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractUuid(array $body): ?string
    {
        $data = $this->extractData($body);

        foreach (['uuid', 'id', 'post_uuid'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<int, array<string, mixed>>
     */
    private function extractCollection(array $body): array
    {
        foreach (['data', 'posts', 'accounts', 'items'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $collection = $body[$key];

                if (array_is_list($collection)) {
                    return array_values(array_filter($collection, 'is_array'));
                }
            }
        }

        return array_is_list($body) ? array_values(array_filter($body, 'is_array')) : [];
    }
}
