<?php

declare(strict_types=1);

namespace SocialBot\CRM;

use SocialBot\Support\AuditLog;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;

/**
 * Inbound Krayin webhooks.
 *
 *   lead.created  -> imported into `leads` (deduplicated on comment_id)
 *   lead.updated  -> local status sync; status "qualified" auto-assigns the
 *                    lead to a sales rep by creating a Krayin activity
 *   quote.won     -> local lead marked as booked
 *
 * Signature: when KRAYIN_WEBHOOK_SECRET is set, the raw body must carry a
 * matching `X-Krayin-Signature` HMAC-SHA256 header.
 */
final class KrayinWebhookReceiver
{
    public const SUPPORTED_EVENTS = [KrayinClient::EVENT_LEAD_CREATED, KrayinClient::EVENT_LEAD_UPDATED, KrayinClient::EVENT_QUOTE_WON];

    private AuditLog $audit;

    public function __construct(
        private readonly Database $db,
        private readonly KrayinClient $krayin,
        ?AuditLog $audit = null,
        private readonly ?Cache $cache = null,
    ) {
        $this->audit = $audit ?? new AuditLog($db);
    }

    public static function make(): self
    {
        return new self(
            \SocialBot\Support\App::db(),
            \SocialBot\Support\App::krayin(),
            new AuditLog(\SocialBot\Support\App::db()),
            \SocialBot\Support\App::cache()
        );
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = trim((string) Config::get('krayin.webhook_secret', ''));

        if ($secret === '') {
            return true; // verification disabled until a secret is configured
        }

        if ($signature === null || trim($signature) === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, trim($signature));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers lower-cased header names
     * @return array{ok:bool, event:string, handled:bool, message:string, data:array<string, mixed>}
     */
    public function handle(array $payload, array $headers = []): array
    {
        $event = (string) ($payload['event'] ?? $payload['type'] ?? $payload['event_name'] ?? 'unknown');

        if (!in_array($event, self::SUPPORTED_EVENTS, true)) {
            $this->audit->warning('krayin.webhook.unsupported', 'Unsupported Krayin event', ['event' => $event]);

            return [
                'ok' => true,
                'event' => $event,
                'handled' => false,
                'message' => 'Event ignored (not in the supported set).',
                'data' => [],
            ];
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $leadId = $this->extractLeadId($data);

        // Idempotency: Krayin retries failed deliveries, and duplicate
        // auto-assignment tasks are worse than a dropped duplicate.
        $dedupeKey = 'krayin:webhook:' . sha1($event . ':' . Json::encode($data));

        if ($this->cache !== null && !$this->cache->once($dedupeKey, 3600)) {
            return [
                'ok' => true,
                'event' => $event,
                'handled' => false,
                'message' => 'Duplicate delivery ignored.',
                'data' => ['lead_id' => $leadId],
            ];
        }

        return match ($event) {
            KrayinClient::EVENT_LEAD_CREATED => $this->onLeadCreated($data, $leadId),
            KrayinClient::EVENT_LEAD_UPDATED => $this->onLeadUpdated($data, $leadId),
            KrayinClient::EVENT_QUOTE_WON => $this->onQuoteWon($data, $leadId),
            default => [
                'ok' => true,
                'event' => $event,
                'handled' => false,
                'message' => 'Event ignored.',
                'data' => [],
            ],
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool, event:string, handled:bool, message:string, data:array<string, mixed>}
     */
    private function onLeadCreated(array $data, ?int $leadId): array
    {
        if ($leadId === null) {
            return [
                'ok' => false,
                'event' => KrayinClient::EVENT_LEAD_CREATED,
                'handled' => false,
                'message' => 'Webhook payload has no lead id.',
                'data' => [],
            ];
        }

        $existing = $this->db->findWhere('leads', ['krayin_lead_id' => $leadId]);

        if ($existing !== null) {
            return [
                'ok' => true,
                'event' => KrayinClient::EVENT_LEAD_CREATED,
                'handled' => false,
                'message' => 'Lead already imported.',
                'data' => ['lead_id' => (int) $existing['id']],
            ];
        }

        $commentId = 'krayin:' . $leadId;

        $inserted = $this->db->insertIgnore('leads', [
            'platform' => 'krayin',
            'comment_id' => $commentId,
            'post_id' => null,
            'author' => (string) ($data['title'] ?? $data['name'] ?? 'Krayin lead'),
            'comment_text' => (string) ($data['description'] ?? ''),
            'intent' => 'none',
            'intent_score' => 0,
            'sentiment' => 'neutral',
            'lead_score' => 0,
            'priority' => 'P3',
            'is_qualified' => 0,
            'status' => 'new',
            'summary' => mb_substr((string) ($data['title'] ?? 'Imported from Krayin'), 0, 500),
            'routing_queue' => 'general_followup',
            'krayin_lead_id' => $leadId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->info('krayin.webhook.lead_imported', 'Krayin lead imported', [
            'krayin_lead_id' => $leadId,
            'lead_id' => $inserted,
        ], 'krayin_lead', (string) $leadId);

        return [
            'ok' => true,
            'event' => KrayinClient::EVENT_LEAD_CREATED,
            'handled' => true,
            'message' => 'Lead imported.',
            'data' => ['krayin_lead_id' => $leadId, 'lead_id' => $inserted],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool, event:string, handled:bool, message:string, data:array<string, mixed>}
     */
    private function onLeadUpdated(array $data, ?int $leadId): array
    {
        $status = strtolower((string) ($data['status'] ?? $data['lead_status'] ?? ''));

        if ($leadId === null) {
            return [
                'ok' => false,
                'event' => KrayinClient::EVENT_LEAD_UPDATED,
                'handled' => false,
                'message' => 'Webhook payload has no lead id.',
                'data' => [],
            ];
        }

        $local = $this->db->findWhere('leads', ['krayin_lead_id' => $leadId]);
        $mappedStatus = self::mapStatus($status);

        if ($local !== null && $mappedStatus !== null) {
            $this->db->update('leads', [
                'status' => $mappedStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $local['id']]);
        }

        $assigned = false;

        if (in_array($status, ['qualified', 'won', 'converted'], true)) {
            $activity = $this->krayin->createActivity($leadId, [
                'title' => 'Sales follow-up: ' . (string) ($local['author'] ?? ($data['title'] ?? 'Krayin lead')),
                'type' => 'task',
                'comment' => 'Auto-assigned because the Krayin lead was marked "' . $status . '".',
                'user_id' => (int) Config::get('krayin.default_user_id', 1),
                'schedule_from' => date('Y-m-d H:i:s'),
                'schedule_to' => date('Y-m-d H:i:s', strtotime('+4 hours')),
            ]);

            $assigned = (bool) ($activity['ok'] ?? false);

            if ($local !== null && $assigned && $activity['id'] !== null) {
                $this->db->update('leads', [
                    'status' => $status === 'qualified' ? 'follow_up' : 'booked',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => (int) $local['id']]);
            }
        }

        $this->audit->info('krayin.webhook.lead_updated', 'Krayin lead update received', [
            'krayin_lead_id' => $leadId,
            'krayin_status' => $status,
            'local_status' => $mappedStatus,
            'assigned' => $assigned,
        ], 'krayin_lead', (string) $leadId);

        return [
            'ok' => true,
            'event' => KrayinClient::EVENT_LEAD_UPDATED,
            'handled' => true,
            'message' => $assigned
                ? 'Status synced and the lead was auto-assigned to a sales rep.'
                : 'Status synced.',
            'data' => ['krayin_lead_id' => $leadId, 'status' => $status, 'assigned' => $assigned],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool, event:string, handled:bool, message:string, data:array<string, mixed>}
     */
    private function onQuoteWon(array $data, ?int $leadId): array
    {
        $local = $leadId === null ? null : $this->db->findWhere('leads', ['krayin_lead_id' => $leadId]);

        if ($local !== null) {
            $this->db->update('leads', [
                'status' => 'booked',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $local['id']]);
        }

        $this->audit->info('krayin.webhook.quote_won', 'Quote won', [
            'krayin_lead_id' => $leadId,
            'local_lead_id' => $local['id'] ?? null,
            'amount' => $data['amount'] ?? $data['total'] ?? null,
        ], 'krayin_lead', $leadId === null ? null : (string) $leadId);

        return [
            'ok' => true,
            'event' => KrayinClient::EVENT_QUOTE_WON,
            'handled' => $local !== null,
            'message' => $local === null ? 'Quote recorded; no matching local lead.' : 'Lead marked as booked.',
            'data' => ['krayin_lead_id' => $leadId, 'lead_id' => $local['id'] ?? null],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractLeadId(array $data): ?int
    {
        foreach (['lead_id', 'id', 'entity_id'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        if (isset($data['lead']) && is_array($data['lead']) && isset($data['lead']['id']) && is_numeric($data['lead']['id'])) {
            return (int) $data['lead']['id'];
        }

        return null;
    }

    public static function mapStatus(string $krayinStatus): ?string
    {
        return match (strtolower($krayinStatus)) {
            'new', 'open' => 'new',
            'contacted', 'in progress', 'in_progress' => 'contacted',
            'qualified', 'follow up', 'follow_up' => 'follow_up',
            'won', 'converted', 'booked' => 'booked',
            'lost', 'junk', 'not interested', 'not_interested' => 'not_interested',
            'completed', 'closed', 'done' => 'completed',
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentImports(int $limit = 20): array
    {
        return $this->db->all('leads', ['platform' => 'krayin'], '`id` DESC', $limit);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function logUnverified(array $payload): void
    {
        Logger::warning('krayin webhook signature rejected', ['event' => $payload['event'] ?? 'unknown']);
    }
}
