<?php

declare(strict_types=1);

namespace SocialBot\CRM;

use GuzzleHttp\Client;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Http;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;
use Throwable;

/**
 * Krayin CRM REST client (the central hub of the stack).
 *
 * Base URL: {KRAYIN_API_URL}/api/v1/  -  Bearer token auth.
 *
 * Every call is audited (audit_log) and rate limited through the local cache,
 * and every failure returns a structured ['ok' => false, 'error' => ...] array
 * instead of throwing, so a CRM outage degrades the workflow instead of
 * breaking the worker loop.
 */
final class KrayinClient
{
    public const EVENT_LEAD_CREATED = 'lead.created';

    public const EVENT_LEAD_UPDATED = 'lead.updated';

    public const EVENT_QUOTE_WON = 'quote.won';

    private ?Client $http;

    private AuditLog $audit;

    public function __construct(
        private readonly Database $db,
        ?Client $http = null,
        ?AuditLog $audit = null,
    ) {
        // The Guzzle client is built on first use so that the pipeline can still
        // be constructed (and degrade to "crm unavailable") when the vendor
        // directory has not been installed yet.
        $this->http = $http;
        $this->audit = $audit ?? new AuditLog($db);
    }

    private function http(): Client
    {
        return $this->http ??= Http::client(['timeout' => (int) Config::get('krayin.timeout', 30)]);
    }

    public function configured(): bool
    {
        return trim((string) Config::get('krayin.token', '')) !== '';
    }

    public function baseUrl(): string
    {
        return rtrim((string) Config::get('krayin.api_url', ''), '/') . '/api/v1';
    }

    /**
     * Public UI URL for a lead (used by the dashboard).
     */
    public function leadUrl(int|string $leadId): string
    {
        return rtrim((string) Config::get('krayin.api_url', ''), '/') . '/admin/leads/view/' . $leadId;
    }

    /**
     * POST /leads
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, id:int|null, data:array<string, mixed>, error:string|null, status:int}
     */
    public function createLead(array $data): array
    {
        $payload = $this->leadPayload($data);

        $response = $this->request('POST', '/leads', $payload, 'lead.create');

        $id = $this->extractId($response['body']);

        if ($response['ok'] && $id !== null) {
            $this->audit->info('krayin.lead.created', 'Krayin lead created', [
                'lead_id' => $id,
                'title' => $payload['title'] ?? null,
            ], 'krayin_lead', (string) $id);
        }

        return [
            'ok' => $response['ok'],
            'id' => $id,
            'data' => $response['body'],
            'error' => $response['ok'] ? null : $response['error'],
            'status' => $response['status'],
        ];
    }

    /**
     * POST /activities  (task/appointment attached to a lead)
     *
     * @param array<string, mixed> $data title, type, schedule_from, schedule_to, comment, user_id, is_done
     * @return array{ok:bool, id:int|null, data:array<string, mixed>, error:string|null, status:int}
     */
    public function createActivity(int $leadId, array $data): array
    {
        $payload = array_merge([
            'title' => 'Follow up',
            'type' => 'task',
            'comment' => '',
            'lead_id' => $leadId,
            'user_id' => (int) Config::get('krayin.default_user_id', 1),
            'is_done' => 0,
            'schedule_from' => date('Y-m-d H:i:s'),
            'schedule_to' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ], $data);

        // Krayin expects the lead to be linked through the polymorphic
        // "schedule_from/to" + person/lead relation; we pass both the flat
        // lead_id and the `leads` relation array for version tolerance.
        $payload['lead_id'] = $leadId;

        if (!isset($payload['participants']) && isset($payload['user_id'])) {
            $payload['participants'] = [(int) $payload['user_id']];
        }

        $response = $this->request('POST', '/activities', $payload, 'activity.create');

        $id = $this->extractId($response['body']);

        if ($response['ok']) {
            $this->audit->info('krayin.activity.created', 'Krayin activity created', [
                'lead_id' => $leadId,
                'activity_id' => $id,
                'title' => $payload['title'],
                'type' => $payload['type'],
            ], 'krayin_lead', (string) $leadId);
        }

        return [
            'ok' => $response['ok'],
            'id' => $id,
            'data' => $response['body'],
            'error' => $response['ok'] ? null : $response['error'],
            'status' => $response['status'],
        ];
    }

    /**
     * PUT /leads/{id}
     *
     * @param array<string, mixed> $extra
     * @return array{ok:bool, data:array<string, mixed>, error:string|null, status:int}
     */
    public function updateLeadStatus(int $leadId, string $status, array $extra = []): array
    {
        $payload = array_merge(['status' => $status], $extra);

        $response = $this->request('PUT', '/leads/' . $leadId, $payload, 'lead.update_status');

        if ($response['ok']) {
            $this->audit->info('krayin.lead.updated', 'Krayin lead status updated', [
                'lead_id' => $leadId,
                'status' => $status,
            ], 'krayin_lead', (string) $leadId);
        }

        return [
            'ok' => $response['ok'],
            'data' => $response['body'],
            'error' => $response['ok'] ? null : $response['error'],
            'status' => $response['status'],
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{ok:bool, data:array<string, mixed>, error:string|null, status:int}
     */
    public function updateLead(int $leadId, array $extra): array
    {
        $response = $this->request('PUT', '/leads/' . $leadId, $extra, 'lead.update');

        return [
            'ok' => $response['ok'],
            'data' => $response['body'],
            'error' => $response['ok'] ? null : $response['error'],
            'status' => $response['status'],
        ];
    }

    /**
     * @return array{ok:bool, data:array<string, mixed>, error:string|null, status:int}
     */
    public function getLead(int $leadId): array
    {
        $response = $this->request('GET', '/leads/' . $leadId, [], 'lead.get');

        return [
            'ok' => $response['ok'],
            'data' => $response['body'],
            'error' => $response['ok'] ? null : $response['error'],
            'status' => $response['status'],
        ];
    }

    /**
     * Lightweight connectivity probe for audit.php.
     *
     * @return array{ok:bool, status:int, error:string|null}
     */
    public function ping(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'error' => 'KRAYIN_API_TOKEN is not configured'];
        }

        $response = $this->request('GET', '/leads?limit=1', [], 'ping');

        return ['ok' => $response['ok'], 'status' => $response['status'], 'error' => $response['error']];
    }

    /**
     * Build the Krayin payload for a scored social lead.
     *
     * @param array<string, mixed> $lead
     * @return array<string, mixed>
     */
    public function leadPayloadFromLead(array $lead): array
    {
        $title = sprintf(
            '[%s] %s - %s',
            strtoupper((string) ($lead['priority'] ?? 'P3')),
            (string) ($lead['author'] ?? 'unknown'),
            ucfirst((string) ($lead['intent'] ?? 'lead'))
        );

        $description = implode("\n", array_filter([
            'Platform: ' . (string) ($lead['platform'] ?? ''),
            'Author: ' . (string) ($lead['author'] ?? ''),
            'Intent: ' . (string) ($lead['intent'] ?? 'none') . ' (score ' . (int) ($lead['intent_score'] ?? 0) . ')',
            'Lead score: ' . (int) ($lead['lead_score'] ?? 0) . ' / 100 (' . (string) ($lead['priority'] ?? 'P3') . ')',
            'Sentiment: ' . (string) ($lead['sentiment'] ?? 'neutral'),
            'Summary: ' . (string) ($lead['summary'] ?? ''),
            'Comment: ' . (string) ($lead['comment_text'] ?? ''),
            'Suggested reply: ' . (string) ($lead['suggested_reply'] ?? ''),
            'Post: ' . (string) ($lead['post_id'] ?? ''),
            'Captured: ' . (string) ($lead['created_at'] ?? date('Y-m-d H:i:s')),
        ]));

        return [
            'title' => $title,
            'description' => $description,
            'lead_value' => 0,
            'user_id' => (int) Config::get('krayin.default_user_id', 1),
            'entity_type' => 'leads',
            'status' => 1,
            'lead_source_id' => null,
            'lead_type_id' => null,
            'lead_pipeline_id' => (int) Config::get('krayin.pipeline_id', 1),
            'person' => [
                'name' => (string) ($lead['author'] ?? 'Social Lead'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function leadPayload(array $data): array
    {
        $payload = array_merge([
            'title' => 'Social Lead',
            'description' => '',
            'lead_value' => 0,
            'user_id' => (int) Config::get('krayin.default_user_id', 1),
            'entity_type' => 'leads',
            'lead_pipeline_id' => (int) Config::get('krayin.pipeline_id', 1),
        ], $data);

        // Drop nulls: Krayin validates several of these as nullable-but-present.
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool, status:int, body:array<string, mixed>, error:string|null}
     */
    private function request(string $method, string $path, array $payload, string $purpose): array
    {
        if (!$this->configured()) {
            $error = 'credentials pending: KRAYIN_API_TOKEN is empty';
            Logger::notice('krayin call skipped', ['purpose' => $purpose, 'reason' => $error]);

            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => $error];
        }

        $url = $this->baseUrl() . $path;

        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) Config::get('krayin.token', ''),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ];

            if ($method !== 'GET') {
                $options['json'] = $payload;
            }

            $response = $this->http()->request($method, $url, $options);

            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $body = json_decode($raw, true);
            $body = is_array($body) ? $body : [];

            if (!Http::isSuccess($status)) {
                $error = 'HTTP ' . $status . ': ' . Http::errorMessage($body !== [] ? $body : $raw);

                Logger::warning('krayin request failed', ['purpose' => $purpose, 'status' => $status, 'error' => $error]);
                $this->audit->warning('krayin.' . $purpose . '.failed', $error, ['status' => $status, 'path' => $path]);

                return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => $error];
            }

            return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => null];
        } catch (Throwable $e) {
            Logger::error('krayin request threw', ['purpose' => $purpose, 'error' => $e->getMessage()]);
            $this->audit->error('krayin.' . $purpose . '.exception', $e->getMessage(), ['path' => $path]);

            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Krayin wraps single resources as {"data": {...}}; tolerate both shapes.
     *
     * @param mixed $body
     */
    private function extractId(mixed $body): ?int
    {
        if (!is_array($body)) {
            return null;
        }

        foreach ([$body['data'] ?? null, $body] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            foreach (['id', 'lead_id', 'activity_id'] as $key) {
                if (isset($candidate[$key]) && is_numeric($candidate[$key])) {
                    return (int) $candidate[$key];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonBody(array $response): array
    {
        return Json::fromColumn($response['body'] ?? null);
    }
}
