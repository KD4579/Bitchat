<?php
/**
 * Bitchat ↔ Tradex24 Ecosystem Outbound Helpers
 *
 * All outbound calls to Tradex24 go through Wo_FireTradex24Request().
 * - HMAC-SHA256 signed (X-Tradex24-Signature header) — matches their inbound pattern
 * - Fire-and-forget with 5s timeout — never blocks user-facing requests
 * - Disabled when tradex24_referral_enabled != 1
 */

/**
 * Low-level: fire a signed POST request to a Tradex24 endpoint.
 * Returns true on HTTP 2xx, false otherwise. Never throws.
 *
 * @param string $endpoint  Full URL, e.g. https://tradex24.com/api/ecosystem/fee-discount
 * @param array  $payload   Data to JSON-encode and send
 * @return bool
 */
function Wo_FireTradex24Request($endpoint, array $payload) {
    global $wo;

    if (empty($wo['config']['tradex24_referral_enabled']) || $wo['config']['tradex24_referral_enabled'] != '1') {
        return false;
    }

    $secret = $wo['config']['tradex24_referral_secret'] ?? '';
    if (empty($secret)) {
        return false;
    }

    $payload['timestamp'] = time();
    $body = json_encode($payload);
    $sig  = 'sha256=' . hash_hmac('sha256', $body, $secret);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Tradex24-Signature: ' . $sig,
            'X-Bitchat-Source: bitchat',
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($http_code >= 200 && $http_code < 300);
}

/**
 * Fire fee-discount grant to Tradex24 after a successful Bitchat Pro purchase.
 *
 * Called by every Pro payment handler immediately after Wo_UpdateUserData() confirms Pro.
 * Skipped automatically if:
 *   - Tradex24 integration is disabled
 *   - Pro was granted via Tradex24 VIP sync (avoid circular call)
 *
 * @param int    $bitchat_user_id  The Bitchat user who just went Pro
 * @param string $pro_type         Pro package key (e.g. 'monthly', 'yearly')
 * @param string $source           Payment source label for Tradex24 logs
 * @return bool
 */
function Wo_FireTradex24FeeDiscount($bitchat_user_id, $pro_type = '', $source = 'bitchat_pro_purchase') {
    global $wo;

    // Never call back when Pro was granted FROM Tradex24 (vip_status webhook)
    if ($source === 'tradex24_vip_sync') {
        return false;
    }

    $base_url = rtrim($wo['config']['tradex24_api_base'] ?? 'https://tradex24.com', '/');
    $endpoint = $base_url . '/api/ecosystem/fee-discount';

    return Wo_FireTradex24Request($endpoint, [
        'bitchat_user_id'  => intval($bitchat_user_id),
        'pro_type'         => (string) $pro_type,
        'source'           => $source,
        'idempotency_key'  => 'pro_' . intval($bitchat_user_id) . '_' . time(),
    ]);
}
