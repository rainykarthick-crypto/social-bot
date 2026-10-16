-- 001_create_leads.sql
-- Qualified social/paid leads, one row per ingested comment or form fill.

CREATE TABLE IF NOT EXISTS `leads` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `platform` VARCHAR(32) NOT NULL,
    `comment_id` VARCHAR(191) NOT NULL,
    `post_id` VARCHAR(191) DEFAULT NULL,
    `author` VARCHAR(191) DEFAULT NULL,
    `author_profile_url` VARCHAR(512) DEFAULT NULL,
    `comment_text` TEXT DEFAULT NULL,
    `intent` VARCHAR(32) NOT NULL DEFAULT 'none',
    `intent_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `sentiment` VARCHAR(16) NOT NULL DEFAULT 'neutral',
    `sentiment_score` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    `lead_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `priority` ENUM('P1','P2','P3') NOT NULL DEFAULT 'P3',
    `is_qualified` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('new','contacted','follow_up','booked','not_interested','completed') NOT NULL DEFAULT 'new',
    `summary` VARCHAR(512) DEFAULT NULL,
    `suggested_reply` TEXT DEFAULT NULL,
    `department` VARCHAR(64) DEFAULT NULL,
    `area` VARCHAR(128) DEFAULT NULL,
    `follow_up_date` DATE DEFAULT NULL,
    `appointment_slot` DATETIME DEFAULT NULL,
    -- extension: SmartRouter destination ("sales_priority", "support_queue", ...)
    `routing_queue` VARCHAR(64) DEFAULT NULL,
    -- extension: set when the lead originated from a Google Ads lead form
    `google_ads_lead_id` BIGINT UNSIGNED DEFAULT NULL,
    `krayin_lead_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `leads_comment_id_unique` (`comment_id`),
    KEY `leads_platform_index` (`platform`),
    KEY `leads_priority_index` (`priority`),
    KEY `leads_status_index` (`status`),
    KEY `leads_qualified_index` (`is_qualified`),
    KEY `leads_score_index` (`lead_score`),
    KEY `leads_krayin_index` (`krayin_lead_id`),
    KEY `leads_routing_index` (`routing_queue`),
    KEY `leads_created_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
