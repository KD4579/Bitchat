<?php
if ($f == 'delete_all_sessions') {
    // CSRF protection for session management
    if (Wo_CheckSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403, 'error' => 'Invalid security token'));
        exit();
    }
    $delete_session = $db->where('user_id', $wo['user']['user_id'])->delete(T_APP_SESSIONS);
    if ($delete_session) {
        $data['status'] = 200;
    }
}
