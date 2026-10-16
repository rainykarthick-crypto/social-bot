<?php

declare(strict_types=1);

namespace SocialBot\Agents;

use SocialBot\Ingestion\InstagramIngestor;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Logger;

/**
 * Social listening agent.
 *
 * Runs on a schedule (audit.php triggers it, or `php audit.php --listen`):
 *   1. pulls the newest comments from the connected Instagram account and
 *      enqueues them (worker.php classifies them later),
 *   2. reports the comments that actually mention a tracked keyword, across
 *      every ingested platform, so brand mentions never wait for a webhook.
 *
 * Keywords come from LISTENING_KEYWORDS (comma separated).
 */
final class SocialListeningAgent
{
    public function __construct(
        private readonly Database $db,
        private readonly InstagramIngestor $instagram,
        private readonly ?Cache $cache = null,
        private readonly ?AuditLog $audit = null,
    ) {
    }

    public static function make(): self
    {
        return new self(
            \SocialBot\Support\App::db(),
            InstagramIngestor::make(),
            \SocialBot\Support\App::cache(),
            new AuditLog(\SocialBot\Support\App::db())
        );
    }

    /**
     * @return array<int, string>
     */
    public function keywords(): array
    {
        $keywords = (array) Config::get('listening.keywords', []);

        return array_values(array_filter(array_map(static fn (mixed $k): string => strtolower(trim((string) $k)), $keywords)));
    }

    /**
     * @param array<int, string> $keywords
     * @return array{scanned:int, fetched:int, queued:int, mentions:int, matches:array<int, array<string, mixed>>, errors:array<int, string>}
     */
    public function run(array $keywords = [], int $maxPosts = 0): array
    {
        $keywords = $keywords === [] ? $this->keywords() : $keywords;
        $maxPosts = $maxPosts > 0 ? $maxPosts : (int) Config::get('listening.max_posts', 10);

        $sync = ['fetched' => 0, 'queued' => 0, 'media_scanned' => 0, 'errors' => []];

        if ($this->instagram->configured()) {
            $sync = $this->instagram->syncRecentComments($maxPosts);
        } else {
            $sync['errors'][] = 'credentials pending: INSTAGRAM_ACCESS_TOKEN is empty (listening limited to already-ingested comments)';
        }

        $mentions = $keywords === [] ? [] : $this->findMentions($keywords, (int) Config::get('listening.lookback_hours', 24), 50);

        $this->audit?->info('agent.listening.run', 'Social listening run completed', [
            'keywords' => $keywords,
            'media_scanned' => $sync['media_scanned'],
            'fetched' => $sync['fetched'],
            'queued' => $sync['queued'],
            'mentions' => count($mentions),
        ]);

        return [
            'scanned' => (int) $sync['media_scanned'],
            'fetched' => (int) $sync['fetched'],
            'queued' => (int) $sync['queued'],
            'mentions' => count($mentions),
            'matches' => $mentions,
            'errors' => (array) $sync['errors'],
        ];
    }

    /**
     * Comments captured in the lookback window that mention a tracked keyword.
     *
     * @param array<int, string> $keywords
     * @return array<int, array<string, mixed>>
     */
    public function findMentions(array $keywords, int $hours = 24, int $limit = 50): array
    {
        if ($keywords === []) {
            return [];
        }

        $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $hours) . ' hours'));

        $clauses = [];
        $bindings = [$since];

        foreach ($keywords as $keyword) {
            $clauses[] = '`text` LIKE ?';
            $bindings[] = '%' . $keyword . '%';
        }

        $sql = 'SELECT `id`, `platform`, `comment_id`, `author`, `text`, `status`, `created_at`
                FROM `' . $this->db->table('comment_queue') . '`
                WHERE `created_at` >= ? AND (' . implode(' OR ', $clauses) . ')
                ORDER BY `id` DESC
                LIMIT ' . max(1, $limit);

        try {
            $rows = $this->db->select($sql, $bindings);
        } catch (\Throwable $e) {
            Logger::warning('listening query failed', ['error' => $e->getMessage()]);

            return [];
        }

        return array_map(function (array $row) use ($keywords): array {
            $row['matched_keywords'] = $this->matches((string) $row['text'], $keywords);

            return $row;
        }, $rows);
    }

    /**
     * @param array<int, string> $keywords
     * @return array<int, string>
     */
    public function matches(string $text, array $keywords): array
    {
        $haystack = mb_strtolower($text);
        $found = [];

        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword));

            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                $found[] = $keyword;
            }
        }

        return $found;
    }

    /**
     * Mention volume per keyword for the digest.
     *
     * @return array<string, int>
     */
    public function mentionVolume(int $hours = 24): array
    {
        $volume = [];

        foreach ($this->keywords() as $keyword) {
            $row = $this->db->first(
                'SELECT COUNT(*) AS aggregate FROM `' . $this->db->table('comment_queue') . '`
                 WHERE `created_at` >= ? AND `text` LIKE ?',
                [date('Y-m-d H:i:s', strtotime('-' . max(1, $hours) . ' hours')), '%' . $keyword . '%']
            );

            $volume[$keyword] = (int) ($row['aggregate'] ?? 0);
        }

        return $volume;
    }

    /**
     * Store a discovered mention for later review (deduplicated).
     */
    public function rememberMention(string $platform, string $commentId): bool
    {
        if ($this->cache === null) {
            return true;
        }

        return $this->cache->once('mention:' . $platform . ':' . $commentId, 604800);
    }
}
