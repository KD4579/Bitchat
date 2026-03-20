<?php
if ($f == "delete-event") {
    // CSRF protection
    if (Wo_CheckMainSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403));
        exit();
    }
    $data = array(
        'status' => 400,
        'error' => 'Invalid event ID. Please try again.'
    );

    if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
        if (Wo_DeleteEvent($_GET['id'])) {
            $data = array(
                'status' => 200,
                'message' => 'Event deleted successfully.'
            );
        } else {
            $data = array(
                'status' => 500,
                'error' => 'Failed to delete event. You may not have permission to delete this event.'
            );
        }
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
