<?php
if ($f == 'recover') {
    // Rate limit password reset requests: 3 per hour per IP
    if (!bitchat_rate_limit('password_reset', get_ip_address(), 3, 3600)) {
        $errors = $error_icon . $wo['lang']['login_attempts'];
    }

    if (empty($errors) && empty($_POST['recoveremail'])) {
        $errors = $error_icon . $wo['lang']['please_check_details'];
    } else if (empty($errors)) {
        if (!filter_var($_POST['recoveremail'], FILTER_VALIDATE_EMAIL)) {
            $errors = $error_icon . $wo['lang']['email_invalid_characters'];
        } else if ($config['reCaptcha'] == 1) {
            if (!isset($_POST['g-recaptcha-response']) || empty($_POST['g-recaptcha-response'])) {
                $errors = $error_icon . $wo['lang']['reCaptcha_error'];
            }
        }
    }
    if (empty($errors)) {
        // Always show success to prevent email enumeration
        $data = array(
            'status' => 200,
            'message' => $success_icon . $wo['lang']['email_sent']
        );
        // Only actually send if email exists (but don't reveal this to the user)
        if (Wo_EmailExists($_POST['recoveremail']) === true) {
            $user_recover_data         = Wo_UserData(Wo_UserIdFromEmail($_POST['recoveremail']));
            $subject                   = $config['siteName'] . ' ' . $wo['lang']['password_rest_request'];
            // Use cryptographically secure token
            $code              = bin2hex(random_bytes(32));
            $user_recover_data['link'] = Wo_Link('index.php?link1=reset-password&code=' . $user_recover_data['user_id'] . '_' . $code);
            $time = time() + (60 * 60); // 1 hour expiry (not 12 hours)
            $query                     = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `email_code` = '" . Wo_Secure($code) . "' , `time_code_sent` = " . intval($time) . " WHERE `user_id` = " . intval($user_recover_data['user_id']));
            cache($user_recover_data['user_id'], 'users', 'delete');
            $wo['recover']             = $user_recover_data;
            $body                      = Wo_LoadPage('emails/recover');
            $send_message_data         = array(
                'from_email' => $wo['config']['siteEmail'],
                'from_name' => $wo['config']['siteName'],
                'to_email' => $_POST['recoveremail'],
                'to_name' => '',
                'subject' => $subject,
                'charSet' => 'utf-8',
                'message_body' => $body,
                'is_html' => true
            );
            $send                      = Wo_SendMessage($send_message_data);
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
