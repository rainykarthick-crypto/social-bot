<?php

declare(strict_types=1);

namespace SocialBot\Ingestion;

use SocialBot\Support\AuditLog;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Logger;

/**
 * WhatsApp Cloud API webhook.
 *
 * GET  /webhook/whatsapp -> hub_mode=subscribe verification
 * POST /webhook/whatsapp -> parses entry[0].changes[0].value.messages[] and
 *                           enqueues inbound messages (platform='whatsapp')
 *
 * Text, button and interactive replies are all normalised to plain text so the
 * classifier sees one shape.
 */
final class WhatsAppWebhook
{
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

    /**
     * @param array<string, mixed> $query
     * @return array{ok:bool, challenge:string|null, status:int, reason:string}
     */
    public function verify(array $query): array
    {
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        $expected = trim((string) Config::get('whatsapp.verify_token', ''));

        if ($expected === '') {
            // Fall back to the Meta verify token so a single token can serve both.
            $expected = trim((string) Config::get('meta.verify_token', ''));
        }

        if ($mode !== 'subscribe') {
            return ['ok' => false, 'challenge' => null, 'status' => 400, 'reason' => 'unsupported hub.mode'];
        }

        if ($expected === '') {
            return ['ok' => false, 'challenge' => null, 'status' => 503, 'reason' => 'WHATSAPP_VERIFY_TOKEN is not configured'];
        }

        if (!hash_equals($expected, $token)) {
            return ['ok' => false, 'challenge' => null, 'status' => 403, 'reason' => 'verify token mismatch'];
        }

        return ['ok' => true, 'challenge' => $challenge, 'status' => 200, 'reason' => 'verified'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool, queued:int, duplicates:int, parsed:int, statuses:int}
     */
    public function handle(array $payload): array
    {
        $messages = $this->parse($payload);

        $queued = 0;
        $duplicates = 0;

        foreach ($messages as $message) {
            $dedupeKey = 'whatsapp:' . $message['comment_id'];

            if ($this->cache !== null && !$this->cache->once($dedupeKey, 86400)) {
                $duplicates++;

                continue;
            }

            $id = $this->db->insertIgnore('comment_queue', [
                'platform' => 'whatsapp',
                'post_id' => $message['post_id'],
                'comment_id' => $message['comment_id'],
                'author' => $message['author'],
                'author_profile_url' => null,
                'text' => $message['text'],
                'post_context' => $message['post_context'],
                'likes' => 0,
                'author_followers' => 0,
                'author_verified' => 0,
                'status' => 'pending',
                'received_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($id === null) {
                $duplicates++;
            } else {
                $queued++;
            }
        }

        $statuses = $this->countStatuses($payload);

        if ($queued > 0 || $statuses > 0) {
            $this->audit?->info('ingestion.whatsapp.received', 'WhatsApp webhook processed', [
                'queued' => $queued,
                'duplicates' => $duplicates,
                'statuses' => $statuses,
            ]);
        }

        return [
            'ok' => true,
            'queued' => $queued,
            'duplicates' => $duplicates,
            'parsed' => count($messages),
            'statuses' => $statuses,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function parse(array $payload): array
    {
        $rows = [];
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

        foreach ($entries as $entry) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];

            foreach ($changes as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                if (!isset($value['messages']) || !is_array($value['messages'])) {
                    continue;
                }

                $contacts = [];
                foreach ((array) ($value['contacts'] ?? []) as $contact) {
                    if (is_array($contact) && isset($contact['wa_id'])) {
                        $contacts[(string) $contact['wa_id']] = (string) ($contact['profile']['name'] ?? '');
                    }
                }

                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');

                foreach ($value['messages'] as $message) {
                    if (!is_array($message)) {
                        continue;
                    }

                    $text = $this->extractText($message);

                    if ($text === '') {
                        continue;
                    }

                    $from = (string) ($message['from'] ?? '');

                    $rows[] = [
                        'comment_id' => (string) ($message['id'] ?? ('wa-' . sha1($from . $text . (string) ($message['timestamp'] ?? '')))),
                        'post_id' => $phoneNumberId !== '' ? 'whatsapp:' . $phoneNumberId : null,
                        'author' => $contacts[$from] !== '' && isset($contacts[$from]) ? $contacts[$from] : ('+' . $from),
                        'text' => $text,
                        'post_context' => 'whatsapp ' . (string) ($message['type'] ?? 'text') . ' message',
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function extractText(array $message): string
    {
        $type = (string) ($message['type'] ?? '');

        if ($type === 'text' && isset($message['text']['body'])) {
            return trim((string) $message['text']['body']);
        }

        if ($type === 'button' && isset($message['button']['text'])) {
            return trim((string) $message['button']['text']);
        }

        if ($type === 'interactive') {
            $interactive = (array) ($message['interactive'] ?? []);

            foreach (['button_reply', 'list_reply'] as $key) {
                if (isset($interactive[$key]['title'])) {
                    return trim((string) $interactive[$key]['title']);
                }
            }
        }

        if ($type === 'image' && isset($message['image']['caption'])) {
            return trim((string) $message['image']['caption']);
        }

        if ($type === 'contacts' || $type === 'location' || $type === 'reaction') {
            return '';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function countStatuses(array $payload): int
    {
        $count = 0;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? null;

                if (is_array($value) && isset($value['statuses']) && is_array($value['statuses'])) {
                    $count += count($value['statuses']);
                }
            }
        }

        if ($count > 0) {
            Logger::debug('whatsapp delivery statuses received', ['count' => $count]);
        }

        return $count;
    }
}
