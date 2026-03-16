-- 008_deposit_system.sql
-- BSC HD Wallet Deposit System
-- Run: mysql -u root -p database_name < sql/008_deposit_system.sql

-- Deposit addresses: one per user, derived from HD seed
CREATE TABLE IF NOT EXISTS `Wo_Deposit_Addresses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `address` VARCHAR(42) NOT NULL,
    `derivation_index` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    UNIQUE KEY `uk_user` (`user_id`),
    UNIQUE KEY `uk_address` (`address`),
    UNIQUE KEY `uk_derivation_index` (`derivation_index`),
    KEY `idx_address_lookup` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Detected deposits (BNB, USDT, TRDC)
CREATE TABLE IF NOT EXISTS `Wo_Deposits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `address` VARCHAR(42) NOT NULL,
    `token` ENUM('BNB','USDT','TRDC') NOT NULL,
    `amount` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `tx_hash` VARCHAR(66) NOT NULL,
    `log_index` INT NOT NULL DEFAULT 0,
    `block_number` BIGINT UNSIGNED NOT NULL,
    `confirmations` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('detected','confirmed','credited','swept','failed') NOT NULL DEFAULT 'detected',
    `failure_reason` VARCHAR(500) DEFAULT NULL,
    `credited_at` INT UNSIGNED DEFAULT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    `updated_at` INT UNSIGNED NOT NULL,
    UNIQUE KEY `uk_tx_log` (`tx_hash`, `log_index`),
    KEY `idx_user` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_block` (`block_number`),
    KEY `idx_address_token` (`address`, `token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sweep queue: move tokens from user deposit address to hot wallet
CREATE TABLE IF NOT EXISTS `Wo_Sweep_Queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `deposit_id` INT UNSIGNED NOT NULL,
    `address` VARCHAR(42) NOT NULL,
    `token` ENUM('BNB','USDT','TRDC') NOT NULL,
    `amount` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `status` ENUM('pending','gas_sent','sweeping','completed','failed') NOT NULL DEFAULT 'pending',
    `gas_tx_hash` VARCHAR(66) DEFAULT NULL,
    `sweep_tx_hash` VARCHAR(66) DEFAULT NULL,
    `failure_reason` VARCHAR(500) DEFAULT NULL,
    `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` INT UNSIGNED NOT NULL,
    `updated_at` INT UNSIGNED NOT NULL,
    KEY `idx_status` (`status`),
    KEY `idx_deposit` (`deposit_id`),
    KEY `idx_address` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add multi-token balance columns to users
ALTER TABLE `Wo_Users`
    ADD COLUMN `balance_bnb` DECIMAL(18,8) NOT NULL DEFAULT 0 AFTER `wallet`,
    ADD COLUMN `balance_usdt` DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER `balance_bnb`;

-- Deposit system config
INSERT INTO `Wo_Config` (`name`, `value`) VALUES
    ('deposit_enabled', '0'),
    ('deposit_confirmations', '15'),
    ('deposit_hot_wallet', ''),
    ('deposit_min_bnb', '0.001'),
    ('deposit_min_usdt', '1'),
    ('deposit_min_trdc', '10'),
    ('deposit_monitor_last_block', '0')
ON DUPLICATE KEY UPDATE `name` = `name`;
