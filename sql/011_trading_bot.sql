-- Migration 011: trading bot trade history
-- Run once: mysql -u USER -p DBNAME < sql/011_trading_bot.sql

CREATE TABLE IF NOT EXISTS `Wo_Bot_Trades` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `strategy` VARCHAR(24) NOT NULL,
    `direction` VARCHAR(40) NOT NULL,
    `token_in` VARCHAR(16) NOT NULL,
    `token_out` VARCHAR(16) NOT NULL,
    `amount_in` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `amount_out` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `price_usd` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `trade_value_usd` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `gas_used` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `gas_cost_bnb` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `tx_hash` CHAR(66) NOT NULL,
    `pnl_usd` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `daily_pnl_usd` DECIMAL(36,18) NOT NULL DEFAULT 0,
    `pool_tvl` DECIMAL(36,8) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bot_trades_created_at` (`created_at`),
    KEY `idx_bot_trades_strategy_created` (`strategy`, `created_at`),
    KEY `idx_bot_trades_tx_hash` (`tx_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
