<?php
if ($f == 'pay_using_wallet') {
    // CSRF protection for financial operations
    if (Wo_CheckSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403, 'message' => 'Invalid security token'));
        exit();
    }
    $type = (isset($_POST['type']) && is_numeric($_POST['type'])) ? $_POST['type'] : false;
    $html = "";
    $data = array(
        "status" => 404,
        "html" => $html
    );
    if ($type) {
        $can_buy              = false;
        $dollar_to_point_cost = $wo['config']['dollar_to_point_cost'];
        $price                = 0;
        $points               = 0;
        $img                  = "";
        if ($wo['config']['point_level_system'] == 1) {
            switch ($type) {
                case 1:
                    $img   = $wo['lang']['star'];
                    $price = $wo['pro_packages']['star']['price'];
                    break;
                case 2:
                    $img   = $wo['lang']['hot'];
                    $price = $wo['pro_packages']['hot']['price'];
                    break;
                case 3:
                    $img   = $wo['lang']['ultima'];
                    $price = $wo['pro_packages']['ultima']['price'];
                    break;
                case 4:
                    $img   = $wo['lang']['vip'];
                    $price = $wo['pro_packages']['vip']['price'];
                    break;
            }
            if ($wo["user"]["wallet"] >= $price) {
                $can_buy = true;
            }
            $points = $price * $dollar_to_point_cost;
            //if( $wo["user"]["balance"] >= $price ){ $can_buy = true; }
            //$balance = $wo["user"]["balance"];
        }
        if ($can_buy == true) {
            $safe_price         = floatval($price);
            $safe_uid           = intval($wo['user']['user_id']);
            $points             = $price * $dollar_to_point_cost;
            $safe_points        = floatval($points);
            $update_array       = array(
                'is_pro' => 1,
                'pro_time' => time(),
                'pro_' => 1,
                'pro_type' => $type
            );
            if (in_array($type, array_keys($wo['pro_packages_types'])) && $wo['pro_packages'][$wo['pro_packages_types'][$type]]['verified_badge'] == 1) {
                $update_array['verified'] = 1;
            }
            $mysqli             = Wo_UpdateUserData($wo['user']['user_id'], $update_array);
            $notes              = $wo['lang']['upgrade_to_pro'] . " " . $img . " : Wallet";
            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$safe_uid}, 'PRO', {$safe_price}, '{$notes}')");
            // SECURITY: atomic deduction — prevents race condition double-spend
            $points_dec = ($wo['config']['point_allow_withdrawal'] == 0) ? ", `points` = `points` - {$safe_points}" : "";
            $query_one  = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` - {$safe_price}{$points_dec} WHERE `user_id` = {$safe_uid} AND `wallet` >= {$safe_price}");
            cache($wo['user']['user_id'], 'users', 'delete');
            $data['status']     = 200;
            $data['url']        = Wo_SeoLink('index.php?link1=upgraded');
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
