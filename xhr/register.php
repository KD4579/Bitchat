<?php
if ($f == 'register') {
        $disallowed_usernames = array(
        'admin',
        'administrator',
        'support',
        'helper',
        'manager',
        'owner',
        'wowonder',
        'superadmin',
        'root',
        'user',
        'profile',
        'settings',
        'moderator',
        'operator',
        'trdc',
        'bit',
        'bitchat'
    );
    
    $input_username = isset($_POST['username']) ? strtolower($_POST['username']) : '';
    
    // Case-insensitive partial match check
    foreach ($disallowed_usernames as $word) {
        if (stripos($input_username, $word) !== false) {
            $errors = $error_icon . $wo['lang']['username_is_disallowed'];
            header("Content-type: application/json");
            echo json_encode(array(
                'errors' => $errors
            ));
            exit();
        }
    }
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['user_id'] = '';
        unset($_SESSION['user_id']);
    }
    if (!empty($_COOKIE['user_id'])) {
        $_COOKIE['user_id'] = '';
        unset($_COOKIE['user_id']);
        setcookie('user_id', '', -1);
        setcookie('user_id', '', -1, '/');
    }
    if ($wo['config']['auto_username'] == 1) {
        $_POST['username'] = time() . rand(111111, 999999);
        if (empty($_POST['first_name']) || empty($_POST['last_name'])) {
            $errors = $error_icon . $wo['lang']['first_name_last_name_empty'];
            header("Content-type: application/json");
            echo json_encode(array(
                'errors' => $errors
            ));
            exit();
        }
        if (preg_match('/[^\w\s]+/u', $_POST['first_name']) || preg_match('/[^\w\s]+/u', $_POST['last_name'])) {
            $errors = $error_icon . $wo['lang']['username_invalid_characters'];
        }
    }
    // Rate limit registration: 3 per hour per IP to prevent mass account creation
    if (!bitchat_rate_limit('register', get_ip_address(), 3, 3600)) {
        header("Content-type: application/json");
        echo json_encode(array('errors' => $error_icon . $wo['lang']['login_attempts']));
        exit();
    }

    // Honeypot anti-bot check: if hidden field is filled, it's a bot
    if (!empty($_POST['website_url'])) {
        header("Content-type: application/json");
        echo json_encode(array('errors' => $error_icon . $wo['lang']['please_check_details']));
        exit();
    }

    $fields = Wo_GetWelcomeFileds();
    $signup_method = isset($_POST['signup_method']) ? Wo_Secure($_POST['signup_method']) : 'email';
    if (!in_array($signup_method, ['email', 'phone'])) $signup_method = 'email';

    // Prepend country code to phone number if provided separately
    if (!empty($_POST['phone_num']) && !empty($_POST['country_code'])) {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone_num']); // strip non-digits
        $code = preg_replace('/[^0-9+]/', '', $_POST['country_code']); // keep + and digits
        if (strpos($phone, '+') !== 0) { // only prepend if phone doesn't already have +
            $_POST['phone_num'] = $code . $phone;
        }
    }

    // For phone signup: email is not required at signup (will be collected later)
    // For email signup: phone is not required at signup (will be collected later)
    if ($signup_method === 'phone') {
        // Phone signup: require phone, generate placeholder email
        if (empty($_POST['phone_num'])) {
            $errors = $error_icon . ($wo['lang']['worng_phone_number'] ?? 'Please enter a valid phone number');
        }
        if (empty($_POST['email'])) {
            // Generate a unique placeholder email so DB constraints are satisfied
            $_POST['email'] = 'phone_' . time() . rand(1000,9999) . '@placeholder.bitchat.live';
        }
    } else {
        // Email signup: require email
        if (empty($_POST['email'])) {
            $errors = $error_icon . $wo['lang']['please_check_details'];
        }
    }

    if (empty($_POST['username']) || empty($_POST['password']) || empty($_POST['confirm_password']) || empty($_POST['gender'])) {
        $errors = $error_icon . $wo['lang']['please_check_details'];
    } else {
        $is_exist = Wo_IsNameExist($_POST['username'], 0);
        if (in_array(true, $is_exist)) {
            $errors = $error_icon . $wo['lang']['username_exists'];
        }
        if (Wo_IsBanned($_POST['username'])) {
            $errors = $error_icon . $wo['lang']['username_is_banned'];
        }
        if ($signup_method === 'email' && !empty($_POST['email'])) {
            if (Wo_IsBanned($_POST['email'])) {
                $errors = $error_icon . $wo['lang']['email_is_banned'];
            }
            if (preg_match_all('~@(.*?)(.*)~', $_POST['email'], $matches) && !empty($matches[2]) && !empty($matches[2][0]) && Wo_IsBanned($matches[2][0])) {
                $errors = $error_icon . $wo['lang']['email_provider_banned'];
            }
        }
        if (Wo_CheckIfUserCanRegister($wo['config']['user_limit']) === false) {
            $errors = $error_icon . $wo['lang']['limit_exceeded'];
        }
        if (in_array($_POST['username'], $wo['site_pages'])) {
            $errors = $error_icon . $wo['lang']['username_invalid_characters'];
        }
        if (strlen($_POST['username']) < 5 OR strlen($_POST['username']) > 32) {
            $errors = $error_icon . $wo['lang']['username_characters_length'];
        }
        if (!preg_match('/^[\w]+$/', $_POST['username'])) {
            $errors = $error_icon . $wo['lang']['username_invalid_characters'];
        }
        if ($wo['config']['reserved_usernames_system'] == 1 && in_array($_POST["username"], $wo['reserved_usernames'])) {
            $errors = $error_icon . $wo['lang']['username_is_disallowed'];
        }
        if (!empty($_POST['phone_num'])) {
            if (!preg_match('/^\+?\d+$/', $_POST['phone_num'])) {
                $errors = $error_icon . ($wo['lang']['worng_phone_number'] ?? 'Invalid phone number format');
            } else {
                if (Wo_PhoneExists($_POST['phone_num']) === true) {
                    $errors = $error_icon . ($wo['lang']['phone_already_used'] ?? 'Phone number already in use');
                }
            }
        }
        if ($signup_method === 'email') {
            if (Wo_EmailExists($_POST['email']) === true) {
                $errors = $error_icon . $wo['lang']['email_exists'];
            }
            if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                $errors = $error_icon . $wo['lang']['email_invalid_characters'];
            }
        }
        if (strlen($_POST['password']) < 8) {
            $errors = $error_icon . $wo['lang']['password_short'];
        }
        // Require at least one letter and one number for password strength
        if (!preg_match('/[a-zA-Z]/', $_POST['password']) || !preg_match('/[0-9]/', $_POST['password'])) {
            $errors = $error_icon . ($wo['lang']['password_weak'] ?? 'Password must contain at least one letter and one number');
        }
        if ($_POST['password'] != $_POST['confirm_password']) {
            $errors = $error_icon . $wo['lang']['password_mismatch'];
        }
        if ($config['reCaptcha'] == 1) {
            if (!isset($_POST['g-recaptcha-response']) || empty($_POST['g-recaptcha-response'])) {
                $errors = $error_icon . $wo['lang']['reCaptcha_error'];
            }
        }
        $gender = 'male';
        if (in_array($_POST['gender'], array_keys($wo['genders']))) {
            $gender = $_POST['gender'];
        }
        if (!empty($fields) && count($fields) > 0) {
            foreach ($fields as $key => $field) {
                if (empty($_POST[$field['fid']])) {
                    $errors = $error_icon . $field['name'] . ' is required';
                }
                if (!empty($_POST[$field['fid']]) && mb_strlen($_POST[$field['fid']]) > $field['length']) {
                    $errors = $error_icon . $field['name'] . ' field max characters is ' . $field['length'];
                }
            }
        }
    }
    
    #not use this name
    
    $forbidden_words = array('admin',
        'administrator',
        'support',
        'helper',
        'manager',
        'owner',
        'wowonder',
        'superadmin',
        'root',
        'user',
        'profile',
        'settings',
        'moderator',
        'operator',
        'trdc',
        'bit',
        'bitchat'); // Add more as needed

    function contains_forbidden_word($input, $forbidden_words) {
        foreach ($forbidden_words as $word) {
            if (stripos($input, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    if (
        contains_forbidden_word($_POST['username'], $forbidden_words) ||
        (isset($_POST['first_name']) && contains_forbidden_word($_POST['first_name'], $forbidden_words)) ||
        (isset($_POST['last_name']) && contains_forbidden_word($_POST['last_name'], $forbidden_words))
    ) {
        $errors = $error_icon . $wo['lang']['username_invalid_characters'];
    }
    
    $field_data = array();
    if (empty($errors)) {
        if (!empty($fields) && count($fields) > 0) {
            foreach ($fields as $key => $field) {
                if (!empty($_POST[$field['fid']])) {
                    $name = $field['fid'];
                    if (!empty($_POST[$name])) {
                        $field_data[] = array(
                            $name => $_POST[$name]
                        );
                    }
                }
            }
        }
        $activate = ($wo['config']['emailValidation'] == '1') ? '0' : '1';
        // Use cryptographically secure activation code
        $code     = bin2hex(random_bytes(32));
        $re_data  = array(
            'email' => Wo_Secure($_POST['email'], 0),
            'username' => Wo_Secure($_POST['username'], 0),
            'password' => $_POST['password'],
            'email_code' => Wo_Secure($code, 0),
            'src' => 'site',
            'gender' => Wo_Secure($gender),
            'lastseen' => time(),
            'active' => Wo_Secure($activate),
            'birthday' => '0000-00-00'
        );
        if ($wo['config']['disable_start_up'] == '1') {
            $re_data['start_up'] = '1';
            $re_data['start_up_info'] = '1';
            $re_data['startup_follow'] = '1';
            $re_data['startup_image'] = '1';
        }
        if ($wo['config']['website_mode'] == 'linkedin' && !empty($_POST['currently_working']) && in_array($_POST['currently_working'], array(
            'yes',
            'am_looking_to_work',
            'am_looking_for_employees'
        ))) {
            $re_data['currently_working'] = Wo_Secure($_POST['currently_working'], 0);
        }
        if ($wo['config']['auto_username'] == 1) {
            if (!empty($_POST['first_name'])) {
                $re_data['first_name'] = Wo_Secure($_POST['first_name'],1);
            }
            if (!empty($_POST['last_name'])) {
                $re_data['last_name'] = Wo_Secure($_POST['last_name'],1);
            }
        }
        if ($gender == 'female') {
            $re_data['avatar'] = "upload/photos/f-avatar.jpg";
        }
        // Restore referral from cookie if session lost
        if (empty($_SESSION['ref']) && !empty($_COOKIE['ref'])) {
            $_SESSION['ref'] = Wo_Secure($_COOKIE['ref']);
        }
        if (!empty($_SESSION['ref']) && $wo['config']['affiliate_type'] == 0) {
            $ref_user_id = Wo_UserIdFromUsername($_SESSION['ref']);
            if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                // Anti-abuse: block self-referral and admin accounts
                $ref_user = Wo_UserData($ref_user_id);
                $is_self_ref = (!empty($_POST['username']) && $_SESSION['ref'] === $_POST['username']);
                $is_admin = (!empty($ref_user['admin']) && $ref_user['admin'] == '1');
                $same_ip = (!empty($ref_user['ip_address']) && $ref_user['ip_address'] === Wo_Secure($_SERVER['REMOTE_ADDR'] ?? ''));
                if (!$is_self_ref && !$is_admin && !$same_ip) {
                    $re_data['referrer'] = Wo_Secure($ref_user_id);
                    $re_data['src']      = Wo_Secure('Referrer');
                    if ($wo['config']['affiliate_level'] < 2) {
                        // Referral TRDC reward (via Reward Engine)
                        if (function_exists('Wo_TriggerReward')) {
                            Wo_TriggerReward($ref_user_id, 'referral_signup', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                        }
                    }
                }
                unset($_SESSION['ref']);
                @setcookie('ref', '', time() - 3600, '/');
            }
        } elseif (!empty($_SESSION['ref']) && $wo['config']['affiliate_type'] == 1) {
            $ref_user_id = Wo_UserIdFromUsername($_SESSION['ref']);
            if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                // Anti-abuse: block self-referral and admin accounts
                $ref_user = Wo_UserData($ref_user_id);
                $is_self_ref = (!empty($_POST['username']) && $_SESSION['ref'] === $_POST['username']);
                $is_admin = (!empty($ref_user['admin']) && $ref_user['admin'] == '1');
                $same_ip = (!empty($ref_user['ip_address']) && $ref_user['ip_address'] === Wo_Secure($_SERVER['REMOTE_ADDR'] ?? ''));
                if (!$is_self_ref && !$is_admin && !$same_ip) {
                    $re_data['ref_user_id'] = Wo_Secure($ref_user_id);
                    $re_data['referrer'] = Wo_Secure($ref_user_id);
                    $re_data['src'] = Wo_Secure('Referrer');
                }
                unset($_SESSION['ref']);
                @setcookie('ref', '', time() - 3600, '/');
            }
        }
        if (!empty($_POST['phone_num'])) {
            $re_data['phone_number'] = Wo_Secure($_POST['phone_num']);
        }

        $in_code = (isset($_POST['invited'])) ? Wo_Secure($_POST['invited']) : false;

        // Always register the user first (creates DB record)
        $register = Wo_RegisterUser($re_data, $in_code);

        if ($register !== true) {
            $errors = $error_icon . ($wo['lang']['registration_failed'] ?? 'Registration failed. Please try again.');
        }
        if ($register === true) {
            $r_id = Wo_UserIdFromUsername($_POST['username']);

            // Process avatar upload during registration (can't use Wo_UploadImage — it requires $wo['loggedin'])
            if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                $av_name = $_FILES['avatar']['name'];
                $av_tmp  = $_FILES['avatar']['tmp_name'];
                $av_ext  = strtolower(pathinfo($av_name, PATHINFO_EXTENSION));
                $av_allowed_ext  = array('jpg', 'jpeg', 'png', 'gif');
                $av_allowed_mime = array('image/png', 'image/jpeg', 'image/gif');

                // Validate MIME type server-side using finfo (not client-provided type)
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $av_type = $finfo->file($av_tmp);

                if (in_array($av_ext, $av_allowed_ext) && in_array($av_type, $av_allowed_mime)) {
                    $av_dir = 'upload/photos/' . date('Y') . '/' . date('m');
                    if (!file_exists($av_dir)) {
                        mkdir($av_dir, 0755, true);
                    }
                    $av_filename = $av_dir . '/' . md5($r_id . time() . rand(1000,9999)) . '_avatar.' . $av_ext;
                    if (move_uploaded_file($av_tmp, $av_filename)) {
                        $av_check = getimagesize($av_filename);
                        if ($av_check) {
                            $db->where('user_id', $r_id)->update(T_USERS, array(
                                'avatar'        => $av_filename,
                                'startup_image' => '1'
                            ));
                            cache($r_id, 'users', 'delete');
                        } else {
                            @unlink($av_filename);
                        }
                    }
                }
            }

            if (!empty($re_data['referrer']) && is_numeric($wo['config']['affiliate_level']) && $wo['config']['affiliate_level'] > 1) {
                AddNewRef($re_data['referrer'], $r_id, $wo['config']['amount_ref']);
            }
            if (!empty($re_data['referrer']) && function_exists('Wo_RegisterNotification')) {
                $newUserName = !empty($_POST['first_name']) ? Wo_Secure($_POST['first_name']) : $_POST['username'];
                Wo_RegisterNotification(array(
                    'recipient_id' => intval($re_data['referrer']),
                    'notifier_id'  => $r_id,
                    'type'         => 'remaining',
                    'text'         => htmlspecialchars($newUserName) . ' joined using your invite link!',
                    'url'          => 'index.php?link1=timeline&u=' . $_POST['username']
                ));
            }

            // Store signup_method in DB for complete-profile redirect
            // Preserve 'Referrer' src if user came via referral link
            if ($r_id && empty($re_data['referrer'])) {
                mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `src` = '" . Wo_Secure($signup_method) . "_signup' WHERE `user_id` = {$r_id}");
                cache($r_id, 'users', 'delete');
            }

            // Post-registration setup (auto-follow, auto-like, etc.)
            $wo['user'] = Wo_UserData($r_id);
            if ($wo['config']['auto_username'] == 1) {
                $_POST['username'] = $_POST['username'] . "_" . $r_id;
                $db->where('user_id', $r_id)->update(T_USERS, array('username' => $_POST['username']));
                cache($r_id, 'users', 'delete');
            }
            if (!empty($wo['config']['auto_friend_users'])) {
                $autoFollow = Wo_AutoFollow(Wo_UserIdFromUsername($_POST['username']));
            }
            if (!empty($wo['config']['auto_page_like'])) {
                Wo_AutoPageLike(Wo_UserIdFromUsername($_POST['username']));
            }
            if (!empty($wo['config']['auto_group_join'])) {
                Wo_AutoGroupJoin(Wo_UserIdFromUsername($_POST['username']));
            }

            // Verification flow depends on signup method
            if ($signup_method === 'phone') {
                // Phone signup: send SMS OTP for verification
                $random_activation = random_int(100000, 999999); // 6-digit, cryptographically secure
                $hashed_activation = Wo_Secure(md5($random_activation)); // Hash before storing
                $message = "Your Bitchat confirmation code is: {$random_activation}";
                if (Wo_SendSMSMessage($_POST['phone_num'], $message) === true) {
                    $user_id = Wo_UserIdFromUsername($_POST['username']);
                    mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `sms_code` = '{$hashed_activation}', `active` = '0' WHERE `user_id` = {$user_id}");
                    cache($user_id, 'users', 'delete');
                    $data = array(
                        'status' => 300,
                        'location' => Wo_SeoLink('index.php?link1=confirm-sms&code=' . $code)
                    );
                } else {
                    $errors = $error_icon . ($wo['lang']['failed_to_send_code_email'] ?? 'Failed to send SMS code. Please try again.');
                }
            } else if ($signup_method === 'email') {
                // Email signup: send verification email
                if ($wo['config']['emailValidation'] == '1') {
                    $wo['code'] = $code;
                    $body = Wo_LoadPage('emails/activate');
                    $send_message_data = array(
                        'from_email' => $wo['config']['siteEmail'],
                        'from_name' => $wo['config']['siteName'],
                        'to_email' => $_POST['email'],
                        'to_name' => $_POST['username'],
                        'subject' => $wo['lang']['account_activation'],
                        'charSet' => 'utf-8',
                        'message_body' => $body,
                        'is_html' => true
                    );
                    Wo_SendMessage($send_message_data);
                    $errors = $success_icon . $wo['lang']['successfully_joined_verify_label'];
                } else {
                    // No email validation required — auto-login
                    $data = array(
                        'status' => 200,
                        'message' => $success_icon . ($wo['lang']['successfully_joined_label'] ?? 'Registration successful!')
                    );
                    $login = Wo_Login($_POST['username'], $_POST['password']);
                    if ($login === true) {
                        session_regenerate_id(true);
                        $session = Wo_CreateLoginSession(Wo_UserIdFromUsername($_POST['username']));
                        $_SESSION['user_id'] = $session;
                        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                        setcookie("user_id", $session, [
                            'expires'  => time() + (30 * 24 * 60 * 60),
                            'path'     => '/',
                            'secure'   => $isSecure,
                            'httponly'  => true,
                            'samesite' => 'Lax'
                        ]);
                    }
                    $data['location'] = $wo['config']['site_url'] . '/?cache=' . time();
                    if ($wo['config']['membership_system'] == 1) {
                        $data['location'] = Wo_SeoLink('index.php?link1=go-pro');
                    }
                }
            }
        }
        if (!empty($field_data)) {
            $user_id = Wo_UserIdFromUsername($_POST['username']);
            $insert  = Wo_UpdateUserCustomData($user_id, $field_data, false);
        }
    }
    header("Content-type: application/json");
    if (isset($errors)) {
        echo json_encode(array(
            'errors' => $errors
        ));
    } else if (isset($data)) {
        echo json_encode($data);
    } else {
        echo json_encode(array(
            'errors' => $error_icon . ($wo['lang']['registration_failed'] ?? 'Registration failed. Please try again.')
        ));
    }
    exit();
}
