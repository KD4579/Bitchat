<?php
if ($f == 'session_status') {
    // Return session status for AJAX requests
    $data = array(
        'status' => 200,
        'logged_in' => $wo['loggedin'] ? true : false,
        'session_valid' => isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
    );

    // If logged in, refresh session timestamp to prevent expiry
    if ($wo['loggedin'] && isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
        $data['user_id'] = $wo['user']['user_id'];
    }

    // Add hash for CSRF protection renewal
    if ($wo['loggedin'] && !empty($_SESSION['hash_id'])) {
        $data['hash_id'] = $_SESSION['hash_id'];
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
