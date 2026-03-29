<?php
/**
 * Bitchat OAuth2 User Info Endpoint
 *
 * GET /api/oauth/me.php
 *
 * Auth:
 *   Authorization: Bearer {access_token}
 *   OR ?access_token={token} (query param, less preferred)
 *
 * Response (JSON):
 *   {
 *     "id": 123,
 *     "username": "johndoe",
 *     "email": "john@example.com",
 *     "first_name": "John",
 *     "last_name": "Doe",
 *     "avatar": "https://...",
 *     "verified": "1",
 *     "active": "1"
 *   }
 *
 * Rate limited: 60 requests/min per token.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header_remove('Server');

require_once('../../assets/init.php');

// ── Only allow GET ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit();
}

// ── Extract Bearer token ──────────────────────────────────────────────────────
$access_token = '';

$auth_header = '';
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $auth_header = $value;
            break;
        }
    }
} elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

if (!empty($auth_header) && preg_match('/^Bearer\s+(.+)$/i', trim($auth_header), $matches)) {
    $access_token = $matches[1];
} elseif (!empty($_GET['access_token'])) {
    $access_token = trim($_GET['access_token']);
}

if (empty($access_token)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="Bitchat OAuth2"');
    echo json_encode(['error' => 'unauthorized', 'error_description' => 'No access token provided']);
    exit();
}

// ── Rate limiting: 60 requests/min per token ──────────────────────────────────
$redis = null;
try {
    if (class_exists('Redis')) {
        $redis = new Redis();
        if (!@$redis->connect('127.0.0.1', 6379, 1)) {
            $redis = null;
        } else {
            $redis->select(1);
        }
    }
} catch (Exception $e) {
    $redis = null;
}

if ($redis) {
    $rl_key = 'oauth2_me_rl:' . md5($access_token);
    $hits   = $redis->incr($rl_key);
    if ($hits === 1) {
        $redis->expire($rl_key, 60);
    }
    if ($hits > 60) {
        http_response_code(429);
        echo json_encode(['error' => 'rate_limit_exceeded', 'retry_after' => 60]);
        exit();
    }
}

// ── Validate token and get user_id ────────────────────────────────────────────
$safe_token = Wo_Secure($access_token);
$user_id    = Wo_UserIdFromToken($safe_token);

if (empty($user_id) || $user_id === false) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="Bitchat OAuth2", error="invalid_token"');
    echo json_encode(['error' => 'invalid_token', 'error_description' => 'The access token is invalid or expired']);
    exit();
}

// ── Fetch user data ───────────────────────────────────────────────────────────
$user_data = Wo_UserData($user_id);

if (empty($user_data) || $user_data === false) {
    http_response_code(404);
    echo json_encode(['error' => 'user_not_found', 'error_description' => 'User does not exist']);
    exit();
}

// ── Build response — only safe/public fields ──────────────────────────────────
$avatar = '';
if (!empty($user_data['avatar'])) {
    $avatar = Wo_GetMedia($user_data['avatar']);
}

echo json_encode([
    'id'         => intval($user_data['user_id']),
    'username'   => $user_data['username']   ?? '',
    'email'      => $user_data['email']      ?? '',
    'first_name' => $user_data['first_name'] ?? '',
    'last_name'  => $user_data['last_name']  ?? '',
    'avatar'     => $avatar,
    'verified'   => $user_data['verified']   ?? '0',
    'active'     => $user_data['active']     ?? '0',
]);
exit();
