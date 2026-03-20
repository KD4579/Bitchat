<?php 
if ($f == "update-event") {
    // SECURITY: Verify CSRF token and event ownership
    if (Wo_CheckSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403, 'message' => 'Invalid security token'));
        exit();
    }
    $event_id = isset($_GET['eid']) ? intval($_GET['eid']) : 0;
    $event_data = !empty($event_id) ? Wo_EventData($event_id) : array();
    if (empty($event_data) || $event_data['user_id'] != $wo['user']['user_id']) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403, 'message' => 'Permission denied'));
        exit();
    }
    if (true) {
        if (empty($_POST['event-name']) || empty($_POST['event-locat']) || empty($_POST['event-description'])) {
            $error = $error_icon . $wo['lang']['please_check_details'];
        } else {
            if (strlen($_POST['event-name']) < 10) {
                $error = $error_icon . $wo['lang']['title_more_than10'];
            }
            if (strlen($_POST['event-description']) < 10) {
                $error = $error_icon . $wo['lang']['desc_more_than32'];
            }
            if (empty($_POST['event-start-date'])) {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
            if (empty($_POST['event-end-date'])) {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
            if (empty($_POST['event-start-time'])) {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
            if (empty($_POST['event-end-time'])) {
                $error = $error_icon . $wo['lang']['please_check_details'];
            }
            if (empty($error)) {
                $date_start = explode('-', $_POST['event-start-date']);
                $date_end = explode('-', $_POST['event-end-date']);
                if ($date_start[0] < $date_end[0]) {
                }
                else{
                    if ($date_start[0] > $date_end[0]) {
                        $error = $error_icon . $wo['lang']['please_check_details'];
                    }
                    else{
                        if ($date_start[1] < $date_end[1]) {
                        }
                        else{
                            if ($date_start[1] > $date_end[1]) {
                                $error = $error_icon . $wo['lang']['please_check_details'];
                            }
                            else{
                                if ($date_start[2] < $date_end[2]) {

                                }
                                else{
                                    if ($date_start[2] > $date_end[2]) {
                                        $error = $error_icon . $wo['lang']['please_check_details'];
                                    }
                                }
                            }
                        }
                    } 
                }
            }
        }
        if (empty($error) && isset($_GET['eid']) && is_numeric($_GET['eid'])) {
            $registration_data = array(
                'name' => Wo_Secure($_POST['event-name'], 1),
                'location' => Wo_Secure($_POST['event-locat'], 1),
                'description' => Wo_Secure($_POST['event-description'], 1),
                'start_date' => Wo_Secure($_POST['event-start-date']),
                'start_time' => Wo_Secure($_POST['event-start-time']),
                'end_date' => Wo_Secure($_POST['event-end-date']),
                'end_time' => Wo_Secure($_POST['event-end-time'])
            );
            $result            = Wo_UpdateEvent($_GET['eid'], $registration_data);
            if ($result) {
                if (!empty($_FILES["event-cover"]["tmp_name"])) {
                    $temp_name = $_FILES["event-cover"]["tmp_name"];
                    $file_name = $_FILES["event-cover"]["name"];
                    $file_type = $_FILES['event-cover']['type'];
                    $file_size = $_FILES["event-cover"]["size"];
                    Wo_UploadImage($temp_name, $file_name, 'cover', $file_type, $_GET['eid'], 'event');
                }
                $data = array(
                    'message' => $success_icon . $wo['lang']['event_saved'],
                    'status' => 200,
                    'url' => Wo_SeoLink("index.php?link1=show-event&eid=" . $_GET['eid'])
                );
            }
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
