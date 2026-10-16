<?php

declare(strict_types=1);

namespace SocialBot\Agents;

use SocialBot\Support\AuditLog;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;
use SocialBot\Workflow\PostApprovalWorkflow;

/**
 * Evergreen recycler.
 *
 * Finds published posts that are old enough to re-run and that demonstrably
 * produced leads, then re-submits the content through the normal approval
 * workflow (so nothing is ever re-published without sign-off).
 *
 * Attribution is deliberately explainable:
 *   a) exact   - a lead whose `post_id` equals the platform post id recorded in
 *                the Mixpost response for that post,
 *   b) overlap - leads captured in the 72h after publication whose comment text
 *                shares a significant keyword with the post content.
 * (b) is a heuristic, so it is reported as such in the audit trail.
 */
final class EvergreenRecycler
{
    /** Words that carry no attribution signal. */
    private const STOP_WORDS = ['this', 'that', 'with', 'from', 'your', 'have', 'will', 'just', 'about', 'there', 'they', 'what', 'when', 'were', 'been', 'them', 'than', 'then', 'into', 'more', 'most', 'some', 'very', 'also', 'over', 'only', 'como', 'para'];

    public function __construct(
        private readonly Database $db,
        private readonly PostApprovalWorkflow $workflow,
        private readonly ?Cache $cache = null,
        private readonly ?AuditLog $audit = null,
    ) {
    }

    public static function make(): self
    {
        return new self(
            \SocialBot\Support\App::db(),
            \SocialBot\Support\App::workflow(),
            \SocialBot\Support\App::cache(),
            new AuditLog(\SocialBot\Support\App::db())
        );
    }

    /**
     * Rank recycling candidates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidates(?int $minAgeDays = null, int $limit = 10): array
    {
        $minAgeDays ??= (int) Config::get('evergreen.min_age_days', 30);
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, $minAgeDays) . ' days'));

        $posts = $this->db->select(
            'SELECT * FROM `' . $this->db->table('social_posts') . '`
             WHERE `status` = ? AND `published_at` IS NOT NULL AND `published_at` <= ?
             ORDER BY `published_at` ASC
             LIMIT ' . max(1, $limit * 3),
            ['published', $cutoff]
        );

        $ranked = [];

        foreach ($posts as $post) {
            $attribution = $this->attribute($post);

            if ($attribution['leads'] < (int) Config::get('evergreen.min_leads', 1)) {
                continue;
            }

            $post['evergreen_leads'] = $attribution['leads'];
            $post['attribution_method'] = $attribution['method'];
            $post['evergreen_score'] = $attribution['leads'] * 10 + min(20, (int) ($post['attempts'] ?? 0));
            $ranked[] = $post;
        }

        usort($ranked, static fn (array $a, array $b): int => $b['evergreen_score'] <=> $a['evergreen_score']);

        return array_slice($ranked, 0, max(1, $limit));
    }

    /**
     * Re-submit the best candidates for approval.
     *
     * @return array{considered:int, queued:array<int, array<string, mixed>>, skipped:array<int, string>}
     */
    public function run(?int $maxPerRun = null): array
    {
        $maxPerRun ??= (int) Config::get('evergreen.max_per_run', 3);
        $candidates = $this->candidates(null, max(1, $maxPerRun * 2));

        $queued = [];
        $skipped = [];
        $count = 0;

        foreach ($candidates as $candidate) {
            if ($count >= $maxPerRun) {
                break;
            }

            $content = (string) $candidate['content'];
            $fingerprint = 'evergreen:' . sha1($content);

            if ($this->cache !== null && !$this->cache->once($fingerprint, 60 * 60 * 24 * 30)) {
                $skipped[] = 'post #' . $candidate['id'] . ' already recycled in the last 30 days';

                continue;
            }

            $accounts = Json::fromColumn($candidate['target_accounts'] ?? null);
            $platforms = Json::fromColumn($candidate['platforms'] ?? null);

            $result = $this->workflow->requestPost(
                $content,
                array_map('intval', $accounts),
                'evergreen-recycler',
                [
                    'platforms' => $platforms,
                    'lead_id' => $candidate['lead_id'] ?? null,
                ]
            );

            if (($result['ok'] ?? false) === true) {
                $queued[] = [
                    'source_post_id' => (int) $candidate['id'],
                    'new_post_id' => (int) ($result['post_id'] ?? 0),
                    'attributed_leads' => (int) $candidate['evergreen_leads'],
                ];
                $count++;
            } else {
                $skipped[] = 'post #' . $candidate['id'] . ': ' . (string) ($result['error'] ?? 'unknown error');
            }
        }

        $this->audit?->info('agent.evergreen.run', 'Evergreen recycler run completed', [
            'considered' => count($candidates),
            'queued' => count($queued),
            'skipped' => count($skipped),
        ]);

        return ['considered' => count($candidates), 'queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{leads:int, method:string}
     */
    public function attribute(array $post): array
    {
        $platformPostIds = $this->platformPostIds($post);

        $exact = 0;

        foreach ($platformPostIds as $platformPostId) {
            $row = $this->db->first(
                'SELECT COUNT(*) AS aggregate FROM `' . $this->db->table('leads') . '` WHERE `post_id` = ?',
                [$platformPostId]
            );

            $exact += (int) ($row['aggregate'] ?? 0);
        }

        if ($exact > 0) {
            return ['leads' => $exact, 'method' => 'post_id'];
        }

        $publishedAt = (string) ($post['published_at'] ?? '');

        if ($publishedAt === '') {
            return ['leads' => 0, 'method' => 'none'];
        }

        $from = $publishedAt;
        $to = date('Y-m-d H:i:s', strtotime($publishedAt . ' +72 hours'));

        $keywords = $this->significantWords((string) $post['content']);

        if ($keywords === []) {
            return ['leads' => 0, 'method' => 'none'];
        }

        $clauses = [];
        $bindings = [$from, $to];

        foreach ($keywords as $keyword) {
            $clauses[] = '`comment_text` LIKE ?';
            $bindings[] = '%' . $keyword . '%';
        }

        try {
            $row = $this->db->first(
                'SELECT COUNT(*) AS aggregate FROM `' . $this->db->table('leads') . '`
                 WHERE `created_at` BETWEEN ? AND ? AND (' . implode(' OR ', $clauses) . ')',
                $bindings
            );
        } catch (\Throwable $e) {
            Logger::warning('evergreen attribution failed', ['error' => $e->getMessage()]);

            return ['leads' => 0, 'method' => 'none'];
        }

        return ['leads' => (int) ($row['aggregate'] ?? 0), 'method' => 'keyword_overlap'];
    }

    /**
     * Platform post ids recorded in the Mixpost response payload.
     *
     * @param array<string, mixed> $post
     * @return array<int, string>
     */
    private function platformPostIds(array $post): array
    {
        $ids = [];
        $response = Json::fromColumn($post['mixpost_response'] ?? null);

        $stack = [$response];

        while ($stack !== []) {
            $node = array_pop($stack);

            if (!is_array($node)) {
                continue;
            }

            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;

                    continue;
                }

                if (in_array((string) $key, ['platform_post_id', 'provider_post_id', 'remote_id', 'post_id'], true)
                    && is_scalar($value)
                    && (string) $value !== ''
                ) {
                    $ids[] = (string) $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, string>
     */
    private function significantWords(string $content): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($content)) ?: [];
        $counts = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 5 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publishedPosts(int $limit = 25): array
    {
        return $this->db->all('social_posts', ['status' => 'published'], '`published_at` DESC', $limit);
    }
}
