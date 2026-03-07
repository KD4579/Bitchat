-- ============================================================
-- Bitchat TRDC Reward Engine — SQL Migration 005
-- Creates Wo_TRDC_Rewards table (if missing) and adds indexes
-- for cron performance and admin queries.
-- Safe to run multiple times (CREATE TABLE uses IF NOT EXISTS;
-- ADD INDEX will error if index already exists — that is fine).
-- ============================================================

-- Create table if it was never migrated on this instance
CREATE TABLE IF NOT EXISTS `Wo_TRDC_Rewards` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `amount`         DECIMAL(10,4) NOT NULL DEFAULT 0,
  `reason`         VARCHAR(255) DEFAULT '',
  `milestone_type` VARCHAR(100) NOT NULL DEFAULT '',
  `post_id`        INT UNSIGNED DEFAULT NULL,
  `created_at`     INT UNSIGNED NOT NULL,
  UNIQUE KEY `uq_user_milestone_post` (`user_id`, `milestone_type`, `post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Performance indexes for cron queries (cooldown, daily cap lookups)
-- Note: these will error if indexes already exist — that is safe to ignore.
ALTER TABLE `Wo_TRDC_Rewards`
  ADD INDEX `idx_user_milestone` (`user_id`, `milestone_type`),
  ADD INDEX `idx_user_created`   (`user_id`, `created_at`),
  ADD INDEX `idx_created_at`     (`created_at`);

-- Track migration version
INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES ('reward_engine_migration', '5');
