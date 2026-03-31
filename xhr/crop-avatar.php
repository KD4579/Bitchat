<?php 
if ($f == 'crop-avatar' && Wo_CheckMainSession($hash_id) === true) {
    if (Wo_IsAdmin() || $wo['user']['user_id'] == $_POST['user_id']) {
        // SECURITY: ensure path stays within upload/ directory (prevent path traversal)
        $safe_path = $_POST['path'] ?? '';
        $real = realpath('./' . ltrim($safe_path, '/'));
        $upload_base = realpath('./upload');
        if (!$real || !$upload_base || strpos($real, $upload_base) !== 0) {
            header("Content-type: application/json");
            echo json_encode(['status' => 400, 'message' => 'Invalid path']);
            exit();
        }
        $crop_image = Wo_CropAvatarImage($safe_path, array(
            'x' => $_POST['x'],
            'y' => $_POST['y'],
            'w' => $_POST['width'],
            'h' => $_POST['height']
        ));
        if ($crop_image) {
            $update_user_data = Wo_UpdateUserData($_POST['user_id'], array(
                'last_avatar_mod' => time()
            ));
            $data             = array(
                'status' => 200
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
