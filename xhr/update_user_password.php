<?php 
if ($f == "update_user_password") {
    if (isset($_POST['user_id']) && is_numeric($_POST['user_id']) && $_POST['user_id'] > 0 && Wo_CheckSession($hash_id) === true) {
        // Only allow users to change their own password, or admins to change any
        if (intval($_POST['user_id']) !== intval($wo['user']['user_id']) && !Wo_IsAdmin()) { // SECURITY: strict int comparison prevents type juggling
            $errors[] = $wo['lang']['permission_denied'] ?? 'Permission denied';
        }
        $Userdata = Wo_UserData($_POST['user_id']);
        if (!empty($Userdata['user_id']) && empty($errors)) {
            $isSocialUser = !empty($Userdata['social_login']);
            if ((!$isSocialUser && empty($_POST['current_password'])) OR empty($_POST['new_password']) OR empty($_POST['repeat_new_password'])) {
                $errors[] = $error_icon . $wo['lang']['please_check_details'];
            } else {
                // Social login users (Google, Facebook, etc.) have no known password — skip current password check
                if (!$isSocialUser) {
                    // Verify current password against the TARGET user's password (not logged-in user)
                    $targetPassword = $Userdata['password'];
                    // SECURITY: strict === avoids type juggling (0 == false in PHP)
                    if (Wo_HashPassword($_POST['current_password'], $targetPassword) === false) {
                        $errors[] = $error_icon . $wo['lang']['current_password_mismatch'];
                    }
                }
                if ($_POST['new_password'] != $_POST['repeat_new_password']) {
                    $errors[] = $error_icon . $wo['lang']['password_mismatch'];
                }
                if (strlen($_POST['new_password']) < 8) {
                    $errors[] = $error_icon . $wo['lang']['password_short'];
                }
                if (!preg_match('/[a-zA-Z]/', $_POST['new_password']) || !preg_match('/[0-9]/', $_POST['new_password'])) {
                    $errors[] = $error_icon . ($wo['lang']['password_weak'] ?? 'Password must contain at least one letter and one number');
                }
                if (empty($errors)) {
                    $Update_data = array(
                        'password' => password_hash($_POST['new_password'], PASSWORD_DEFAULT)
                    );
                    if (Wo_UpdateUserData($_POST['user_id'], $Update_data)) {
                        $user_id    = Wo_Secure($_POST['user_id']);
                        // SECURITY: use only server-side session — cookie is user-controlled and must not be trusted
                        $session_id = Wo_Secure($_SESSION['user_id'] ?? '');
                        $mysqli     = mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `user_id` = '{$user_id}' AND `session_id` <> '{$session_id}'");
                        $data       = array(
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
