<?php
if ($f == 'reset_password') {
    // Rate limit password reset attempts
    if (!bitchat_rate_limit('reset_password', get_ip_address(), 5, 900)) {
        $errors = $error_icon . $wo['lang']['login_attempts'];
    }

    if (empty($errors) && !empty($_POST['id'])) {
        $user_id     = explode("_", $_POST['id']);
        $user_id_int = intval($user_id[0]); // SECURITY: sanitize before any use in queries/calls
        if (Wo_isValidPasswordResetToken($_POST['id']) === false && Wo_isValidPasswordResetToken2($_POST['id']) === false) {
            $errors = $error_icon . $wo['lang']['invalid_token'];
        } elseif (empty($_POST['password'])) {
            $errors = $error_icon . $wo['lang']['please_check_details'];
        } elseif (strlen($_POST['password']) < 8) {
            $errors = $error_icon . $wo['lang']['password_short'];
        } elseif (!preg_match('/[a-zA-Z]/', $_POST['password']) || !preg_match('/[0-9]/', $_POST['password'])) {
            $errors = $error_icon . ($wo['lang']['password_weak'] ?? 'Password must contain at least one letter and one number');
        } else if (Wo_TwoFactor($user_id_int, 'id') === false) {
            // 2FA is enabled — DO NOT reset password yet. Store it in session
            // and require 2FA verification BEFORE changing the password.
            $_SESSION['code_id'] = $user_id_int;
            // Hash the pending password so plaintext is never stored in session/Redis
            $_SESSION['pending_reset_password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $_SESSION['pending_reset_token'] = $_POST['id'];
            $_SESSION['pending_reset_expires'] = time() + 900; // 15-min window to complete 2FA
            $data               = array(
                'status' => 600,
                'location' => $wo['config']['site_url'] . '/unusual-login?type=two-factor'
            );
            $phone               = 1;
        }
        if (empty($errors) && empty($phone)) {
            $password = $_POST['password'];
            if (Wo_ResetPassword($user_id_int, $password) === true) {
                // Invalidate reset token after use
                mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `email_code` = '', `time_code_sent` = 0 WHERE `user_id` = {$user_id_int}");
                // Invalidate all other sessions
                $session_id = Wo_CreateLoginSession($user_id_int);
                $_SESSION['user_id'] = $session_id;
                mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `user_id` = '{$user_id_int}' AND `session_id` <> '" . Wo_Secure($session_id) . "'");
                cache($user_id_int, 'users', 'delete');
                // SECURITY: only set success response when password was actually changed
                $data = array(
                    'status' => 200,
                    'message' => $success_icon . $wo['lang']['password_changed'],
                    'location' => $wo['config']['site_url']
                );
            } else {
                $errors = $error_icon . $wo['lang']['processing_error'];
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
