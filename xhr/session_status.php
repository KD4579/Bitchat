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
