<?php
if ($f == 'resned_code') {
    // Rate limit SMS resend: 3 per hour per IP to prevent SMS toll fraud
    if (!bitchat_rate_limit('resend_sms', get_ip_address(), 3, 3600)) {
        $errors = $wo['lang']['login_attempts'];
    }

    if (empty($errors) && isset($_POST['user_id']) && is_numeric($_POST['user_id']) && $_POST['user_id'] > 0) {
        $user = Wo_UserData($_POST['user_id']);
        if (empty($user) || empty($_POST['user_id'])) {
            $errors = $wo['lang']['failed_to_send_code'];
        }

        // SECURITY: Always send to the user's registered phone number, never accept phone from POST
        // This prevents attackers from redirecting SMS codes to their own phone
        if (empty($errors) && empty($user['phone_number'])) {
            $errors = $wo['lang']['failed_to_send_code'];
        }

        // Per-user rate limit
        if (empty($errors) && !bitchat_rate_limit('resend_sms_user_' . $_POST['user_id'], $_POST['user_id'], 3, 3600)) {
            $errors = $wo['lang']['login_attempts'];
        }

        if (empty($errors)) {
            $random_activation = random_int(100000, 999999); // 6-digit, cryptographically secure
            $hashed_code       = Wo_Secure(md5($random_activation)); // Hash before storing
            $message           = "Your confirmation code is: {$random_activation}";
            $user_id           = intval($_POST['user_id']);
            $query             = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `sms_code` = '{$hashed_code}' WHERE `user_id` = {$user_id}");
            if ($query) {
                cache($user_id, 'users', 'delete');
                if (Wo_SendSMSMessage($user['phone_number'], $message) === true) {
                    $data = array(
                        'status' => 200,
                        'message' => $success_icon . $wo['lang']['sms_has_been_sent']
                    );
                } else {
                    $errors = $wo['lang']['error_while_sending_sms'];
                }
            }
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
