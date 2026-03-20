<?php
if ($f == 'follow_users') {
    // CSRF protection for mass follow
    if (Wo_CheckSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403));
        exit();
    }
    if (!empty($_POST['user'])) {
        $continue = false;
        $ids      = @explode(',', $_POST['user']);
        foreach ($ids as $id) {
            if (Wo_RegisterFollow($id, $wo['user']['user_id']) === true) {
                $continue = true;
            }
        }
        Wo_UpdateUserData($wo['user']['user_id'], array(
            'startup_follow' => '1',
            'start_up' => '1'
        ));
        $user_data = Wo_UpdateUserDetails($wo['user']['user_id'], false, false, true);
        $data = array(
            'status' => 200
        );
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
