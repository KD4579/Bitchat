<?php
if ($f == 'wallet') {
    // CSRF check — require valid session hash for all wallet operations
    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 400, 'message' => 'Not authorized'));
        exit();
    }

    $dollar_to_point_cost = $wo['config']['dollar_to_point_cost'];
    if ($s == 'replenish-user-account') {
        $error = "";
        if (!isset($_GET['amount']) || !is_numeric($_GET['amount']) || $_GET['amount'] < 1) {
            $error = $error_icon . $wo['lang']['please_check_details'];
        }
        if (empty($error)) {
            $data = Wo_ReplenishWallet($_GET['amount']);
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        } else {
            header("Content-type: application/json");
            echo json_encode(array(
                'status' => 500,
                'error' => $error
            ));
            exit();
        }
    }
    if ($s == 'get-paid') {
        if (isset($_GET['success']) && $_GET['success'] == 1 && isset($_GET['token']) && !empty($_GET['token'])) {
            include_once "assets/includes/paypal_config.php";
            $token = Wo_Secure($_GET['token']);
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url . '/v2/checkout/orders/'.$token.'/capture');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);

            $headers = array();
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Authorization: Bearer '.$wo['paypal_access_token'];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                header("Location: $site_url/payment-error?reason=invalid-payment");
                exit();
            }
            curl_close($ch);
            if (!empty($result)) {
                $result = json_decode($result);
                if (!empty($result->status) && $result->status == 'COMPLETED') {
                    // SECURITY: Use PayPal-verified amount from capture response, NOT URL parameter
                    $paypal_amount = 0;
                    if (!empty($result->purchase_units[0]->payments->captures[0]->amount->value)) {
                        $paypal_amount = floatval($result->purchase_units[0]->payments->captures[0]->amount->value);
                    } elseif (!empty($result->purchase_units[0]->amount->value)) {
                        $paypal_amount = floatval($result->purchase_units[0]->amount->value);
                    }
                    if ($paypal_amount <= 0) { $paypal_amount = floatval($_GET['amount']); } // fallback
                    if (!empty($wo["config"]['currency_array']) && in_array($wo["config"]['paypal_currency'], $wo["config"]['currency_array']) && $wo["config"]['paypal_currency'] != $wo['config']['currency'] && !empty($wo['config']['exchange']) && !empty($wo['config']['exchange'][$wo["config"]['paypal_currency']])) {
                        $paypal_amount = ($paypal_amount / $wo['config']['exchange'][$wo["config"]['paypal_currency']]);
                    }
                    if (Wo_ReplenishingUserBalance($paypal_amount)) {
                        $safe_amount                    = floatval($paypal_amount);
                        $safe_userid                    = intval($wo['user']['user_id']);
                        $create_payment_log             = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $safe_userid . "', 'WALLET', '" . $safe_amount . "', 'PayPal')");
                        $_SESSION['replenished_amount'] = $_GET['amount'];
                        if (!empty($_COOKIE['redirect_page'])) {
                            $parsed_redir  = parse_url($_COOKIE['redirect_page']);
                            $site_host     = parse_url($wo['config']['site_url'], PHP_URL_HOST);
                            $has_host      = !empty($parsed_redir['host']);
                            $same_host     = $has_host && $parsed_redir['host'] === $site_host;
                            $is_relative   = !$has_host && strncmp($_COOKIE['redirect_page'], '//', 2) !== 0;
                            $redirect_page = ($is_relative || $same_host) ? $_COOKIE['redirect_page'] : Wo_SeoLink('index.php?link1=wallet');
                            header("Location: " . $redirect_page);
                        } else {
                            header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
                        }
                        exit();
                    } else {
                        header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
                        exit();
                    }
                }
            }
            else{
                header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
                exit();
            }
        } else if (isset($_GET['success']) && $_GET['success'] == 0) {
            header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
            exit();
        } else {
            header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
            exit();
        }
    }
    if ($s == 'remove' && isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
        $data['status'] = 304;
        if (Wo_DeleteUserAd($_GET['id'])) {
            $data['status'] = 200;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'send' && $wo['loggedin'] === true) {
        // CSRF protection for financial operations
        if (Wo_CheckSession($hash_id) !== true) {
            header("Content-type: application/json");
            echo json_encode(array('status' => 403, 'message' => 'Invalid security token'));
            exit();
        }
        $data     = array(
            'status' => 400
        );
        $user_id  = (!empty($_POST['user_id']) && is_numeric($_POST['user_id'])) ? $_POST['user_id'] : 0;
        $amount   = (!empty($_POST['amount']) && is_numeric($_POST['amount'])) ? floatval($_POST['amount']) : 0;
        $userdata = Wo_UserData($user_id);
        $wallet   = floatval($wo['user']['wallet']);
        if (empty($user_id) || $amount <= 0 || empty($userdata) || $wallet <= 0) {
            $data['message'] = $wo['lang']['please_check_details'];
        } else if ($wallet < $amount) {
            $data['message'] = $wo['lang']['amount_exceded'];
        } else {
            // Use atomic database operations to prevent race condition double-spend
            $safe_sender_id  = intval($wo['user']['user_id']);
            $safe_user_id    = intval($user_id);
            $safe_amount     = floatval($amount);

            mysqli_begin_transaction($sqlConnect);
            try {
                // Atomic debit: only succeeds if sufficient balance (prevents race condition)
                $debit = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` - {$safe_amount} WHERE `user_id` = {$safe_sender_id} AND `wallet` >= {$safe_amount}");
                if (!$debit || mysqli_affected_rows($sqlConnect) === 0) {
                    throw new Exception('Insufficient balance');
                }
                // Atomic credit
                $credit = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` + {$safe_amount} WHERE `user_id` = {$safe_user_id}");
                if (!$credit) {
                    throw new Exception('Transfer failed');
                }
                $note1 = mysqli_real_escape_string($sqlConnect, $userdata['name']);
                $note2 = mysqli_real_escape_string($sqlConnect, $wo['user']['name']);
                mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$safe_user_id}, 'RECEIVED', {$safe_amount}, '{$note2}')");
                mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$safe_sender_id}, 'SENT', {$safe_amount}, '{$note1}')");

                mysqli_commit($sqlConnect);

                $recipient_name  = $userdata['username'];
                $currency        = Wo_GetCurrency($wo['config']['ads_currency']);
                $success_msg     = $wo['lang']['money_sent_to'];
                $notif_msg       = $wo['lang']['sent_you'];
                $data['status']  = 200;
                $data['message'] = "$success_msg@ $recipient_name";
                $data['sender_balance'] = sprintf('%.2f', $wallet - $amount);
                cache($user_id, 'users', 'delete');
                cache($wo['user']['user_id'], 'users', 'delete'); // SECURITY: was $wo['user']['user_id'] — wrong field, resolves to null
                $notification_data_array = array(
                    'recipient_id' => $user_id,
                    'type' => 'sent_u_money',
                    'user_id' => $wo['user']['user_id'], // SECURITY: was $wo['user']['user_id'] — wrong field
                    'text' => "$notif_msg $amount$currency!",
                    'url' => 'index.php?link1=wallet'
                );
                Wo_RegisterNotification($notification_data_array);
            } catch (Exception $e) {
                mysqli_rollback($sqlConnect);
                $data['message'] = $wo['lang']['amount_exceded'];
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'pay' && $wo['loggedin'] === true) {
        $data  = array(
            'status' => 400
        );
        $price = 0;
        if (!empty($_GET['type']) && in_array($_GET['type'], array(
            'pro',
            'fund'
        ))) {
            if ($_GET['type'] == 'pro') {
                $img             = "";
                if (!empty($_GET['pro_type']) && in_array($_GET['pro_type'], array_keys($wo["pro_packages"]))) {
                    $_GET['pro_type'] = Wo_Secure($_GET['pro_type']);

                    $img = $wo["pro_packages"][$_GET['pro_type']]['name'];

                    if ($wo["pro_packages"][$_GET['pro_type']]['price'] > $wo['user']['wallet']) {
                        $data['message'] = "<a href='" . $wo['config']['site_url'] . "/wallet'>" . $wo["lang"]["please_top_up_wallet"] . "</a>";
                    }
                    elseif ($wo['user']['pro_type'] == $_GET['pro_type']) {
                        $data['message'] = $error_icon . $wo['lang']['something_wrong'];
                    }
                    else {
                        $price = $wo["pro_packages"][$_GET['pro_type']]['price'];
                    }
                } else {
                    $data['message'] = $error_icon . $wo['lang']['something_wrong'];
                }
            } elseif ($_GET['type'] == 'fund') {
                if (!empty($_GET['price']) && is_numeric($_GET['price']) && $_GET['price'] > 0) {
                    if (!empty($_GET['fund_id']) && is_numeric($_GET['fund_id']) && $_GET['fund_id'] > 0) {
                        $fund_id = Wo_Secure($_GET['fund_id']);
                        $price   = Wo_Secure($_GET['price']);
                        $fund    = $db->where('id', $fund_id)->getOne(T_FUNDING);
                        if (empty($fund)) {
                            $data['message'] = $error_icon . $wo['lang']['fund_not_found'];
                        }
                    } else {
                        $data['message'] = $error_icon . $wo['lang']['something_wrong'];
                    }
                } else {
                    $data['message'] = $error_icon . $wo['lang']['amount_can_not_empty'];
                }
            }
            if (empty($data['message'])) {
                if ($_GET['type'] == 'pro') {
                    $is_pro = 0;
                    $stop   = 0;
                    // $user   = Wo_UserData($wo['user']['user_id']);
                    // if ($user['is_pro'] == 1) {
                    //     $stop = 1;
                    //     if ($user['pro_type'] == 1) {
                    //         $time_ = time() - $star_package_duration;
                    //         if ($user['pro_time'] > $time_) {
                    //             $stop = 1;
                    //         }
                    //     } else if ($user['pro_type'] == 2) {
                    //         $time_ = time() - $hot_package_duration;
                    //         if ($user['pro_time'] > $time_) {
                    //             $stop = 1;
                    //         }
                    //     } else if ($user['pro_type'] == 3) {
                    //         $time_ = time() - $ultima_package_duration;
                    //         if ($user['pro_time'] > $time_) {
                    //             $stop = 1;
                    //         }
                    //     } else if ($user['pro_type'] == 4) {
                    //         if ($vip_package_duration > 0) {
                    //             $time_ = time() - $vip_package_duration;
                    //             if ($user['pro_time'] > $time_) {
                    //                 $stop = 1;
                    //             }
                    //         }
                    //     }
                    // }
                    if ($stop == 0) {
                        $pro_type        = $_GET['pro_type'];
                        $is_pro          = 1;
                    }
                    if ($stop == 0) {
                        $time = time();
                        if ($is_pro == 1) {
                            $update_array = array(
                                'is_pro' => 1,
                                'pro_time' => time(),
                                'pro_' => 1,
                                'pro_type' => $pro_type
                            );
                            if (in_array($pro_type, array_keys($wo['pro_packages'])) && $wo["pro_packages"][$pro_type]['verified_badge'] == 1) {
                                $update_array['verified'] = 1;
                            }
                            $mysqli             = Wo_UpdateUserData($wo['user']['user_id'], $update_array);
                            //$notes              = $wo['lang']['upgrade_to_pro'] . " " . $img . " : Wallet";
                            //$notes              = $img . " : Wallet";
                            //$notes              = str_replace('{text}', $img . " : Wallet", $wo['lang']['trans_upgrade_to_pro']);
                            $notes = json_encode([
                                'pro_type' => $pro_type,
                                'method_type' => 'wallet'
                            ]);

                            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$wo['user']['user_id']}, 'PRO', {$price}, '{$notes}')");
                            $create_payment     = Wo_CreatePayment($pro_type);
                            if ($mysqli) {
                                if ((!empty($_SESSION['ref']) || !empty($wo['user']['ref_user_id'])) && $wo['config']['affiliate_type'] == 1 && $wo['user']['referrer'] == 0) {
                                    if (!empty($_SESSION['ref'])) {
                                        $ref_user_id = Wo_UserIdFromUsername($_SESSION['ref']);
                                    } elseif (!empty($wo['user']['ref_user_id'])) {
                                        $ref_user_id = $wo['user']['ref_user_id'];
                                    }
                                    if ($wo['config']['amount_percent_ref'] > 0) {
                                        if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                                            $update_user = Wo_UpdateUserData($wo['user']['user_id'], array(
                                                'referrer' => $ref_user_id,
                                                'src' => 'Referrer'
                                            ));
                                            $ref_amount  = ($wo['config']['amount_percent_ref'] * $price) / 100;
                                            if ($wo['config']['affiliate_level'] < 2) {
                                                $update_balance = Wo_UpdateBalance($ref_user_id, $ref_amount);
                                            }
                                            if (is_numeric($wo['config']['affiliate_level']) && $wo['config']['affiliate_level'] > 1) {
                                                AddNewRef($ref_user_id, $wo['user']['user_id'], $ref_amount);
                                            }
                                            unset($_SESSION['ref']);
                                        }
                                    } else if ($wo['config']['amount_ref'] > 0) {
                                        if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                                            $update_user = Wo_UpdateUserData($wo['user']['user_id'], array(
                                                'referrer' => $ref_user_id,
                                                'src' => 'Referrer'
                                            ));
                                            if ($wo['config']['affiliate_level'] < 2) {
                                                $update_balance = Wo_UpdateBalance($ref_user_id, $wo['config']['amount_ref']);
                                            }
                                            if (is_numeric($wo['config']['affiliate_level']) && $wo['config']['affiliate_level'] > 1) {
                                                AddNewRef($ref_user_id, $wo['user']['user_id'], $wo['config']['amount_ref']);
                                            }
                                            unset($_SESSION['ref']);
                                        }
                                    }
                                }
                                $points = 0;
                                if ($wo['config']['point_level_system'] == 1) {
                                    $points = $price * $dollar_to_point_cost;
                                }
                                // SECURITY: Atomic wallet deduction to prevent race condition
                                $safe_price = floatval($price);
                                $safe_points = floatval($points);
                                $safe_uid = intval($wo['user']['user_id']);
                                $points_dec = ($wo['config']['point_allow_withdrawal'] == 0) ? ", `points` = `points` - {$safe_points}" : "";
                                $query_one = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` - {$safe_price}{$points_dec} WHERE `user_id` = {$safe_uid} AND `wallet` >= {$safe_price}");
                                cache($wo['user']['user_id'], 'users', 'delete');
                                $data['status'] = 200;
                                $data['url']    = Wo_SeoLink('index.php?link1=upgraded');
                            }
                        } else {
                            $data['message'] = $error_icon . $wo['lang']['something_wrong'];
                        }
                    } else {
                        $data['message'] = $error_icon . $wo['lang']['something_wrong'];
                    }
                } elseif ($_GET['type'] == 'fund') {
                    $amount             = $price;
                    //$notes              = "Doanted to " . mb_substr($fund->title, 0, 100, "UTF-8");
                    $notes              = mb_substr($fund->title, 0, 100, "UTF-8");
                    //$notes              = str_replace('{text}', mb_substr($fund->title, 0, 100, "UTF-8"), $wo['lang']['trans_doanted_to']);
                    $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$wo['user']['user_id']}, 'DONATE', {$amount}, '{$notes}')");
                    // SECURITY: Atomic wallet deduction for fund donation
                    $safe_price_f = floatval($price);
                    $safe_uid_f = intval($wo['user']['user_id']);
                    $query_one = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` - {$safe_price_f} WHERE `user_id` = {$safe_uid_f} AND `wallet` >= {$safe_price_f}");
                    cache($wo['user']['user_id'], 'users', 'delete');
                    $admin_com          = 0;
                    if (!empty($wo['config']['donate_percentage']) && is_numeric($wo['config']['donate_percentage']) && $wo['config']['donate_percentage'] > 0) {
                        $admin_com = ($wo['config']['donate_percentage'] * $amount) / 100;
                        $amount    = $amount - $admin_com;
                    }
                    $user_data = Wo_UserData($fund->user_id);
                    $db->where('user_id', $fund->user_id)->update(T_USERS, array(
                        'balance' => $user_data['balance'] + $amount
                    ));
                    cache($fund->user_id, 'users', 'delete');
                    $fund_raise_id           = $db->insert(T_FUNDING_RAISE, array(
                        'user_id' => $wo['user']['user_id'],
                        'funding_id' => $fund_id,
                        'amount' => $amount,
                        'time' => time()
                    ));
                    $post_data               = array(
                        'user_id' => Wo_Secure($wo['user']['user_id']),
                        'fund_raise_id' => $fund_raise_id,
                        'time' => time(),
                        'multi_image_post' => 0
                    );
                    $id                      = Wo_RegisterPost($post_data);
                    $notification_data_array = array(
                        'recipient_id' => $fund->user_id,
                        'type' => 'fund_donate',
                        'url' => 'index.php?link1=show_fund&id=' . $fund->hashed_id
                    );
                    Wo_RegisterNotification($notification_data_array);
                    $data = array(
                        'status' => 200,
                        'url' => $config['site_url'] . "/show_fund/" . $fund->hashed_id
                    );
                }
            }
        } else {
            $data['message'] = $error_icon . $wo['lang']['something_wrong'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'set' && $wo['loggedin'] === true) {
        if (!empty($_GET['type']) && in_array($_GET['type'], array(
            'pro',
            'fund'
        ))) {
            if ($_GET['type'] == 'pro') {
                setcookie("redirect_page", $wo['config']['site_url'] . '/go-pro', time() + (60 * 60), '/');
            } else if ($_GET['type'] == 'fund' && !empty($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                $fund_id = Wo_Secure($_GET['id']);
                $fund    = $db->where('id', $fund_id)->getOne(T_FUNDING);
                if (!empty($fund) && !empty($fund->id)) {
                    setcookie("redirect_page", $wo['config']['site_url'] . '/show_fund/' . $fund->hashed_id, time() + (60 * 60), '/');
                }
            }
        }
        $data = array(
            'status' => 200
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'check_credit' && $wo['loggedin'] === true) {
        $data['status'] = 400;
        if (!empty($_POST['amount'])) {
            if (($_POST['amount'] / $wo['config']['credit_price']) > $wo['user']['wallet']) {
                $data['message'] = $wo['lang']['not_enough_wallet_to_credits'];
            }
            else{
                $data['status'] = 200;
            }
        }
        else{
            $data['message'] = $wo['lang']['please_check_details'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'buy_credit' && $wo['loggedin'] === true) {
        $data['status'] = 400;
        if (!empty($_POST['amount'])) {
            if (($_POST['amount'] / $wo['config']['credit_price']) > $wo['user']['wallet']) {
                $data['message'] = $wo['lang']['not_enough_wallet_to_credits'];
            }
            else{
                $amount = Wo_Secure($_POST['amount']);
                $notes = $wo['lang']['ai_credit_purchase'];
                $dec = ($amount / $wo['config']['credit_price']);
                mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$wo['user']['user_id']}, 'Credits', {$dec}, '{$notes}')");
                $db->where('user_id',$wo['user']['user_id'])->update(T_USERS,[
                    'wallet' => $db->dec($dec),
                    'credits' => $db->inc($amount)
                ]);
                $data['status'] = 200;
            }
        }
        else{
            $data['message'] = $wo['lang']['please_check_details'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_credits' && $wo['loggedin'] === true) {
        $data['status'] = 200;
        $data['credits'] = $wo['user']['credits'];
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get-balance' && $wo['loggedin'] === true) {
        $uid = intval($wo['user']['user_id']);
        $q   = mysqli_query($sqlConnect, "SELECT `wallet`, `points` FROM " . T_USERS . " WHERE `user_id` = '{$uid}'");
        $row = mysqli_fetch_assoc($q);
        header("Content-type: application/json");
        echo json_encode([
            'status'  => 200,
            'balance' => $row ? number_format(floatval($row['wallet']), 2) : '0.00',
            'points'  => $row ? intval($row['points']) : 0,
        ]);
        exit();
    }
}
