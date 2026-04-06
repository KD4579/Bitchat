<?php
/**
 * Tradex24 → Bitchat Referral Conversion Webhook
 *
 * Called by Tradex24 when a referred user completes their first qualifying TRDC trade.
 * Awards TRDC to the referring Bitchat user.
 *
 * Security:
 *   - HMAC-SHA256 signature verification (shared secret in admin config)
 *   - IP allowlist (Tradex24 server IPs in config)
 *   - Idempotency key (prevents double-reward on retry)
 *   - Disabled by default (tradex24_referral_enabled = 0 in admin panel)
 *
 * Expected POST body (JSON):
 *   {
 *     "bitchat_user_id": 123,
 *     "tradex24_user_id": "t24_abc",
 *     "amount_traded_usd": 15.00,
 *     "trade_currency": "TRDC",
 *     "idempotency_key": "unique-string-per-event",
 *     "timestamp": 1700000000
 *   }
 *
 * Expected header:
 *   X-Tradex24-Signature: sha256=HMAC_HEX
 *
 * Response: JSON { success: bool, message: string }
 */

header('Content-Type: application/json');
header_remove('Server');

require_once('../../../assets/init.php');

// ── Helper ─────────────────────────────────────────────────────────────────
function tx24_respond($success, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit();
}

// ── Feature flag — disabled until secret is agreed with Tradex24 ───────────
if (empty($wo['config']['tradex24_referral_enabled']) || $wo['config']['tradex24_referral_enabled'] != '1') {
    tx24_respond(false, 'Referral integration not enabled', 503);
}

// ── Only POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tx24_respond(false, 'Method not allowed', 405);
}

// ── IP allowlist ──────────────────────────────────────────────────────────
$allowed_ips = array_filter(array_map('trim', explode(',', $wo['config']['tradex24_allowed_ips'] ?? '')));
if (!empty($allowed_ips)) {
    $remote_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote_ip, $allowed_ips)) {
        tx24_respond(false, 'Forbidden', 403);
    }
}

// ── Read raw body ──────────────────────────────────────────────────────────
$raw_body = file_get_contents('php://input');
if (empty($raw_body)) {
    tx24_respond(false, 'Empty body', 400);
}

// ── HMAC signature verification ────────────────────────────────────────────
$secret = $wo['config']['tradex24_referral_secret'] ?? '';
if (empty($secret)) {
    tx24_respond(false, 'Webhook secret not configured', 503);
}

$sig_header = $_SERVER['HTTP_X_TRADEX24_SIGNATURE'] ?? '';
if (empty($sig_header) || !str_starts_with($sig_header, 'sha256=')) {
    tx24_respond(false, 'Missing signature', 401);
}

$provided_sig  = substr($sig_header, 7); // strip "sha256="
$expected_sig  = hash_hmac('sha256', $raw_body, $secret);
if (!hash_equals($expected_sig, $provided_sig)) {
    tx24_respond(false, 'Invalid signature', 401);
}

// ── Parse payload ──────────────────────────────────────────────────────────
$payload = json_decode($raw_body, true);
if (empty($payload) || !is_array($payload)) {
    tx24_respond(false, 'Invalid JSON payload', 400);
}

$bitchat_user_id   = intval($payload['bitchat_user_id']   ?? 0);
$tradex24_user_id  = trim($payload['tradex24_user_id']    ?? '');
$amount_usd        = floatval($payload['amount_traded_usd'] ?? 0);
$trade_currency    = strtoupper(trim($payload['trade_currency'] ?? ''));
$idempotency_key   = trim($payload['idempotency_key']     ?? '');
$timestamp         = intval($payload['timestamp']          ?? 0);

// ── Validate fields ────────────────────────────────────────────────────────
if ($bitchat_user_id <= 0) {
    tx24_respond(false, 'Invalid bitchat_user_id', 400);
}
if (empty($idempotency_key) || strlen($idempotency_key) > 128) {
    tx24_respond(false, 'Invalid idempotency_key', 400);
}
if ($trade_currency !== 'TRDC') {
    tx24_respond(false, 'trade_currency must be TRDC', 400);
}

// Minimum trade threshold (configurable, default $10)
$min_usd = floatval($wo['config']['tradex24_referral_min_usd'] ?? 10);
if ($amount_usd < $min_usd) {
    tx24_respond(false, 'Trade amount below minimum threshold', 400);
}

// Timestamp freshness: reject if older than 5 minutes
if (abs(time() - $timestamp) > 300) {
    tx24_respond(false, 'Timestamp too old or too far in future', 400);
}

// ── Idempotency check — prevent double-reward on retry ────────────────────
$safe_key = Wo_Secure($idempotency_key, 0);
$idem_q = mysqli_query($sqlConnect,
    "SELECT id FROM " . T_PAYMENT_TRANSACTIONS .
    " WHERE notes LIKE 'tradex24_ref:" . $safe_key . "' LIMIT 1"
);
if ($idem_q && mysqli_num_rows($idem_q) > 0) {
    tx24_respond(true, 'Already processed (idempotent)', 200);
}

// ── Verify Bitchat user exists ─────────────────────────────────────────────
$user = Wo_UserData($bitchat_user_id);
if (empty($user) || empty($user['user_id'])) {
    tx24_respond(false, 'Bitchat user not found', 404);
}

// ── Calculate referral reward ──────────────────────────────────────────────
$reward_amount = floatval($wo['config']['tradex24_referral_reward_trdc'] ?? 25);
if ($reward_amount <= 0) {
    tx24_respond(false, 'Referral reward not configured', 503);
}

// ── Award TRDC to referrer ─────────────────────────────────────────────────
$safe_uid    = intval($bitchat_user_id);
$safe_reward = floatval($reward_amount);
$safe_note   = Wo_Secure('tradex24_ref:' . $idempotency_key, 0);
$now         = time();

mysqli_begin_transaction($sqlConnect);
try {
    // Credit wallet
    $q1 = mysqli_query($sqlConnect,
        "UPDATE " . T_USERS . " SET wallet = wallet + {$safe_reward} WHERE user_id = {$safe_uid}"
    );
    if (!$q1 || mysqli_affected_rows($sqlConnect) == 0) {
        throw new Exception('Failed to credit wallet');
    }

    // Log to TRDC rewards table
    if (defined('T_TRDC_REWARDS')) {
        mysqli_query($sqlConnect,
            "INSERT INTO " . T_TRDC_REWARDS .
            " (user_id, amount, reward_key, created_at) VALUES ({$safe_uid}, {$safe_reward}, 'tradex24_referral', {$now})"
        );
    }

    // Log to payment transactions (also serves as idempotency record)
    mysqli_query($sqlConnect,
        "INSERT INTO " . T_PAYMENT_TRANSACTIONS .
        " (userid, kind, amount, notes) VALUES ({$safe_uid}, 'TRADEX24_REFERRAL', {$safe_reward}, '{$safe_note}')"
    );

    mysqli_commit($sqlConnect);
} catch (Exception $e) {
    mysqli_rollback($sqlConnect);
    tx24_respond(false, 'Internal error crediting reward', 500);
}

// Invalidate user cache
cache($safe_uid, 'users', 'delete');

// Notify user
if (function_exists('Wo_RegisterNotification')) {
    Wo_RegisterNotification([
        'recipient_id' => $safe_uid,
        'type'         => 'trdc_reward',
        'url'          => 'index.php?link1=wallet',
        'text'         => 'You earned ' . number_format($safe_reward, 2) . ' TRDC! A friend you referred completed their first trade on Tradex24.',
        'type2'        => 'no_name',
    ]);
}

tx24_respond(true, 'Referral reward of ' . $safe_reward . ' TRDC credited to user ' . $safe_uid);
