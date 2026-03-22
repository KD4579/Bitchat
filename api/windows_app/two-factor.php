<?php
// +------------------------------------------------------------------------+
// | @author Deen Doughouz (DoughouzForest)
// | @author_url 1: http://www.wowonder.com
// | @author_url 2: http://codecanyon.net/user/doughouzforest
// | @author_email: wowondersocial@gmail.com   
// +------------------------------------------------------------------------+
// | WoWonder - The Ultimate Social Networking Platform
// | Copyright (c) 2018 WoWonder. All rights reserved.
// +------------------------------------------------------------------------+
$response_data   = array(
    'api_status' => 400
);

$required_fields = array(
    'user_id',
    'code'
);
foreach ($required_fields as $key => $value) {
    if (empty($_POST[$value]) && empty($error_code)) {
        $error_message = $value . ' (POST) is missing';
        $json_error_data = array(
            'api_status' => '400',
            'api_text' => 'failed',
            'api_version' => $api_version,
            'errors' => array(
                'error_id' => '3',
                'error_text' => $error_message
            )
        );
        header("Content-type: application/json");
        echo json_encode($json_error_data, JSON_PRETTY_PRINT);
        exit();
    }
}
if (empty($error_code)) {
    // SECURITY: sanitize user_id — must be numeric to prevent injection
    $user_id = intval($_POST['user_id']);
    if (empty($user_id) || $user_id <= 0) {
        header("Content-type: application/json");
        echo json_encode(array(
            'api_status' => '400',
            'api_text' => 'failed',
            'api_version' => $api_version,
            'errors' => array('error_id' => '5', 'error_text' => 'Invalid user.')
        ), JSON_PRETTY_PRINT);
        exit();
    }

    // SECURITY: rate limit 2FA code attempts — 6-digit codes are brute-forceable
    // without this (only 900,000 combinations, feasible in minutes)
    if (function_exists('bitchat_rate_limit') && !bitchat_rate_limit('api_2fa_' . $user_id, $user_id, 5, 600)) {
        header("Content-type: application/json");
        echo json_encode(array(
            'api_status' => '429',
            'api_text' => 'failed',
            'api_version' => $api_version,
            'errors' => array('error_id' => '9', 'error_text' => 'Too many attempts. Please request a new code.')
        ), JSON_PRETTY_PRINT);
        exit();
    }

	$confirm_code = $_POST['code'];
	$confirm_code = $db->where('user_id', $user_id)->where('email_code', md5($confirm_code))->getValue(T_USERS, 'count(*)');
    if (empty($confirm_code)) {
        $json_error_data = array(
            'api_status' => '400',
            'api_text' => 'failed',
            'api_version' => $api_version,
            'errors' => array(
                'error_id' => '4',
                'error_text' => 'Wrong confirmation code.'
            )
        );
        header("Content-type: application/json");
        echo json_encode($json_error_data, JSON_PRETTY_PRINT);
        exit();
    }
    else{
        $time           = time();
        $cookie         = '';
        $access_token   = bin2hex(random_bytes(32)); // SECURITY: cryptographically secure
        $add_session = mysqli_query($sqlConnect, "INSERT INTO " . T_APP_SESSIONS . " (`user_id`, `session_id`, `platform`, `time`) VALUES ('{$user_id}', '{$access_token}', 'windows', '{$time}')");
        if ($add_session) {
            if (!empty($_POST['timezone'])) {
                $timezone = Wo_Secure($_POST['timezone']);
            } else {
                $timezone = 'UTC';
            }
            $add_timezone = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `timezone` = '{$timezone}' WHERE `user_id` = {$user_id}");
            cache($user_id, 'users', 'delete');
            $json_success_data = array(
                'api_status' => '200',
                'api_text' => 'success',
                'api_version' => $api_version,
                'user_id' => Wo_UserIdFromUsername($username),
                'messages' => 'Successfully logged in, Please wait..',
                'access_token' => $access_token,
                'user_id' => $user_id,
                'timezone' => $timezone
            );
            header("Content-type: application/json");
            echo json_encode($json_success_data, JSON_PRETTY_PRINT);
            exit();
        } else {
            $json_error_data = array(
                'api_status' => '400',
                'api_text' => 'failed',
                'api_version' => $api_version,
                'errors' => array(
                    'error_id' => '8',
                    'error_text' => 'Error found, please try again later.'
                )
            );
            header("Content-type: application/json");
            echo json_encode($json_error_data, JSON_PRETTY_PRINT);
            exit();
        }




    }

}