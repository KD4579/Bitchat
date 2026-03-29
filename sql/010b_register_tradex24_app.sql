-- Migration 010b: Register Tradex24 as an OAuth2 client app in Wo_Apps
-- Run once on production: mysql -u USER -p DBNAME < sql/010b_register_tradex24_app.sql
--
-- client_id:     tradex24_bc7f2a91e4d8
-- client_secret: tx24_sk_a3f8c1d9e2b47650f8a1c3d9e2b4765a
-- callback:      https://trade.bitchat.live/api/auth/login/bitchat-callback
--
-- IMPORTANT: Store the client_secret securely in Tradex24 env as BITCHAT_APP_SECRET.

INSERT INTO `Wo_Apps`
    (`app_user_id`, `app_name`, `app_id`, `app_secret`, `app_website_url`,
     `app_callback_url`, `app_description`, `active`)
SELECT
    (SELECT `user_id` FROM `Wo_Users` WHERE `admin_permissions` = '1' LIMIT 1),
    'Tradex24',
    'tradex24_bc7f2a91e4d8',
    'tx24_sk_a3f8c1d9e2b47650f8a1c3d9e2b4765a',
    'https://trade.bitchat.live',
    'https://trade.bitchat.live/api/auth/login/bitchat-callback',
    'Tradex24 — crypto trading platform powered by Bitchat SSO',
    '1'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `Wo_Apps` WHERE `app_id` = 'tradex24_bc7f2a91e4d8'
);
