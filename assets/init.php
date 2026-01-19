<?php
@ini_set('session.cookie_httponly',1);
@ini_set('session.use_only_cookies',1);
@ini_set('session.gc_maxlifetime', 86400); // 24 hours session lifetime
@ini_set('session.cookie_lifetime', 86400); // 24 hours cookie lifetime
if (!version_compare(PHP_VERSION, '7.1.0', '>=')) {
    exit("Required PHP_VERSION >= 7.1.0 , Your PHP_VERSION is : " . PHP_VERSION . "\n");
}
if (!function_exists("mysqli_connect")) {
    exit("MySQLi is required to run the application, please contact your hosting to enable php mysqli.");
}
date_default_timezone_set('UTC');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Regenerate session ID periodically for security (every 30 minutes)
if (!isset($_SESSION['session_regenerate_time'])) {
    $_SESSION['session_regenerate_time'] = time();
} elseif (time() - $_SESSION['session_regenerate_time'] > 1800) {
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