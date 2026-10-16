-- 006_create_account_workspaces.sql
-- Mixpost workspaces (one per brand/team) bound to a Krayin pipeline.

CREATE TABLE IF NOT EXISTS `account_workspaces` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(191) NOT NULL,
    `mixpost_workspace_uuid` VARCHAR(191) NOT NULL,
    `krayin_pipeline_id` BIGINT UNSIGNED DEFAULT NULL,
    `platform_accounts` JSON DEFAULT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `account_workspaces_uuid_unique` (`mixpost_workspace_uuid`),
    KEY `account_workspaces_default_index` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
