<?php 
if ($f == 'coinpayments_callback_donate') {
    global $sqlConnect, $wo;
    $data  = array();
    $error = "";
    if (!isset($_POST['user_id']) || empty($_POST['user_id']) || !is_numeric($_POST['user_id']) || !isset($_POST['amountf']) || !is_numeric($_POST['amountf']) || $_POST['amountf'] < 1) {
        $error = $error_icon . $wo['lang']['please_check_details'];
    }
    if (empty($error)) {
        if ($wo['config']['coinpayments_secret'] !== "" && $wo['config']['coinpayments_id'] !== "") {
            try {
                include_once('assets/libraries/coinpayments.php');
                $CP = new \MineSQL\CoinPayments();
                $CP->setMerchantId($wo['config']['coinpayments_id']);
                $CP->setSecretKey($wo['config']['coinpayments_secret']);
                if ($CP->listen($_POST, $_SERVER)) {
                    // The payment is successful and passed all security measures
                    // SECURITY: IPN callbacks have no session — $wo['user']['user_id'] is null.
                    // Use HMAC-verified $_POST['user_id'] (set at form creation time).
                    $user_id   = intval($_POST['user_id']);
                    $txn_id    = $_POST['txn_id'];
                    $item_name = $_POST['item_name'];
                    $amount    = floatval($_POST['amountf']); // total in original currency
                    $fund_id   = Wo_Secure($_POST['fund_id']);
                    $status    = intval($_POST['status']);
                    // SECURITY: only process fully completed payments (status >= 100).
                    // Pending (0) and failed/cancelled (<0) IPNs must not trigger donations.
                    if ($status < 100) {
                        header("Location: " . Wo_SeoLink('index.php?link1=oops'));
                        exit();
                    }
                    $fund = $db->where('id',$fund_id)->getOne(T_FUNDING);

                    $notes = "Donated to ".mb_substr($fund->title, 0, 100, "UTF-8");

                    $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$user_id}, 'DONATE', {$amount}, '{$notes}')");

                    $admin_com = 0;
                    if (!empty($wo['config']['donate_percentage']) && is_numeric($wo['config']['donate_percentage']) && $wo['config']['donate_percentage'] > 0) {
                        $admin_com = ($wo['config']['donate_percentage'] * $amount) / 100;
                        $amount = $amount - $admin_com;
                    }
                    $user_data = Wo_UserData($fund->user_id);
                    $db->where('user_id',$fund->user_id)->update(T_USERS,array('balance' => $user_data['balance'] + $amount));
                    cache($fund->user_id, 'users', 'delete');
                    $fund_raise_id = $db->insert(T_FUNDING_RAISE,array('user_id' => $user_id,
                                                      'funding_id' => $fund_id,
                                                      'amount' => $amount,
                                                      'time' => time()));
                    $post_data = array(
                        'user_id' => $user_id,
                        'fund_raise_id' => $fund_raise_id,
                        'time' => time(),
                        'multi_image_post' => 0
                    );

                    $id = Wo_RegisterPost($post_data);

                    $notification_data_array = array(
                        'recipient_id' => $fund->user_id,
                        'type' => 'fund_donate',
                        'url' => 'index.php?link1=show_fund&id=' . $fund->hashed_id
                    );
                    Wo_RegisterNotification($notification_data_array);

                    // SECURITY: was $config['site_url'] — undefined variable, must be $wo['config']['site_url']
                    header("Location: " . $wo['config']['site_url'] . "/show_fund/".$fund->hashed_id);
                    exit();
                } else {
                    // the payment is pending. an exception is thrown for all other payment errors.
                    $data = array(
                        'status' => 400,
                        'error' => 'the payment is pending.'
                    );
                }
            }
            catch (Exception $e) {
                $data = array(
                    'status' => 400,
                    'error' => $e->getMessage()
                );
            }
        } else {
            $data = array(
                'status' => 400,
                'error' => 'bitcoin not set'
            );
        }
    } else {
        $data = array(
            'status' => 500,
            'error' => $error
        );
    }
    if ($data['status'] !== 200) {
        header("Location: " . Wo_SeoLink('index.php?link1=oops'));
        exit();
    } else {
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
}
