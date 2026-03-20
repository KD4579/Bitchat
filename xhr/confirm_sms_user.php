<?php
if ($f == 'confirm_sms_user') {
    // Rate limit SMS code verification to prevent brute force (5 attempts per 15 min per IP)
    if (!bitchat_rate_limit('confirm_sms', get_ip_address(), 5, 900)) {
        $errors = $error_icon . $wo['lang']['login_attempts'];
    }
    if (empty($errors) && !empty($_POST['confirm_code']) && !empty($_POST['user_id']) && is_numeric($_POST['user_id']) && $_POST['user_id'] > 0) {
        $confirm_code = $_POST['confirm_code'];
        $user_id      = intval($_POST['user_id']);

        // Per-user rate limit (5 attempts per code)
        if (!bitchat_rate_limit('confirm_sms_user_' . $user_id, $user_id, 5, 900)) {
            $errors = $error_icon . $wo['lang']['login_attempts'];
        }

        if (empty($errors)) {
            $confirm_code = Wo_ConfirmSMSUser($user_id, $confirm_code);
            if ($confirm_code === false) {
                $errors = $error_icon . $wo['lang']['wrong_confirmation_code'];
            }
        }
        if (empty($errors) && $confirm_code === true) {
            // Generate a secure one-time reset token instead of exposing password hash
            $reset_token = bin2hex(random_bytes(32));
            $time = time() + (60 * 60); // 1 hour expiry
            mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `email_code` = '" . Wo_Secure($reset_token) . "', `time_code_sent` = '{$time}' WHERE `user_id` = {$user_id}");
            cache($user_id, 'users', 'delete');
            $data = array(
                'status' => 200,
                'location' => $wo['config']['site_url'] . '/index.php?link1=reset-password&code=' . $user_id . "_" . $reset_token
            );
        }
    }
    header("Content-type: application/json");
    if (!empty($errors)) {
        echo json_encode(array(
            'errors' => $errors
        ));
    } else {
        echo json_encode($data);
    }
    exit();
}
