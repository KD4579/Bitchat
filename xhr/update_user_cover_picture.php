<?php
if ($f == 'update_user_cover_picture') {
    // CSRF protection
    if (Wo_CheckSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403, 'message' => 'Invalid security token'));
        exit();
    }
    // IDOR protection: users can only update their own cover
    if (!empty($_POST['user_id']) && $_POST['user_id'] != $wo['user']['user_id'] && !Wo_IsAdmin()) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403, 'message' => 'Permission denied'));
        exit();
    }
    if (isset($_FILES['cover']['name'])) {
        $ai_post = 0;
        if ($wo['config']['ai_user_system'] == 1 && !empty($_POST['ai_post']) && $_POST['ai_post'] == 'on') {
            $ai_post = 1;
        }
        $safe_user_id = !empty($_POST['user_id']) ? $_POST['user_id'] : $wo['user']['user_id'];
        $upload = Wo_UploadImage($_FILES["cover"]["tmp_name"], $_FILES['cover']['name'], 'cover', $_FILES['cover']['type'], $safe_user_id, '', $ai_post);
        if ($upload === true) {
            $img              = Wo_UserData($_POST['user_id']);
            $_SESSION['file'] = $img['cover_org'];
            $data             = array(
                'status' => 200,
                'img' => $img['cover'],
                'cover_or' => $img['cover_org'],
                'cover_full' => Wo_GetMedia($img['cover_full']),
                'session' => $_SESSION['file']
            );
        } else {
            $data = $upload;
        }
    }
    Wo_CleanCache();
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
