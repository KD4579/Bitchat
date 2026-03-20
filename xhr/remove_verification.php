<?php
if ($f == 'remove_verification') {
    if (Wo_CheckSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403));
        exit();
    }
    if (!empty($_GET['id']) && !empty($_GET['type'])) {
        if (Wo_RemoveVerificationRequest($_GET['id'], $_GET['type']) === true) {
            $data = array(
                'status' => 200,
                'html' => Wo_GetVerificationButton($_GET['id'], $_GET['type'])
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
