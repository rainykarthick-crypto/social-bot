<?php

declare(strict_types=1);

namespace SocialBot\Workflow;

use SocialBot\AI\DeepSeekClassifier;
use SocialBot\CRM\KrayinClient;
use SocialBot\Leads\LeadScorer;
use SocialBot\Leads\SmartRouter;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;
use SocialBot\Support\SentimentFilter;
use Throwable;

/**
 * worker.php's step 1-7 pipeline.
 *
 *   1. claim up to N pending rows from comment_queue
 *   2. VADER pre-filter (no LLM for noise)      -> status=skipped
 *   3. batch the survivors into one DeepSeek call
 *   4. score each result (LeadScorer) and route it (SmartRouter)
 *   5. insert into leads
 *   6. qualified leads are pushed to Krayin CRM and linked back
 *   7. mark the queue row processed
 *
 * Rows are claimed with SELECT ... FOR UPDATE inside a short transaction so two
 * workers can run side by side without double-processing.
 */
final class CommentPipeline
{
    private ?AuditLog $audit;

    public function __construct(
        private readonly Database $db,
        private readonly SentimentFilter $sentiment,
        private readonly DeepSeekClassifier $classifier,
        private readonly LeadScorer $scorer,
        private readonly SmartRouter $router,
        private readonly KrayinClient $krayin,
        ?AuditLog $audit = null,
    ) {
        $this->audit = $audit;
    }

    public function db(): Database
    {
        return $this->db;
    }

    /**
     * @return array<string, mixed>
     */
    public function processBatch(int $limit = 20, bool $dryRun = false): array
    {
        $limit = max(1, $limit);

        // Release claims orphaned by a crashed worker before taking new work.
        $this->closeStaleClaims();

        $rows = $this->claim($limit);

        $stats = [
            'claimed' => count($rows),
            'skipped' => 0,
            'classified' => 0,
            'leads_created' => 0,
            'duplicates' => 0,
            'qualified' => 0,
            'krayin_created' => 0,
            'failed' => 0,
            'provider' => 'n/a',
            'skip_rate' => 0.0,
            'dry_run' => $dryRun,
        ];

        if ($rows === []) {
            return $stats;
        }

        // 2. Local pre-filter.
        $partitioned = $this->sentiment->filter($rows);
        $stats['skip_rate'] = $this->sentiment->stats()['skip_rate'];

        foreach ($partitioned['skipped'] as $row) {
            $stats['skipped']++;

            if (!$dryRun) {
                $this->db->update('comment_queue', [
                    'status' => 'skipped',
                    'skip_reason' => (string) ($row['skip_reason'] ?? 'filtered'),
                    'processed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => (int) $row['id']]);
            }
        }

        $keep = $partitioned['keep'];

        if ($keep === []) {
            return $stats;
        }

        // 3. One batched LLM call for everything that survived the filter.
        $classifications = $this->classifier->classifyBatch($keep);
        $stats['provider'] = $this->classifier->lastProvider();

        foreach ($keep as $index => $row) {
            $classification = $classifications[$index] ?? null;

            if ($classification === null) {
                $stats['failed']++;
                $this->markFailed((int) $row['id'], 'no classification returned');

                continue;
            }

            $stats['classified']++;

            if ($dryRun) {
                continue;
            }

            try {
                $this->persist($row, $classification, $stats);
            } catch (Throwable $e) {
                $stats['failed']++;
                Logger::error('lead persistence failed', [
                    'queue_id' => $row['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $this->markFailed((int) $row['id'], $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Claim pending rows and flip them to 'processing'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim(int $limit): array
    {
        // Row-level locking is only emitted for engines that support it, so the
        // pipeline can also be exercised against SQLite in tests/smoke.php.
        $lock = in_array($this->db->driver(), ['mysql', 'pgsql', 'mariadb'], true) ? ' FOR UPDATE' : '';

        return $this->db->transaction(function (Database $db) use ($limit, $lock): array {
            $rows = $db->select(
                'SELECT * FROM `' . $db->table('comment_queue') . '`
                 WHERE `status` = ?
                 ORDER BY `id` ASC
                 LIMIT ' . max(1, $limit) . $lock,
                ['pending']
            );

            if ($rows === []) {
                return [];
            }

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

            $db->execute(
                'UPDATE `' . $db->table('comment_queue') . '`
                 SET `status` = ?, `attempts` = `attempts` + 1, `updated_at` = ?
                 WHERE `id` IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                array_merge(['processing', date('Y-m-d H:i:s')], $ids)
            );

            return $rows;
        });
    }

    /**
     * Reset rows abandoned by a crashed worker.
     */
    public function closeStaleClaims(int $minutes = 10): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, $minutes) . ' minutes'));

        return $this->db->execute(
            'UPDATE `' . $this->db->table('comment_queue') . '`
             SET `status` = ?, `updated_at` = ?
             WHERE `status` = ? AND `updated_at` <= ?',
            ['pending', date('Y-m-d H:i:s'), 'processing', $cutoff]
        );
    }

    /**
     * Put failed rows back in the queue when they still have retries left.
     */
    public function requeueFailed(int $maxAttempts = 3, int $limit = 100): int
    {
        $limit = max(1, $limit);

        // MySQL/MariaDB accept ORDER BY + LIMIT on UPDATE; SQLite does not, so
        // the bound is expressed as a subquery there.
        if (in_array($this->db->driver(), ['mysql', 'mariadb'], true)) {
            return $this->db->execute(
                'UPDATE `' . $this->db->table('comment_queue') . '`
                 SET `status` = ?, `updated_at` = ?
                 WHERE `status` = ? AND `attempts` < ?
                 ORDER BY `id` ASC
                 LIMIT ' . $limit,
                ['pending', date('Y-m-d H:i:s'), 'failed', max(1, $maxAttempts)]
            );
        }

        return $this->db->execute(
            'UPDATE `' . $this->db->table('comment_queue') . '`
             SET `status` = ?, `updated_at` = ?
             WHERE `id` IN (
                 SELECT `id` FROM (
                     SELECT `id` FROM `' . $this->db->table('comment_queue') . '`
                     WHERE `status` = ? AND `attempts` < ?
                     ORDER BY `id` ASC LIMIT ' . $limit . '
                 ) AS `retryable`
             )',
            ['pending', date('Y-m-d H:i:s'), 'failed', max(1, $maxAttempts)]
        );
    }

    /**
     * Steps 4-7 for one comment.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $stats
     */
    private function persist(array $row, array $classification, array &$stats): void
    {
        $scoring = $this->scorer->scoreFromComment($classification, $row);
        $routing = $this->router->route($classification, $scoring);

        $commentId = (string) $row['comment_id'];
        $platform = (string) $row['platform'];

        $leadRow = [
            'platform' => $platform,
            'comment_id' => $commentId,
            'post_id' => $row['post_id'] !== null ? (string) $row['post_id'] : null,
            'author' => $row['author'] !== null ? (string) $row['author'] : null,
            'author_profile_url' => $row['author_profile_url'] !== null ? (string) $row['author_profile_url'] : null,
            'comment_text' => (string) $row['text'],
            'intent' => (string) $classification['intent'],
            'intent_score' => (int) $classification['score'],
            'sentiment' => (string) $classification['sentiment'],
            'sentiment_score' => (float) ($row['sentiment_score'] ?? 0),
            'lead_score' => (int) $scoring['lead_score'],
            'priority' => (string) $scoring['priority'],
            'is_qualified' => $scoring['is_qualified'] ? 1 : 0,
            'status' => 'new',
            'summary' => mb_substr((string) $classification['summary'], 0, 500),
            'suggested_reply' => (string) $classification['reply'],
            'department' => (string) $routing['department'],
            'area' => (string) $routing['area'],
            'routing_queue' => (string) $routing['queue'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $leadId = $this->db->insertIgnore('leads', $leadRow);

        if ($leadId === null) {
            // Already captured (webhook redelivery): reuse the existing row.
            $existing = $this->db->findWhere('leads', ['comment_id' => $commentId]);
            $leadId = $existing === null ? null : (int) $existing['id'];
            $stats['duplicates']++;
        } else {
            $stats['leads_created']++;
        }

        if ($leadId === null) {
            $this->db->update('comment_queue', [
                'status' => 'processed',
                'processed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);

            return;
        }

        if ($scoring['is_qualified']) {
            $stats['qualified']++;
        }

        // 6. Krayin for qualified leads.
        if ($scoring['is_qualified']) {
            $existingLead = $this->db->find('leads', $leadId);

            if ($existingLead !== null && empty($existingLead['krayin_lead_id'])) {
                $response = $this->krayin->createLead($this->krayin->leadPayloadFromLead($existingLead + [
                    'created_at' => $leadRow['created_at'],
                ]));

                if (($response['ok'] ?? false) && $response['id'] !== null) {
                    $this->db->update('leads', [
                        'krayin_lead_id' => $response['id'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $leadId]);
                    $stats['krayin_created']++;

                    if ($this->audit !== null) {
                        $this->audit->info('pipeline.lead_pushed', 'Qualified lead pushed to Krayin', [
                            'lead_id' => $leadId,
                            'krayin_lead_id' => $response['id'],
                            'priority' => $scoring['priority'],
                            'queue' => $routing['queue'],
                        ], 'lead', (string) $leadId);
                    }
                } elseif ($response['id'] === null) {
                    Logger::notice('krayin lead not created', [
                        'lead_id' => $leadId,
                        'error' => $response['error'],
                    ]);
                }
            }
        }

        $this->linkGoogleAdsRow($platform, $commentId, $leadId);

        // 7. Close the queue row.
        $this->db->update('comment_queue', [
            'status' => 'processed',
            'lead_id' => $leadId,
            'last_error' => null,
            'processed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);
    }

    /**
     * Google Ads rows are stored twice (raw + queue); close the loop when the
     * pipeline turns the queue row into a lead.  comment_id format: gads-<id>.
     */
    private function linkGoogleAdsRow(string $platform, string $commentId, int $leadId): void
    {
        if ($platform !== 'google_ads' || preg_match('/^gads-(\d+)$/', $commentId, $matches) !== 1) {
            return;
        }

        try {
            $this->db->update('google_ads_leads', [
                'lead_id' => $leadId,
                'processed' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $matches[1]]);

            $this->db->update('leads', [
                'google_ads_lead_id' => (int) $matches[1],
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $leadId]);
        } catch (Throwable $e) {
            Logger::warning('google ads lead link failed', ['error' => $e->getMessage()]);
        }
    }

    private function markFailed(int $queueId, string $error): void
    {
        $this->db->update('comment_queue', [
            'status' => 'failed',
            'last_error' => mb_substr($error, 0, 500),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $queueId]);

        Logger::error('comment processing failed', ['queue_id' => $queueId, 'error' => $error]);
    }

    /**
     * Queue-depth report used by audit.php / the dashboard.
     *
     * @return array<string, int>
     */
    public function queueDepth(): array
    {
        $rows = $this->db->select(
            'SELECT `status`, COUNT(*) AS total FROM `' . $this->db->table('comment_queue') . '` GROUP BY `status`'
        );

        $depth = ['pending' => 0, 'processing' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $depth[(string) $row['status']] = (int) $row['total'];
        }

        $depth['total'] = array_sum($depth);

        return $depth;
    }

    /**
     * Skip-rate check against the 40-60% target of the token-budget rules.
     *
     * @return array{processed:int, skipped:int, rate:float, target_met:bool}
     */
    public function skipRateReport(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $hours) . ' hours'));

        $row = $this->db->first(
            'SELECT
                COALESCE(SUM(`status` = \'processed\'), 0) AS processed,
                COALESCE(SUM(`status` = \'skipped\'), 0) AS skipped
             FROM `' . $this->db->table('comment_queue') . '`
             WHERE `created_at` >= ?',
            [$since]
        ) ?? [];

        $processed = (int) ($row['processed'] ?? 0);
        $skipped = (int) ($row['skipped'] ?? 0);
        $total = $processed + $skipped;

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'rate' => $total > 0 ? round($skipped / $total, 3) : 0.0,
            'target_met' => $total > 0 && $skipped / $total >= 0.4,
        ];
    }

    /**
     * Per-queue lead counts for the digest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function routingBreakdown(int $days = 1): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'));

        return $this->db->select(
            'SELECT `routing_queue`, `priority`, COUNT(*) AS total
             FROM `' . $this->db->table('leads') . '`
             WHERE `created_at` >= ?
             GROUP BY `routing_queue`, `priority`
             ORDER BY total DESC',
            [$since]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function rowPayload(array $row): string
    {
        return Json::encode([
            'platform' => $row['platform'] ?? null,
            'comment_id' => $row['comment_id'] ?? null,
            'text' => $row['text'] ?? null,
        ]);
    }
}
