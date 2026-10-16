<?php

declare(strict_types=1);

namespace SocialBot\Support\Messages;

/**
 * Publish an approved social post to Mixpost.  Dispatched by the approval
 * workflow so the HTTP call can be retried without blocking the approver.
 */
final class PublishSocialPostMessage
{
    public function __construct(
        public readonly int $socialPostId,
    ) {
    }
}
