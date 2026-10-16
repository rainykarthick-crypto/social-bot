-- 005_create_approval_audit.sql
-- Every approval transition, one row per (post, level, action).

CREATE TABLE IF NOT EXISTS `approval_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `social_post_id` BIGINT UNSIGNED NOT NULL,
    `level_name` VARCHAR(64) NOT NULL,
    `action` ENUM('submitted','approved','rejected','delegated','sla_breached') NOT NULL,
    `actor` VARCHAR(191) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `approval_audit_post_index` (`social_post_id`),
    KEY `approval_audit_action_index` (`action`),
    KEY `approval_audit_level_index` (`level_name`),
    CONSTRAINT `approval_audit_post_foreign`
        FOREIGN KEY (`social_post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
