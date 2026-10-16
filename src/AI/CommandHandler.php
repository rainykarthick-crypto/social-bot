<?php

declare(strict_types=1);

namespace SocialBot\AI;

use SocialBot\Support\App;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Workflow\PostApprovalWorkflow;

/**
 * Routes a parsed command to the module that executes it.
 *
 * Kept separate from CommandParser so the parser stays a pure NLU component and
 * the side effects stay testable.
 */
final class CommandHandler
{
    public function __construct(
        private readonly CommandParser $parser,
        private readonly PostApprovalWorkflow $workflow,
        private readonly AnalyticsSummarizer $analytics,
        private readonly Database $db,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            App::commandParser(),
            App::workflow(),
            AnalyticsSummarizer::fromConfig(),
            App::db()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $command, string $userId): array
    {
        $parsed = $this->parser->parse($command, ['requested_by' => $userId]);

        return match ($parsed['action']) {
            'create_post', 'schedule_post' => $this->requestPost($parsed, $userId),
            'list_pending' => $this->listPending(),
            'approve_post' => $this->approveLatest($userId),
            'check_analytics' => $this->analytics(),
            default => [
                'ok' => false,
                'action' => (string) $parsed['action'],
                'message' => 'Unsupported action.',
                'parsed' => $parsed,
            ],
        };
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function requestPost(array $parsed, string $userId): array
    {
        $content = trim((string) ($parsed['content'] ?? ''));

        if ($content === '') {
            return [
                'ok' => false,
                'action' => (string) $parsed['action'],
                'message' => 'No post content was found in that command.',
                'parsed' => $parsed,
            ];
        }

        $platforms = (array) ($parsed['platforms'] ?? []);
        $accounts = $this->accountIdsFor($platforms, (array) Config::get('platform_accounts', []));

        $schedule = (array) ($parsed['schedule'] ?? []);
        $scheduledFor = null;

        if (($schedule['date'] ?? null) !== null) {
            $scheduledFor = (string) $schedule['date'] . ' ' . ((string) ($schedule['time'] ?? '10:00')) . ':00';
        }

        $result = $this->workflow->requestPost($content, $accounts, $userId, [
            'platforms' => $platforms,
            'scheduled_for' => $scheduledFor,
        ]);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'action' => (string) $parsed['action'],
            'message' => ($result['ok'] ?? false)
                ? sprintf(
                    'Post #%d queued for approval (%s). Krayin lead %s, next approver: %s.',
                    (int) ($result['post_id'] ?? 0),
                    implode(', ', $platforms),
                    (string) ($result['krayin_lead_id'] ?? 'pending'),
                    (string) ($result['next_level'] ?? 'Social Media Manager')
                )
                : 'Could not queue the post: ' . (string) ($result['error'] ?? 'unknown error'),
            'data' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listPending(): array
    {
        $rows = $this->db->all(
            'social_posts',
            ['status' => 'pending_approval'],
            '`id` DESC',
            25
        );

        $pending = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'content' => mb_substr((string) $row['content'], 0, 160),
            'platforms' => Json::fromColumn($row['platforms'] ?? null),
            'requested_by' => (string) ($row['requested_by'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ], $rows);

        return [
            'ok' => true,
            'action' => 'list_pending',
            'message' => $pending === []
                ? 'No posts are waiting for approval.'
                : count($pending) . ' post(s) waiting for approval.',
            'data' => ['pending' => $pending],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approveLatest(string $userId): array
    {
        $latest = $this->db->findWhere('social_posts', ['status' => 'pending_approval']);

        if ($latest === null) {
            $rows = $this->db->all('social_posts', ['status' => 'pending_approval'], '`id` ASC', 1);
            $latest = $rows[0] ?? null;
        }

        if ($latest === null) {
            return [
                'ok' => false,
                'action' => 'approve_post',
                'message' => 'There is nothing pending approval.',
            ];
        }

        $result = $this->workflow->approvePost((int) $latest['id'], $userId, 'Approved via command');

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'action' => 'approve_post',
            'message' => (string) ($result['message'] ?? ''),
            'data' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analytics(): array
    {
        $summary = $this->analytics->summarize(7);

        return [
            'ok' => true,
            'action' => 'check_analytics',
            'message' => sprintf(
                'Last 7 days: %d leads, %d qualified, top platform %s, top intent %s. %s',
                (int) $summary['total_leads'],
                (int) $summary['qualified'],
                (string) $summary['top_platform'],
                (string) $summary['top_intent'],
                (string) $summary['recommendation']
            ),
            'data' => $summary,
        ];
    }

    /**
     * @param array<int, string> $platforms
     * @param array<string, mixed> $map
     * @return array<int, int>
     */
    private function accountIdsFor(array $platforms, array $map): array
    {
        $accounts = [];

        foreach ($platforms as $platform) {
            $id = $map[strtolower((string) $platform)] ?? null;

            if ($id === null) {
                continue;
            }

            if (is_array($id)) {
                foreach ($id as $candidate) {
                    $accounts[] = (int) $candidate;
                }

                continue;
            }

            $accounts[] = (int) $id;
        }

        if ($accounts === []) {
            foreach ($map as $id) {
                if (is_numeric($id)) {
                    $accounts[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($accounts));
    }
}
