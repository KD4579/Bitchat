<?php
/**
 * Bitchat Home Page Crash Diagnostic
 * URL: https://bitchat.live/bc-diagnose.php?key=bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec&step=N
 */
define('ACCESS_KEY', 'bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec');
if (($_GET['key'] ?? '') !== ACCESS_KEY) { http_response_code(403); die('403'); }

// Setup
chdir(__DIR__);
$step = intval($_GET['step'] ?? 0);
$trace_file = '/tmp/bc-trace-' . date('Ymd') . '.txt';

header('Content-Type: text/plain');

function trace($msg) {
    global $trace_file;
    $line = date('[H:i:s] ') . $msg . "\n";
    file_put_contents($trace_file, $line, FILE_APPEND);
    echo $line;
    flush();
}

trace("=== STEP $step ===");
trace("PHP " . phpversion() . " | mem=" . memory_get_usage(true)/1024/1024 . "MB");

// Load WoWonder
trace("Loading init.php...");
require_once 'assets/includes/init.php';
trace("init.php OK | mem=" . memory_get_usage(true)/1024/1024 . "MB");

// Simulate logged-in user (use session or hardcode)
trace("Loading user data...");
$wo['loggedin'] = true;
$wo['page'] = 'home';
if (!empty($wo['user']['user_id'])) {
    trace("User from session: user_id=" . $wo['user']['user_id']);
} else {
    // Find a real user_id from DB
    $uq = mysqli_query($sqlConnect, "SELECT user_id FROM Wo_Users WHERE active='1' LIMIT 1");
    if ($uq) {
        $urow = mysqli_fetch_assoc($uq);
        $wo['user'] = Wo_UserData($urow['user_id']);
        trace("Test user loaded: user_id=" . $wo['user']['user_id'] . " name=" . $wo['user']['name']);
    } else {
        trace("CANNOT load user from DB");
        die("ERROR: No user found");
    }
}

if ($step <= 0) {
    trace("--- STEP 0: Basic checks ---");
    trace("sqlConnect: " . (isset($sqlConnect) ? 'OK' : 'MISSING'));
    trace("wo[config][user_status]: " . ($wo['config']['user_status'] ?? 'not set'));
    trace("wo[config][afternoon_system]: " . ($wo['config']['afternoon_system'] ?? 'not set'));
    trace("PASS: Use ?step=1 to test announcement");
    die();
}

if ($step <= 1) {
    trace("--- STEP 1: Announcement check ---");
    $result = Wo_IsThereAnnouncement();
    trace("Wo_IsThereAnnouncement returned: " . var_export($result, true));
    if ($result === true) {
        $ann = Wo_GetHomeAnnouncements();
        trace("Wo_GetHomeAnnouncements OK: id=" . ($ann['id'] ?? 'N/A'));
    }
    trace("STEP 1 PASS");
}

if ($step <= 2) {
    trace("--- STEP 2: Hero banner load ---");
    $bc_hero_file = __DIR__ . '/themes/wondertag/layout/global/hero-banner.phtml';
    trace("hero-banner.phtml exists: " . (file_exists($bc_hero_file) ? 'YES' : 'NO'));
    if (file_exists($bc_hero_file)) {
        $out = Wo_LoadPage('global/hero-banner');
        trace("hero-banner output: " . strlen($out) . " bytes");
    }
    trace("STEP 2 PASS");
}

if ($step <= 3) {
    trace("--- STEP 3: Creator strip load ---");
    $bc_strip_file = __DIR__ . '/themes/wondertag/layout/global/creator-strip.phtml';
    trace("creator-strip.phtml exists: " . (file_exists($bc_strip_file) ? 'YES' : 'NO'));
    if (file_exists($bc_strip_file)) {
        $out = Wo_LoadPage('global/creator-strip');
        trace("creator-strip output: " . strlen($out) . " bytes");
    }
    trace("STEP 3 PASS");
}

if ($step <= 4) {
    trace("--- STEP 4: functions_growth.php + Wo_GetActionPrompt ---");
    require_once('assets/includes/functions_growth.php');
    trace("functions_growth.php loaded OK");
    $ap = Wo_GetActionPrompt($wo['user']['user_id'], $wo['user']['name']);
    trace("Wo_GetActionPrompt returned: type=" . ($ap['type'] ?? 'N/A'));
    trace("STEP 4 PASS");
}

if ($step <= 5) {
    trace("--- STEP 5: Stories - Wo_GetFriendsStatus ---");
    if ($wo['config']['user_status'] == 1) {
        $get_user_status = Wo_GetFriendsStatus(array('limit' => 6));
        trace("Wo_GetFriendsStatus returned: " . count($get_user_status ?? []) . " items");
        // Test loading one story if available
        if (!empty($get_user_status)) {
            $wo['user_status'] = reset($get_user_status);
            $out = Wo_LoadPage('home/lightbox-user-status');
            trace("lightbox-user-status: " . strlen($out) . " bytes");
        }
    } else {
        trace("user_status config = 0, skipping");
    }
    trace("STEP 5 PASS");
}

if ($step <= 6) {
    trace("--- STEP 6: Wo_GetTrendingPosts ---");
    if (function_exists('Wo_GetTrendingPosts')) {
        $tp = Wo_GetTrendingPosts(3);
        trace("Wo_GetTrendingPosts returned: " . count($tp) . " posts");
    } else {
        trace("Wo_GetTrendingPosts NOT DEFINED");
    }
    trace("STEP 6 PASS");
}

if ($step <= 7) {
    trace("--- STEP 7: Wo_CheckBirthdays ---");
    $birth = Wo_CheckBirthdays();
    trace("Wo_CheckBirthdays: " . count($birth) . " birthdays");
    trace("STEP 7 PASS");
}

if ($step <= 8) {
    trace("--- STEP 8: publisher-box load ---");
    $out = Wo_LoadPage('story/publisher-box');
    trace("publisher-box: " . strlen($out) . " bytes");
    trace("STEP 8 PASS");
}

if ($step <= 9) {
    trace("--- STEP 9: load-posts load ---");
    $out = Wo_LoadPage('home/load-posts');
    trace("home/load-posts: " . strlen($out) . " bytes");
    trace("STEP 9 PASS");
}

if ($step <= 10) {
    trace("--- STEP 10: sidebar/content load ---");
    $out = Wo_LoadPage('sidebar/content');
    trace("sidebar/content: " . strlen($out) . " bytes");
    trace("STEP 10 PASS");
}

trace("=== ALL STEPS PASSED ===");
trace("Peak mem: " . memory_get_peak_usage(true)/1024/1024 . "MB");
