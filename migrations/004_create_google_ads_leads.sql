-- 004_create_google_ads_leads.sql
-- Raw Google Ads lead-form payloads (lead_form_submission webhook).

CREATE TABLE IF NOT EXISTS `google_ads_leads` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id` BIGINT UNSIGNED DEFAULT NULL,
    `campaign_id` VARCHAR(64) DEFAULT NULL,
    `campaign_name` VARCHAR(191) DEFAULT NULL,
    `name` VARCHAR(191) DEFAULT NULL,
    `phone` VARCHAR(64) DEFAULT NULL,
    `email` VARCHAR(191) DEFAULT NULL,
    `company` VARCHAR(191) DEFAULT NULL,
    `city` VARCHAR(128) DEFAULT NULL,
    `gclid` VARCHAR(191) DEFAULT NULL,
    `raw_payload` JSON DEFAULT NULL,
    `processed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `google_ads_leads_lead_unique` (`lead_id`),
    KEY `google_ads_leads_gclid_index` (`gclid`),
    KEY `google_ads_leads_campaign_index` (`campaign_id`),
    KEY `google_ads_leads_processed_index` (`processed`),
    CONSTRAINT `google_ads_leads_lead_foreign`
        FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
