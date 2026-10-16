<?php

declare(strict_types=1);

namespace SocialBot\Ingestion;

use SocialBot\Support\AuditLog;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Logger;

/**
 * Meta (Facebook Page + Instagram) webhook.
 *
 * GET  /webhook/meta  -> hub_mode=subscribe verification, echoes hub_challenge
 * POST /webhook/meta  -> parses entry[].changes[] and enqueues comments
 *
 * The POST handler only INSERTs into comment_queue (status=pending) and returns
 * 200 immediately: all classification happens later in worker.php.
 */
final class MetaWebhook
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
     * GET verification handshake.
     *
     * @param array<string, mixed> $query
     * @return array{ok:bool, challenge:string|null, status:int, reason:string}
     */
    public function verify(array $query): array
    {
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        $expected = (string) Config::get('meta.verify_token', '');

        if ($mode !== 'subscribe') {
            return ['ok' => false, 'challenge' => null, 'status' => 400, 'reason' => 'unsupported hub.mode'];
        }

        if ($expected === '') {
            return ['ok' => false, 'challenge' => null, 'status' => 503, 'reason' => 'META_VERIFY_TOKEN is not configured'];
        }

        if (!hash_equals($expected, $token)) {
            Logger::warning('meta webhook verification rejected', ['mode' => $mode]);

            return ['ok' => false, 'challenge' => null, 'status' => 403, 'reason' => 'verify token mismatch'];
        }

        return ['ok' => true, 'challenge' => $challenge, 'status' => 200, 'reason' => 'verified'];
    }

    /**
     * Verify the X-Hub-Signature-256 header against META_APP_SECRET.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = trim((string) Config::get('meta.app_secret', ''));

        if ($secret === '' || $signature === null || trim($signature) === '') {
            // No secret configured: accept (a warning is logged by the caller).
            return $secret === '';
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, trim($signature));
    }

    /**
     * @param array<string, mixed> $payload decoded JSON body
     * @return array{ok:bool, queued:int, duplicates:int, skipped:int, comments:array<int, array<string, mixed>>}
     */
    public function handle(array $payload): array
    {
        $comments = $this->parse($payload);

        $queued = 0;
        $duplicates = 0;
        $skipped = 0;

        foreach ($comments as $comment) {
            $dedupeKey = 'meta:' . $comment['platform'] . ':' . $comment['comment_id'];

            if ($this->cache !== null && !$this->cache->once($dedupeKey, 86400)) {
                $duplicates++;

                continue;
            }

            $id = $this->db->insertIgnore('comment_queue', [
                'platform' => $comment['platform'],
                'post_id' => $comment['post_id'],
                'comment_id' => $comment['comment_id'],
                'author' => $comment['author'],
                'author_profile_url' => $comment['author_profile_url'],
                'text' => $comment['text'],
                'post_context' => $comment['post_context'],
                'likes' => $comment['likes'],
                'author_followers' => 0,
                'author_verified' => 0,
                'status' => 'pending',
                'received_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($id === null) {
                $duplicates++;

                continue;
            }

            $queued++;
        }

        $this->audit?->info('ingestion.meta.received', 'Meta webhook processed', [
            'queued' => $queued,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
        ]);

        return ['ok' => true, 'queued' => $queued, 'duplicates' => $duplicates, 'skipped' => $skipped, 'comments' => $comments];
    }

    /**
     * Normalise Meta's entry[].changes[] structure into queue rows.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function parse(array $payload): array
    {
        $comments = [];
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

        foreach ($entries as $entry) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];

            foreach ($changes as $change) {
                $field = (string) ($change['field'] ?? '');
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                if ($field === 'comments') {
                    $comments[] = $this->fromInstagramComment($value, $entry);

                    continue;
                }

                if ($field === 'feed' && (($value['item'] ?? '') === 'comment')) {
                    $comments[] = $this->fromFacebookComment($value, $entry);

                    continue;
                }

                if ($field === 'messages') {
                    // Instagram DMs land in the same queue so they can be scored.
                    $comments[] = $this->fromInstagramMessage($value, $entry);
                }
            }

            // Some payloads deliver comments directly under entry[].messaging.
            foreach ((array) ($entry['messaging'] ?? []) as $message) {
                if (is_array($message) && isset($message['message']['text'])) {
                    $comments[] = $this->fromInstagramMessage($message, $entry);
                }
            }
        }

        return array_values(array_filter($comments, static fn (array $row): bool => $row['comment_id'] !== '' && $row['text'] !== ''));
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function fromInstagramComment(array $value, array $entry): array
    {
        $from = is_array($value['from'] ?? null) ? $value['from'] : [];
        $media = is_array($value['media'] ?? null) ? $value['media'] : [];

        return [
            'platform' => 'instagram',
            'post_id' => (string) ($media['id'] ?? $value['media_id'] ?? $entry['id'] ?? ''),
            'comment_id' => (string) ($value['id'] ?? ''),
            'author' => (string) ($from['username'] ?? $from['name'] ?? 'instagram_user'),
            'author_profile_url' => isset($from['id']) ? 'https://instagram.com/' . (string) ($from['username'] ?? $from['id']) : null,
            'text' => (string) ($value['text'] ?? ''),
            'post_context' => (string) ($media['caption'] ?? $media['media_product_type'] ?? 'instagram comment'),
            'likes' => (int) ($value['like_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function fromFacebookComment(array $value, array $entry): array
    {
        $from = is_array($value['from'] ?? null) ? $value['from'] : [];

        return [
            'platform' => 'facebook',
            'post_id' => (string) ($value['post_id'] ?? $entry['id'] ?? ''),
            'comment_id' => (string) ($value['comment_id'] ?? $value['id'] ?? ''),
            'author' => (string) ($from['name'] ?? 'facebook_user'),
            'author_profile_url' => isset($from['id']) ? 'https://facebook.com/' . (string) $from['id'] : null,
            'text' => (string) ($value['message'] ?? $value['text'] ?? ''),
            'post_context' => (string) ($value['parent_id'] ?? 'facebook comment'),
            'likes' => (int) ($value['like_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function fromInstagramMessage(array $value, array $entry): array
    {
        $sender = is_array($value['sender'] ?? null) ? $value['sender'] : [];
        $message = is_array($value['message'] ?? null) ? $value['message'] : [];

        return [
            'platform' => 'instagram',
            'post_id' => null,
            'comment_id' => 'ig-dm:' . (string) ($message['mid'] ?? $value['timestamp'] ?? uniqid('', true)),
            'author' => (string) ($sender['id'] ?? 'instagram_user'),
            'author_profile_url' => isset($sender['id']) ? 'https://instagram.com/' . (string) $sender['id'] : null,
            'text' => (string) ($message['text'] ?? ''),
            'post_context' => 'instagram direct message',
            'likes' => 0,
        ];
    }
}
