-- SQLite schema used by tests/smoke.php.
--
-- Same table and column names as migrations/*.sql (MySQL 8), with types
-- simplified so the whole pipeline - ingestion, scoring, routing, approval and
-- publishing - can be exercised without a MySQL server.  Keep the two in sync.

DROP TABLE IF EXISTS `approval_audit`;
DROP TABLE IF EXISTS `social_posts`;
DROP TABLE IF EXISTS `google_ads_leads`;
DROP TABLE IF EXISTS `leads`;
DROP TABLE IF EXISTS `comment_queue`;
DROP TABLE IF EXISTS `account_workspaces`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `llm_usage`;

CREATE TABLE `leads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `platform` TEXT NOT NULL,
    `comment_id` TEXT NOT NULL UNIQUE,
    `post_id` TEXT,
    `author` TEXT,
    `author_profile_url` TEXT,
    `comment_text` TEXT,
    `intent` TEXT NOT NULL DEFAULT 'none',
    `intent_score` INTEGER NOT NULL DEFAULT 0,
    `sentiment` TEXT NOT NULL DEFAULT 'neutral',
    `sentiment_score` REAL NOT NULL DEFAULT 0,
    `lead_score` INTEGER NOT NULL DEFAULT 0,
    `priority` TEXT NOT NULL DEFAULT 'P3',
    `is_qualified` INTEGER NOT NULL DEFAULT 0,
    `status` TEXT NOT NULL DEFAULT 'new',
    `summary` TEXT,
    `suggested_reply` TEXT,
    `department` TEXT,
    `area` TEXT,
    `follow_up_date` TEXT,
    `appointment_slot` TEXT,
    `routing_queue` TEXT,
    `google_ads_lead_id` INTEGER,
    `krayin_lead_id` INTEGER,
    `created_at` TEXT,
    `updated_at` TEXT
);

CREATE TABLE `comment_queue` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `platform` TEXT NOT NULL,
    `post_id` TEXT,
    `comment_id` TEXT NOT NULL,
    `author` TEXT,
    `author_profile_url` TEXT,
    `text` TEXT NOT NULL,
    `post_context` TEXT,
    `likes` INTEGER NOT NULL DEFAULT 0,
    `author_followers` INTEGER NOT NULL DEFAULT 0,
    `author_verified` INTEGER NOT NULL DEFAULT 0,
    `status` TEXT NOT NULL DEFAULT 'pending',
    `skip_reason` TEXT,
    `attempts` INTEGER NOT NULL DEFAULT 0,
    `last_error` TEXT,
    `lead_id` INTEGER,
    `received_at` TEXT,
    `processed_at` TEXT,
    `created_at` TEXT,
    `updated_at` TEXT,
    UNIQUE (`platform`, `comment_id`)
);

CREATE TABLE `social_posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `lead_id` INTEGER,
    `krayin_lead_id` INTEGER,
    `krayin_activity_id` INTEGER,
    `content` TEXT NOT NULL,
    `target_accounts` TEXT,
    `platforms` TEXT,
    `requested_by` TEXT,
    `status` TEXT NOT NULL DEFAULT 'draft',
    `mixpost_post_uuid` TEXT,
    `mixpost_response` TEXT,
    `rejection_reason` TEXT,
    `scheduled_for` TEXT,
    `approved_by` TEXT,
    `approved_at` TEXT,
    `published_at` TEXT,
    `attempts` INTEGER NOT NULL DEFAULT 0,
    `last_error` TEXT,
    `created_at` TEXT,
    `updated_at` TEXT
);

CREATE TABLE `approval_audit` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `social_post_id` INTEGER NOT NULL,
    `level_name` TEXT NOT NULL,
    `action` TEXT NOT NULL,
    `actor` TEXT,
    `remarks` TEXT,
    `created_at` TEXT
);

CREATE TABLE `google_ads_leads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `lead_id` INTEGER,
    `campaign_id` TEXT,
    `campaign_name` TEXT,
    `name` TEXT,
    `phone` TEXT,
    `email` TEXT,
    `company` TEXT,
    `city` TEXT,
    `gclid` TEXT,
    `raw_payload` TEXT,
    `processed` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT,
    `updated_at` TEXT
);

CREATE TABLE `account_workspaces` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT NOT NULL,
    `mixpost_workspace_uuid` TEXT NOT NULL UNIQUE,
    `krayin_pipeline_id` INTEGER,
    `platform_accounts` TEXT,
    `is_default` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT,
    `updated_at` TEXT
);

CREATE TABLE `audit_log` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `event` TEXT NOT NULL,
    `severity` TEXT NOT NULL DEFAULT 'info',
    `subject_type` TEXT,
    `subject_id` TEXT,
    `message` TEXT,
    `context` TEXT,
    `created_at` TEXT
);

CREATE TABLE `llm_usage` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `provider` TEXT NOT NULL DEFAULT 'deepseek',
    `model` TEXT NOT NULL,
    `purpose` TEXT NOT NULL DEFAULT 'classification',
    `prompt_tokens` INTEGER NOT NULL DEFAULT 0,
    `completion_tokens` INTEGER NOT NULL DEFAULT 0,
    `cached_tokens` INTEGER NOT NULL DEFAULT 0,
    `total_tokens` INTEGER NOT NULL DEFAULT 0,
    `cost_estimate` REAL NOT NULL DEFAULT 0,
    `latency_ms` INTEGER NOT NULL DEFAULT 0,
    `items` INTEGER NOT NULL DEFAULT 0,
    `ok` INTEGER NOT NULL DEFAULT 1,
    `fallback` INTEGER NOT NULL DEFAULT 0,
    `error` TEXT,
    `created_at` TEXT
);
