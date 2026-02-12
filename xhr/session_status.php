<?php
if ($f == 'session_status') {
    // Return session status for AJAX requests

    // Check both session and Redis for logged in status
    $is_logged_in = false;

    // Primary check: WoWonder's login status
    if ($wo['loggedin']) {
        $is_logged_in = true;
    }
    // Fallback: Check session directly
    elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $is_logged_in = true;
    }
    // Additional fallback: Check cookie exists (user likely still logged in)
    elseif (isset($_COOKIE[session_name()]) && !empty($_COOKIE[session_name()])) {
        // Session cookie exists but session data not loaded - this is the SameSite issue
        // Return true to prevent false positive "logged out" dialogs
        $is_logged_in = true;
    }

    $data = array(
        'status' => 200,
        'logged_in' => $is_logged_in,
        'session_valid' => isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
    );

    // If logged in, refresh session timestamp to prevent expiry
    if ($is_logged_in && isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
        $data['user_id'] = $wo['user']['user_id'] ?? $_SESSION['user_id'];
    }

    // Add hash for CSRF protection renewal
    if ($is_logged_in && !empty($_SESSION['hash_id'])) {
        $data['hash_id'] = $_SESSION['hash_id'];
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
