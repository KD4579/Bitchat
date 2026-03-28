<?php 
if ($f == "update_socialinks_setting") {
    if (isset($_POST['user_id']) && is_numeric($_POST['user_id']) && $_POST['user_id'] > 0 && Wo_CheckSession($hash_id) === true) {
        // Only allow users to update their own social links, or admins
        if ($_POST['user_id'] != $wo['user']['user_id'] && !Wo_IsAdmin()) {
            header("Content-type: application/json");
            echo json_encode(array('errors' => array('Permission denied')));
            exit();
        }
        $Userdata = Wo_UserData($_POST['user_id']);
        if (!empty($Userdata['user_id'])) {
            if (empty($errors)) {
                $Update_data = array(
                    'facebook' => $_POST['facebook'],
                    'linkedin' => $_POST['linkedin'],
                    'vk' => $_POST['vk'],
                    'instagram' => $_POST['instagram'],
                    'twitter' => $_POST['twitter'],
                    'youtube' => $_POST['youtube']
                );
                if (isset($_POST['tradex24_user_id'])) {
                    $Update_data['tradex24_user_id'] = Wo_Secure(preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['tradex24_user_id']), 0);
                }
                if (Wo_UpdateUserData($_POST['user_id'], $Update_data)) {
                    $field_data = array();
                    if (!empty($_POST['custom_fields'])) {
                        $fields = Wo_GetProfileFields('social');
                        foreach ($fields as $key => $field) {
                            $name = $field['fid'];
                            if (isset($_POST[$name])) {
                                if (mb_strlen($_POST[$name]) > $field['length']) {
                                    $errors[] = $error_icon . $field['name'] . ' field max characters is ' . $field['length'];
                                }
                                $field_data[] = array(
                                    $name => $_POST[$name]
                                );
                            }
                        }
                    }
                    if (!empty($field_data)) {
                        $insert = Wo_UpdateUserCustomData($_POST['user_id'], $field_data);
                    }
                    if (empty($errors)) {
                        $data = array(
                            'status' => 200,
                            'message' => $success_icon . $wo['lang']['setting_updated']
                        );
                    }
                }
            }
        }
    }
    header("Content-type: application/json");
    if (isset($errors)) {
        echo json_encode(array(
            'errors' => $errors
        ));
    } else {
        echo json_encode($data);
    }
    exit();
}
