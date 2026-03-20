<?php
if ($f == 'update_page_cover_picture') {
    if (isset($_FILES['cover']['name']) && !empty($_POST['page_id']) && is_numeric($_POST['page_id']) && $_POST['page_id'] > 0) {
        // SECURITY: Verify page ownership before allowing cover upload
        $page_check = Wo_PageData($_POST['page_id']);
        if (empty($page_check) || ($page_check['user_id'] != $wo['user']['user_id'] && !Wo_IsAdmin())) {
            header("Content-type: application/json");
            echo json_encode(array('status' => 403));
            exit();
        }
        if (Wo_UploadImage($_FILES["cover"]["tmp_name"], $_FILES['cover']['name'], 'cover', $_FILES['cover']['type'], $_POST['page_id'], 'page')) {
            $img  = Wo_PageData($_POST['page_id']);
            $data = array(
                'status' => 200,
                'img' => $img['cover']
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
