<?php
/**
 * One-time migration for mail campaigns. CLI only:
 *   cd <docroot> && php tools/email-templates/migrate_campaigns.php
 * Idempotent: safe to run again.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__, 2));
require 'config.php';
$c = new mysqli($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
$c->set_charset('utf8mb4');

$steps = array(
    'campaigns table' => "CREATE TABLE IF NOT EXISTS `Wo_Mail_Campaigns` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `subject` varchar(255) NOT NULL DEFAULT '',
        `preheader` varchar(255) NOT NULL DEFAULT '',
        `html` mediumtext NOT NULL,
        `template` varchar(64) NOT NULL DEFAULT '',
        `audience` varchar(32) NOT NULL DEFAULT 'clean',
        `status` varchar(16) NOT NULL DEFAULT 'sending',
        `status_reason` varchar(255) NOT NULL DEFAULT '',
        `bonus_trdc` decimal(12,2) NOT NULL DEFAULT '0.00',
        `bonus_until` int unsigned NOT NULL DEFAULT '0',
        `total` int unsigned NOT NULL DEFAULT '0',
        `sent` int unsigned NOT NULL DEFAULT '0',
        `failed` int unsigned NOT NULL DEFAULT '0',
        `created_by` int unsigned NOT NULL DEFAULT '0',
        `created` int unsigned NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'queue table' => "CREATE TABLE IF NOT EXISTS `Wo_Mail_Campaign_Queue` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `campaign_id` int unsigned NOT NULL,
        `user_id` int unsigned NOT NULL,
        `email` varchar(191) NOT NULL,
        `priority` bigint NOT NULL DEFAULT '0',
        `status` varchar(10) NOT NULL DEFAULT 'pending',
        `sent_at` int unsigned NOT NULL DEFAULT '0',
        `deferrals` smallint unsigned NOT NULL DEFAULT '0',
        `error` varchar(255) NOT NULL DEFAULT '',
        `returned_at` int unsigned NOT NULL DEFAULT '0',
        `bonus_paid` tinyint(1) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        UNIQUE KEY `campaign_user` (`campaign_id`,`user_id`),
        KEY `campaign_status_prio` (`campaign_id`,`status`,`priority`),
        KEY `user_id` (`user_id`),
        KEY `email` (`email`),
        KEY `sent_at` (`sent_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'suppression table' => "CREATE TABLE IF NOT EXISTS `Wo_Mail_Suppression` (
        `email` varchar(191) NOT NULL,
        `reason` varchar(20) NOT NULL DEFAULT '',
        `detail` varchar(255) NOT NULL DEFAULT '',
        `time` int unsigned NOT NULL DEFAULT '0',
        PRIMARY KEY (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
);
foreach ($steps as $name => $sql) {
    echo str_pad($name, 22) . ($c->query($sql) ? "ok\n" : "FAILED: " . $c->error . "\n");
}

// Wo_Emails.time: lets the notification queue skip stale rows
$col = $c->query("SHOW COLUMNS FROM `Wo_Emails` LIKE 'time'");
if ($col && $col->num_rows === 0) {
    echo str_pad('Wo_Emails.time', 22) . ($c->query("ALTER TABLE `Wo_Emails` ADD `time` int unsigned NOT NULL DEFAULT '0', ADD KEY `user_time` (`user_id`,`time`)") ? "ok\n" : "FAILED: " . $c->error . "\n");
} else {
    echo str_pad('Wo_Emails.time', 22) . "exists\n";
}

// Default campaign settings (only inserted when missing)
$defaults = array(
    'campaign_from_email'   => 'hello@bitchat.social',
    'campaign_from_name'    => 'Bitchat',
    'campaign_reply_to'     => 'support@bitchat.social',
    'campaign_hour_cap'     => '150',
    'campaign_daily_max'    => '3000',
    'campaign_window_start' => '8',
    'campaign_window_end'   => '22',
    'campaign_warmup_start' => '0',
    'campaign_unsub_secret' => bin2hex(random_bytes(32)),
);
foreach ($defaults as $k => $v) {
    $st = $c->prepare("INSERT INTO Wo_Config (`name`, `value`) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Wo_Config WHERE `name` = ?)");
    $st->bind_param('sss', $k, $v, $k);
    $st->execute();
    echo str_pad("config $k", 34) . ($st->affected_rows ? "added\n" : "kept\n");
}
echo "Done. Clear the config cache (redis key site_config) if values look stale.\n";
