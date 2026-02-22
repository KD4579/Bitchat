-- ============================================================
-- Bitchat TRDC Reward Engine — SQL Migration 003
-- Creates Wo_Rewards_Config table for admin-controlled rewards
-- ============================================================

CREATE TABLE IF NOT EXISTS `Wo_Rewards_Config` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reward_key` VARCHAR(50) NOT NULL UNIQUE,
  `title` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT '',
  `enabled` TINYINT(1) DEFAULT 0,
  `reward_amount` DECIMAL(10,4) DEFAULT 0,
  `cooldown_hours` INT DEFAULT 0,
  `max_per_day` INT DEFAULT 0,
  `min_account_age_days` INT DEFAULT 0,
  `guard_function` VARCHAR(100) DEFAULT NULL,
  `sort_order` INT DEFAULT 0,
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed reward types
INSERT IGNORE INTO `Wo_Rewards_Config`
  (`reward_key`, `title`, `description`, `enabled`, `reward_amount`, `cooldown_hours`, `max_per_day`, `min_account_age_days`, `guard_function`, `sort_order`, `created_at`)
VALUES
  ('post_create',      'Post Created',      'Reward for creating a quality post',           1, 50.0000,  0,  5, 3, 'Wo_RewardGuard_Post',        1, UNIX_TIMESTAMP()),
  ('comment_create',   'Comment Created',   'Reward for posting a meaningful comment',      1, 10.0000,  0, 10, 0, 'Wo_RewardGuard_Comment',     2, UNIX_TIMESTAMP()),
  ('daily_login',      'Daily Login',       'Reward for logging in each day',               1, 20.0000, 20,  1, 1, NULL,                         3, UNIX_TIMESTAMP()),
  ('like_received',    'Like Received',     'Reward when someone reacts to your post',      1,  5.0000,  0, 20, 0, NULL,                         4, UNIX_TIMESTAMP()),
  ('post_share',       'Post Shared',       'Reward for sharing a post',                    1, 15.0000,  0,  5, 0, NULL,                         5, UNIX_TIMESTAMP()),
  ('first_post',       'First Post Bonus',  'One-time bonus for your first post',           1, 100.0000, 0,  1, 0, 'Wo_RewardGuard_FirstAction', 6, UNIX_TIMESTAMP()),
  ('referral_signup',  'Referral Signup',   'Reward when your referral registers',          1,  5.0000,  0,  5, 0, 'Wo_RewardGuard_Referral',    7, UNIX_TIMESTAMP()),
  ('milestone_100',    '100 Reactions',     'Post reached 100 reactions milestone',         1,  0.5000,  0,  0, 0, 'Wo_RewardGuard_Milestone',   8, UNIX_TIMESTAMP()),
  ('milestone_500',    '500 Reactions',     'Post reached 500 reactions milestone',         1,  2.0000,  0,  0, 0, 'Wo_RewardGuard_Milestone',   9, UNIX_TIMESTAMP()),
  ('milestone_1000',   '1000 Reactions',    'Post reached 1000 reactions milestone',        1,  5.0000,  0,  0, 0, 'Wo_RewardGuard_Milestone',  10, UNIX_TIMESTAMP()),
  ('first_video',      'First Video Post',  'One-time bonus for your first video post',     1,  0.2500,  0,  1, 0, NULL,                        11, UNIX_TIMESTAMP()),
  ('verify_email',     'Email Verified',    'Reward for verifying your email address',      1, 50.0000,  0,  1, 0, NULL,                        12, UNIX_TIMESTAMP()),
  ('complete_profile', 'Profile Completed', 'Reward for completing your profile',           1, 75.0000,  0,  1, 0, NULL,                        13, UNIX_TIMESTAMP());

-- Track engine version in config
INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES ('reward_engine_version', '1');
