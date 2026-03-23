<?php
// SECURITY: Only allow cron from localhost or with a secret token
// This prevents external users from triggering expensive cron operations
$cron_secret = getenv('BITCHAT_CRON_SECRET');
$is_cli = (php_sapi_name() === 'cli');
$is_localhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
$has_valid_token = !empty($cron_secret) && !empty($_GET['token']) && hash_equals($cron_secret, $_GET['token']);
if (!$is_cli && !$is_localhost && !$has_valid_token) {
    http_response_code(403);
    echo json_encode(["status" => 403, "message" => "Access denied"]);
    exit();
}

// Prevent concurrent cron execution with file lock
$_cron_lock_fp = fopen(__DIR__ . '/assets/logs/cron.lock', 'w');
if (!$_cron_lock_fp || !flock($_cron_lock_fp, LOCK_EX | LOCK_NB)) {
    // Another instance is already running — exit silently
    if ($_cron_lock_fp) fclose($_cron_lock_fp);
    header("Content-type: application/json");
    echo json_encode(["status" => 200, "message" => "skipped (already running)"]);
    exit();
}

// Release lock on fatal errors so the cron isn't stuck
register_shutdown_function(function() {
    global $_cron_lock_fp, $_cron_errors, $_cron_log_file;
    if (isset($_cron_lock_fp) && $_cron_lock_fp) {
        flock($_cron_lock_fp, LOCK_UN);
        fclose($_cron_lock_fp);
    }
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = date('Y-m-d H:i:s') . " | FATAL: {$err['message']} in {$err['file']}:{$err['line']}";
        @file_put_contents(isset($_cron_log_file) ? $_cron_log_file : __DIR__ . '/assets/logs/cron.log', $msg . "\n", FILE_APPEND | LOCK_EX);
    }
});

require_once('assets/init.php');

mysqli_query($sqlConnect, "UPDATE " . T_CONFIG . " SET `value` = '" . time() . "' WHERE `name` = 'cronjob_last_run'");

// Track section errors
$_cron_errors = [];

// Execution logging
$_cron_log = [];
$_cron_start = microtime(true);
$_cron_log_file = __DIR__ . '/assets/logs/cron.log';
function _cron_log_section($name) {
    global $_cron_log, $_cron_start;
    $_cron_log[] = $name;
}
function _cron_log_write() {
    global $_cron_log, $_cron_start, $_cron_log_file;
    $elapsed = round((microtime(true) - $_cron_start) * 1000);
    $line = date('Y-m-d H:i:s') . " | {$elapsed}ms | sections: " . implode(', ', $_cron_log);
    @file_put_contents($_cron_log_file, $line . "\n", FILE_APPEND | LOCK_EX);
    // Keep log file under 500KB
    if (@filesize($_cron_log_file) > 512000) {
        $lines = file($_cron_log_file);
        file_put_contents($_cron_log_file, implode('', array_slice($lines, -200)));
    }
}
// ********** Pro Users **********
_cron_log_section('pro_users');
try {
$users = $db->where('is_pro','1')->where('admin','0')->ArrayBuilder()->get(T_USERS);
foreach ($users as $key => $value) {
	$wo["user"] = Wo_UserData($value['user_id']);
	if ($wo["user"]["pro_type"] == 0) {
		$update      = Wo_UpdateUserData($wo["user"]["id"], array(
            "is_pro" => 0,
            'verified' => 0,
            'pro_' => 1
        ));
        $user_id     = intval($wo["user"]["id"]);
        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_PAGES . " SET `boosted` = '0' WHERE `user_id` = {$user_id}");
        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `boosted` = '0' WHERE `user_id` = {$user_id}");
        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `boosted` = '0' WHERE `page_id` IN (SELECT `page_id` FROM " . T_PAGES . " WHERE `user_id` = {$user_id})");
	}
	else{
		$notify = false;
	    $remove = false;

		if ($wo["pro_packages"][$wo["user"]["pro_type"]]['ex_time'] != 0) {
	        $end_time = $wo["user"]["pro_time"] + $wo["pro_packages"][$wo["user"]["pro_type"]]['ex_time'];
	        if ($end_time > time() && $end_time <= time() + 60 * 60 * 24 * 3) {
	            $notify = true;
	        } elseif ($end_time <= time()) {
	            $remove = true;
	        }
	    }

	    if ($notify == true) {
	        $start     = date_create(date("Y-m-d H:i:s", time()));
	        $end       = date_create(date("Y-m-d H:i:s", $end_time));
	        $diff      = date_diff($end, $start);
	        $left_time = "";
	        if (!empty($diff->d)) {
	            $left_time = $diff->d . " " . $wo["lang"]["day"];
	        } elseif (!empty($diff->h)) {
	            $left_time = $diff->h . " " . $wo["lang"]["hour"];
	        } elseif (!empty($diff->i)) {
	            $left_time = $diff->i . " " . $wo["lang"]["minute"];
	        }
	        $day       = date("d");
	        $month     = date("n");
	        $year      = date("Y");
	        $query_one = " SELECT COUNT(*) AS count FROM " . T_USERS . " WHERE `user_id` = " . $wo["user"]["id"] . " AND DAY(FROM_UNIXTIME(pro_remainder)) = '{$day}' AND MONTH(FROM_UNIXTIME(pro_remainder)) = '{$month}' AND YEAR(FROM_UNIXTIME(pro_remainder)) = '{$year}'";
	        $query     = mysqli_query($sqlConnect, $query_one);
	        if ($query) {
	            $fetched_data = mysqli_fetch_assoc($query);
	            if ($fetched_data["count"] < 1) {
	                $db->insert(T_NOTIFICATION, array(
	                    "recipient_id" => $wo["user"]["id"],
	                    "type" => "remaining",
	                    "text" => str_replace("{{time}}", $left_time, $wo["lang"]["remaining_text"]),
	                    "url" => "index.php?link1=home",
	                    "time" => time()
	                ));
	                $db->where('user_id',$wo["user"]["id"])->update(T_USERS,array('pro_remainder' => time()));
            		cache($wo['user']['user_id'], 'users', 'delete');
	            }
	        }
	    }
	    if ($remove == true) {
	    	if ($wo["user"]['wallet'] >= $wo["pro_packages"][$wo["user"]["pro_type"]]['price']) {
	    		$pro_type = $wo["user"]["pro_type"];
	    		$price = $wo["pro_packages"][$wo["user"]["pro_type"]]['price'];
	    		$update_array = array(
	                'is_pro' => 1,
	                'pro_time' => time(),
	                'pro_' => 1,
	                'pro_type' => $pro_type
	            );
	            if (in_array($pro_type, array_keys($wo['pro_packages'])) && $wo["pro_packages"][$pro_type]['verified_badge'] == 1) {
	                $update_array['verified'] = 1;
	            }
	            $mysqli             = Wo_UpdateUserData($wo['user']['user_id'], $update_array);
	            $notes = json_encode([
	                'pro_type' => $pro_type,
	                'method_type' => 'wallet'
	            ]);

	            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$wo['user']['user_id']}, 'PRO', {$price}, '{$notes}')");
	            $create_payment     = Wo_CreatePayment($pro_type);

	            $points = 0;
	            if ($wo['config']['point_level_system'] == 1) {
	                $points = $price * $wo['config']['dollar_to_point_cost'];
	            }
	            $wallet_amount  = ($wo["user"]['wallet'] - $price);
	            $points_amount  = ($wo['config']['point_allow_withdrawal'] == 0) ? ($wo["user"]['points'] - $points) : $wo["user"]['points'];
	            $query_one      = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `points` = '{$points_amount}', `wallet` = '{$wallet_amount}' WHERE `user_id` = {$wo['user']['user_id']} ");
				cache($wo['user']['user_id'], 'users', 'delete');

	    	}
	    	else{
	    		$update      = Wo_UpdateUserData($wo["user"]["id"], array(
		            "is_pro" => 0,
		            'verified' => 0,
		            'pro_' => 1
		        ));
		        $user_id     = $wo["user"]["id"];
		        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_PAGES . " SET `boosted` = '0' WHERE `user_id` = {$user_id}");
		        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `boosted` = '0' WHERE `user_id` = {$user_id}");
		        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `boosted` = '0' WHERE `page_id` IN (SELECT `page_id` FROM " . T_PAGES . " WHERE `user_id` = {$user_id})");
	    	}

	    }
	}
}
} catch (Exception $e) { $_cron_errors[] = 'pro_users: ' . $e->getMessage(); }
// ********** Pro Users **********

// ********** Stories **********
_cron_log_section('stories');
try {
$expired_stories = $db->where("expire", time(), "<")->get(T_USER_STORY);
if (!empty($expired_stories)) {
	foreach ($expired_stories as $key => $value) {
	    $db->where("story_id", $value->id)->delete(T_STORY_SEEN);
	}
	@mysqli_query($sqlConnect, "DELETE FROM " . T_USER_STORY_MEDIA . " WHERE `expire` < " . time());
	@mysqli_query($sqlConnect, "DELETE FROM " . T_USER_STORY . " WHERE `expire` < " . time());
}
} catch (Exception $e) { $_cron_errors[] = 'stories: ' . $e->getMessage(); }
// ********** Stories **********

// ********** Notifications **********
_cron_log_section('notifications');
try {
if ($wo["config"]["last_notification_delete_run"] <= time() - 60 * 60 * 24) {
    mysqli_multi_query($sqlConnect, " DELETE FROM " . T_NOTIFICATION . " WHERE `time` < " . (time() - 60 * 60 * 24 * 5) . " AND `seen` <> 0");
    mysqli_query($sqlConnect, "UPDATE " . T_CONFIG . " SET `value` = '" . time() . "' WHERE `name` = 'last_notification_delete_run'");
}
} catch (Exception $e) { $_cron_errors[] = 'notifications: ' . $e->getMessage(); }
// ********** Notifications **********

// ********** Nearby User Notifications **********
try {
if (!empty($wo['config']['find_friends']) && $wo['config']['find_friends'] == 1) {
    $admin_user = $db->where('admin','1')->ArrayBuilder()->getOne(T_USERS);
    if (!empty($admin_user)) {
        $wo['user']     = Wo_UserData($admin_user['user_id']);
        $wo['loggedin'] = true;
        $nearby_distance = 10; // km
        if (function_exists('Wo_CheckNearbyProximityNotifications')) {
            Wo_CheckNearbyProximityNotifications($nearby_distance);
        }
    }
}
} catch (Exception $e) { $_cron_errors[] = 'nearby_notifications: ' . $e->getMessage(); }
// ********** Nearby User Notifications **********

// ********** Typing **********
try {
Wo_GetOfflineTyping();
} catch (Exception $e) { $_cron_errors[] = 'typing: ' . $e->getMessage(); }
// ********** Typing **********


// ********** Live **********
_cron_log_section('live_video');
try {
if ($wo['config']['live_video'] == 1) {
	$user = $db->where('admin','1')->ArrayBuilder()->getOne(T_USERS);
	if (!empty($user)) {
		$wo['user'] = Wo_UserData($user['user_id']);
		$wo['loggedin'] = true;
		if ($wo['config']['live_video_save'] == 0) {
	        try {
	            $posts = $db->where('live_time','0','!=')->where('live_time',time() - 11,'<=')->get(T_POSTS);
	            foreach ($posts as $key => $post) {
	                if ($wo['config']['agora_live_video'] == 1 && !empty($wo['config']['agora_app_id']) && !empty($wo['config']['agora_customer_id']) && !empty($wo['config']['agora_customer_certificate']) && $wo['config']['live_video_save'] == 1) {
	                    StopCloudRecording(array('resourceId' => $post->agora_resource_id,
	                                             'sid' => $post->agora_sid,
	                                             'cname' => $post->stream_name,
	                                             'post_id' => $post->post_id,
	                                             'uid' => explode('_', $post->stream_name)[2]));
	                }
	                Wo_DeletePost(Wo_Secure($post->id),'shared');
	            }
	        } catch (Exception $e) {

	        }

	    }
	    else{
	        if ($wo['config']['agora_live_video'] == 1 && $wo['config']['amazone_s3_2'] != 1) {
	            try {
		            $posts = $db->where('live_time','0','!=')->where('live_time',time() - 11,'<=')->get(T_POSTS);
		            foreach ($posts as $key => $post) {
		                Wo_DeletePost(Wo_Secure($post->id),'shared');
		            }
		        } catch (Exception $e) {

		        }
	        }
	    }
	}
}
$posts = $db->where('stream_name','','<>')->where('postFile','')->get(T_POSTS);
if (!empty($posts)) {
    foreach ($posts as $key => $value) {
        if ((!empty($value->agora_resource_id) || !empty($value->agora_sid) || !empty($value->agora_token)) && empty($value->postFile)) {
            Wo_DeletePost($value->id,'shared');
        }
    }
}
} catch (Exception $e) { $_cron_errors[] = 'live_video: ' . $e->getMessage(); }
// ********** Live **********

// ********** Spam Tracking Cleanup **********
_cron_log_section('spam_cleanup');
try {
if (function_exists('Wo_CleanupSpamTracking')) {
    Wo_CleanupSpamTracking();
}
} catch (Exception $e) { $_cron_errors[] = 'spam_cleanup: ' . $e->getMessage(); }
// ********** Spam Tracking Cleanup **********

// ********** Scheduled Posts **********
_cron_log_section('scheduled_posts');
try {
if (!empty($wo['config']['scheduled_posts_enabled']) && $wo['config']['scheduled_posts_enabled'] == '1') {
    if (function_exists('Wo_PublishScheduledPosts')) {
        Wo_PublishScheduledPosts();
    }
}
} catch (Exception $e) { $_cron_errors[] = 'scheduled_posts: ' . $e->getMessage(); }
// ********** Scheduled Posts **********

// ********** Ghost Activity **********
_cron_log_section('ghost_activity');
try {
if (!empty($wo['config']['ghost_activity_enabled']) && $wo['config']['ghost_activity_enabled'] == '1') {
    // Keep ghost accounts appearing online (update lastseen every cron run)
    $ghostIds = $wo['config']['ghost_activity_accounts'] ?? '';
    if (!empty($ghostIds)) {
        $safeIds = implode(',', array_filter(array_map('intval', explode(',', $ghostIds))));
        if (!empty($safeIds)) {
            mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET lastseen = " . time() . " WHERE user_id IN ({$safeIds})");
        }
    }

    if (function_exists('Wo_ProcessGhostQueue')) {
        Wo_ProcessGhostQueue();
    }
    // Zero engagement protection — rescue posts with 0 reactions after 30 min
    if (function_exists('Wo_ProtectZeroEngagement')) {
        Wo_ProtectZeroEngagement();
    }
}
} catch (Exception $e) { $_cron_errors[] = 'ghost_activity: ' . $e->getMessage(); }
// ********** Ghost Activity **********

// ********** TRDC Boost Expiry Cleanup **********
_cron_log_section('trdc_boost_expiry');
try {
@mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET trdc_boosted = 0 WHERE trdc_boosted = 1 AND trdc_boost_expires <= " . time());
} catch (Exception $e) { $_cron_errors[] = 'trdc_boost_expiry: ' . $e->getMessage(); }
// ********** TRDC Boost Expiry Cleanup **********

// ********** TRDC Creator Rewards **********
_cron_log_section('trdc_rewards');
try {
if (!empty($wo['config']['trdc_creator_rewards_enabled']) && $wo['config']['trdc_creator_rewards_enabled'] == '1') {
    if (function_exists('Wo_ProcessMilestoneRewards')) {
        Wo_ProcessMilestoneRewards();
    }
}
} catch (Exception $e) { $_cron_errors[] = 'trdc_rewards: ' . $e->getMessage(); }
// ********** TRDC Creator Rewards **********

// ********** Automated Backup **********
_cron_log_section('auto_backup');
try {
if (!empty($wo['config']['auto_backup_enabled']) && $wo['config']['auto_backup_enabled'] == '1') {
    $lastAutoBackup = !empty($wo['config']['auto_backup_last_run']) ? intval($wo['config']['auto_backup_last_run']) : 0;
    $backupInterval = !empty($wo['config']['auto_backup_interval']) ? intval($wo['config']['auto_backup_interval']) : 86400; // daily default

    if (time() - $lastAutoBackup >= $backupInterval) {
        $backupDir = __DIR__ . '/script_backups/';
        if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

        // DB credentials from config.php (loaded via init.php)
        $dbHost = $sql_db_host;
        $dbName = $sql_db_name;
        $dbUser = $sql_db_user;
        $dbPass = $sql_db_pass;

        $backupFile = $backupDir . 'auto_db_' . date('Y-m-d_His') . '.sql.gz';
        $cmd = sprintf('mysqldump --single-transaction -h %s -u %s -p%s %s 2>/dev/null | gzip > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($backupFile)
        );
        exec($cmd);

        // Empty gzip = ~20 bytes (header only), real dump is at least hundreds of KB
        if (!file_exists($backupFile) || filesize($backupFile) < 100) {
            @file_put_contents(__DIR__ . '/assets/logs/cron.log',
                date('Y-m-d H:i:s') . " | auto_backup FAILED — file empty or missing\n",
                FILE_APPEND | LOCK_EX);
            if (file_exists($backupFile)) @unlink($backupFile);
        }

        // Cleanup old auto backups (keep last 7)
        $autoBackups = glob($backupDir . 'auto_db_*.sql.gz');
        if (count($autoBackups) > 7) {
            usort($autoBackups, function($a, $b) { return filemtime($a) - filemtime($b); });
            $toDelete = array_slice($autoBackups, 0, count($autoBackups) - 7);
            foreach ($toDelete as $old) @unlink($old);
        }

        mysqli_query($sqlConnect, "UPDATE " . T_CONFIG . " SET `value` = '" . time() . "' WHERE `name` = 'auto_backup_last_run'");
    }
}
} catch (Exception $e) { $_cron_errors[] = 'auto_backup: ' . $e->getMessage(); }
// ********** Automated Backup **********

// ********** News Bots Auto-Posting **********
_cron_log_section('news_bots');
try {
require_once(__DIR__ . '/assets/includes/functions_news_bots.php');
bc_run_all_bots($sqlConnect, $wo);
} catch (Exception $e) { $_cron_errors[] = 'news_bots: ' . $e->getMessage(); }
// ********** News Bots Auto-Posting **********

// ********** Crypto Blog Bot **********
_cron_log_section('crypto_blog_bot');
try {
require_once(__DIR__ . '/assets/includes/functions_crypto_blog_bot.php');
bc_run_crypto_blog_bot($sqlConnect, $wo);
} catch (Exception $e) { $_cron_errors[] = 'crypto_blog_bot: ' . $e->getMessage(); }
// ********** Crypto Blog Bot **********

// ********** Session Cleanup **********
_cron_log_section('session_cleanup');
try {
// Remove DB login sessions older than 30 days (matches PHP session gc_maxlifetime)
$sessionCutoff = time() - 2592000;
@mysqli_query($sqlConnect, "DELETE FROM Wo_AppsSessions WHERE time < {$sessionCutoff}");
} catch (Exception $e) { $_cron_errors[] = 'session_cleanup: ' . $e->getMessage(); }
// ********** Session Cleanup **********

// ********** Purge Inactive Accounts **********
_cron_log_section('purge_inactive_accounts');
try {
// Delete accounts with no posts, no avatar, no activity after 30 days (excludes bots & admins)
// Processes up to 50 per cron run to avoid long-running queries
if (function_exists('Wo_PurgeInactiveAccounts')) {
    Wo_PurgeInactiveAccounts(30);
}
} catch (Exception $e) { $_cron_errors[] = 'purge_inactive_accounts: ' . $e->getMessage(); }
// ********** Purge Inactive Accounts **********

// ********** Weekly Digest Email **********
_cron_log_section('weekly_digest');
try {
if (!empty($wo['config']['weekly_digest_enabled']) && $wo['config']['weekly_digest_enabled'] == '1') {
    $digestDay = intval($wo['config']['weekly_digest_day'] ?? 1); // 0=Sun, 1=Mon, ...
    $lastRun = intval($wo['config']['weekly_digest_last_run'] ?? 0);
    $today = intval(date('w')); // Current day of week
    $daysSinceLastRun = ($lastRun > 0) ? floor((time() - $lastRun) / 86400) : 999;

    // Run on configured day, max once per 6 days
    if ($today == $digestDay && $daysSinceLastRun >= 6) {
        require_once(__DIR__ . '/assets/includes/functions_weekly_digest.php');
        $digestCount = Wo_SendWeeklyDigests();
        mysqli_query($sqlConnect, "UPDATE " . T_CONFIG . " SET `value` = '" . time() . "' WHERE `name` = 'weekly_digest_last_run'");
    }
}
} catch (Exception $e) { $_cron_errors[] = 'weekly_digest: ' . $e->getMessage(); }
// ********** Weekly Digest Email **********

_cron_log_write();

// Log any section errors
if (!empty($_cron_errors)) {
    $errMsg = date('Y-m-d H:i:s') . " | ERRORS: " . implode('; ', $_cron_errors);
    @file_put_contents($_cron_log_file, $errMsg . "\n", FILE_APPEND | LOCK_EX);
}

// Release cron lock
if (isset($_cron_lock_fp) && $_cron_lock_fp) {
    flock($_cron_lock_fp, LOCK_UN);
    fclose($_cron_lock_fp);
}

$_cron_status = empty($_cron_errors) ? 200 : 500;
$_cron_message = empty($_cron_errors) ? 'success' : 'completed with errors: ' . implode('; ', $_cron_errors);

header("Content-type: application/json");
echo json_encode(["status" => $_cron_status, "message" => $_cron_message]);
exit();
