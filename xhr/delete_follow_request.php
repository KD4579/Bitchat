<?php 
if ($f == 'delete_follow_request') {
    // Initialize with error state
    $data = array(
        'status' => 400,
        'error' => 'Failed to delete follow request. Please try again.'
    );

    if (isset($_GET['following_id'])) {
        if (Wo_DeleteFollowRequest($_GET['following_id'], $wo['user']['user_id'])) {
            $data = array(
                'status' => 200,
                'html' => Wo_GetFollowButton($_GET['following_id'])
            );
        } else {
            $data = array(
                'status' => 500,
                'error' => 'Failed to delete follow request. The request may have already been removed.'
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
