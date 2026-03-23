<?php 
if ($f == 'follow_user' && $wo['loggedin'] === true) {
    // Initialize data array with error state
    $data = array(
        'status' => 400,
        'error' => 'Invalid request. Please try again.'
    );

    if (isset($_GET['following_id']) && Wo_CheckMainSession($hash_id) === true) {
        $user_followers = Wo_CountFollowing($wo['user']['user_id'], true);
        $friends_limit  = $wo['config']['connectivitySystemLimit'];
        if (Wo_IsFollowing($_GET['following_id'], $wo['user']['user_id']) === true || Wo_IsFollowRequested($_GET['following_id'], $wo['user']['user_id']) === true) {
            if (Wo_DeleteFollow($_GET['following_id'], $wo['user']['user_id'])) {
                $data = array(
                    'status' => 200,
                    'can_send' => 0,
                    'html' => ''
                );
            } else {
                $data = array(
                    'status' => 500,
                    'error' => 'Failed to unfollow user. Please try again.'
                );
            }
        } else if ($wo['config']['connectivitySystem'] == 1 && $user_followers >= $friends_limit) {
            $data = array(
                'status' => 400,
                'can_send' => 0,
                'error' => "You've reached your connection limit of {$friends_limit} follows. Please upgrade your plan or unfollow some users first."
            );
        } else {
            if (Wo_RegisterFollow($_GET['following_id'], $wo['user']['user_id'])) {
                $data = array(
                    'status' => 200,
                    'can_send' => 0,
                    'html' => ''
                );
                if (Wo_CanSenEmails()) {
                    $data['can_send'] = 1;
                }
            } else {
                $data = array(
                    'status' => 500,
                    'error' => 'Failed to follow user. Please try again.'
                );
            }
        }
    }
    if ($wo['loggedin'] == true) {
        Wo_CleanCache();
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
