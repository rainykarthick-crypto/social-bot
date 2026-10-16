<?php

declare(strict_types=1);

namespace SocialBot\Workflow;

use SocialBot\CRM\KrayinClient;
use SocialBot\Social\MixpostManager;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Support\Logger;

/**
 * The "ask CRM -> task -> approve -> post" flow.
 *
 * requestPost()  : create a Krayin lead ("Social Media Post Request") + a review
 *                  task assigned to the default approver, persist the post as
 *                  pending_approval, compute the approval chain and write the
 *                  'submitted' audit row.
 * approvePost()  : record the sign-off for the acting level; when the whole
 *                  chain has approved, publish through Mixpost.
 * rejectPost()   : record the rejection and stop the chain.
 * publishToMixpost(): create the Mixpost post (scheduled), approve it in Mixpost
 *                  when Mixpost asks for approval, then sync Krayin and the
 *                  local row.
 */
final class PostApprovalWorkflow
{
    private AuditLog $audit;

    public function __construct(
        private readonly Database $db,
        private readonly KrayinClient $krayin,
        private readonly MixpostManager $mixpost,
        private readonly ConditionalApproval $approvals,
        ?AuditLog $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLog($db);
    }

    /**
     * @param array<int, int> $accounts Mixpost account ids
     * @param array<string, mixed> $options platforms[], scheduled_for, workspace_uuid, lead_id, tags[]
     * @return array<string, mixed>
     */
    public function requestPost(string $content, array $accounts, string $requestedBy, array $options = []): array
    {
        $content = trim($content);

        if ($content === '') {
            return ['ok' => false, 'error' => 'Post content is empty', 'status' => 'rejected'];
        }

        $platforms = array_values(array_filter(array_map('strval', (array) ($options['platforms'] ?? []))));
        $scheduledFor = $options['scheduled_for'] ?? null;
        $workspaceUuid = (string) ($options['workspace_uuid'] ?? $this->mixpost->workspaceUuid());
        $levels = $this->approvals->determineLevels($content);
        $warnings = [];

        // 1. Krayin lead: "Social Media Post Request".
        $leadResponse = $this->krayin->createLead([
            'title' => 'Social Media Post Request',
            'description' => $content,
            'user_id' => (int) Config::get('krayin.default_user_id', 1),
        ]);

        $krayinLeadId = $leadResponse['id'];

        if (!($leadResponse['ok'] ?? false)) {
            $warnings[] = 'krayin_lead_pending: ' . (string) ($leadResponse['error'] ?? 'unknown error');
        }

        // 2. Persist the post (needed before the activity so we can link both ways).
        $postId = $this->db->insert('social_posts', [
            'lead_id' => isset($options['lead_id']) ? (int) $options['lead_id'] : null,
            'krayin_lead_id' => $krayinLeadId,
            'content' => $content,
            'target_accounts' => Json::encode($accounts),
            'platforms' => Json::encode($platforms),
            'requested_by' => $requestedBy,
            'status' => 'pending_approval',
            'scheduled_for' => $scheduledFor,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 3. Krayin task: "Review & Approve Social Post".
        $approverId = (int) Config::get('krayin.default_approver_id', 1);

        $activityResponse = $this->krayin->createActivity($krayinLeadId ?? 0, [
            'title' => 'Review & Approve Social Post',
            'type' => 'task',
            'comment' => $this->reviewComment($content, $levels, $requestedBy),
            'user_id' => $approverId,
            'is_done' => 0,
            'schedule_from' => date('Y-m-d H:i:s'),
            'schedule_to' => date('Y-m-d H:i:s', strtotime('+' . (int) Config::get('approval.sla_hours', 24) . ' hours')),
        ]);

        if (!($activityResponse['ok'] ?? false)) {
            $warnings[] = 'krayin_activity_pending: ' . (string) ($activityResponse['error'] ?? 'unknown error');
        }

        if ($krayinLeadId !== null && $activityResponse['id'] !== null) {
            $this->db->update('social_posts', ['krayin_activity_id' => $activityResponse['id']], ['id' => $postId]);
        }

        // 4. Audit: submitted at the first level of the chain.
        $this->logApproval($postId, $levels[0], 'submitted', $requestedBy, 'Post submitted for approval');

        $this->audit->info('workflow.post_requested', 'Social post submitted for approval', [
            'post_id' => $postId,
            'krayin_lead_id' => $krayinLeadId,
            'levels' => $levels,
            'accounts' => $accounts,
            'workspace' => $workspaceUuid,
        ], 'social_post', (string) $postId);

        return [
            'ok' => true,
            'post_id' => $postId,
            'krayin_lead_id' => $krayinLeadId,
            'krayin_activity_id' => $activityResponse['id'],
            'status' => 'pending_approval',
            'approval_levels' => $levels,
            'next_level' => $levels[0],
            'accounts' => $accounts,
            'platforms' => $platforms,
            'warnings' => $warnings,
        ];
    }

    /**
     * Record an approval. When the chain is complete the post is published.
     *
     * @return array<string, mixed>
     */
    public function approvePost(int $postId, string $approver, ?string $remarks = null, ?string $level = null): array
    {
        $post = $this->db->find('social_posts', $postId);

        if ($post === null) {
            return ['ok' => false, 'status' => 'missing', 'message' => 'Post #' . $postId . ' not found.'];
        }

        if (in_array((string) $post['status'], ['rejected', 'published'], true)) {
            return [
                'ok' => false,
                'status' => (string) $post['status'],
                'message' => 'Post #' . $postId . ' is already ' . (string) $post['status'] . '.',
            ];
        }

        $required = $this->approvals->determineLevels((string) $post['content']);
        $approved = $this->approvedLevels($postId);
        $progress = $this->approvals->progress($required, $approved);

        $level ??= $progress['next'];

        if ($level === null) {
            return ['ok' => true, 'status' => 'approved', 'message' => 'All levels already approved.', 'progress' => $progress];
        }

        if (!in_array($level, $required, true)) {
            return [
                'ok' => false,
                'status' => 'invalid_level',
                'message' => $level . ' is not part of this post\'s approval chain.',
                'progress' => $progress,
            ];
        }

        if (in_array($level, $approved, true)) {
            return [
                'ok' => false,
                'status' => 'already_approved',
                'message' => $level . ' already approved this post.',
                'progress' => $progress,
            ];
        }

        $this->db->insert('approval_audit', [
            'social_post_id' => $postId,
            'level_name' => $level,
            'action' => 'approved',
            'actor' => $approver,
            'remarks' => $remarks,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $approved[] = $level;
        $progress = $this->approvals->progress($required, $approved);

        // Keep the "next approver" task visible inside Krayin.
        if ($progress['next'] !== null && $post['krayin_lead_id'] !== null) {
            $this->krayin->createActivity((int) $post['krayin_lead_id'], [
                'title' => 'Awaiting approval: ' . $progress['next'],
                'type' => 'task',
                'comment' => $level . ' approved on ' . date('Y-m-d H:i:s') . ' by ' . $approver
                    . ($remarks !== null && $remarks !== '' ? '. Remarks: ' . $remarks : ''),
                'user_id' => (int) Config::get('krayin.default_approver_id', 1),
                'schedule_from' => date('Y-m-d H:i:s'),
                'schedule_to' => date('Y-m-d H:i:s', strtotime('+' . (int) Config::get('approval.sla_hours', 24) . ' hours')),
            ]);
        }

        if (!$progress['complete']) {
            $this->db->update('social_posts', [
                'status' => 'pending_approval',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $postId]);

            $this->audit->info('workflow.post_partially_approved', 'Partial approval recorded', [
                'post_id' => $postId,
                'level' => $level,
                'next_level' => $progress['next'],
            ], 'social_post', (string) $postId);

            return [
                'ok' => true,
                'status' => 'partially_approved',
                'message' => $level . ' approved. Next approver: ' . (string) $progress['next'] . '.',
                'progress' => $progress,
                'approved_by' => $approver,
                'level' => $level,
            ];
        }

        $this->db->update('social_posts', [
            'status' => 'approved',
            'approved_by' => $approver,
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $postId]);

        $this->audit->info('workflow.post_approved', 'All approval levels signed off', [
            'post_id' => $postId,
            'approved_by' => $approver,
            'levels' => $required,
        ], 'social_post', (string) $postId);

        $publish = $this->publishToMixpost($this->db->find('social_posts', $postId) ?? $post);

        return [
            'ok' => (bool) ($publish['ok'] ?? false),
            'status' => (string) ($publish['status'] ?? 'approved'),
            'message' => ($publish['ok'] ?? false)
                ? 'All levels approved and the post was sent to Mixpost.'
                : 'All levels approved, but publishing failed: ' . (string) ($publish['error'] ?? 'unknown error'),
            'progress' => $progress,
            'level' => $level,
            'publish' => $publish,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectPost(int $postId, string $approver, ?string $reason = null): array
    {
        $post = $this->db->find('social_posts', $postId);

        if ($post === null) {
            return ['ok' => false, 'status' => 'missing', 'message' => 'Post #' . $postId . ' not found.'];
        }

        $required = $this->approvals->determineLevels((string) $post['content']);
        $approved = $this->approvedLevels($postId);
        $level = $this->approvals->nextLevel($required, $approved) ?? ConditionalApproval::LEVEL_BRAND;

        $this->db->insert('approval_audit', [
            'social_post_id' => $postId,
            'level_name' => $level,
            'action' => 'rejected',
            'actor' => $approver,
            'remarks' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->db->update('social_posts', [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $postId]);

        if ($post['krayin_lead_id'] !== null) {
            $this->krayin->createActivity((int) $post['krayin_lead_id'], [
                'title' => 'Social post rejected by ' . $level,
                'type' => 'task',
                'comment' => 'Rejected by ' . $approver . '. Reason: ' . (string) ($reason ?? 'not provided'),
                'is_done' => 1,
                'schedule_from' => date('Y-m-d H:i:s'),
                'schedule_to' => date('Y-m-d H:i:s'),
            ]);
            $this->krayin->updateLeadStatus((int) $post['krayin_lead_id'], 'rejected');
        }

        $this->audit->info('workflow.post_rejected', 'Social post rejected', [
            'post_id' => $postId,
            'level' => $level,
            'actor' => $approver,
            'reason' => $reason,
        ], 'social_post', (string) $postId);

        return [
            'ok' => true,
            'status' => 'rejected',
            'level' => $level,
            'message' => 'Post #' . $postId . ' rejected by ' . $level . '.',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function publishToMixpost(array $post): array
    {
        $postId = (int) ($post['id'] ?? 0);
        $accounts = Json::fromColumn($post['target_accounts'] ?? null);

        if ($accounts === []) {
            $accounts = array_values(array_filter(array_map('intval', array_values((array) Config::get('platform_accounts', [])))));
        }

        if ($accounts === []) {
            return $this->failPublish($postId, 'No Mixpost target accounts configured (PLATFORM_ACCOUNT_MAP is empty).');
        }

        $workspace = $this->workspaceUuidFor($post);

        $scheduledFor = (string) ($post['scheduled_for'] ?? '');
        $timestamp = $scheduledFor !== '' ? (strtotime($scheduledFor) ?: time() + 60) : time() + 60;

        $response = $this->mixpost->createPost($workspace, [
            'content' => (string) $post['content'],
            'accounts' => $accounts,
            'schedule' => true,
            'date' => date('Y-m-d', $timestamp),
            'time' => date('H:i', $timestamp),
            'timezone' => (string) Config::get('mixpost.timezone', 'Asia/Kolkata'),
        ]);

        if (!($response['ok'] ?? false)) {
            return $this->failPublish($postId, (string) ($response['error'] ?? 'unknown Mixpost error'));
        }

        $uuid = (string) ($response['uuid'] ?? '');
        $mixpostStatus = (string) ($response['status'] ?? '');

        // 2. Mixpost itself may hold the post for review.
        if ($mixpostStatus === MixpostManager::STATUS_NEEDS_APPROVAL && $uuid !== '') {
            $approved = $this->mixpost->approvePost($workspace, $uuid);
            $mixpostStatus = (string) ($approved['status'] ?? $mixpostStatus);

            if (!($approved['ok'] ?? false)) {
                Logger::warning('mixpost approval failed', ['post_uuid' => $uuid, 'error' => $approved['error'] ?? null]);
            }
        }

        // 3. Local row.
        $this->db->update('social_posts', [
            'status' => 'published',
            'mixpost_post_uuid' => $uuid !== '' ? $uuid : null,
            'mixpost_response' => Json::encode($response['data'] ?? []),
            'published_at' => date('Y-m-d H:i:s'),
            'attempts' => (int) ($post['attempts'] ?? 0) + 1,
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $postId]);

        // 4. Krayin.
        if (!empty($post['krayin_lead_id'])) {
            $this->krayin->updateLeadStatus((int) $post['krayin_lead_id'], 'approved');
        }

        $this->audit->info('workflow.post_published', 'Post handed to Mixpost', [
            'post_id' => $postId,
            'mixpost_post_uuid' => $uuid,
            'mixpost_status' => $mixpostStatus,
            'workspace' => $workspace,
            'accounts' => $accounts,
        ], 'social_post', (string) $postId);

        return [
            'ok' => true,
            'status' => 'published',
            'mixpost_status' => $mixpostStatus,
            'mixpost_post_uuid' => $uuid,
            'message' => $uuid === ''
                ? 'Post created in Mixpost (no uuid returned).'
                : 'Post created in Mixpost as ' . $uuid . ' (Mixpost status: ' . $mixpostStatus . ').',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publishPostById(int $postId): array
    {
        $post = $this->db->find('social_posts', $postId);

        if ($post === null) {
            return ['ok' => false, 'status' => 'missing', 'message' => 'Post #' . $postId . ' not found.'];
        }

        return $this->publishToMixpost($post);
    }

    /**
     * Approval chain state for a post, reconstructed from approval_audit.
     *
     * @return array{required:array<int, string>, approved:array<int, string>, pending:array<int, string>, next:?string, complete:bool, percent:int}
     */
    public function levelProgress(int $postId): array
    {
        $post = $this->db->find('social_posts', $postId);

        if ($post === null) {
            return $this->approvals->progress([], []);
        }

        return $this->approvals->progress(
            $this->approvals->determineLevels((string) $post['content']),
            $this->approvedLevels($postId)
        );
    }

    /**
     * @return array<int, string>
     */
    public function approvedLevels(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT `level_name` FROM `' . $this->db->table('approval_audit') . '`
             WHERE `social_post_id` = ? AND `action` = ?',
            [$postId, 'approved']
        );

        return array_values(array_filter(array_map(static fn (array $row): string => (string) $row['level_name'], $rows)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function auditTrail(int $postId): array
    {
        return $this->db->select(
            'SELECT * FROM `' . $this->db->table('approval_audit') . '` WHERE `social_post_id` = ? ORDER BY `id` ASC',
            [$postId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingPosts(int $limit = 50): array
    {
        return $this->db->all('social_posts', ['status' => 'pending_approval'], '`id` ASC', $limit);
    }

    /**
     * Flag approvals that exceeded the SLA. Called by audit.php.
     */
    public function markSlaBreaches(?int $slaHours = null): int
    {
        $slaHours ??= (int) Config::get('approval.sla_hours', 24);
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, $slaHours) . ' hours'));

        $stale = $this->db->select(
            'SELECT * FROM `' . $this->db->table('social_posts') . '`
             WHERE `status` = ? AND `created_at` <= ?',
            ['pending_approval', $cutoff]
        );

        $breaches = 0;

        foreach ($stale as $post) {
            $postId = (int) $post['id'];
            $progress = $this->levelProgress($postId);
            $level = $progress['next'];

            if ($level === null) {
                continue;
            }

            $already = $this->db->findWhere('approval_audit', [
                'social_post_id' => $postId,
                'level_name' => $level,
                'action' => 'sla_breached',
            ]);

            if ($already !== null) {
                continue;
            }

            $this->logApproval($postId, $level, 'sla_breached', 'system', 'No decision within ' . $slaHours . ' hour(s)');
            $breaches++;

            if (!empty($post['krayin_lead_id'])) {
                $this->krayin->createActivity((int) $post['krayin_lead_id'], [
                    'title' => 'SLA breached: approval overdue (' . $level . ')',
                    'type' => 'task',
                    'comment' => 'Post #' . $postId . ' has been waiting for ' . $level . ' since ' . (string) $post['created_at'] . '.',
                    'user_id' => (int) Config::get('krayin.default_approver_id', 1),
                    'schedule_from' => date('Y-m-d H:i:s'),
                    'schedule_to' => date('Y-m-d H:i:s', strtotime('+2 hours')),
                ]);
            }

            $this->audit->warning('workflow.approval_sla_breached', 'Approval SLA breached', [
                'post_id' => $postId,
                'level' => $level,
                'created_at' => $post['created_at'],
            ], 'social_post', (string) $postId);
        }

        return $breaches;
    }

    /**
     * @return array<string, mixed>
     */
    private function failPublish(int $postId, string $error): array
    {
        $post = $this->db->find('social_posts', $postId);

        $this->db->update('social_posts', [
            'status' => 'failed',
            'last_error' => mb_substr($error, 0, 500),
            'attempts' => (int) ($post['attempts'] ?? 0) + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $postId]);

        $this->audit->error('workflow.publish_failed', $error, ['post_id' => $postId], 'social_post', (string) $postId);

        return ['ok' => false, 'status' => 'failed', 'error' => $error];
    }

    /**
     * @param array<string, mixed> $post
     */
    private function workspaceUuidFor(array $post): string
    {
        if (!empty($post['lead_id'])) {
            $lead = $this->db->find('leads', (int) $post['lead_id']);

            if ($lead !== null && !empty($lead['workspace_uuid'])) {
                return (string) $lead['workspace_uuid'];
            }
        }

        $workspace = $this->mixpost->workspaceFor();

        return (string) ($workspace['mixpost_workspace_uuid'] ?? $this->mixpost->workspaceUuid());
    }

    private function logApproval(int $postId, string $level, string $action, ?string $actor, ?string $remarks): void
    {
        $this->db->insert('approval_audit', [
            'social_post_id' => $postId,
            'level_name' => $level,
            'action' => $action,
            'actor' => $actor,
            'remarks' => $remarks,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<int, string> $levels
     */
    private function reviewComment(string $content, array $levels, string $requestedBy): string
    {
        return "Approval required before publishing.\n\n"
            . "Requested by: {$requestedBy}\n"
            . 'Approval chain: ' . implode(' -> ', $levels) . "\n\n"
            . "Content:\n" . $content;
    }
}
