<?php
if ($f == 'update_two_factor') {
    // CSRF protection for 2FA settings changes
    if (Wo_CheckSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403, 'message' => 'Invalid security token. Please refresh and try again.'));
        exit();
    }
    $error = '';

    if ($s == 'enable') {
            
            $is_phone = false;
            if ($wo['config']['two_factor_type'] == 'both' || $wo['config']['two_factor_type'] == 'phone') {
                if (!empty($_POST['phone_number']) && ($wo['config']['two_factor_type'] == 'both' || $wo['config']['two_factor_type'] == 'phone')) {
                    preg_match_all('/\+(9[976]\d|8[987530]\d|6[987]\d|5[90]\d|42\d|3[875]\d|
                                    2[98654321]\d|9[8543210]|8[6421]|6[6543210]|5[87654321]|
                                    4[987654310]|3[9643210]|2[70]|7|1)\d{1,14}$/', $_POST['phone_number'], $matches);
                    if (!empty($matches[1][0]) && !empty($matches[0][0])) {
                        $is_phone = true;
                    }
                }
                if ((empty($_POST['phone_number']) && $wo['config']['two_factor_type'] == 'phone')) {
                    $error = $error_icon . $wo['lang']['please_check_details'];
                }
                elseif (!empty($_POST['phone_number']) && ($wo['config']['two_factor_type'] == 'both' || $wo['config']['two_factor_type'] == 'phone') && $is_phone == false) {
                    $error = $error_icon . $wo['lang']['phone_number_error'];
                }
            }
                

            if (empty($error)) {

                $code = random_int(100000, 999999);
                $hash_code = hash('sha256', $code); // SECURITY: was md5() — precomputable for 6-digit space (~900K values)
                $message = "Your confirmation code is: $code";
                $phone_sent = false;
                $email_sent = false;
                if (!empty($_POST['phone_number']) && ($wo['config']['two_factor_type'] == 'both' || $wo['config']['two_factor_type'] == 'phone')) {
                    $send = Wo_SendSMSMessage($_POST['phone_number'], $message);
                    if ($send) {
                        $phone_sent = true;
                        $Update_data = array(
                            'phone_number' => Wo_Secure($_POST['phone_number'])
                        );
                        Wo_UpdateUserData($wo['user']['user_id'], $Update_data);
                    }
                }
                if ($wo['config']['two_factor_type'] == 'both' || $wo['config']['two_factor_type'] == 'email') {

                    $send_message_data       = array(
                        'from_email' => $wo['config']['siteEmail'],
                        'from_name' => $wo['config']['siteName'],
                        'to_email' => $wo['user']['email'],
                        'to_name' => $wo['user']['name'],
                        'subject' => 'Please verify that it’s you',
                        'charSet' => 'utf-8',
                        'message_body' => $message,
                        'is_html' => true
                    );
                    $send = Wo_SendMessage($send_message_data);
                    if ($send) {
                        $email_sent = true;
                    }
                }
                if ($email_sent == true || $phone_sent == true) {
                    $Update_data = array(
                        'two_factor' => 0,
                        'two_factor_verified' => 0
                    );
                    Wo_UpdateUserData($wo['user']['user_id'], $Update_data);
                    $update_code =  $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array('email_code' => $hash_code));
                    cache($wo['user']['user_id'], 'users', 'delete');
                    $data = array(
                                'status' => 200,
                                'message' => $success_icon . $wo['lang']['we_have_sent_you_code'],
                            );
                }
                else{
                    $data = array(
                                'status' => 400,
                                'message' => $error_icon . $wo['lang']['something_wrong'],
                            );
                }
            }
            else{
                $data = array(
                                'status' => 400,
                                'message' => $error,
                            );
            }
    }

    if ($s == 'disable') {
        if ($_POST['two_factor'] != 'disable') {
            $error = $error_icon . $wo['lang']['please_check_details'];
            $data = array(
                            'status' => 400,
                            'message' => $error,
                        );
        }
        // Require password re-authentication before disabling 2FA
        else if (empty($_POST['current_password']) || Wo_HashPassword($_POST['current_password'], $wo['user']['password']) !== true) {
            $error = $error_icon . ($wo['lang']['current_password_mismatch'] ?? 'Incorrect password. Please enter your current password to disable 2FA.');
            $data = array(
                            'status' => 400,
                            'message' => $error,
                        );
        }
        else{
            $Update_data = array(
                'two_factor' => 0,
                'two_factor_verified' => 0
            );
            Wo_UpdateUserData($wo['user']['user_id'], $Update_data);
            $data = array(
                        'status' => 200,
                        'message' => $success_icon . $wo['lang']['setting_updated'],
                    );
        }

    }

    if ($s == 'verify') {
        if (empty($_POST['code'])) {
            $error = $error_icon . $wo['lang']['please_check_details'];
        }
        // Rate limit: 5 attempts per 15 min per user — prevents brute force of 6-digit code
        if (empty($error) && !bitchat_rate_limit('2fa_setup_verify_' . $wo['user']['user_id'], $wo['user']['user_id'], 5, 900)) {
            $error = $error_icon . $wo['lang']['login_attempts'];
        }
        if (empty($error)) {
            $user_verify = $db->where('user_id', $wo['user']['user_id'])->getOne(T_USERS);
            $confirm_code = (!empty($user_verify) && hash_equals($user_verify->email_code, hash('sha256', $_POST['code']))) ? 1 : 0; // SECURITY: was md5()
            $Update_data = array();
            if (empty($confirm_code)) {
                $error = $error_icon . $wo['lang']['wrong_confirmation_code'];
            }
            if (empty($error)) {
                $message = '';
                if ($wo['config']['two_factor_type'] == 'phone') {
                    $message = $success_icon . $wo['lang']['your_phone_verified'];
                    if (!empty($_GET['setting'])) {
                        $Update_data['phone_number'] = $wo['user']['new_phone'];
                        $Update_data['new_phone'] = '';
                    }
                }
                if ($wo['config']['two_factor_type'] == 'email') {
                    $message = $success_icon . $wo['lang']['your_email_verified'];
                    if (!empty($_GET['setting'])) {
                        $Update_data['email'] = $wo['user']['new_email'];
                        $Update_data['new_email'] = '';
                    }
                }
                if ($wo['config']['two_factor_type'] == 'both') {
                    $message = $success_icon . $wo['lang']['your_phone_email_verified'];
                    if (!empty($_GET['setting'])) {
                        if (!empty($wo['user']['new_email'])) {
                            $Update_data['email'] = $wo['user']['new_email'];
                            $Update_data['new_email'] = '';
                        }
                        if (!empty($wo['user']['new_phone'])) {
                            $Update_data['phone_number'] = $wo['user']['new_phone'];
                            $Update_data['new_phone'] = '';
                        }
                    }
                }
                $Update_data['two_factor_verified'] = 1;
                $Update_data['two_factor'] = 1;
                $Update_data['two_factor_method'] = 'two_factor';
                Wo_UpdateUserData($wo['user']['user_id'], $Update_data);

                $data = array(
                            'status' => 200,
                            'message' => $message,
                        );
            }
        }
        if (!empty($error)) {
            $data = array(
                        'status' => 400,
                        'message' => $error,
                    );
        }
    }

    if ($s == 'verify_code') {
        $data['status'] = 400;

        if (empty($_POST['code'])) {
            $data['message'] = $wo['lang']['empty_code'];
        }
        elseif (empty($_POST['factor_method']) || !in_array($_POST['factor_method'],array('two_factor','google','authy'))) {
            $data['message'] = $wo['lang']['select_two_factor_method'];
        }

        if (empty($data['message'])) {
            if ($_POST['factor_method'] == 'google') {
                require_once 'assets/libraries/google_auth/vendor/autoload.php';
                try {
                    $google2fa = new \PragmaRX\Google2FA\Google2FA();
                    if ($google2fa->verifyKey($wo['user']['google_secret'], $_POST['code'])) {
                        $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, ['two_factor' => 1,
                                                                                        'two_factor_verified' => 1,
                                                                                        'two_factor_method' => 'google']);
                     
                        $data['status'] = 200;
                        $data['message'] = $success_icon . $wo['lang']['setting_updated'];
                    } else {
                        $data['message'] = $wo['lang']['wrong_confirm_code'];
                    }
                } catch (Exception $e) {
                    $data['message'] = $e->getMessage();
                }
                    
            }
            elseif ($_POST['factor_method'] == 'authy') {
                if (verifyAuthy($_POST['code'],$wo['user']['authy_id'])) {
                    $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, ['two_factor' => 1,
                                                                                    'two_factor_verified' => 1,
                                                                                    'two_factor_method' => 'authy']);
                    $data['status'] = 200;
                    $data['message'] = $success_icon . $wo['lang']['setting_updated'];
                }
                else{
                    $data['status'] = 400;
                    $data['message'] = $wo['lang']['wrong_confirm_code'];
                }
            }
            else{
                if (hash_equals($wo['user']['email_code'], hash('sha256', $_POST['code']))) { // SECURITY: was md5()
                    $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, ['two_factor' => 1,
                                                                                    'two_factor_verified' => 1,
                                                                                    'two_factor_method' => 'two_factor']);
                    $data['status'] = 200;
                    $data['message'] = $success_icon . $wo['lang']['setting_updated'];
                }
                else{
                    $data['status'] = 400;
                    $data['message'] = $wo['lang']['wrong_confirm_code'];
                }
            }
        }

    }

    if ($s == 'authy_register') {
        $data['status'] = 400;

        if (empty($_POST['email'])) {
            $data['message'] = $wo['lang']['empty_email'];
        }
        if (empty($_POST['phone'])) {
            $data['message'] = $wo['lang']['empty_phone'];
        }
        if (empty($_POST['country_code'])) {
            $data['status'] = 400;
            $data['message'] = $wo['lang']['empty_country_code'];
        }

        if (empty($data['message'])) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, 'https://api.authy.com/protected/json/users/new');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            // SECURITY: use http_build_query to prevent HTTP parameter injection from unsanitized POST values
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'user[email]'        => $_POST['email'],
                'user[cellphone]'    => $_POST['phone'],
                'user[country_code]' => $_POST['country_code'],
            ]));

            $headers = array();
            $headers[] = 'X-Authy-Api-Key: '.$wo['config']['authy_token'];
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                $data['status'] = 400;
                $data['message'] = curl_error($ch);
            }
            curl_close($ch);
            $result = json_decode($result);
            if (!empty($result) && !empty($result->user) && !empty($result->user->id)) {
                $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, ['authy_id' => $result->user->id]);
                $QR = getAuthyQR($result->user->id);
                if (!empty($QR)) {
                    $data['qr'] = $QR;
                }
                $data['status'] = 200;
                $data['message'] = $wo['lang']['authy_registered'];
            }
            else{
                $data['message'] = $result->message;
            }
        }
    }

    if ($s == 'backup_codes') {
        $codes = $db->where('user_id',$wo['user']['user_id'])->getOne(T_BACKUP_CODES);
        $filename = 'backup-codes.txt';
        if (!empty($codes)) {
            $backupCodes = json_decode($codes->codes,true);
            createBackupCodesFile($backupCodes,$filename);
        }
        else{
            $backupCodes = createBackupCodes();
            createBackupCodesFile($backupCodes,$filename);

            $id = $db->insert(T_BACKUP_CODES,[
                'user_id' => $wo['user']['user_id'],
                'codes' => json_encode($backupCodes)
            ]);
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');
        exit;
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
