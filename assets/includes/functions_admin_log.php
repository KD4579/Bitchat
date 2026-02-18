<?php
/**
 * Log an admin action to the admin activity log file.
 *
 * @param string $action  Short action label (e.g. "config_change", "preset_applied")
 * @param string $details Human-readable details
 * @param int    $adminId Admin user ID (0 = auto-detect)
 */
function Wo_LogAdminAction($action, $details, $adminId = 0) {
    global $wo;

    if ($adminId <= 0 && !empty($wo['user']['user_id'])) {
        $adminId = intval($wo['user']['user_id']);
    }

    $adminName = 'Unknown';
    if (!empty($wo['user']['username'])) {
        $adminName = $wo['user']['username'];
    }

    $logFile = dirname(dirname(__DIR__)) . '/assets/logs/admin_activity.log';
    $logDir  = dirname($logFile);
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

    $line = date('Y-m-d H:i:s') . ' | ' .
            str_pad($adminName, 20) . ' | ' .
            str_pad($action, 25) . ' | ' .
            $details;

    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);

    // Keep log under 1MB
    if (@filesize($logFile) > 1048576) {
        $lines = file($logFile);
        file_put_contents($logFile, implode('', array_slice($lines, -500)));
    }
}

/**
 * Read admin activity log entries.
 *
 * @param int $limit Max entries to return (most recent first)
 * @return array
 */
function Wo_GetAdminLog($limit = 100) {
    $logFile = dirname(dirname(__DIR__)) . '/assets/logs/admin_activity.log';
    if (!file_exists($logFile)) return array();

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines);
    $entries = array();

    foreach (array_slice($lines, 0, $limit) as $line) {
        $parts = explode(' | ', $line, 4);
        if (count($parts) >= 4) {
            $entries[] = array(
                'time'    => trim($parts[0]),
                'admin'   => trim($parts[1]),
                'action'  => trim($parts[2]),
                'details' => trim($parts[3]),
            );
        }
    }

    return $entries;
}
