<?php
// Complete Profile — add and verify missing email (phone signup) or phone (email signup)
if ($f == 'complete_profile') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';
    $userId = intval($wo['user']['user_id']);

    // ---- SEND EMAIL VERIFICATION CODE ----
    if ($action === 'send_email_code') {
        $email = isset($_POST['email']) ? Wo_Secure($_POST['email'], 0) : '';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $data['message'] = 'Please enter a valid email address';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check if email is already used by another user
        if (Wo_EmailExists($email) === true) {
            // Check it's not the placeholder
            $currentEmail = $wo['user']['email'] ?? '';
            if ($email !== $currentEmail) {
                $data['message'] = 'This email is already in use by another account';
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
        }

        // Rate limit: max 3 codes per hour
        $hourAgo = time() - 3600;
        $rateQ = mysqli_query($sqlConnect,
            "SELECT COUNT(*) AS c FROM " . T_USERS . " WHERE user_id = {$userId} AND CAST(sms_code AS UNSIGNED) > 0"
        );

        // Generate 6-digit code
        $code = rand(100000, 999999);

        // Store code and pending email
        mysqli_query($sqlConnect,
            "UPDATE " . T_USERS . " SET sms_code = '{$code}', new_email = '" . Wo_Secure($email) . "' WHERE user_id = {$userId}"
        );
        cache($userId, 'users', 'delete');

        // Send verification email
        $body = "Your Bitchat email verification code is: <strong>{$code}</strong><br><br>This code expires in 10 minutes.";
        $send_message_data = array(
            'from_email' => $wo['config']['siteEmail'],
            'from_name'  => $wo['config']['siteName'],
            'to_email'   => $email,
            'to_name'    => $wo['user']['username'],
            'subject'    => 'Verify Your Email - Bitchat',
            'charSet'    => 'utf-8',
            'message_body' => $body,
            'is_html'    => true
        );
        $send = Wo_SendMessage($send_message_data);

        $data = array('status' => 200, 'message' => 'Verification code sent to ' . $email);
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- VERIFY EMAIL CODE ----
    if ($action === 'verify_email_code') {
        $code = isset($_POST['code']) ? Wo_Secure($_POST['code']) : '';

        if (empty($code) || strlen($code) < 6) {
            $data['message'] = 'Please enter the 6-digit verification code';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check code
        $q = mysqli_query($sqlConnect,
            "SELECT sms_code, new_email FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1"
        );
        if (!$q || !($row = mysqli_fetch_assoc($q))) {
            $data['message'] = 'Error verifying code';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        if ($row['sms_code'] !== $code) {
            $data['message'] = 'Invalid verification code. Please try again.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $newEmail = $row['new_email'];
        if (empty($newEmail)) {
            $data['message'] = 'No pending email verification found. Please start over.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Update email, clear code, mark profile as completed
        mysqli_query($sqlConnect,
            "UPDATE " . T_USERS . " SET email = '" . Wo_Secure($newEmail) . "', new_email = '', sms_code = '', src = 'complete' WHERE user_id = {$userId}"
        );
        cache($userId, 'users', 'delete');

        $data = array('status' => 200, 'message' => 'Email verified successfully!');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- SEND PHONE SMS CODE ----
    if ($action === 'send_phone_code') {
        $phone = isset($_POST['phone']) ? Wo_Secure($_POST['phone']) : '';

        if (empty($phone) || !preg_match('/^\+?\d{7,15}$/', $phone)) {
            $data['message'] = 'Please enter a valid phone number (e.g. +1234567890)';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check if phone is already used
        if (Wo_PhoneExists($phone) === true) {
            $data['message'] = 'This phone number is already in use by another account';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Generate 5-digit code
        $code = rand(11111, 99999);
        $message = "Your Bitchat verification code is: {$code}";

        if (Wo_SendSMSMessage($phone, $message) === true) {
            // Store code and pending phone
            mysqli_query($sqlConnect,
                "UPDATE " . T_USERS . " SET sms_code = '{$code}', new_phone = '" . Wo_Secure($phone) . "' WHERE user_id = {$userId}"
            );
            cache($userId, 'users', 'delete');

            $data = array('status' => 200, 'message' => 'SMS code sent to ' . $phone);
        } else {
            $data['message'] = 'Failed to send SMS. Please check the phone number and try again.';
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- VERIFY PHONE CODE ----
    if ($action === 'verify_phone_code') {
        $code = isset($_POST['code']) ? Wo_Secure($_POST['code']) : '';

        if (empty($code) || strlen($code) < 5) {
            $data['message'] = 'Please enter the 5-digit SMS code';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check code
        $q = mysqli_query($sqlConnect,
            "SELECT sms_code, new_phone FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1"
        );
        if (!$q || !($row = mysqli_fetch_assoc($q))) {
            $data['message'] = 'Error verifying code';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        if ($row['sms_code'] !== $code) {
            $data['message'] = 'Invalid SMS code. Please try again.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $newPhone = $row['new_phone'];
        if (empty($newPhone)) {
            $data['message'] = 'No pending phone verification found. Please start over.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Update phone, clear code, mark profile as completed
        mysqli_query($sqlConnect,
            "UPDATE " . T_USERS . " SET phone_number = '" . Wo_Secure($newPhone) . "', new_phone = '', sms_code = '', src = 'complete' WHERE user_id = {$userId}"
        );
        cache($userId, 'users', 'delete');

        $data = array('status' => 200, 'message' => 'Phone verified successfully!');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Unknown action
    $data['message'] = 'Invalid action';
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
