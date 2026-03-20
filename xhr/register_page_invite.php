<?php 
if ($f == 'register_page_invite') {
    if (!empty($_GET['user_id']) && !empty($_GET['page_id'])) {
        // SECURITY: Prevent inviting blocked users
        if (Wo_IsBlocked($_GET['user_id'])) {
            $data = array('status' => 403);
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
        $register_invite = Wo_RegsiterInvite($_GET['user_id'], $_GET['page_id']);
        if ($register_invite === true) {
            $data = array(
                'status' => 200
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
