<?php
if ($f == 'accept_follow_request') {
    // CSRF Protection - Prevent unauthorized follow request acceptance
    BitchatSecurity::requireCsrfToken();

    // Initialize with error state
    $data = array(
        'status' => 400,
        'error' => 'Failed to accept follow request. Please try again.'
    );

    if (isset($_GET['following_id'])) {
        if (Wo_AcceptFollowRequest($_GET['following_id'], $wo['user']['user_id'])) {
            $data = array(
                'status' => 200,
                'html' => Wo_GetFollowButton($_GET['following_id'])
            );
        } else {
            $data = array(
                'status' => 500,
                'error' => 'Failed to accept follow request. The request may have expired or been withdrawn.'
            );
        }
    } else {
        $data = array(
            'status' => 400,
            'error' => 'Invalid request. Missing required parameters.'
        );
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
