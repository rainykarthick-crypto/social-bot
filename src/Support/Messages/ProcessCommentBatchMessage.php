<?php

declare(strict_types=1);

namespace SocialBot\Support\Messages;

/**
 * "Process up to N pending comments" job consumed by the worker through the
 * message bus.
 */
final class ProcessCommentBatchMessage
{
    public function __construct(
        public readonly int $limit = 20,
        public readonly bool $dryRun = false,
    ) {
    }
}
