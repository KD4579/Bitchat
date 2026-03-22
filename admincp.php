<?php
// You can access the admin panel by using the following url: http://yoursite.com/admincp

require 'assets/init.php';

// Rate limit admin panel access (30 requests per minute)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (class_exists('BitchatSecurity') && !BitchatSecurity::rateLimit('admincp_access', $clientIp, 30, 60)) {
    BitchatSecurity::logSecurityEvent('RATE_LIMIT', 'Admin panel access rate limited');
    http_response_code(429);
    die('Too many requests. Please try again later.');
}

$is_admin = Wo_IsAdmin();
$is_moderoter = Wo_IsModerator();

if ($wo['config']['maintenance_mode'] == 1) {
    if ($wo['loggedin'] == false) {
        header("Location: " . Wo_SeoLink('index.php?link1=welcome') . $wo['marker'] . 'm=true');
        exit();
    } else {
        if ($is_admin === false) {
            header("Location: " . Wo_SeoLink('index.php?link1=welcome') . $wo['marker'] . 'm=true');
            exit();
        }
    }
}
if ($is_admin == false && $is_moderoter == false) {
    // Log unauthorized access attempt
    if (class_exists('BitchatSecurity')) {
        BitchatSecurity::logSecurityEvent('UNAUTHORIZED_ADMIN', 'Unauthorized admin panel access attempt');
    }
	header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
if (!empty($_GET)) {
    foreach ($_GET as $key => $value) {
        $value = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
        $_GET[$key] = strip_tags($value);
    }
}
if (!empty($_REQUEST)) {
    foreach ($_REQUEST as $key => $value) {
        $value = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
        $_REQUEST[$key] = strip_tags($value);
    }
}
if (!empty($_POST)) {
    // SECURITY: exempt password fields — strip_tags silently corrupts passwords
    // containing HTML-like content (e.g. "onclick=Secret1" → "") causing login failures
    $password_fields = ['password', 'confirm_password', 'new_password', 'repeat_new_password', 'current_password'];
    foreach ($_POST as $key => $value) {
        if (!is_array($value) && !in_array($key, $password_fields) && $key != 'avatar' && $key != 'game') {
            $value       = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
            $_POST[$key] = strip_tags($value);
        }
    }
}
$wo['script_root'] = dirname(__FILE__);
// autoload admin panel files
require 'admin-panel/autoload.php';