-- 002_create_comment_queue.sql
-- Raw ingestion buffer.  Webhooks only INSERT here (status='pending') and return
-- 200 immediately; worker.php drains the queue asynchronously.

CREATE TABLE IF NOT EXISTS `comment_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `platform` VARCHAR(32) NOT NULL,
    `post_id` VARCHAR(191) DEFAULT NULL,
    `comment_id` VARCHAR(191) NOT NULL,
    `author` VARCHAR(191) DEFAULT NULL,
    `author_profile_url` VARCHAR(512) DEFAULT NULL,
    `text` TEXT NOT NULL,
    `post_context` TEXT DEFAULT NULL,
    `likes` INT UNSIGNED NOT NULL DEFAULT 0,
    `author_followers` INT UNSIGNED NOT NULL DEFAULT 0,
    `author_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('pending','processing','processed','skipped','failed') NOT NULL DEFAULT 'pending',
    -- extension: distinguishes "no LLM needed" from infrastructure failures
    `skip_reason` VARCHAR(64) DEFAULT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(512) DEFAULT NULL,
    `lead_id` BIGINT UNSIGNED DEFAULT NULL,
    `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `comment_queue_comment_unique` (`platform`, `comment_id`),
    KEY `comment_queue_status_index` (`status`),
    KEY `comment_queue_platform_index` (`platform`),
    KEY `comment_queue_created_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
