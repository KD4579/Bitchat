<?php
if ($f == 'confirm_user_unusal_login') {
    // Always enforce rate limiting on 2FA verification (not config-gated)
    if (!bitchat_rate_limit('2fa_verify', get_ip_address(), 5, 900)) {
        header("Content-type: application/json");
        echo json_encode(array(
            'errors' => $error_icon . $wo['lang']['login_attempts']
        ));
        exit();
    }
    if ($wo['config']['prevent_system'] == 1) {
        if (!WoCanLogin()) {
            header("Content-type: application/json");
            echo json_encode(array(
                'errors' => $error_icon . $wo['lang']['login_attempts']
            ));
            exit();
        }
    }
    if (empty($_POST['confirm_code'])) {
        $errors = $error_icon . $wo['lang']['please_check_details'];
    }
    // Use server-side session ONLY for 2FA username (no cookie fallback -- prevents user switching)
    $two_factor_username = '';
    if (!empty($_SESSION['two_factor_username'])) {
        $two_factor_username = $_SESSION['two_factor_username'];
    }
    if (empty($two_factor_username)) {
        $errors = $error_icon . 'Session expired. Please <a href="' . Wo_SeoLink('index.php?link1=welcome') . '">login again</a>.';
    }
    if (empty($errors) && !empty($_POST['confirm_code']) && !empty($two_factor_username)) {
        $user = $db->where("username", Wo_Secure($two_factor_username))->getOne(T_USERS);
        if (empty($user)) {
            $errors = $error_icon . $wo['lang']['error_while_activating'];
        }
    }

    // Per-user rate limit on 2FA (5 attempts, then lockout)
    if (empty($errors) && !empty($user)) {
        if (!bitchat_rate_limit('2fa_user_' . $user->user_id, $user->user_id, 5, 900)) {
            $errors = $error_icon . $wo['lang']['login_attempts'];
        }
    }

    if (empty($errors) && !empty($user)) {
        $user_id = $user->user_id;
        $confirm_code = 0;
        if ($user->two_factor_method == 'google' || $user->two_factor_method == 'authy') {
            $codes = $db->where('user_id',$user_id)->getOne(T_BACKUP_CODES);
            if (!empty($codes) && !empty($codes->codes)) {
                $backupCodes = json_decode($codes->codes,true);
                $matched_key = null;
                foreach ($backupCodes as $bk => $bv) {
                    if (hash_equals((string)$bv, (string)$_POST['confirm_code'])) {
                        $matched_key = $bk;
                        break;
                    }
                }
                if ($matched_key !== null) {
                    $key = $matched_key;
                    $backupCodes[$key] = bin2hex(random_bytes(8)); // stronger replacement than 6-digit int
                    $db->where('user_id',$user_id)->update(T_BACKUP_CODES,[
                        'codes' => json_encode($backupCodes)
                    ]);
                    $confirm_code = 1;
                }
            }
        }

        // Use timing-safe comparison for 2FA code verification
        if ($user->two_factor_method == 'two_factor' && hash_equals($user->email_code, hash('sha256', $_POST['confirm_code']))) { // SECURITY: was md5()
            $confirm_code = 1;
            // Invalidate the 2FA code after use (prevent replay)
            $db->where('user_id', $user_id)->update(T_USERS, array('email_code' => ''));
            cache($user_id, 'users', 'delete');
        }
        else if ($user->two_factor_method == 'google' && !empty($user->google_secret) && $confirm_code == 0) {
            require_once 'assets/libraries/google_auth/vendor/autoload.php';
            try {
                $google2fa = new \PragmaRX\Google2FA\Google2FA();
                if ($google2fa->verifyKey($user->google_secret, $_POST['confirm_code'])) {
                    $confirm_code = 1;
                }
            } catch (Exception $e) {
                $errors = $e->getMessage();
            }
        }
        else if ($user->two_factor_method == 'authy' && !empty($user->authy_id) && $confirm_code == 0 && verifyAuthy($_POST['confirm_code'],$user->authy_id)) {
            $confirm_code = 1;
        }

        if (empty($confirm_code)) {
            if ($wo['config']['prevent_system'] == 1) {
                WoAddBadLoginLog();
            }
            $errors = $error_icon . $wo['lang']['wrong_confirmation_code'];
        }

        if (empty($errors) && $confirm_code > 0) {
            // Apply pending password reset if this 2FA was triggered during password reset flow
            // Enforce 15-min expiry on the pending reset session
            if (!empty($_SESSION['pending_reset_password']) && !empty($_SESSION['pending_reset_token'])
                && !empty($_SESSION['pending_reset_expires']) && time() <= intval($_SESSION['pending_reset_expires'])) {
                // The pending password is already hashed with password_hash()
                $pending_hash = $_SESSION['pending_reset_password'];
                $pending_uid = intval($user_id);
                $safe_hash = Wo_Secure($pending_hash);
                mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `password` = '{$safe_hash}', `email_code` = '', `time_code_sent` = 0 WHERE `user_id` = {$pending_uid}");
                // Invalidate all other sessions
                mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `user_id` = '{$pending_uid}'");
                cache($pending_uid, 'users', 'delete');
                unset($_SESSION['pending_reset_password']);
                unset($_SESSION['pending_reset_token']);
                unset($_SESSION['pending_reset_expires']);
            }

            // Clear 2FA session state
            unset($_SESSION['code_id']);
            unset($_SESSION['two_factor_username']);
            unset($_SESSION['two_factor_method']);
            unset($_SESSION['two_factor_hash']);

            if (!empty($_SESSION['last_login_data'])) {
                $update_user = $db->where('user_id', $user_id)->update(T_USERS, array('last_login_data' => json_encode($_SESSION['last_login_data'])));
            } else if (!empty(get_ip_address())) {
                $getIpInfo = fetchDataFromURL("https://ip-api.com/json/" .  get_ip_address());
                $getIpInfo = json_decode($getIpInfo, true);
                if ($getIpInfo['status'] == 'success' && !empty($getIpInfo['regionName']) && !empty($getIpInfo['countryCode']) && !empty($getIpInfo['timezone']) && !empty($getIpInfo['city'])) {
                    $update_user = $db->where('user_id', $user_id)->update(T_USERS, array('last_login_data' => json_encode($getIpInfo)));
                }
            }
            Wo_DeleteBadLogins();
            cache($user_id, 'users', 'delete');
            // Regenerate session ID on successful 2FA to prevent session fixation
            session_regenerate_id(true);
            $session             = Wo_CreateLoginSession($user_id);
            $data                = array(
                'status' => 200
            );
            $_SESSION['user_id'] = $session;
            if (isset($_SESSION['last_login_data'])) {
                unset($_SESSION['last_login_data']);
            }
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie("user_id", $session, [
                'expires'  => time() + (30 * 24 * 60 * 60),
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly'  => true,
                'samesite' => 'Lax'
            ]);
            if (!empty($_POST['last_url'])) {
                $parsed = parse_url($_POST['last_url']);
                $site_host = parse_url($wo['config']['site_url'], PHP_URL_HOST);
                // SECURITY: block protocol-relative URLs (//evil.com) — parse_url sees host=evil.com
                // Also block any URL with an explicit scheme (http/https) pointing off-site.
                $has_host = !empty($parsed['host']);
                $same_host = $has_host && $parsed['host'] === $site_host;
                $is_relative = !$has_host && strncmp($_POST['last_url'], '//', 2) !== 0;
                if ($is_relative || $same_host) {
                    $data['location'] = $_POST['last_url'];
                } else {
                    $data['location'] = $wo['config']['site_url'];
                }
            } else {
                $data['location'] = $wo['config']['site_url'];
            }
            $user_data = Wo_UserData($user_id);
            if ($wo['config']['membership_system'] == 1 && $user_data['is_pro'] == 0) {
                $data['location'] = Wo_SeoLink('index.php?link1=go-pro');
            }
        }
    }
    header("Content-type: application/json");
    if (!empty($errors)) {
        echo json_encode(array(
            'errors' => $errors
        ));
    } else if (!empty($data)) {
        echo json_encode($data);
    } else {
        echo json_encode(array(
            'errors' => $error_icon . 'Session expired. Please <a href="' . Wo_SeoLink('index.php?link1=welcome') . '">login again</a>.'
        ));
    }
    exit();
}

