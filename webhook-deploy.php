<?php
/**
 * GitHub Webhook Auto-Deploy for Bitchat
 * Receives GitHub push events and triggers deployment
 */

// Configuration - Use this exact secret in GitHub webhook settings
define('SECRET_TOKEN', 'bitchat-deploy-2024');
define('DEPLOY_SCRIPT', __DIR__ . '/webhook-deploy.sh');
define('LOG_FILE', __DIR__ . '/webhook-deploy.log');

// Helper function to write log
function writeLog($message) {
    @file_put_contents(LOG_FILE, $message, FILE_APPEND);
}

// Get the request payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Verify GitHub signature if present
if (!empty($signature)) {
    $hash = 'sha256=' . hash_hmac('sha256', $payload, SECRET_TOKEN);
    if (!hash_equals($hash, $signature)) {
        writeLog(date('Y-m-d H:i:s') . " - REJECTED: Invalid signature\n");
        http_response_code(403);
        die('Invalid signature');
    }
}

// Parse payload
$data = json_decode($payload, true);
$branch = $data['ref'] ?? 'unknown';
$pusher = $data['pusher']['name'] ?? 'unknown';

// Log the webhook request
$logEntry = date('Y-m-d H:i:s') . " - Webhook received from: $pusher, branch: $branch\n";

// Only deploy on push to main branch
if (isset($data['ref']) && $data['ref'] === 'refs/heads/main') {
    $logEntry .= date('Y-m-d H:i:s') . " - Deploying...\n";
    writeLog($logEntry);

    // Execute deployment script as KamalDave user (has git SSH access)
    $output = [];
    $returnCode = 0;
    exec('sudo -u KamalDave bash ' . DEPLOY_SCRIPT . ' 2>&1', $output, $returnCode);

    $result = implode("\n", $output);
    writeLog(date('Y-m-d H:i:s') . " - Deploy output:\n$result\n");
    writeLog(date('Y-m-d H:i:s') . " - Return code: $returnCode\n");
    writeLog(str_repeat('-', 60) . "\n");

    if ($returnCode === 0) {
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Deployment completed']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Deployment failed', 'code' => $returnCode]);
    }
} else {
    $logEntry .= date('Y-m-d H:i:s') . " - Skipped (not main branch)\n";
    $logEntry .= str_repeat('-', 60) . "\n";
    writeLog($logEntry);
    http_response_code(200);
    echo json_encode(['status' => 'skipped', 'message' => 'Not main branch']);
}
