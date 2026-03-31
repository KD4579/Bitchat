<?php
/**
 * Bitchat Health Check Endpoint
 * Monitors system status and dependencies
 *
 * Usage:
 * - Internal monitoring: https://yourdomain.com/health-check.php
 * - With details: https://yourdomain.com/health-check.php?detailed=1
 * - Specific check: https://yourdomain.com/health-check.php?check=database
 *
 * Returns JSON with status and component health
 */

// Load configuration
require_once('assets/init.php');

// Security: require admin session or localhost
$allowed_ips = ['127.0.0.1', '::1'];
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_admin_session = !empty($wo['loggedin']) && !empty($wo['user']['is_admin_or_moderator']) && $wo['user']['is_admin_or_moderator'] == 1;
if (!in_array($remote_ip, $allowed_ips) && !$is_admin_session) {
    http_response_code(403);
    die(json_encode(['status' => 'forbidden', 'message' => 'Access denied']));
}

header('Content-Type: application/json');

$health_status = [
    'status' => 'healthy',
    'timestamp' => date('Y-m-d H:i:s'),
    'version' => $wo['config']['version'] ?? 'unknown',
    'checks' => []
];

$detailed = isset($_GET['detailed']) && $_GET['detailed'] == '1';
$specific_check = $_GET['check'] ?? null;

/**
 * Check database connectivity
 */
function checkDatabase() {
    global $db, $sqlConnect;

    try {
        if (!$db && $sqlConnect) {
            // Try to query
            $result = mysqli_query($sqlConnect, "SELECT 1");
            if ($result) {
                mysqli_free_result($result);
                return [
                    'status' => 'healthy',
                    'message' => 'Database connected',
                    'response_time_ms' => 0
                ];
            }
        } elseif ($db) {
            $start = microtime(true);
            $db->where('user_id', 1)->getOne(T_USERS, 'user_id');
            $response_time = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Database connected and responding',
                'response_time_ms' => $response_time
            ];
        }

        return [
            'status' => 'unhealthy',
            'message' => 'Database connection failed'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'unhealthy',
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Check Redis cache
 */
function checkRedis() {
    global $wo;

    if ($wo['config']['cacheSystem'] != 'redis') {
        return [
            'status' => 'disabled',
            'message' => 'Redis caching not enabled'
        ];
    }

    try {
        $test_key = 'health_check_' . time();
        $test_value = 'test';

        // Try to set and get a key
        Wo_SetCacheRedis($test_key, $test_value, 10);
        $result = Wo_GetCacheRedis($test_key);

        if ($result === $test_value) {
            Wo_DeleteCacheRedis($test_key);

            return [
                'status' => 'healthy',
                'message' => 'Redis connected and responding'
            ];
        }

        return [
            'status' => 'unhealthy',
            'message' => 'Redis read/write test failed'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'unhealthy',
            'message' => 'Redis error: ' . $e->getMessage()
        ];
    }
}

/**
 * Check file system (writable directories)
 */
function checkFileSystem() {
    $directories = [
        'upload' => dirname(__FILE__) . '/upload',
        'cache' => dirname(__FILE__) . '/cache',
        'logs' => dirname(__FILE__) . '/logs'
    ];

    $results = [];
    $overall_status = 'healthy';

    foreach ($directories as $name => $path) {
        if (!file_exists($path)) {
            $results[$name] = [
                'status' => 'warning',
                'message' => 'Directory does not exist',
                'path' => $path
            ];
            if ($overall_status === 'healthy') {
                $overall_status = 'warning';
            }
        } elseif (!is_writable($path)) {
            $results[$name] = [
                'status' => 'unhealthy',
                'message' => 'Directory not writable',
                'path' => $path
            ];
            $overall_status = 'unhealthy';
        } else {
            $results[$name] = [
                'status' => 'healthy',
                'message' => 'Directory writable',
                'path' => $path
            ];
        }
    }

    return [
        'status' => $overall_status,
        'message' => 'File system check complete',
        'directories' => $results
    ];
}

/**
 * Check disk space
 */
function checkDiskSpace() {
    $root_path = dirname(__FILE__);
    $free_space = disk_free_space($root_path);
    $total_space = disk_total_space($root_path);
    $used_percentage = round((($total_space - $free_space) / $total_space) * 100, 2);

    $status = 'healthy';
    if ($used_percentage > 90) {
        $status = 'critical';
    } elseif ($used_percentage > 80) {
        $status = 'warning';
    }

    return [
        'status' => $status,
        'message' => 'Disk usage at ' . $used_percentage . '%',
        'free_space_gb' => round($free_space / 1024 / 1024 / 1024, 2),
        'total_space_gb' => round($total_space / 1024 / 1024 / 1024, 2),
        'used_percentage' => $used_percentage
    ];
}

/**
 * Check PHP configuration
 */
function checkPHPConfig() {
    $checks = [
        'version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
    ];

    // Check if values are adequate
    $warnings = [];

    $memory_mb = (int)ini_get('memory_limit');
    if ($memory_mb < 128) {
        $warnings[] = 'memory_limit should be at least 128M';
    }

    $max_exec = (int)ini_get('max_execution_time');
    if ($max_exec < 120 && $max_exec != 0) {
        $warnings[] = 'max_execution_time should be at least 120 seconds';
    }

    return [
        'status' => empty($warnings) ? 'healthy' : 'warning',
        'message' => empty($warnings) ? 'PHP configuration OK' : implode(', ', $warnings),
        'config' => $checks
    ];
}

/**
 * Check Node.js Socket.io server
 */
function checkNodeServer() {
    global $wo;

    $port = $wo['config']['nodejs_port'] ?? 3000;
    $host = $wo['config']['nodejs_host'] ?? 'localhost';

    // Try to connect to Socket.io server
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);

    if ($fp) {
        fclose($fp);
        return [
            'status' => 'healthy',
            'message' => 'Node.js server responding',
            'host' => $host,
            'port' => $port
        ];
    }

    return [
        'status' => 'unhealthy',
        'message' => 'Node.js server not responding: ' . $errstr,
        'host' => $host,
        'port' => $port,
        'error_code' => $errno
    ];
}

/**
 * Run health checks
 */
$checks_to_run = [
    'database' => 'checkDatabase',
    'redis' => 'checkRedis',
    'filesystem' => 'checkFileSystem',
    'diskspace' => 'checkDiskSpace',
    'php' => 'checkPHPConfig',
    'nodejs' => 'checkNodeServer'
];

// If specific check requested, only run that one
if ($specific_check && isset($checks_to_run[$specific_check])) {
    $checks_to_run = [$specific_check => $checks_to_run[$specific_check]];
}

// Run checks
foreach ($checks_to_run as $name => $function) {
    $result = $function();
    $health_status['checks'][$name] = $result;

    // Update overall status
    if ($result['status'] === 'unhealthy' || $result['status'] === 'critical') {
        $health_status['status'] = 'unhealthy';
    } elseif ($result['status'] === 'warning' && $health_status['status'] === 'healthy') {
        $health_status['status'] = 'degraded';
    }
}

// Add system info if detailed
if ($detailed) {
    $health_status['system'] = [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'os' => PHP_OS,
        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'uptime' => file_exists('/proc/uptime') ? file_get_contents('/proc/uptime') : 'N/A'
    ];
}

// Set appropriate HTTP status code
if ($health_status['status'] === 'unhealthy') {
    http_response_code(503); // Service Unavailable
} elseif ($health_status['status'] === 'degraded') {
    http_response_code(200); // Still responding, just with warnings
} else {
    http_response_code(200); // OK
}

echo json_encode($health_status, JSON_PRETTY_PRINT);
