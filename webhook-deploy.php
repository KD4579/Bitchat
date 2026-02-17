<?php
/**
 * GitHub Webhook Auto-Deploy for Bitchat
 * Automatically pulls latest code when GitHub sends a push event
 *
 * Setup:
 * 1. Upload this file to: /home/KamalDave/web/bitchat.live/public_html/
 * 2. Make webhook-deploy.sh executable: chmod +x webhook-deploy.sh
 * 3. Add GitHub webhook: https://bitchat.live/webhook-deploy.php
 * 4. Set secret in GitHub webhook settings
 */

// Configuration
define('SECRET_TOKEN', 'bitchat_webhook_secret_' . md5('KDTradex@2424'));
define('DEPLOY_SCRIPT', __DIR__ . '/webhook-deploy.sh');
define('LOG_FILE', __DIR__ . '/webhook-deploy.log');

// Get the request payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Verify GitHub signature
if (!empty($signature)) {
    $hash = 'sha256=' . hash_hmac('sha256', $payload, SECRET_TOKEN);
    if (!hash_equals($hash, $signature)) {
        http_response_code(403);
        die('Invalid signature');
    }
}

// Parse payload
$data = json_decode($payload, true);

// Log the deployment request
$logEntry = date('Y-m-d H:i:s') . " - Webhook received\n";
$logEntry .= "Branch: " . ($data['ref'] ?? 'unknown') . "\n";
$logEntry .= "Pusher: " . ($data['pusher']['name'] ?? 'unknown') . "\n";
$logEntry .= "Commits: " . count($data['commits'] ?? []) . "\n";

// Only deploy on push to main branch
if (isset($data['ref']) && $data['ref'] === 'refs/heads/main') {
    $logEntry .= "Status: Deploying...\n";

    // Execute deployment script in background
    $output = [];
    $returnCode = 0;
    exec(DEPLOY_SCRIPT . ' 2>&1', $output, $returnCode);

    $logEntry .= "Deploy script output:\n" . implode("\n", $output) . "\n";
    $logEntry .= "Return code: $returnCode\n";

    if ($returnCode === 0) {
        $logEntry .= "Status: SUCCESS\n";
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Deployment completed']);
    } else {
        $logEntry .= "Status: FAILED\n";
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Deployment failed', 'code' => $returnCode]);
    }
} else {
    $logEntry .= "Status: Skipped (not main branch)\n";
    http_response_code(200);
    echo json_encode(['status' => 'skipped', 'message' => 'Not main branch']);
}

$logEntry .= str_repeat('-', 80) . "\n";

// Write to log file
file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
