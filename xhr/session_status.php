<?php
if ($f == 'session_status') {
    // Return session status for AJAX requests
    // Uses status 304 for logged-in users and status 200 for logged-out users
    // This ensures compatibility with cached JS that shows the modal on status == 200

    $is_logged_in = false;

    // Primary check: WoWonder's login status (set in app_start.php via Wo_IsLogged())
    // This checks $_SESSION['user_id'] first, then $_COOKIE['user_id'] fallback,
    // both validated against Wo_AppSessions database table
    if ($wo['loggedin']) {
        $is_logged_in = true;
    }

    // Diagnostic logging (temporary - remove after debugging)
    $log_data = date('Y-m-d H:i:s') . ' | ' .
        'wo_loggedin=' . ($wo['loggedin'] ? 'true' : 'false') . ' | ' .
        'session_user_id=' . (isset($_SESSION['user_id']) ? substr($_SESSION['user_id'], 0, 10) . '...' : 'EMPTY') . ' | ' .
        'cookie_user_id=' . (isset($_COOKIE['user_id']) ? substr($_COOKIE['user_id'], 0, 10) . '...' : 'EMPTY') . ' | ' .
        'phpsessid_cookie=' . (isset($_COOKIE[session_name()]) ? 'YES' : 'NO') . ' | ' .
        'result=' . ($is_logged_in ? '304' : '200') . "\n";
    @file_put_contents('/tmp/session_status_debug.log', $log_data, FILE_APPEND);

    if ($is_logged_in) {
        // User IS logged in - return status 304
        // Old cached JS checks "if(data.status == 200)" to show modal - won't match 304
        // New JS checks "data.logged_in === false" - also won't trigger
        $data = array(
            'status' => 304,
            'logged_in' => true,
            'session_valid' => true
        );

        // Refresh session timestamp to prevent expiry
        if (isset($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
        }
    } else {
        // User is NOT logged in - return status 200
        // Both old and new JS will show the logged-out modal
        $data = array(
            'status' => 200,
            'logged_in' => false,
            'session_valid' => false
        );
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
