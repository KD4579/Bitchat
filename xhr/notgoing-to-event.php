<?php 
if ($f == "notgoing-to-event" && Wo_CheckMainSession($hash_id) === true) {
    $data = array(
        'status' => 500
    );
    if (isset($_GET['eid']) && is_numeric($_GET['eid'])) {
        if (Wo_UnsetEventGoingUsers($_GET['eid'])) {
            $data['status'] = 200;
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
