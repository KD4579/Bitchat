-- Bitchat Phase 4: Retention & Discovery
-- Run this ONCE on the database.

-- =============================================
-- 1. New User Onboarding
-- =============================================

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Wo_Users' AND COLUMN_NAME = 'onboarding_completed');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `Wo_Users` ADD COLUMN `onboarding_completed` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Mark all existing users as onboarded (only new signups get the wizard)
UPDATE `Wo_Users` SET `onboarding_completed` = 1 WHERE `onboarding_completed` = 0 AND `user_id` > 0;

-- =============================================
-- 2. Post View Tracking
-- =============================================

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Wo_Posts' AND COLUMN_NAME = 'post_views');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `Wo_Posts` ADD COLUMN `post_views` INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
