-- Migration 009: Ecosystem columns
-- Adds show_on_leaderboard opt-in flag to Wo_Users.
-- Run once on production: mysql -u USER -p DBNAME < sql/009_ecosystem_columns.sql

ALTER TABLE `Wo_Users`
    ADD COLUMN IF NOT EXISTS `show_on_leaderboard` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = user consents to appear on public TRDC leaderboard (default opt-in)',
    ADD COLUMN IF NOT EXISTS `tradex24_user_id` VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'Linked Tradex24 account identifier (set by user in social-links settings)';

-- Index speeds up the leaderboard query in api/ecosystem/stats.php and sources/leaderboard.php
CREATE INDEX IF NOT EXISTS `idx_users_leaderboard`
    ON `Wo_Users` (`show_on_leaderboard`, `activated`);
