<?php
if ($f == 'update_data') {
    if (Wo_CheckMainSession($hash_id) === true) {
        $sql_query             = mysqli_query($sqlConnect, "UPDATE " . T_APP_SESSIONS . " SET `time` = " . time() . " WHERE `session_id` = '{$session_id}'");
        $data['pop']           = 0;
        $data['status']        = 200;
        $data['notifications'] = Wo_CountNotifications(array(
            'unread' => true
        ));
        $data['html']          = '';
        $notifications         = Wo_GetNotifications(array(
            'type_2' => 'popunder',
            'unread' => true,
            'limit' => 1
        ));
        foreach ($notifications as $wo['notification']) {
            $data['html']              = Wo_LoadPage('header/notifecation');
            $data['icon']              = $wo['notification']['notifier']['avatar'];
            $data['title']             = $wo['notification']['notifier']['name'];
            $data['notification_text'] = $wo['notification']['type_text'];
            $data['url']               = $wo['notification']['url'];
            $data['pop']               = 200;
            if ($wo['notification']['seen'] == 0) {
                $query     = "UPDATE " . T_NOTIFICATION . " SET `seen_pop` = " . time() . " WHERE `id` = " . $wo['notification']['id'];
                $sql_query = mysqli_query($sqlConnect, $query);
            }
        }
        $data['messages'] = Wo_CountMessages(array(
            'new' => true
        ), 'interval');
        $chat_groups      = Wo_CheckLastGroupUnread();
        $data['messages'] = $data['messages'] + count($chat_groups);
        $data['calls']    = 0;
        $data['is_call']  = 0;
        $check_calles     = Wo_CheckFroInCalls();
        if ($check_calles !== false && is_array($check_calles)) {
            $wo['incall']                 = $check_calles;
            $wo['incall']['in_call_user'] = Wo_UserData($check_calles['from_id']);
            $data['calls']                = 200;
            $data['is_call']              = 1;
            $data['call_id']              = $wo['incall']['id'];
            $data['calls_html']           = Wo_LoadPage('modals/in_call');
        }
        $data['audio_calls']   = 0;
        $data['is_audio_call'] = 0;
        $check_calles          = Wo_CheckFroInCalls('audio');
        if ($check_calles !== false && is_array($check_calles)) {
            $wo['incall']                 = $check_calles;
            $wo['incall']['in_call_user'] = Wo_UserData($check_calles['from_id']);
            $data['audio_calls']          = 200;
            $data['is_audio_call']        = 1;
            $data['call_id']              = $wo['incall']['id'];
            $data['audio_calls_html']     = Wo_LoadPage('modals/in_audio_call');
        }
        $data['followRequests']      = Wo_CountFollowRequests();
        $data['followRequests']      = $data['followRequests'] + Wo_CountGroupChatRequests();
        $data['notifications_sound'] = $wo['user']['notifications_sound'];
    }
    $data['count_num'] = 0;
    if ($_GET['check_posts'] == 'true') {
        if (!empty($_GET['before_post_id']) && isset($_GET['user_id'])) {
            $before_post_id = Wo_Secure($_GET['before_post_id']);
            $logged_user_id = Wo_Secure($wo['user']['user_id']);
            $user_id_param  = Wo_Secure($_GET['user_id']);

            if (!empty($user_id_param) && $user_id_param > 0) {
                // Profile page: count new posts by that user
                $count_query = "SELECT COUNT(*) AS cnt FROM " . T_POSTS . " WHERE `id` > {$before_post_id} AND `multi_image_post` = 0 AND `postType` <> 'profile_picture_deleted' AND (`user_id` = {$user_id_param} OR `recipient_id` = {$user_id_param}) AND `postShare` IN (0,1) AND `group_id` = 0 AND `event_id` = 0 AND `postPrivacy` <> '4'";
            } else {
                // Home feed: count new posts from real users only (exclude bots)
                // Bot posts appear naturally in feed refresh — counting them inflates the indicator
                $count_query = "SELECT COUNT(*) AS cnt FROM " . T_POSTS . " WHERE `id` > {$before_post_id} AND `multi_image_post` = 0 AND `postType` <> 'profile_picture_deleted' AND `postShare` NOT IN (1) AND `active` = 1";
                $count_query .= " AND `user_id` NOT IN (SELECT `user_id` FROM Wo_Bot_Accounts)";
                $count_query .= " AND (
                    `user_id` IN (SELECT `following_id` FROM " . T_FOLLOWERS . " WHERE `follower_id` = {$logged_user_id} AND `active` = '1')
                    OR `user_id` = {$logged_user_id}
                    OR `page_id` IN (SELECT `page_id` FROM " . T_PAGES_LIKES . " WHERE `user_id` = {$logged_user_id} AND `active` = '1')
                    OR `group_id` IN (SELECT `group_id` FROM " . T_GROUP_MEMBERS . " WHERE `user_id` = {$logged_user_id} AND `active` = '1')
                )";
                $count_query .= " AND (`postPrivacy` <> '3' OR (`user_id` = {$logged_user_id} AND `postPrivacy` >= '0'))";
            }

            $count_result = mysqli_query($sqlConnect, $count_query);
            $count = 0;
            if ($count_result) {
                $row = mysqli_fetch_assoc($count_result);
                $count = (int) $row['cnt'];
            }

            $lang_key = ($count == 1) ? 'view_more_post' : 'view_more_posts';
            $lang_str = isset($wo['lang'][$lang_key]) ? $wo['lang'][$lang_key] : 'View {count} new post' . ($count != 1 ? 's' : '');
            $data['count'] = str_replace('{count}', $count, $lang_str);
            $data['count_num'] = $count;
        }
    } else if ($_GET['hash_posts'] == 'true') {
        if (!empty($_GET['before_post_id']) && isset($_GET['user_id'])) {
            $html  = '';
            $posts = Wo_GetHashtagPosts($_GET['hashtagName'], 0, 20, $_GET['before_post_id']);
            $count = count($posts);
            $lang_key = ($count == 1) ? 'view_more_post' : 'view_more_posts';
            $lang_str = isset($wo['lang'][$lang_key]) ? $wo['lang'][$lang_key] : 'View {count} new post' . ($count != 1 ? 's' : '');
            $data['count'] = str_replace('{count}', $count, $lang_str);
            $data['count_num'] = $count;
        }
    }
    $send_messages_to_phones = Wo_MessagesPushNotifier();

    $payment_data           = $db->objectBuilder()->where('user_id',$wo['user']['user_id'])->where('method_name', 'coinpayments')->orderBy('id','DESC')->getOne(T_PENDING_PAYMENTS);
    $coinpayments_txn_id = '';
    if (!empty($payment_data)) {
        $coinpayments_txn_id = $payment_data->payment_data;
    }

    if (!empty($coinpayments_txn_id)) {
        $result = coinpayments_api_call(array('key' => $wo['config']['coinpayments_public_key'],
                                              'version' => '1',
                                              'format' => 'json',
                                              'cmd' => 'get_tx_info',
                                              'full' => '1',
                                              'txid' => $coinpayments_txn_id));
        if (!empty($result) && $result['status'] == 200) {
            if ($result['data']['status'] == -1) {
                $db->where('user_id', $wo['user']['user_id'])->where('payment_data', $coinpayments_txn_id)->delete(T_PENDING_PAYMENTS);
                $notification_data_array = array(
                    'recipient_id' => $wo['user']['user_id'],
                    'type' => 'admin_notification',
                    'type2' => 'coinpayments_canceled',
                    'url' => 'index.php?link1=wallet',
                    'time' => time()
                );
                $db->insert(T_NOTIFICATION, $notification_data_array);
            }
            elseif ($result['data']['status'] == 100) {
                $amount   = $result['data']['checkout']['amountf'];
                $db->where('user_id', $wo['user']['user_id'])->where('payment_data', $coinpayments_txn_id)->delete(T_PENDING_PAYMENTS);
                $db->where('user_id',$wo['user']['user_id'])->update(T_USERS,array('wallet' => $db->inc($amount)));

                cache($wo['user']['user_id'], 'users', 'delete');

                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'coinpayments')");
                $_SESSION['replenished_amount'] = $amount;

                $notification_data_array = array(
                    'recipient_id' => $wo['user']['user_id'],
                    'type' => 'admin_notification',
                    'type2' => 'coinpayments_approved',
                    'url' => 'index.php?link1=wallet',
                    'time' => time()
                );
                $db->insert(T_NOTIFICATION, $notification_data_array);
            }
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
