<?php 
if ($f == 'coinpayments_callback') {
    global $sqlConnect, $wo;
    $data  = array();
    $error = "";
    if (!isset($_POST['user_id']) || empty($_POST['user_id']) || !is_numeric($_POST['user_id']) || !isset($_POST['amount1']) || !is_numeric($_POST['amount1']) || $_POST['amount1'] < 1) {
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
                    $user_id   = $_POST['user_id'];
                    $txn_id    = $_POST['txn_id'];
                    $item_name = $_POST['item_name'];
                    $amount1   = floatval($_POST['amount1']); //    The total amount of the payment in your original currency/coin.
                    $amount2   = floatval($_POST['amount2']); //  The total amount of the payment in the buyer's selected coin.
                    $status    = intval($_POST['status']);
                    // SECURITY: only credit wallet for fully completed payments (status >= 100).
                    // Pending (0) and failed/cancelled (<0) IPNs must not add funds.
                    if ($status < 100) {
                        header("Location: " . Wo_SeoLink('index.php?link1=oops'));
                        exit();
                    }
                    $safe_amount = floatval($amount1);
                    $safe_userid = intval($user_id);
                    $result    = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` + " . $safe_amount . " WHERE `user_id` = '" . $safe_userid . "'");
                    if ($result) {
                        cache($user_id, 'users', 'delete');
                        $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $safe_userid . "', 'WALLET', '" . $safe_amount . "', 'coinpayments')");
                        // SECURITY: validate same-origin before redirecting via cookie (open redirect fix)
                        if (!empty($_COOKIE['redirect_page'])) {
                            $parsed_redir  = parse_url($_COOKIE['redirect_page']);
                            $site_host     = parse_url($wo['config']['site_url'], PHP_URL_HOST);
                            $has_host      = !empty($parsed_redir['host']);
                            $same_host     = $has_host && $parsed_redir['host'] === $site_host;
                            $is_relative   = !$has_host && strncmp($_COOKIE['redirect_page'], '//', 2) !== 0;
                            $redirect_page = ($is_relative || $same_host) ? $_COOKIE['redirect_page'] : Wo_SeoLink('index.php?link1=wallet');
                            header("Location: " . $redirect_page);
                        }
                        else{
                            header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
                        } 
                        exit();
                    }
                    $data = array(
                        'status' => 200,
                        'message' => $result
                    );
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
