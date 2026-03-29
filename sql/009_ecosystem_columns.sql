-- Migration 009: Ecosystem columns
-- Adds show_on_leaderboard opt-in flag to Wo_Users.
-- Run once on production: mysql -u USER -p DBNAME < sql/009_ecosystem_columns.sql
-- Note: uses stored procedures for MySQL 8.0 compatibility (no ADD COLUMN IF NOT EXISTS)

DROP PROCEDURE IF EXISTS _migration_009;
DELIMITER //
CREATE PROCEDURE _migration_009()
BEGIN
    -- Add show_on_leaderboard if missing
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Wo_Users'
          AND COLUMN_NAME = 'show_on_leaderboard'
    ) THEN
        ALTER TABLE `Wo_Users`
            ADD COLUMN `show_on_leaderboard` TINYINT(1) NOT NULL DEFAULT 1
                COMMENT '1 = user consents to appear on public TRDC leaderboard (default opt-in)';
    END IF;

    -- Add tradex24_user_id if missing
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Wo_Users'
          AND COLUMN_NAME = 'tradex24_user_id'
    ) THEN
        ALTER TABLE `Wo_Users`
            ADD COLUMN `tradex24_user_id` VARCHAR(64) NULL DEFAULT NULL
                COMMENT 'Linked Tradex24 account identifier (set by user in social-links settings)';
    END IF;

    -- Add index if missing (column is active, not activated)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Wo_Users'
          AND INDEX_NAME = 'idx_users_leaderboard'
    ) THEN
        ALTER TABLE `Wo_Users`
            ADD INDEX `idx_users_leaderboard` (`show_on_leaderboard`, `active`);
    END IF;
END //
DELIMITER ;

CALL _migration_009();
DROP PROCEDURE IF EXISTS _migration_009;
