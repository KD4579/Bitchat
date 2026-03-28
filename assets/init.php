<?php
// Security headers - prevent token leakage via Referer and clickjacking
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

// Error logging — capture all PHP errors to a log file (not displayed to users)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/logs/php_errors.log');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

@ini_set('session.cookie_httponly', 1);
@ini_set('session.use_only_cookies', 1);
@ini_set('session.gc_maxlifetime', 2592000); // 30 days session lifetime
@ini_set('session.cookie_lifetime', 2592000); // 30 days cookie lifetime
@ini_set('session.cookie_samesite', 'Lax'); // Allow cookies with same-site AJAX requests
@ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0); // Secure cookies when on HTTPS
@ini_set('session.use_strict_mode', 1);
if (!version_compare(PHP_VERSION, '7.1.0', '>=')) {
    exit("Required PHP_VERSION >= 7.1.0 , Your PHP_VERSION is : " . PHP_VERSION . "\n");
}
if (!function_exists("mysqli_connect")) {
    exit("MySQLi is required to run the application, please contact your hosting to enable php mysqli.");
}
date_default_timezone_set('UTC');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Regenerate session ID periodically for security (every 60 minutes)
// Using false to keep old session data accessible briefly during AJAX requests
if (!isset($_SESSION['session_regenerate_time'])) {
    $_SESSION['session_regenerate_time'] = time();
} elseif (time() - $_SESSION['session_regenerate_time'] > 3600) {
    session_regenerate_id(true);
    $_SESSION['session_regenerate_time'] = time();
}
@ini_set('gd.jpeg_ignore_warning', 1);
require_once('assets/libraries/DB/vendor/joshcam/mysqli-database-class/MySQL-Maria.php');
require_once('includes/cache.php');
require_once('includes/redis_cache.php');
require_once('includes/security_helpers.php');
require_once('includes/functions_general.php');
require_once('includes/tabels.php');
require_once('includes/functions_one.php');
require_once('includes/functions_two.php');
require_once('includes/functions_three.php');
require_once('includes/functions_feed.php');
require_once('includes/functions_spam.php');
require_once('includes/functions_scheduled.php');
require_once('includes/functions_ghost.php');
require_once('includes/functions_creator.php');
require_once('includes/functions_trdc_rewards.php');
require_once('includes/functions_reward_guards.php');
require_once('includes/functions_reward_engine.php');
require_once('includes/ecosystem_helpers.php');
require_once('includes/functions_admin_log.php');