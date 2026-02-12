<?php
/**
 * Comprehensive Error Logging System for Bitchat
 * Provides structured logging with levels, context, and rotation
 *
 * Features:
 * - Multiple log levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
 * - Automatic log rotation (daily files)
 * - Context data support (user ID, request info, stack traces)
 * - Performance metrics
 * - Integration with existing security logger
 */

class ErrorLogger {

    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';

    private static $log_dir = null;
    private static $max_file_size = 10485760; // 10MB
    private static $enabled = true;
    private static $log_to_file = true;
    private static $log_to_database = false;

    /**
     * Initialize logger with configuration
     */
    public static function init($config = []) {
        global $wo;

        // Set log directory
        self::$log_dir = $config['log_dir'] ?? dirname(__DIR__, 2) . '/logs';

        // Create logs directory if it doesn't exist
        if (!file_exists(self::$log_dir)) {
            @mkdir(self::$log_dir, 0755, true);
        }

        // Set configuration
        self::$enabled = $config['enabled'] ?? true;
        self::$log_to_file = $config['log_to_file'] ?? true;
        self::$log_to_database = $config['log_to_database'] ?? false;
        self::$max_file_size = $config['max_file_size'] ?? 10485760;

        // Register error and exception handlers
        if ($config['register_handlers'] ?? false) {
            set_error_handler([self::class, 'errorHandler']);
            set_exception_handler([self::class, 'exceptionHandler']);
            register_shutdown_function([self::class, 'shutdownHandler']);
        }
    }

    /**
     * Log a message with context
     */
    public static function log($level, $message, $context = []) {
        if (!self::$enabled) {
            return;
        }

        // Build log entry
        $entry = self::buildLogEntry($level, $message, $context);

        // Write to file
        if (self::$log_to_file) {
            self::writeToFile($level, $entry);
        }

        // Write to database (optional)
        if (self::$log_to_database) {
            self::writeToDatabase($level, $message, $context);
        }

        // Send critical errors to admin email (optional)
        if ($level === self::CRITICAL && isset($context['notify_admin']) && $context['notify_admin']) {
            self::notifyAdmin($message, $context);
        }
    }

    /**
     * Convenience methods for each log level
     */
    public static function debug($message, $context = []) {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info($message, $context = []) {
        self::log(self::INFO, $message, $context);
    }

    public static function warning($message, $context = []) {
        self::log(self::WARNING, $message, $context);
    }

    public static function error($message, $context = []) {
        self::log(self::ERROR, $context);
    }

    public static function critical($message, $context = []) {
        self::log(self::CRITICAL, $message, $context);
    }

    /**
     * Build structured log entry
     */
    private static function buildLogEntry($level, $message, $context) {
        global $wo;

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'user_id' => $wo['user']['user_id'] ?? 'guest',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        // Add context data
        if (!empty($context)) {
            $entry['context'] = $context;
        }

        // Add stack trace for errors
        if (in_array($level, [self::ERROR, self::CRITICAL]) && empty($context['trace'])) {
            $entry['trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        }

        // Add memory usage
        $entry['memory_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);

        return $entry;
    }

    /**
     * Write log entry to file
     */
    private static function writeToFile($level, $entry) {
        $log_file = self::getLogFilePath($level);

        // Check file size and rotate if needed
        if (file_exists($log_file) && filesize($log_file) > self::$max_file_size) {
            self::rotateLogFile($log_file);
        }

        // Format entry as JSON for easier parsing
        $formatted = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        // Also create human-readable format
        $readable = sprintf(
            "[%s] [%s] %s | User: %s | IP: %s | URI: %s\n",
            $entry['timestamp'],
            $entry['level'],
            $entry['message'],
            $entry['user_id'],
            $entry['ip'],
            $entry['request_uri']
        );

        // Write to file
        @file_put_contents($log_file, $readable, FILE_APPEND | LOCK_EX);

        // Write detailed JSON to separate file
        $json_file = str_replace('.log', '.json', $log_file);
        @file_put_contents($json_file, $formatted, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get log file path based on level and date
     */
    private static function getLogFilePath($level) {
        $date = date('Y-m-d');
        $level_lower = strtolower($level);
        return self::$log_dir . "/{$level_lower}-{$date}.log";
    }

    /**
     * Rotate log file when it exceeds max size
     */
    private static function rotateLogFile($log_file) {
        $rotated = $log_file . '.' . time();
        @rename($log_file, $rotated);

        // Compress old log (optional)
        if (function_exists('gzopen')) {
            self::compressLogFile($rotated);
        }
    }

    /**
     * Compress rotated log file
     */
    private static function compressLogFile($file) {
        $gz_file = $file . '.gz';

        if ($fp_in = fopen($file, 'rb')) {
            if ($fp_out = gzopen($gz_file, 'wb9')) {
                while (!feof($fp_in)) {
                    gzwrite($fp_out, fread($fp_in, 8192));
                }
                gzclose($fp_out);
            }
            fclose($fp_in);

            // Delete original after compression
            @unlink($file);
        }
    }

    /**
     * Write to database (optional)
     */
    private static function writeToDatabase($level, $message, $context) {
        global $db;

        if (!$db) return;

        try {
            $db->insert('Wo_ErrorLogs', [
                'level' => $level,
                'message' => $message,
                'context' => json_encode($context),
                'user_id' => $wo['user']['user_id'] ?? 0,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            // Silently fail - don't break application if logging fails
        }
    }

    /**
     * Notify admin of critical errors
     */
    private static function notifyAdmin($message, $context) {
        global $wo;

        if (empty($wo['config']['adminEmail'])) {
            return;
        }

        $subject = "[CRITICAL] Bitchat Error Alert";
        $body = "A critical error occurred:\n\n";
        $body .= "Message: {$message}\n";
        $body .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $body .= "URL: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown') . "\n";
        $body .= "User: " . ($wo['user']['username'] ?? 'Guest') . "\n\n";
        $body .= "Context: " . print_r($context, true);

        @mail($wo['config']['adminEmail'], $subject, $body);
    }

    /**
     * Custom error handler
     */
    public static function errorHandler($errno, $errstr, $errfile, $errline) {
        // Don't log errors suppressed with @
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $level = self::ERROR;

        switch ($errno) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                $level = self::CRITICAL;
                break;
            case E_WARNING:
            case E_USER_WARNING:
                $level = self::WARNING;
                break;
        }

        self::log($level, $errstr, [
            'file' => $errfile,
            'line' => $errline,
            'error_no' => $errno
        ]);

        return false; // Let PHP handle it too
    }

    /**
     * Custom exception handler
     */
    public static function exceptionHandler($exception) {
        self::critical($exception->getMessage(), [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'notify_admin' => true
        ]);
    }

    /**
     * Shutdown handler for fatal errors
     */
    public static function shutdownHandler() {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::critical('Fatal error: ' . $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'notify_admin' => true
            ]);
        }
    }

    /**
     * Clean old log files (keep last 30 days)
     */
    public static function cleanOldLogs($days = 30) {
        if (!is_dir(self::$log_dir)) {
            return;
        }

        $cutoff = time() - ($days * 86400);
        $files = glob(self::$log_dir . '/*.log*');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Get recent logs (for admin dashboard)
     */
    public static function getRecentLogs($level = null, $limit = 100) {
        $logs = [];
        $files = glob(self::$log_dir . '/*.json');

        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        foreach ($files as $file) {
            if ($level && strpos(basename($file), strtolower($level)) === false) {
                continue;
            }

            $content = file_get_contents($file);
            $entries = array_filter(explode("\n", $content));

            foreach ($entries as $entry) {
                $log = json_decode($entry, true);
                if ($log) {
                    $logs[] = $log;

                    if (count($logs) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return $logs;
    }
}

// Auto-initialize if config exists
if (isset($wo['config']['error_logging'])) {
    ErrorLogger::init($wo['config']['error_logging']);
}
