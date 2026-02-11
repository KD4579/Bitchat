<?php 
if ($f == 'join_group') {
    // Initialize with error state
    $data = array(
        'status' => 400,
        'error' => 'Invalid request. Please try again.'
    );

    if (isset($_GET['group_id']) && Wo_CheckMainSession($hash_id) === true) {
        if (Wo_IsGroupJoined($_GET['group_id']) === true || Wo_IsJoinRequested($_GET['group_id'], $wo['user']['user_id']) === true) {
            if (Wo_LeaveGroup($_GET['group_id'], $wo['user']['user_id'])) {
                $data = array(
                    'status' => 200,
                    'html' => ''
                );
            } else {
                $data = array(
                    'status' => 500,
                    'error' => 'Failed to leave group. Please try again.'
                );
            }
        } else {
            if (Wo_RegisterGroupJoin($_GET['group_id'], $wo['user']['user_id'])) {
                $data = array(
                    'status' => 200,
                    'html' => ''
                );
                if (Wo_CanSenEmails()) {
                    $data['can_send'] = 1;
                }
            } else {
                $data = array(
                    'status' => 500,
                    'error' => 'Failed to join group. The group may be private or no longer exists.'
                );
            }
        }
    } else if (!isset($_GET['group_id'])) {
        $data = array(
            'status' => 400,
            'error' => 'Invalid request. Missing group ID.'
        );
    }
    if ($wo['loggedin'] == true) {
        Wo_CleanCache();
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
