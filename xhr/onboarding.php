<?php
if ($f == 'onboarding') {
    if (!empty($_POST['action']) && $_POST['action'] == 'complete' && $wo['loggedin'] == true) {
        if (Wo_CheckSession($hash_id) === true) {
            $userId = intval($wo['user']['user_id']);
            mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET onboarding_completed = 1 WHERE user_id = {$userId}");
            cache($userId, 'users', 'delete');
            echo json_encode(array('status' => 200));
            exit();
        }
    }
    echo json_encode(array('status' => 400));
    exit();
}
