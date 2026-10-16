-- 007_create_audit_log.sql
-- Platform audit trail written by audit.php (and any module that needs to leave
-- a durable trace of an external-system interaction).

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event` VARCHAR(96) NOT NULL,
    `severity` ENUM('debug','info','notice','warning','error','critical') NOT NULL DEFAULT 'info',
    `subject_type` VARCHAR(64) DEFAULT NULL,
    `subject_id` VARCHAR(64) DEFAULT NULL,
    `message` VARCHAR(512) DEFAULT NULL,
    `context` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `audit_log_event_index` (`event`),
    KEY `audit_log_severity_index` (`severity`),
    KEY `audit_log_subject_index` (`subject_type`, `subject_id`),
    KEY `audit_log_created_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
