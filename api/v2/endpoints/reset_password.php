<?php
if (!empty($_POST['new_password']) && !empty($_POST['email']) && !empty($_POST['code'])) {
	$code   = Wo_Secure($_POST['code']);
	$email   = Wo_Secure($_POST['email']);
	$update = true;

	//$is_owner = $db->where('email',$email)->where('email_code',$code)->where('time_code_sent',time(),'>')->getValue(T_USERS,'COUNT(*)');
	
	// if ($is_owner > 0) {
	// 	$update = true;
	// }
	// else{
	// 	$is_owner = $db->where('email',$email)->where('password',$code)->where('time_code_sent',time(),'>')->getValue(T_USERS,'COUNT(*)');
	// 	if ($is_owner > 0) {
	// 		$update = true;
	// 	}
	// 	else{
	// 		$error_code    = 9;
	// 	    $error_message = 'email , code wrong';
	// 	}
	// }
	if (Wo_isValidPasswordResetToken($_POST['code']) === false && Wo_isValidPasswordResetToken2($_POST['code']) === false) {
		$update = false;
		$error_code    = 9;
		$error_message = 'email , code wrong';
	}
	if ($update == true) {
		if (strlen($_POST['new_password']) < 8) {
			$error_code    = 10;
		    $error_message = 'Password must be at least 8 characters';
		} elseif (!preg_match('/[a-zA-Z]/', $_POST['new_password']) || !preg_match('/[0-9]/', $_POST['new_password'])) {
			$error_code    = 10;
		    $error_message = 'Password must contain at least one letter and one number';
		} else {
			$password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
			// SECURITY: look up by user_id extracted from the validated token — not the
			// user-supplied $email. Old code used $email (from POST) which meant the token
			// and the updated record could be for different users.
			$token_parts = explode('_', $_POST['code']);
			$token_user_id = intval($token_parts[0]);
			$getUser = $db->where('user_id', $token_user_id)->getOne(T_USERS);
			// Invalidate reset token and clear all other sessions
			$db->where('user_id', $token_user_id)->update(T_USERS, array(
				'password'       => $password,
				'email_code'     => '',
				'time_code_sent' => 0
			));
			if (!empty($getUser->user_id)) {
				// Invalidate all existing sessions after password reset
				mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `user_id` = '{$getUser->user_id}'");
				cache($getUser->user_id, 'users', 'delete');
			}
			$response_data['api_status'] = 200;
			$response_data['message'] = 'Your password was updated';
		}
	}
}
else{
	$error_code    = 8;
    $error_message = 'new_password , email , code can not be empty';
}