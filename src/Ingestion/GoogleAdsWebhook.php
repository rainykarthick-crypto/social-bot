<?php

declare(strict_types=1);

namespace SocialBot\Ingestion;

use SocialBot\Support\AuditLog;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;

/**
 * Google Ads lead-form webhook.
 *
 * Validates ?google_key= against GOOGLE_ADS_WEBHOOK_KEY, parses
 * user_column_data[] (column_name -> name/phone/email/company/city), stores the
 * raw payload in google_ads_leads AND enqueues a synthetic comment in
 * comment_queue (platform='google_ads', text="Name: X | Phone: Y | Email: Z").
 *
 * The queue row's comment_id is 'gads-<google_ads_leads.id>', which is how the
 * worker links the created lead back to the raw row.
 */
final class GoogleAdsWebhook
{
    /** Google's column_name values vary by form language/version. */
    private const COLUMN_MAP = [
        'full_name' => 'name',
        'name' => 'name',
        'first_name' => 'name',
        'last_name' => 'name',
        'phone_number' => 'phone',
        'phone' => 'phone',
        'work_phone' => 'phone',
        'email' => 'email',
        'email_address' => 'email',
        'work_email' => 'email',
        'company_name' => 'company',
        'company' => 'company',
        'city' => 'city',
        'location' => 'city',
        'country' => 'city',
        'postal_code' => 'city',
    ];

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

    public function validateKey(?string $key): bool
    {
        $expected = trim((string) Config::get('google_ads.webhook_key', ''));

        if ($expected === '') {
            Logger::warning('google ads webhook key is not configured - rejecting delivery');

            return false;
        }

        return $key !== null && hash_equals($expected, trim($key));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $query
     * @return array{ok:bool, status:int, message:string, google_ads_lead_id:int|null, comment_id:string|null, fields:array<string, string>}
     */
    public function handle(array $payload, array $query = []): array
    {
        $key = $query['google_key'] ?? $payload['google_key'] ?? null;

        if (!$this->validateKey(is_string($key) ? $key : null)) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'Invalid or missing google_key.',
                'google_ads_lead_id' => null,
                'comment_id' => null,
                'fields' => [],
            ];
        }

        $fields = $this->extractFields($payload);
        $campaignId = (string) ($payload['campaign_id'] ?? $payload['campaignId'] ?? '');
        $campaignName = (string) ($payload['campaign_name'] ?? $payload['campaignName'] ?? '');
        $gclid = (string) ($payload['gclid'] ?? $payload['google_key'] ?? '');

        if ($gclid === '' && isset($payload['user_column_data'])) {
            foreach ((array) $payload['user_column_data'] as $column) {
                if (is_array($column) && strtolower((string) ($column['column_name'] ?? '')) === 'gclid') {
                    $gclid = (string) ($column['string_value'] ?? '');
                }
            }
        }

        $rawId = $this->db->insert('google_ads_leads', [
            'campaign_id' => $campaignId !== '' ? mb_substr($campaignId, 0, 64) : null,
            'campaign_name' => $campaignName !== '' ? mb_substr($campaignName, 0, 191) : null,
            'name' => $fields['name'] !== '' ? $fields['name'] : null,
            'phone' => $fields['phone'] !== '' ? $fields['phone'] : null,
            'email' => $fields['email'] !== '' ? $fields['email'] : null,
            'company' => $fields['company'] !== '' ? $fields['company'] : null,
            'city' => $fields['city'] !== '' ? $fields['city'] : null,
            'gclid' => $gclid !== '' ? mb_substr($gclid, 0, 191) : null,
            'raw_payload' => Json::encode($payload),
            'processed' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $commentId = 'gads-' . $rawId;

        $text = $this->composeText($fields);

        $queued = $this->db->insertIgnore('comment_queue', [
            'platform' => 'google_ads',
            'post_id' => $campaignId !== '' ? 'campaign:' . $campaignId : null,
            'comment_id' => $commentId,
            'author' => $fields['name'] !== '' ? $fields['name'] : 'Google Ads lead',
            'author_profile_url' => null,
            'text' => $text,
            'post_context' => trim('Google Ads lead form' . ($campaignName !== '' ? ' - ' . $campaignName : '')),
            'likes' => 0,
            'author_followers' => 0,
            'author_verified' => 0,
            'status' => 'pending',
            'received_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit?->info('ingestion.google_ads.received', 'Google Ads lead form received', [
            'google_ads_lead_id' => $rawId,
            'campaign_id' => $campaignId,
            'has_phone' => $fields['phone'] !== '',
            'has_email' => $fields['email'] !== '',
            'queued' => $queued !== null,
        ], 'google_ads_lead', (string) $rawId);

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Lead form accepted.',
            'google_ads_lead_id' => $rawId,
            'comment_id' => $commentId,
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name:string, phone:string, email:string, company:string, city:string}
     */
    public function extractFields(array $payload): array
    {
        $fields = ['name' => '', 'phone' => '', 'email' => '', 'company' => '', 'city' => ''];

        $columns = $payload['user_column_data'] ?? [];

        if (is_array($columns)) {
            foreach ($columns as $column) {
                if (!is_array($column)) {
                    continue;
                }

                $columnName = strtolower(trim((string) ($column['column_name'] ?? '')));
                $value = trim((string) ($column['string_value'] ?? $column['value'] ?? ''));

                if ($value === '') {
                    continue;
                }

                $target = self::COLUMN_MAP[$columnName] ?? null;

                if ($target === null) {
                    continue;
                }

                if ($target === 'name' && $fields['name'] !== '') {
                    $fields['name'] .= ' ' . $value;

                    continue;
                }

                $fields[$target] = $fields[$target] === '' ? $value : $fields[$target] . ' ' . $value;
            }
        }

        // Some integrations post flat keys instead of user_column_data.
        foreach (['name' => 'name', 'full_name' => 'name', 'phone' => 'phone', 'phone_number' => 'phone', 'email' => 'email', 'company' => 'company', 'city' => 'city'] as $key => $target) {
            if ($fields[$target] === '' && isset($payload[$key]) && is_scalar($payload[$key])) {
                $fields[$target] = trim((string) $payload[$key]);
            }
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     */
    public function composeText(array $fields): string
    {
        $parts = [];

        foreach (['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'company' => 'Company', 'city' => 'City'] as $key => $label) {
            if ($fields[$key] !== '') {
                $parts[] = $label . ': ' . $fields[$key];
            }
        }

        if ($parts === []) {
            return 'Google Ads lead form submission';
        }

        return implode(' | ', $parts);
    }

    /**
     * Unprocessed raw rows (audit.php reports on them).
     *
     * @return array<int, array<string, mixed>>
     */
    public function unprocessed(int $limit = 20): array
    {
        return $this->db->all('google_ads_leads', ['processed' => 0], '`id` ASC', $limit);
    }
}
