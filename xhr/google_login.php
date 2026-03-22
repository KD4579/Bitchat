<?php
if ($f == 'google_login') {
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['user_id'] = '';
        unset($_SESSION['user_id']);
    }
    if (!empty($_COOKIE['user_id'])) {
        $_COOKIE['user_id'] = '';
        unset($_COOKIE['user_id']);
        setcookie('user_id', null, -1);
        setcookie('user_id', null, -1, '/');
    }
    if ($wo['loggedin'] != true && $wo['config']['googleLogin'] != 0 && !empty($wo['config']['googleAppId']) && !empty($wo['config']['googleAppKey']) && !empty($_POST['id_token'])) {
        $data['status']   = 400;
        $access_token     = $_POST['id_token'];
        $get_user_details = fetchDataFromURL("https://oauth2.googleapis.com/tokeninfo?id_token={$access_token}");
        $json_data        = json_decode($get_user_details);
        if (!empty($json_data->error)) {
            $data['message'] = $error_icon . $json_data->error;
        } else if (!empty($json_data->sub)) {
            // Validate audience (aud) matches our Google Client ID to prevent token from other apps
            if (empty($json_data->aud) || $json_data->aud !== $wo['config']['googleAppId']) {
                $data['message'] = $error_icon . 'Invalid token audience';
            // SECURITY: reject unverified emails — an attacker could register a Google account with a
            // victim's email (unverified) and use the token to log in as the victim
            } else if (!empty($json_data->email) && empty($json_data->email_verified)) {
                $data['message'] = $error_icon . 'Google account email is not verified';
            } else {
                $social_id    = $json_data->sub;
                $social_email = $json_data->email;
                $social_name  = $json_data->name;
                if (empty($social_email)) {
                    $social_email = 'go_' . $social_id . '@google.com';
                }
            }
        }
        if (!empty($social_id)) {
            $create_session = false;
            if (Wo_EmailExists($social_email) === true) {
                $create_session = true;
            } else {
                $str          = md5(microtime());
                $id           = substr($str, 0, 9);
                $user_uniq_id = (Wo_UserExists($id) === false) ? $id : 'u_' . $id;
                $password     = bin2hex(random_bytes(16));
                $re_data      = array(
                    'username' => Wo_Secure($user_uniq_id, 0),
                    'email' => Wo_Secure($social_email, 0),
                    'password' => Wo_Secure(password_hash($password, PASSWORD_DEFAULT), 0),
                    'email_code' => Wo_Secure(bin2hex(random_bytes(16)), 0),
                    'first_name' => Wo_Secure($social_name),
                    'src' => 'google',
                    'lastseen' => time(),
                    'social_login' => 1,
                    'active' => '1'
                );
                if (Wo_RegisterUser($re_data) === true) {
                    $create_session = true;
                }
            }
            if ($create_session == true) {
                $user_id = Wo_UserIdFromEmail($social_email);
                // Check if user has 2FA enabled — don't bypass it via social login
                $social_user_data = Wo_UserData($user_id);
                if (!empty($social_user_data['two_factor']) && $social_user_data['two_factor'] == 1 && !empty($social_user_data['two_factor_verified'])) {
                    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                    $_SESSION['code_id'] = $user_id;
                    $two_factor_hash = bin2hex(random_bytes(18));
                    $db->where('user_id', $user_id)->update(T_USERS, array('two_factor_hash' => $two_factor_hash));
                    cache($user_id, 'users', 'delete');
                    $_SESSION['two_factor_method'] = $social_user_data['two_factor_method'];
                    $_SESSION['two_factor_username'] = $social_user_data['username'];
                    $_SESSION['two_factor_hash'] = $two_factor_hash;
                    $data['status'] = 600;
                    $data['location'] = $wo['config']['site_url'] . '/unusual-login?type=two-factor';
                    header("Content-type: application/json");
                    echo json_encode($data);
                    exit();
                }
                Wo_SetLoginWithSession($social_email);
                // Restore referral from cookie if session lost
                if (empty($_SESSION['ref']) && !empty($_COOKIE['ref'])) {
                    $_SESSION['ref'] = Wo_Secure($_COOKIE['ref']);
                }
                if (!empty($_SESSION['ref']) && $wo['config']['affiliate_type'] == 0) {
                    $ref_user_id = Wo_UserIdFromUsername($_SESSION['ref']);
                    if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                        $ref_user = Wo_UserData($ref_user_id);
                        $is_admin = (!empty($ref_user['admin']) && $ref_user['admin'] == '1');
                        $same_ip = (!empty($ref_user['ip_address']) && $ref_user['ip_address'] === Wo_Secure($_SERVER['REMOTE_ADDR'] ?? ''));
                        if (!$is_admin && !$same_ip) {
                            $re_data['referrer'] = Wo_Secure($ref_user_id);
                            $re_data['src']      = Wo_Secure('Referrer');
                            if ($wo['config']['affiliate_level'] < 2) {
                                // TRDC referral reward via Reward Engine (guards + audit trail)
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
                        $ref_user = Wo_UserData($ref_user_id);
                        $is_admin = (!empty($ref_user['admin']) && $ref_user['admin'] == '1');
                        $same_ip = (!empty($ref_user['ip_address']) && $ref_user['ip_address'] === Wo_Secure($_SERVER['REMOTE_ADDR'] ?? ''));
                        if (!$is_admin && !$same_ip) {
                            $re_data['ref_user_id'] = Wo_Secure($ref_user_id);
                        }
                        unset($_SESSION['ref']);
                        @setcookie('ref', '', time() - 3600, '/');
                    }
                }
                if (!empty($re_data['referrer']) && is_numeric($wo['config']['affiliate_level']) && $wo['config']['affiliate_level'] > 1) {
                    AddNewRef($re_data['referrer'], $user_id, $wo['config']['amount_ref']);
                }
                if (!empty($wo['config']['auto_friend_users'])) {
                    $autoFollow = Wo_AutoFollow($user_id);
                }
                if (!empty($wo['config']['auto_page_like'])) {
                    Wo_AutoPageLike($user_id);
                }
                if (!empty($wo['config']['auto_group_join'])) {
                    Wo_AutoGroupJoin($user_id);
                }
                $data['status']   = 200;
                $data['location'] = $wo['config']['site_url'] . '/?cache=' . time();
            } else {
                $data['message'] = $error_icon . $wo['lang']['something_wrong'];
            }
        } else {
            $data['message'] = $error_icon . $wo['lang']['something_wrong'];
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
