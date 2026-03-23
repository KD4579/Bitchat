<?php

$response_data = array(
    'api_status' => 400,
);
if (empty($_POST['group_id'])) {
    $error_code    = 3;
    $error_message = 'group_id (POST) is missing';
}
if (empty($_POST['user_id'])) {
    $error_code    = 4;
    $error_message = 'user_id (POST) is missing';
}

if (empty($error_code)) {
    $group_id = Wo_Secure($_POST['group_id']);
    $user_id  = Wo_Secure($_POST['user_id']);

    // SECURITY: verify the requester is the group owner or an authorized admin.
    // Previously any authenticated user could remove any member from any group (IDOR).
    // Allow self-leave always; removal of others requires owner/admin privilege.
    $is_self_leave = (intval($user_id) === intval($wo['user']['user_id']));
    if (!$is_self_leave && !Wo_IsCanGroupUpdate($group_id, 'members')) {
        $error_code    = 5;
        $error_message = 'Not authorized to remove members from this group';
    } elseif (Wo_LeaveGroup($group_id, $user_id) === true) {
        $response_data = array(
            'api_status' => 200,
            'message' => 'removed'
        );
    }
}