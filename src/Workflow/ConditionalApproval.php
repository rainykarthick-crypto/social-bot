<?php

declare(strict_types=1);

namespace SocialBot\Workflow;

/**
 * Decides which sign-offs a post needs before it can be published.
 *
 *   always                                   -> Social Media Manager
 *   /\b(price|cost|\$|₹|discount|offer)\b/i  -> + Finance Manager
 *   /\b(competitor|vs|alternative)\b/i       -> + Legal
 *   always                                   -> Brand Manager (final sign-off)
 *
 * Levels are derived from the content, never stored, so the approval chain
 * cannot drift out of sync with the audit trail.
 */
final class ConditionalApproval
{
    public const LEVEL_MANAGER = 'Social Media Manager';

    public const LEVEL_FINANCE = 'Finance Manager';

    public const LEVEL_LEGAL = 'Legal';

    public const LEVEL_BRAND = 'Brand Manager';

    /** Exactly the pattern from the specification. */
    private const FINANCE_PATTERN = '/\b(price|cost|\$|₹|discount|offer)\b/i';

    /** Hardening: the spec pattern misses "$500" because of the \b before the symbol. */
    private const FINANCE_CURRENCY_PATTERN = '/[$₹€£]\s?\d|\b\d+\s?(?:usd|inr|rs\.?|rupees|dollars)\b/i';

    private const LEGAL_PATTERN = '/\b(competitor|vs|alternative)\b/i';

    /**
     * @return array<int, string> ordered approval chain
     */
    public function determineLevels(string $content): array
    {
        $levels = [self::LEVEL_MANAGER];

        if ($this->requiresFinance($content)) {
            $levels[] = self::LEVEL_FINANCE;
        }

        if ($this->requiresLegal($content)) {
            $levels[] = self::LEVEL_LEGAL;
        }

        $levels[] = self::LEVEL_BRAND;

        return $levels;
    }

    public function requiresFinance(string $content): bool
    {
        return preg_match(self::FINANCE_PATTERN, $content) === 1
            || preg_match(self::FINANCE_CURRENCY_PATTERN, $content) === 1;
    }

    public function requiresLegal(string $content): bool
    {
        return preg_match(self::LEGAL_PATTERN, $content) === 1;
    }

    /**
     * Explain the chain (shown in the dashboard and stored in the audit trail).
     *
     * @return array<int, array{level:string, reason:string}>
     */
    public function explain(string $content): array
    {
        $chain = [
            ['level' => self::LEVEL_MANAGER, 'reason' => 'always required'],
        ];

        if ($this->requiresFinance($content)) {
            $chain[] = ['level' => self::LEVEL_FINANCE, 'reason' => 'content mentions price/cost/discount/offer'];
        }

        if ($this->requiresLegal($content)) {
            $chain[] = ['level' => self::LEVEL_LEGAL, 'reason' => 'content mentions a competitor or comparison'];
        }

        $chain[] = ['level' => self::LEVEL_BRAND, 'reason' => 'final brand sign-off'];

        return $chain;
    }

    /**
     * First level that has not approved yet.
     *
     * @param array<int, string> $requiredLevels
     * @param array<int, string> $approvedLevels
     */
    public function nextLevel(array $requiredLevels, array $approvedLevels): ?string
    {
        foreach ($requiredLevels as $level) {
            if (!in_array($level, $approvedLevels, true)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $requiredLevels
     * @param array<int, string> $approvedLevels
     */
    public function isComplete(array $requiredLevels, array $approvedLevels): bool
    {
        return $this->nextLevel($requiredLevels, $approvedLevels) === null;
    }

    /**
     * @param array<int, string> $requiredLevels
     * @param array<int, string> $approvedLevels
     * @return array{required:array<int, string>, approved:array<int, string>, pending:array<int, string>, next:?string, complete:bool, percent:int}
     */
    public function progress(array $requiredLevels, array $approvedLevels): array
    {
        $pending = array_values(array_diff($requiredLevels, $approvedLevels));
        $approved = array_values(array_intersect($requiredLevels, $approvedLevels));
        $total = max(1, count($requiredLevels));

        return [
            'required' => array_values($requiredLevels),
            'approved' => $approved,
            'pending' => $pending,
            'next' => $this->nextLevel($requiredLevels, $approvedLevels),
            'complete' => $pending === [],
            'percent' => (int) round((count($approved) / $total) * 100),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function allLevels(): array
    {
        return [self::LEVEL_MANAGER, self::LEVEL_FINANCE, self::LEVEL_LEGAL, self::LEVEL_BRAND];
    }
}
