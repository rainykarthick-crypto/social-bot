-- 003_create_social_posts.sql
-- Outbound posts and their approval state.  Each row is mirrored as a Krayin lead
-- (the "ask CRM -> task -> approve -> post" flow) and published through Mixpost.

CREATE TABLE IF NOT EXISTS `social_posts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id` BIGINT UNSIGNED DEFAULT NULL,
    `krayin_lead_id` BIGINT UNSIGNED DEFAULT NULL,
    `krayin_activity_id` BIGINT UNSIGNED DEFAULT NULL,
    `content` TEXT NOT NULL,
    `target_accounts` JSON DEFAULT NULL,
    `platforms` JSON DEFAULT NULL,
    `requested_by` VARCHAR(191) DEFAULT NULL,
    `status` ENUM('draft','pending_approval','approved','rejected','published','failed') NOT NULL DEFAULT 'draft',
    `mixpost_post_uuid` VARCHAR(191) DEFAULT NULL,
    `mixpost_response` JSON DEFAULT NULL,
    `rejection_reason` VARCHAR(512) DEFAULT NULL,
    `scheduled_for` DATETIME DEFAULT NULL,
    `approved_by` VARCHAR(191) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(512) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `social_posts_lead_index` (`lead_id`),
    KEY `social_posts_krayin_index` (`krayin_lead_id`),
    KEY `social_posts_status_index` (`status`),
    KEY `social_posts_uuid_index` (`mixpost_post_uuid`),
    CONSTRAINT `social_posts_lead_foreign`
        FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
