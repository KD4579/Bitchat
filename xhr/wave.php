<?php
if ($f == 'wave') {
    $data = array('status' => 304);
    if (!empty($_POST['receiver_id']) && Wo_CheckMainSession($hash_id) === true) {
        $receiver_id = Wo_Secure($_POST['receiver_id']);
        $sender_id   = Wo_Secure($wo['user']['id']);

        if ($receiver_id == $sender_id) {
            $data['status']  = 400;
            $data['message'] = 'Cannot wave at yourself';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check if already waved within last hour (rate limit)
        $one_hour_ago = time() - 3600;
        $check = mysqli_query($sqlConnect, "SELECT id FROM " . T_WAVES . "
                 WHERE sender_id = '{$sender_id}' AND receiver_id = '{$receiver_id}'
                 AND time > {$one_hour_ago}");
        if ($check && mysqli_num_rows($check) > 0) {
            $data['status']  = 429;
            $data['message'] = 'Already waved recently';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check receiver exists and shares location
        $receiver_data = Wo_UserData($receiver_id);
        if (empty($receiver_data['user_id']) || $receiver_data['share_my_location'] != 1) {
            $data['status'] = 403;
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check block status
        if (Wo_IsBlocked($receiver_id)) {
            $data['status'] = 403;
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Insert or update wave record
        $now = time();
        $wave_sql = "INSERT INTO " . T_WAVES . " (sender_id, receiver_id, time)
                     VALUES ('{$sender_id}', '{$receiver_id}', '{$now}')
                     ON DUPLICATE KEY UPDATE time = '{$now}'";
        $query = mysqli_query($sqlConnect, $wave_sql);

        if ($query) {
            Wo_RegisterNotification(array(
                'recipient_id' => $receiver_id,
                'notifier_id'  => $sender_id,
                'type'         => 'wave',
                'text'         => '',
                'type2'        => 'wave',
                'url'          => 'index.php?link1=friends-nearby',
                'post_id'      => 0
            ));
            $data['status'] = 200;
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
