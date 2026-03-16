-- TRDC On-Chain Withdrawal Queue
-- Tracks withdrawal requests from user wallet balance to their BSC wallet address

CREATE TABLE IF NOT EXISTS `Wo_TRDC_Withdrawals` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `amount`          DECIMAL(18,4) NOT NULL,
  `fee`             DECIMAL(18,4) NOT NULL DEFAULT 0,
  `net_amount`      DECIMAL(18,4) NOT NULL,
  `wallet_address`  VARCHAR(42) NOT NULL,
  `status`          ENUM('pending','approved','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `admin_note`      VARCHAR(500) DEFAULT NULL,
  `approved_by`     INT UNSIGNED DEFAULT NULL,
  `approved_at`     INT UNSIGNED DEFAULT NULL,
  `tx_hash`         VARCHAR(66) DEFAULT NULL,
  `gas_used`        BIGINT UNSIGNED DEFAULT NULL,
  `gas_cost_bnb`    DECIMAL(18,10) DEFAULT NULL,
  `failure_reason`  VARCHAR(500) DEFAULT NULL,
  `retry_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      INT UNSIGNED NOT NULL,
  `processed_at`    INT UNSIGNED DEFAULT NULL,
  `completed_at`    INT UNSIGNED DEFAULT NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Withdrawal config keys
INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES
  ('trdc_withdrawal_enabled', '0'),
  ('trdc_withdrawal_min', '100'),
  ('trdc_withdrawal_max', '50000'),
  ('trdc_withdrawal_fee_percent', '2'),
  ('trdc_withdrawal_daily_limit', '100000'),
  ('trdc_withdrawal_cooldown_hours', '24'),
  ('trdc_withdrawal_max_pending', '1');
