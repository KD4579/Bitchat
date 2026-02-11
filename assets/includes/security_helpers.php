<?php
// +------------------------------------------------------------------------+
// | Security Helper Functions for Bitchat
// | Rate limiting, input sanitization, and security checks
// +------------------------------------------------------------------------+

class BitchatSecurity {

    /**
     * Rate limit an action
     * @param string $action Action name (e.g., 'login', 'post', 'comment')
     * @param string $identifier User ID or IP address
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $window Time window in seconds
     * @return bool True if action allowed, false if rate limited
     */
    public static function rateLimit($action, $identifier, $maxAttempts = 10, $window = 60) {
        // Use Redis if available
        if (class_exists('BitchatCache') && BitchatCache::isEnabled()) {
            return BitchatCache::rateLimit($action, $identifier, $maxAttempts, $window);
        }

        // Fallback to session-based rate limiting
        $key = "rate_limit_{$action}_{$identifier}";
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = array('count' => 0, 'start' => time());
        }

        $data = &$_SESSION[$key];

        // Reset if window expired
        if (time() - $data['start'] > $window) {
            $data['count'] = 0;
            $data['start'] = time();
        }

        $data['count']++;

        return $data['count'] <= $maxAttempts;
    }

    /**
     * Check if request is from admin with proper session
     * @return bool
     */
    public static function isValidAdminRequest() {
        global $wo;

        if (!$wo['loggedin']) {
            return false;
        }

        if (!Wo_IsAdmin() && !Wo_IsModerator()) {
            return false;
        }

        // Check session age (max 4 hours for admin)
        if (isset($_SESSION['admin_login_time'])) {
            if (time() - $_SESSION['admin_login_time'] > 14400) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize HTML content - removes dangerous tags/attributes
     * @param string $content Raw content
     * @return string Sanitized content
     */
    public static function sanitizeHtml($content) {
        if (empty($content)) {
            return '';
        }

        // Remove script tags and event handlers
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
        $content = preg_replace('/on\w+\s*=\s*[^\s>]*/i', '', $content);

        // Remove javascript: urls
        $content = preg_replace('/javascript\s*:/i', '', $content);
        $content = preg_replace('/vbscript\s*:/i', '', $content);
        $content = preg_replace('/data\s*:/i', 'data-blocked:', $content);

        // Encode special characters
        $content = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

        return $content;
    }

    /**
     * Sanitize post/comment text while preserving allowed formatting
     * @param string $text Raw text
     * @return string Sanitized text
     */
    public static function sanitizePostText($text) {
        if (empty($text)) {
            return '';
        }

        // Remove null bytes
        $text = str_replace(chr(0), '', $text);

        // Remove script tags
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);

        // Remove event handlers
        $text = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $text);

        // Remove javascript: urls
        $text = preg_replace('/javascript\s*:/i', '', $text);

        // Trim excessive whitespace
        $text = preg_replace('/\s{10,}/', '          ', $text);

        return trim($text);
    }

    /**
     * Validate and sanitize file upload
     * @param array $file $_FILES array element
     * @param array $allowedTypes Allowed MIME types
     * @param int $maxSize Maximum file size in bytes
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateFileUpload($file, $allowedTypes = [], $maxSize = 10485760) {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid upload'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Upload error: ' . $file['error']];
        }

        if ($file['size'] > $maxSize) {
            return ['valid' => false, 'error' => 'File too large'];
        }

        // Check MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!empty($allowedTypes) && !in_array($mimeType, $allowedTypes)) {
            return ['valid' => false, 'error' => 'Invalid file type'];
        }

        // Check for PHP code in file
        $content = file_get_contents($file['tmp_name'], false, null, 0, 1024);
        if (preg_match('/<\?php|<\?=/i', $content)) {
            return ['valid' => false, 'error' => 'Invalid file content'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Generate CSRF token
     * @return string
     */
    public static function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     * @param string $token Token to verify
     * @return bool
     */
    public static function verifyCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Require valid CSRF token or terminate request
     * Call this at the beginning of sensitive XHR handlers
     * @param string|null $token Token to verify (auto-detects from POST/GET if null)
     * @param bool $logFailure Whether to log CSRF failures (default: true)
     * @return void Exits with 403 error if token invalid
     */
    public static function requireCsrfToken($token = null, $logFailure = true) {
        // Auto-detect token from request
        // Checks: csrf_token (new), hash_id (legacy form), hash (AJAX legacy)
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ??
                     $_POST['hash_id'] ?? $_GET['hash_id'] ??
                     $_POST['hash'] ?? $_GET['hash'] ?? null;
        }

        // Verify token using new or legacy method
        $isValid = self::verifyCsrfToken($token) ||
                   (isset($_SESSION['main_hash_id']) && hash_equals($_SESSION['main_hash_id'], $token ?? '')) ||
                   (isset($_SESSION['hash_id']) && hash_equals($_SESSION['hash_id'], $token ?? ''));

        if (empty($token) || !$isValid) {
            if ($logFailure) {
                self::logSecurityEvent('CSRF_FAILURE',
                    'Invalid or missing CSRF token | Request: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
            }

            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 403,
                'error' => 'Invalid security token. Please refresh the page and try again.',
                'error_id' => 'csrf_token_invalid'
            ]);
            exit();
        }
    }

    /**
     * Log security event
     * @param string $event Event type
     * @param string $details Event details
     */
    public static function logSecurityEvent($event, $details = '') {
        $logEntry = date('Y-m-d H:i:s') . " | {$event} | " .
                    ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " | " .
                    ($_SESSION['user_id'] ?? 'guest') . " | {$details}\n";

        $logFile = dirname(__FILE__) . '/../../logs/security.log';
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

// Initialize security helpers
if (!function_exists('bitchat_rate_limit')) {
    function bitchat_rate_limit($action, $identifier, $max = 10, $window = 60) {
        return BitchatSecurity::rateLimit($action, $identifier, $max, $window);
    }
}

if (!function_exists('bitchat_sanitize')) {
    function bitchat_sanitize($text) {
        return BitchatSecurity::sanitizePostText($text);
    }
}
