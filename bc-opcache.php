<?php
/**
 * OPcache Flush Utility — Bitchat
 * Usage: https://bitchat.live/bc-opcache.php?key=bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec
 *
 * Delete this file after use, or keep for maintenance.
 */
define('ACCESS_KEY', 'bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec');

header('Content-Type: application/json');

if (($_GET['key'] ?? '') !== ACCESS_KEY) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$result = ['status' => 'ok', 'actions' => []];

// OPcache
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    $result['actions']['opcache_reset'] = $ok ? 'flushed' : 'failed';
} else {
    $result['actions']['opcache_reset'] = 'not available';
}

// APC / APCu
if (function_exists('apc_clear_cache')) {
    apc_clear_cache();
    apc_clear_cache('user');
    $result['actions']['apc_clear'] = 'flushed';
}

// Bitchat file cache (./cache/*.tpl files)
$cache_dir = __DIR__ . '/cache';
$cleared = 0;
if (is_dir($cache_dir)) {
    foreach (glob($cache_dir . '/*.tpl') as $f) {
        if (@unlink($f)) $cleared++;
    }
    foreach (glob($cache_dir . '/*.php') as $f) {
        if (@unlink($f)) $cleared++;
    }
}
$result['actions']['file_cache_cleared'] = $cleared . ' files';

$result['timestamp'] = date('Y-m-d H:i:s T');
echo json_encode($result, JSON_PRETTY_PRINT);
