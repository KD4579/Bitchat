<?php
if ($f == 'resend_two_factor') {
	$hash = '';
	// SECURITY: session always wins over cookie — prevents cookie injection overriding session
	if (!empty($_SESSION) && !empty($_SESSION['two_factor_hash'])) {
		if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
		    $hash = filter_var($_SESSION['two_factor_hash'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
		    $hash = filter_var($_SESSION['two_factor_hash'], FILTER_SANITIZE_STRING);
		}
		$hash = Wo_Secure($hash);
	} elseif (!empty($_COOKIE) && !empty($_COOKIE['two_factor_hash'])) {
		// Fallback only when no session value exists (e.g. after server restart)
		if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
		    $hash = filter_var($_COOKIE['two_factor_hash'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		} else {
		    $hash = filter_var($_COOKIE['two_factor_hash'], FILTER_SANITIZE_STRING);
		}
		$hash = Wo_Secure($hash);
	}
	if (empty($hash)) {
		$data['status'] = 400;
		$data['message'] = $wo['lang']['code_two_expired'];
	}
	else{
		$user = $db->where('two_factor_hash',$hash)->where('email_code','','!=')->getOne(T_USERS);
		if (!empty($user)) {
			if ($user->time_code_sent == 0 || $user->time_code_sent < (time() - (60 * 1))) {
				$resent = false;
				// Try standard 2FA resend first
				if (Wo_TwoFactor($user->username) === false) {
					$resent = true;
				}
				// Fallback: unusual-login resend for users without 2FA enabled
				if (!$resent && $user->two_factor_method == 'two_factor') {
					$userData = Wo_UserData($user->user_id);
					$code = random_int(100000, 999999); // cryptographically secure; rand() replaced
					$hash_code = md5($code);
					$db->where('user_id', $user->user_id)->update(T_USERS, array('email_code' => $hash_code));
					cache($user->user_id, 'users', 'delete');

					if ($wo['config']['two_factor_type'] == 'both' || $wo['config']['two_factor_type'] == 'email') {
						$wo['email']['username'] = $userData['name'];
						$wo['email']['code'] = $code;
						$wo['email']['email'] = $userData['email'];
						$wo['email']['date'] = date("Y-m-d h:i:sa");
						$wo['email']['countryCode'] = '';
						$wo['email']['timezone'] = '';
						$wo['email']['ip_address'] = get_ip_address();
						$wo['email']['city'] = '';
						if (!empty($_SESSION['last_login_data'])) {
							$wo['email']['countryCode'] = $_SESSION['last_login_data']['countryCode'] ?? '';
							$wo['email']['timezone'] = $_SESSION['last_login_data']['timezone'] ?? '';
							$wo['email']['city'] = $_SESSION['last_login_data']['city'] ?? '';
						}
						$email_body = Wo_LoadPage("emails/unusual-login");
						$send = Wo_SendMessage(array(
							'from_email' => $wo['config']['siteEmail'],
							'from_name' => $wo['config']['siteName'],
							'to_email' => $userData['email'],
							'to_name' => $userData['name'],
							'subject' => 'Please verify that it\'s you',
							'charSet' => 'utf-8',
							'message_body' => $email_body,
							'is_html' => true
						));
						if ($send) $resent = true;
					}
				}

				if ($resent) {
					$db->where('user_id', $user->user_id)->update(T_USERS, array('time_code_sent' => time()));
					cache($user->user_id, 'users', 'delete');
					$data = array(
                        'status' => 200,
                        'message' => $wo['lang']['code_successfully_sent']
                    );
				}
				else{
					$data['status'] = 400;
		   			$data['message'] = $wo['lang']['something_wrong'];
				}
			}
			else{
				$data['status'] = 400;
		        $data['message'] = $wo['lang']['you_cant_send_now'];
			}
		}
		else{
			$data['status'] = 400;
		    $data['message'] = $wo['lang']['something_wrong'];
		}
	}
	header("Content-type: application/json");
    echo json_encode($data);
    exit();
}