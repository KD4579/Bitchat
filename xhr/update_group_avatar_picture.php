<?php 
if ($f == 'update_group_avatar_picture') {
    if (isset($_FILES['avatar']['name']) && !empty($_POST['group_id']) && is_numeric($_POST['group_id']) && $_POST['group_id'] > 0) {
        // Verify user is group owner/admin before allowing avatar change
        $groupData = Wo_GroupData($_POST['group_id']);
        if (empty($groupData) || ($groupData['user_id'] != $wo['user']['user_id'] && !Wo_IsAdmin())) {
            header("Content-type: application/json");
            echo json_encode(array('status' => 403));
            exit();
        }
        if (Wo_UploadImage($_FILES["avatar"]["tmp_name"], $_FILES['avatar']['name'], 'avatar', $_FILES['avatar']['type'], $_POST['group_id'], 'group')) {
            $img  = Wo_GroupData($_POST['group_id']);
            $data = array(
                'status' => 200,
                'img' => $img['avatar']
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
