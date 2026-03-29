-- Migration 010: OAuth2 state and scope columns
-- Adds `state` and `scope` to Wo_Codes for PKCE-style state validation.
-- Adds `scope` to Wo_Tokens for future fine-grained permission enforcement.
-- Run once on production: mysql -u USER -p DBNAME < sql/010_oauth2_state.sql

DROP PROCEDURE IF EXISTS _migration_010;
DELIMITER //
CREATE PROCEDURE _migration_010()
BEGIN
    -- Add `state` to Wo_Codes if missing
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Wo_Codes'
          AND COLUMN_NAME = 'state'
    ) THEN
        ALTER TABLE `Wo_Codes`
            ADD COLUMN `state` VARCHAR(64) NULL DEFAULT NULL
                COMMENT 'OAuth2 state parameter for CSRF protection, stored during authorization';
    END IF;

    -- Add `scope` to Wo_Codes if missing
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Wo_Codes'
          AND COLUMN_NAME = 'scope'
    ) THEN
        ALTER TABLE `Wo_Codes`
            ADD COLUMN `scope` VARCHAR(255) NOT NULL DEFAULT 'basic'
                COMMENT 'OAuth2 scope granted with this authorization code';
    END IF;

    -- Add `scope` to Wo_Tokens if missing
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Wo_Tokens'
          AND COLUMN_NAME = 'scope'
    ) THEN
        ALTER TABLE `Wo_Tokens`
            ADD COLUMN `scope` VARCHAR(255) NOT NULL DEFAULT 'basic'
                COMMENT 'OAuth2 scope granted with this access token';
    END IF;
END //
DELIMITER ;

CALL _migration_010();
DROP PROCEDURE IF EXISTS _migration_010;
