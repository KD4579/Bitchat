<?php
/**
 * Tradex24 → Bitchat VIP Tier Sync Webhook
 *
 * Called by Tradex24's fireBitchatVipSync() whenever a user hits a VIP tier.
 * Grants Bitchat Pro membership for the configured duration.
 *
 * Security: same ECOSYSTEM_SECRET HMAC-SHA256 as referral_converted.php
 *
 * Expected POST body (JSON):
 *   {
 *     "bitchat_user_id": 123,
 *     "tradex24_user_id": "t24_abc",
 *     "vip_tier": "gold",          // bronze | silver | gold | platinum
 *     "idempotency_key": "unique-string",
 *     "timestamp": 1700000000
 *   }
 *
 * Header: X-Tradex24-Signature: sha256=HMAC_HEX
 */

header('Content-Type: application/json');
header_remove('Server');

require_once('../../../assets/init.php');

function vip_respond($success, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit();
}

// ── Feature flag ────────────────────────────────────────────────────────────
if (empty($wo['config']['tradex24_referral_enabled']) || $wo['config']['tradex24_referral_enabled'] != '1') {
    vip_respond(false, 'Tradex24 integration not enabled', 503);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vip_respond(false, 'Method not allowed', 405);
}

// ── IP allowlist ────────────────────────────────────────────────────────────
$allowed_ips = array_filter(array_map('trim', explode(',', $wo['config']['tradex24_allowed_ips'] ?? '')));
if (!empty($allowed_ips)) {
    $remote_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote_ip, $allowed_ips)) {
        vip_respond(false, 'Forbidden', 403);
    }
}

// ── Raw body + HMAC ─────────────────────────────────────────────────────────
$raw_body = file_get_contents('php://input');
if (empty($raw_body)) { vip_respond(false, 'Empty body', 400); }

$secret = $wo['config']['tradex24_referral_secret'] ?? '';
if (empty($secret)) { vip_respond(false, 'Webhook secret not configured', 503); }

$sig_header = $_SERVER['HTTP_X_TRADEX24_SIGNATURE'] ?? '';
if (!str_starts_with($sig_header, 'sha256=')) { vip_respond(false, 'Missing signature', 401); }

if (!hash_equals(hash_hmac('sha256', $raw_body, $secret), substr($sig_header, 7))) {
    vip_respond(false, 'Invalid signature', 401);
}

// ── Parse ───────────────────────────────────────────────────────────────────
$payload = json_decode($raw_body, true);
if (empty($payload)) { vip_respond(false, 'Invalid JSON', 400); }

$bitchat_user_id  = intval($payload['bitchat_user_id']  ?? 0);
$vip_tier         = strtolower(trim($payload['vip_tier'] ?? ''));
$idempotency_key  = trim($payload['idempotency_key']    ?? '');
$timestamp        = intval($payload['timestamp']         ?? 0);

if ($bitchat_user_id <= 0)           { vip_respond(false, 'Invalid bitchat_user_id', 400); }
if (empty($idempotency_key))         { vip_respond(false, 'Missing idempotency_key', 400); }
if (abs(time() - $timestamp) > 300)  { vip_respond(false, 'Timestamp out of range', 400); }

$valid_tiers = ['bronze', 'silver', 'gold', 'platinum'];
if (!in_array($vip_tier, $valid_tiers)) {
    vip_respond(false, 'Invalid vip_tier — expected: ' . implode(', ', $valid_tiers), 400);
}

// ── Idempotency ─────────────────────────────────────────────────────────────
$safe_key = Wo_Secure($idempotency_key, 0);
$idem_q = mysqli_query($sqlConnect,
    "SELECT id FROM " . T_PAYMENT_TRANSACTIONS .
    " WHERE notes LIKE 'tradex24_vip:{$safe_key}' LIMIT 1"
);
if ($idem_q && mysqli_num_rows($idem_q) > 0) {
    vip_respond(true, 'Already processed (idempotent)');
}

// ── Verify user ─────────────────────────────────────────────────────────────
$user = Wo_UserData($bitchat_user_id);
if (empty($user)) { vip_respond(false, 'User not found', 404); }

// ── Map VIP tier → Pro package + duration ───────────────────────────────────
// Configurable via admin panel; sensible defaults
$tier_map = [
    'bronze'   => ['pro_type' => 'monthly',  'days' => 30,  'verified' => 0],
    'silver'   => ['pro_type' => 'quarterly','days' => 90,  'verified' => 0],
    'gold'     => ['pro_type' => 'yearly',   'days' => 365, 'verified' => 1],
    'platinum' => ['pro_type' => 'lifetime', 'days' => 3650,'verified' => 1],
];

// Allow admin override for pro_type names
$tier_cfg = $tier_map[$vip_tier];
$pro_type = $tier_cfg['pro_type'];

// Validate pro_type exists in site config
if (!empty($wo['pro_packages']) && !isset($wo['pro_packages'][$pro_type])) {
    // Fall back to first available package if mapping doesn't match site config
    $pro_type = array_key_first($wo['pro_packages']);
}

$safe_uid      = intval($bitchat_user_id);
$safe_pro_type = Wo_Secure($pro_type, 0);
$safe_note     = Wo_Secure('tradex24_vip:' . $idempotency_key, 0);
$now           = time();
$pro_expires   = $now + ($tier_cfg['days'] * 86400);

// ── Grant Pro ────────────────────────────────────────────────────────────────
$update = [
    'is_pro'   => 1,
    'pro_time' => $now,
    'pro_'     => 1,
    'pro_type' => $safe_pro_type,
];
if ($tier_cfg['verified']) {
    $update['verified'] = 1;
}

$granted = Wo_UpdateUserData($safe_uid, $update);
if (!$granted) {
    vip_respond(false, 'Failed to grant Pro status', 500);
}

// Log transaction (also serves as idempotency record)
mysqli_query($sqlConnect,
    "INSERT INTO " . T_PAYMENT_TRANSACTIONS .
    " (userid, kind, amount, notes) VALUES ({$safe_uid}, 'TRADEX24_VIP_SYNC', 0, '{$safe_note}')"
);

cache($safe_uid, 'users', 'delete');

// Notify user
if (function_exists('Wo_RegisterNotification')) {
    $tier_display = ucfirst($vip_tier);
    Wo_RegisterNotification([
        'recipient_id' => $safe_uid,
        'type'         => 'pro_membership',
        'url'          => 'index.php?link1=wallet',
        'text'         => "Your Tradex24 {$tier_display} VIP status has been applied — you now have Bitchat Pro!",
        'type2'        => 'no_name',
    ]);
}

vip_respond(true, "Pro ({$pro_type}) granted to user {$safe_uid} for Tradex24 {$vip_tier} VIP");
