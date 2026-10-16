-- 008_create_llm_usage.sql
-- Token-budget rule 7: model, prompt_tokens, completion_tokens, cached_tokens and
-- a cost estimate for every LLM call.  Also drives the daily budget guard.

CREATE TABLE IF NOT EXISTS `llm_usage` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(32) NOT NULL DEFAULT 'deepseek',
    `model` VARCHAR(64) NOT NULL,
    `purpose` VARCHAR(48) NOT NULL DEFAULT 'classification',
    `prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
    `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
    `cached_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
    `cost_estimate` DECIMAL(12,8) NOT NULL DEFAULT 0.00000000,
    `latency_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `items` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `ok` TINYINT(1) NOT NULL DEFAULT 1,
    `fallback` TINYINT(1) NOT NULL DEFAULT 0,
    `error` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `llm_usage_provider_index` (`provider`),
    KEY `llm_usage_model_index` (`model`),
    KEY `llm_usage_created_index` (`created_at`),
    KEY `llm_usage_purpose_index` (`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
