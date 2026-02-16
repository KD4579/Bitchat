-- Bitchat Feed Algorithm & Growth Features Migration
-- Run this ONCE on the database before enabling features.
-- All features default to disabled (0) — enable via admin panel.

-- =============================================
-- Phase 1: Feed Algorithm + Anti-Spam
-- =============================================

CREATE TABLE IF NOT EXISTS `Wo_Spam_Tracking` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `domain` VARCHAR(255) DEFAULT NULL,
  `text_hash` VARCHAR(64) DEFAULT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  KEY `idx_user_domain` (`user_id`, `domain`, `created_at`),
  KEY `idx_user_hash` (`user_id`, `text_hash`, `created_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Performance indexes for scoring queries
ALTER TABLE `Wo_Reactions` ADD INDEX IF NOT EXISTS `idx_post_id_score` (`post_id`);
ALTER TABLE `Wo_Comments` ADD INDEX IF NOT EXISTS `idx_post_id_score` (`post_id`);
ALTER TABLE `Wo_Posts` ADD INDEX IF NOT EXISTS `idx_time_boosted` (`time`, `boosted`);
ALTER TABLE `Wo_Posts` ADD INDEX IF NOT EXISTS `idx_user_time` (`user_id`, `time`);

-- Feed algorithm config rows (all disabled by default)
INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES
  ('feed_algorithm_enabled', '0'),
  ('feed_weights', '{"engagement":1.0,"comments":2.0,"shares":1.5,"media_bonus":2.0,"freshness_decay":0.95,"pro_boost":3.0,"spam_penalty":5.0,"link_penalty":2.0,"frequency_penalty":3.0}'),
  ('feed_candidate_pool', '50'),
  ('feed_max_same_user', '2'),
  ('feed_link_penalty_threshold', '3'),
  ('feed_spam_window_hours', '24');

-- =============================================
-- Phase 2: Scheduled Posting
-- =============================================

CREATE TABLE IF NOT EXISTS `Wo_Scheduled_Posts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `post_data` TEXT NOT NULL,
  `publish_at` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','published','failed','cancelled') DEFAULT 'pending',
  `published_post_id` BIGINT UNSIGNED DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  KEY `idx_publish_status` (`status`, `publish_at`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES
  ('scheduled_posts_enabled', '0');

-- =============================================
-- Phase 3: Ghost Activity
-- =============================================

CREATE TABLE IF NOT EXISTS `Wo_Ghost_Queue` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` BIGINT UNSIGNED NOT NULL,
  `action_type` ENUM('reaction') DEFAULT 'reaction',
  `action_data` VARCHAR(50) DEFAULT '1',
  `execute_at` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','executed','cancelled') DEFAULT 'pending',
  `executed_at` INT UNSIGNED DEFAULT NULL,
  KEY `idx_execute_status` (`status`, `execute_at`),
  KEY `idx_post_id` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES
  ('ghost_activity_enabled', '0'),
  ('ghost_activity_accounts', ''),
  ('ghost_activity_min_delay', '1800'),
  ('ghost_activity_max_delay', '7200');

-- =============================================
-- Phase 4: Creator Mode
-- =============================================

-- Add is_creator column (safe: IF NOT EXISTS via procedure)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Wo_Users' AND COLUMN_NAME = 'is_creator');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `Wo_Users` ADD COLUMN `is_creator` TINYINT(1) UNSIGNED DEFAULT 0 AFTER `is_pro`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES
  ('creator_mode_enabled', '0');

-- =============================================
-- Phase 5: TRDC Rewards
-- =============================================

CREATE TABLE IF NOT EXISTS `Wo_TRDC_Rewards` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,4) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `milestone_type` VARCHAR(50) NOT NULL,
  `post_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  UNIQUE KEY `idx_unique_milestone` (`user_id`, `milestone_type`, `post_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES
  ('trdc_creator_rewards_enabled', '0'),
  ('trdc_reward_milestones', '{"post_likes_100":0.5,"post_likes_500":2.0,"post_likes_1000":5.0,"first_video_post":0.25}');
