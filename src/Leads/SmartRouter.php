<?php

declare(strict_types=1);

namespace SocialBot\Leads;

/**
 * Decides where a scored lead goes.
 *
 * Queue rules (in priority order):
 *   complaint OR negative sentiment        -> support_queue
 *   purchase|pricing|demo AND P1           -> sales_priority
 *   purchase|pricing|demo AND P2/P3        -> sales_queue
 *   spam                                   -> archive
 *   anything else                          -> general_followup
 *
 * The routing decision is persisted in `leads.routing_queue` so the dashboard
 * and the digest can report on it, and the department/area columns are filled
 * with a human-readable owner.
 */
final class SmartRouter
{
    public const SUPPORT_QUEUE = 'support_queue';

    public const SALES_PRIORITY = 'sales_priority';

    public const SALES_QUEUE = 'sales_queue';

    public const ARCHIVE = 'archive';

    public const GENERAL_FOLLOWUP = 'general_followup';

    /** @var array<string, string> */
    private const DEPARTMENTS = [
        self::SUPPORT_QUEUE => 'Support',
        self::SALES_PRIORITY => 'Sales',
        self::SALES_QUEUE => 'Sales',
        self::GENERAL_FOLLOWUP => 'Marketing',
        self::ARCHIVE => 'None',
    ];

    /** @var array<string, string> */
    private const AREAS = [
        'purchase' => 'New Business',
        'pricing' => 'Quotation',
        'demo' => 'Product Demo',
        'availability' => 'Stock & Delivery',
        'support' => 'Customer Support',
        'complaint' => 'Escalations',
        'spam' => 'Spam',
        'none' => 'General',
    ];

    /**
     * @param array<string, mixed> $classification intent / sentiment / priority
     * @param array<string, mixed> $scoring        output of LeadScorer::score()
     * @return array{queue:string, department:string, area:string, reason:string, priority:string}
     */
    public function route(array $classification, array $scoring = []): array
    {
        $intent = strtolower((string) ($classification['intent'] ?? 'none'));
        $sentiment = strtolower((string) ($classification['sentiment'] ?? 'neutral'));
        $priority = strtoupper((string) ($classification['priority'] ?? $scoring['priority'] ?? 'P3'));

        if ($priority !== 'P1' && $priority !== 'P2') {
            $priority = 'P3';
        }

        $queue = $this->queueFor($intent, $sentiment, $priority);
        $reason = $this->reason($intent, $sentiment, $priority, $queue);

        return [
            'queue' => $queue,
            'department' => self::DEPARTMENTS[$queue] ?? 'Marketing',
            'area' => self::AREAS[$intent] ?? 'General',
            'reason' => $reason,
            'priority' => $priority,
        ];
    }

    public function queueFor(string $intent, string $sentiment, string $priority = 'P3'): string
    {
        $intent = strtolower($intent);
        $sentiment = strtolower($sentiment);
        $priority = strtoupper($priority);

        if ($intent === 'spam') {
            return self::ARCHIVE;
        }

        if ($intent === 'complaint' || $sentiment === 'negative') {
            return self::SUPPORT_QUEUE;
        }

        if (in_array($intent, ['purchase', 'pricing', 'demo'], true)) {
            return $priority === 'P1' ? self::SALES_PRIORITY : self::SALES_QUEUE;
        }

        return self::GENERAL_FOLLOWUP;
    }

    /**
     * Route a fully built lead row (used by the dashboard / re-processing).
     *
     * @param array<string, mixed> $lead
     * @return array{queue:string, department:string, area:string, reason:string, priority:string}
     */
    public function routeLead(array $lead): array
    {
        return $this->route([
            'intent' => $lead['intent'] ?? 'none',
            'sentiment' => $lead['sentiment'] ?? 'neutral',
            'priority' => $lead['priority'] ?? 'P3',
        ], ['priority' => $lead['priority'] ?? 'P3']);
    }

    /**
     * SLA in hours before a queued lead should escalate, by queue.
     */
    public function slaHours(string $queue): int
    {
        return match ($queue) {
            self::SUPPORT_QUEUE => 2,
            self::SALES_PRIORITY => 1,
            self::SALES_QUEUE => 8,
            default => 48,
        };
    }

    /**
     * @return array<int, string>
     */
    public function queues(): array
    {
        return [self::SUPPORT_QUEUE, self::SALES_PRIORITY, self::SALES_QUEUE, self::GENERAL_FOLLOWUP, self::ARCHIVE];
    }

    private function reason(string $intent, string $sentiment, string $priority, string $queue): string
    {
        if ($queue === self::ARCHIVE) {
            return 'intent=spam';
        }

        if ($queue === self::SUPPORT_QUEUE) {
            return $intent === 'complaint'
                ? 'intent=complaint'
                : 'sentiment=' . $sentiment;
        }

        if (in_array($queue, [self::SALES_PRIORITY, self::SALES_QUEUE], true)) {
            return 'intent=' . $intent . ' priority=' . $priority;
        }

        return 'no strong buying signal';
    }
}
