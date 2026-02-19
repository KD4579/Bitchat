<?php
/**
 * GitHub Webhook Auto-Deployment Handler
 * Triggered by GitHub on every push to main branch
 *
 * Security: Validates HMAC-SHA256 signature from GitHub
 */

// Must match the secret set in GitHub webhook settings
define('WEBHOOK_SECRET', 'bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec');

// Path to the shell script that does the actual git pull
define('DEPLOY_SCRIPT', __DIR__ . '/webhook-deploy.sh');

// Log file (must be within open_basedir: public_html/../private is allowed)
define('LOG_FILE', __DIR__ . '/../private/webhook-deploy.log');

// ─── Helpers ────────────────────────────────────────────────────────────────

function log_msg($msg) {
    $line = '[' . date('Y-m-d H:i:s') . ' UTC] ' . $msg . PHP_EOL;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function respond($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => $code === 200 ? 'ok' : 'error', 'message' => $msg]);
    exit;
}

// ─── Validate request ────────────────────────────────────────────────────────

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'Method not allowed');
}

// Read raw payload
$raw = file_get_contents('php://input');
if (empty($raw)) {
    respond(400, 'Empty payload');
}

// Verify GitHub HMAC signature
$sig_header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (empty($sig_header)) {
    log_msg('REJECTED: Missing X-Hub-Signature-256 header');
    respond(403, 'Missing signature');
}

$expected = 'sha256=' . hash_hmac('sha256', $raw, WEBHOOK_SECRET);
if (!hash_equals($expected, $sig_header)) {
    log_msg('REJECTED: Invalid signature');
    respond(403, 'Invalid signature');
}

// Only act on push events
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    respond(200, 'Ignored event: ' . $event);
}

// Only deploy on pushes to main branch
$payload = json_decode($raw, true);
$ref     = $payload['ref'] ?? '';
if ($ref !== 'refs/heads/main') {
    respond(200, 'Ignored branch: ' . $ref);
}

// ─── Deploy ─────────────────────────────────────────────────────────────────

$commit_id  = substr($payload['after'] ?? 'unknown', 0, 8);
$commit_msg = $payload['head_commit']['message'] ?? 'unknown';
log_msg("DEPLOY START — commit $commit_id: $commit_msg");

if (!file_exists(DEPLOY_SCRIPT)) {
    log_msg('ERROR: webhook-deploy.sh not found');
    respond(500, 'Deploy script missing');
}

// Run shell script as current user (www-data / KamalDave)
$output = [];
$exit_code = 0;
exec('bash ' . escapeshellarg(DEPLOY_SCRIPT) . ' 2>&1', $output, $exit_code);

$output_str = implode("\n", $output);
log_msg("DEPLOY OUTPUT:\n$output_str");

if ($exit_code !== 0) {
    log_msg("DEPLOY FAILED — exit code $exit_code");
    respond(500, 'Deploy failed with exit code ' . $exit_code);
}

// ─── Flush OPcache after deploy ──────────────────────────────────────────────
if (function_exists('opcache_reset')) {
    opcache_reset();
    log_msg('OPcache flushed');
}

log_msg("DEPLOY SUCCESS — commit $commit_id deployed");
respond(200, 'Deployed commit ' . $commit_id);
