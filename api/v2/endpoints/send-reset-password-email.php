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
$response_data = array(
    'api_status' => 400,
);
if (empty($_POST['email'])) {
    $error_code    = 3;
    $error_message = 'email (POST) is missing';
}
if (empty($error_code)) {
    // SECURITY: always return success to prevent email enumeration.
    // Old code returned distinct "Email not found" error for unknown addresses.
    $response_data = array('api_status' => 200);

    if (Wo_EmailExists($_POST['email']) !== false) {
        $user_recover_data = Wo_UserData(Wo_UserIdFromEmail($_POST['email']));
        $subject           = $config['siteName'] . ' ' . $wo['lang']['password_rest_request'];

        // SECURITY: generate a cryptographically random token stored in DB with expiry.
        // Old code used $user_recover_data['password'] (the bcrypt hash) in the URL —
        // it leaked in server logs, browser history, Referer headers, and reset links
        // never worked because the hash does not match the email_code format expected
        // by Wo_isValidPasswordResetToken().
        $reset_token = bin2hex(random_bytes(32));
        $expiry      = time() + 3600; // 1-hour expiry
        $db->where('user_id', $user_recover_data['user_id'])->update(T_USERS, array(
            'email_code'     => $reset_token,
            'time_code_sent' => $expiry,
        ));
        cache($user_recover_data['user_id'], 'users', 'delete');

        $user_recover_data['link'] = Wo_Link('index.php?link1=reset-password&code=' . $user_recover_data['user_id'] . '_' . $reset_token);
        $wo['recover']             = $user_recover_data;
        $body                      = Wo_LoadPage('emails/recover');
        $send_message_data         = array(
            'from_email'   => $wo['config']['siteEmail'],
            'from_name'    => $wo['config']['siteName'],
            'to_email'     => $_POST['email'],
            'to_name'      => '',
            'subject'      => $subject,
            'charSet'      => 'utf-8',
            'message_body' => $body,
            'is_html'      => true
        );
        Wo_SendMessage($send_message_data);
    }
    // Always return 200 — do not reveal whether the email is registered
}
