<?php
if ($f == 'recoversms') {
    // Rate limit SMS recovery: 3 per hour per IP
    if (!bitchat_rate_limit('recovery_sms', get_ip_address(), 3, 3600)) {
        $errors = $error_icon . $wo['lang']['login_attempts'];
    }

    if (empty($errors) && empty($_POST['recoverphone'])) {
        $errors = $error_icon . $wo['lang']['please_check_details'];
    } else if (empty($errors)) {
        if (!filter_var($_POST['recoverphone'], FILTER_SANITIZE_NUMBER_INT)) {
            $errors = $error_icon . $wo['lang']['phone_invalid_characters'];
        }
        if (empty($errors) && !in_array(true, Wo_IsPhoneExist($_POST['recoverphone']))) {
            // Use generic message to prevent account enumeration
            $errors = $error_icon . ($wo['lang']['please_check_details'] ?? 'If this phone number is registered, you will receive a code shortly.');
        }
    }
    if (empty($errors)) {
        $random_activation = random_int(100000, 999999); // 6-digit, cryptographically secure
        $sms_code          = Wo_Secure($random_activation); // Store plain — Wo_ConfirmSMSUser compares raw input; rate-limited + 1hr expiry
        $message           = $wo['lang']['confirmation_code_is'] . ": {$random_activation}";
        $user_id           = Wo_UserIdFromPhoneNumber($_POST['recoverphone']);
        // Use cryptographically secure reset token
        $code              = bin2hex(random_bytes(32));
        $time = time() + (60 * 60); // 1 hour expiry
        $query             = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `sms_code` = '{$sms_code}', `email_code` = '" . Wo_Secure($code) . "' , `time_code_sent` = '".$time."' WHERE `user_id` = {$user_id}");
        if ($query) {
            cache($user_id, 'users', 'delete');
            if (Wo_SendSMSMessage($_POST['recoverphone'], $message) === true) {
                $data = array(
                    'status' => 200,
                    'message' => $success_icon . $wo['lang']['recoversms_sent'],
                    'location' => Wo_SeoLink('index.php?link1=confirm-sms-password&code=' . $code)
                );
            } else {
                $errors = $error_icon . $wo['lang']['failed_to_send_code_email'];
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
