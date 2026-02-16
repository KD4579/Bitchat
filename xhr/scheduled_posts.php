<?php
if ($f == 'scheduled_posts') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);
    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';

    if ($action == 'save_settings') {
        if (isset($_POST['scheduled_posts_enabled'])) {
            Wo_SaveConfig('scheduled_posts_enabled', ($_POST['scheduled_posts_enabled'] == '1') ? '1' : '0');
        }
    } elseif ($action == 'cancel') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id > 0 && function_exists('Wo_DeleteScheduledPost')) {
            $result = Wo_DeleteScheduledPost($id, 0); // admin override
            if (!$result) {
                $data = array('status' => 400, 'message' => 'Could not cancel post');
            }
        } else {
            $data = array('status' => 400, 'message' => 'Invalid post ID');
        }
    } else {
        $data = array('status' => 400, 'message' => 'Unknown action');
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
