<?php
/**
 * Bitchat OAuth2 Token Endpoint
 *
 * POST /api/oauth/token.php
 * Content-Type: application/x-www-form-urlencoded
 *
 * Parameters:
 *   grant_type    = authorization_code
 *   code          = <authorization code from /authorize>
 *   client_id     = <app_id>
 *   client_secret = <app_secret>
 *   redirect_uri  = <must match registered callback>
 *
 * Response (JSON):
 *   {"access_token":"...","token_type":"Bearer","expires_in":3600,"user_id":123}
 *
 * Rate limited: 20 requests/min per client_id (Redis) or 10/min per IP (fallback).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header_remove('Server');

require_once('../../assets/init.php');

// ── Only allow POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit();
}

// ── Collect params ────────────────────────────────────────────────────────────
$grant_type    = isset($_POST['grant_type'])    ? trim($_POST['grant_type'])    : '';
$code          = isset($_POST['code'])          ? trim($_POST['code'])          : '';
$client_id     = isset($_POST['client_id'])     ? trim($_POST['client_id'])     : '';
$client_secret = isset($_POST['client_secret']) ? trim($_POST['client_secret']) : '';
$redirect_uri  = isset($_POST['redirect_uri'])  ? trim($_POST['redirect_uri'])  : '';

// ── Validate grant_type ───────────────────────────────────────────────────────
if ($grant_type !== 'authorization_code') {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_grant_type']);
    exit();
}

if (empty($code) || empty($client_id) || empty($client_secret) || empty($redirect_uri)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'Missing required parameters']);
    exit();
}

// ── Rate limiting: 20 requests/min per client_id ──────────────────────────────
$redis = null;
try {
    if (class_exists('Redis')) {
        $redis = new Redis();
        if (!@$redis->connect('127.0.0.1', 6379, 1)) {
            $redis = null;
        } else {
            $redis->select(1); // sessions/rate-limit DB
        }
    }
} catch (Exception $e) {
    $redis = null;
}

$rate_key = 'oauth2_token_rl:' . md5($client_id);
if ($redis) {
    $hits = $redis->incr($rate_key);
    if ($hits === 1) {
        $redis->expire($rate_key, 60);
    }
    if ($hits > 20) {
        http_response_code(429);
        echo json_encode(['error' => 'rate_limit_exceeded', 'retry_after' => 60]);
        exit();
    }
} else {
    // Fallback: IP-based rate limiting via file cache
    $ip       = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
    $rl_file  = sys_get_temp_dir() . '/oauth2_rl_' . md5($ip) . '.json';
    $rl_data  = ['count' => 0, 'window_start' => time()];
    if (file_exists($rl_file)) {
        $tmp = json_decode(file_get_contents($rl_file), true);
        if ($tmp && (time() - $tmp['window_start']) < 60) {
            $rl_data = $tmp;
        }
    }
    $rl_data['count']++;
    file_put_contents($rl_file, json_encode($rl_data), LOCK_EX);
    if ($rl_data['count'] > 10) {
        http_response_code(429);
        echo json_encode(['error' => 'rate_limit_exceeded', 'retry_after' => 60]);
        exit();
    }
}

// ── Authenticate client (client_id + client_secret) ───────────────────────────
$safe_client_id     = Wo_Secure($client_id);
$safe_client_secret = Wo_Secure($client_secret);

$app_query = mysqli_query($sqlConnect,
    "SELECT `id`, `app_id`, `app_callback_url`, `app_website_url`, `active`
     FROM " . T_APPS . "
     WHERE `app_id` = '{$safe_client_id}' AND `app_secret` = '{$safe_client_secret}'
     LIMIT 1"
);

if (!$app_query || mysqli_num_rows($app_query) === 0) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client', 'error_description' => 'Invalid client credentials']);
    exit();
}

$app_row = mysqli_fetch_assoc($app_query);

if ($app_row['active'] != '1') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized_client', 'error_description' => 'Application is disabled']);
    exit();
}

// ── Validate redirect_uri matches registered callback ────────────────────────
$registered_callback = !empty($app_row['app_callback_url'])
                         ? $app_row['app_callback_url']
                         : $app_row['app_website_url'];

if (rtrim($redirect_uri, '/') !== rtrim($registered_callback, '/')) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'redirect_uri mismatch']);
    exit();
}

// ── Validate and consume authorization code ───────────────────────────────────
$numeric_app_id = intval($app_row['id']);
$safe_code      = Wo_Secure($code);
$expiry         = time() - 600; // codes expire after 10 minutes

// Fetch code — must belong to this app
$code_query = mysqli_query($sqlConnect,
    "SELECT `id`, `user_id`, `app_id`, `code`, `time`
     FROM " . T_CODES . "
     WHERE `code` = '{$safe_code}'
       AND `app_id` = {$numeric_app_id}
       AND `time` >= {$expiry}
     LIMIT 1"
);

if (!$code_query || mysqli_num_rows($code_query) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid or expired']);
    exit();
}

$code_row = mysqli_fetch_assoc($code_query);

// Single-use: delete the code immediately
mysqli_query($sqlConnect,
    "DELETE FROM " . T_CODES . " WHERE `code` = '{$safe_code}'"
);

$user_id = intval($code_row['user_id']);

// ── Issue access token ────────────────────────────────────────────────────────
$access_token = Wo_GenrateToken($user_id, $numeric_app_id);

if (empty($access_token)) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'error_description' => 'Failed to generate access token']);
    exit();
}

// ── Return token response ─────────────────────────────────────────────────────
echo json_encode([
    'access_token' => $access_token,
    'token_type'   => 'Bearer',
    'expires_in'   => 3600,
    'user_id'      => $user_id,
]);
exit();
