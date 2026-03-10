-- ============================================================
-- Bitchat TRDC Staking — SQL Migration 006
-- Creates Wo_Staking table for offchain staking records.
-- Safe to run multiple times (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `Wo_Staking` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `stake_type`      ENUM('onchain','offchain') NOT NULL DEFAULT 'offchain',
  `amount`          DECIMAL(18,4) NOT NULL DEFAULT 0,
  `apy_rate`        DECIMAL(5,2) NOT NULL DEFAULT 0,
  `lock_days`       INT UNSIGNED NOT NULL DEFAULT 30,
  `earned_reward`   DECIMAL(18,4) NOT NULL DEFAULT 0,
  `status`          ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `started_at`      INT UNSIGNED NOT NULL,
  `unlock_at`       INT UNSIGNED NOT NULL,
  `completed_at`    INT UNSIGNED DEFAULT NULL,
  `tx_hash`         VARCHAR(255) DEFAULT NULL,
  `created_at`      INT UNSIGNED NOT NULL,
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_unlock` (`unlock_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES ('staking_migration', '6');
