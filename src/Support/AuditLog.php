<?php

declare(strict_types=1);

namespace SocialBot\Support;

use Throwable;

/**
 * Durable audit trail (audit_log table) for interactions with external systems:
 * Krayin, Mixpost, Meta, Google Ads and the platform audit runs.
 *
 * Never throws: auditing must not break the workflow it is recording.
 */
final class AuditLog
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(
        string $event,
        string $severity = 'info',
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $message = null,
        array $context = [],
    ): void {
        $severity = in_array($severity, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)
            ? $severity
            : 'info';

        try {
            $this->db->insert('audit_log', [
                'event' => mb_substr($event, 0, 96),
                'severity' => $severity,
                'subject_type' => $subjectType === null ? null : mb_substr($subjectType, 0, 64),
                'subject_id' => $subjectId === null ? null : mb_substr((string) $subjectId, 0, 64),
                'message' => $message === null ? null : mb_substr($message, 0, 512),
                'context' => $context === [] ? null : Json::encode($context),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::warning('audit_log write failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $event, ?string $message = null, array $context = [], ?string $subjectType = null, ?string $subjectId = null): void
    {
        $this->record($event, 'info', $subjectType, $subjectId, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $event, ?string $message = null, array $context = [], ?string $subjectType = null, ?string $subjectId = null): void
    {
        $this->record($event, 'warning', $subjectType, $subjectId, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $event, ?string $message = null, array $context = [], ?string $subjectType = null, ?string $subjectId = null): void
    {
        $this->record($event, 'error', $subjectType, $subjectId, $message, $context);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50, ?string $severity = null): array
    {
        try {
            if ($severity !== null) {
                return $this->db->select(
                    'SELECT * FROM `' . $this->db->table('audit_log') . '` WHERE `severity` = ? ORDER BY `id` DESC LIMIT ' . max(1, $limit),
                    [$severity]
                );
            }

            return $this->db->all('audit_log', [], '`id` DESC', max(1, $limit));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forSubject(string $subjectType, string $subjectId, int $limit = 50): array
    {
        try {
            return $this->db->select(
                'SELECT * FROM `' . $this->db->table('audit_log') . '`
                 WHERE `subject_type` = ? AND `subject_id` = ?
                 ORDER BY `id` DESC LIMIT ' . max(1, $limit),
                [$subjectType, $subjectId]
            );
        } catch (Throwable) {
            return [];
        }
    }
}
