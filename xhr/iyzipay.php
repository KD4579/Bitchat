<?php
if ($f == "iyzipay") {
    if ($s == 'create') {
        if (!empty($_GET['amount']) && is_numeric($_GET['amount']) && $_GET['amount'] > 0) {
            $price = Wo_Secure($_GET['amount']);
            require_once 'assets/libraries/iyzipay/samples/config.php';
            $callback_url = $wo['config']['site_url'] . "/requests.php?f=iyzipay&s=success&amount=" . $price . '&user_id=' . $wo['user']['user_id'] . '&ConversationId=' . $ConversationId;
            $request->setPrice($price);
            $request->setPaidPrice($price);
            $request->setCallbackUrl($callback_url);


            $basketItems     = array();
            $firstBasketItem = new \Iyzipay\Model\BasketItem();
            $firstBasketItem->setId("BI" . rand(11111111, 99999999));
            $firstBasketItem->setName('Top Up Wallet');
            $firstBasketItem->setCategory1('Top Up Wallet');
            $firstBasketItem->setItemType(\Iyzipay\Model\BasketItemType::PHYSICAL);
            $firstBasketItem->setPrice($price);
            $basketItems[0] = $firstBasketItem;
            $request->setBasketItems($basketItems);
            $checkoutFormInitialize = \Iyzipay\Model\CheckoutFormInitialize::create($request, IyzipayConfig::options());
            $content                = $checkoutFormInitialize->getCheckoutFormContent();
            if (!empty($content)) {
                $data['html']   = $content;
                $data['status'] = 200;
            } else {
                $data['error']  = $wo['lang']['something_wrong'];
                $data['status'] = 400;
            }

        } else {
            $data['status'] = 400;
            $data['error']  = $wo['lang']['invalid_amount_value'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    } elseif ($s == 'success') {
        if (!empty($_GET['ConversationId']) && !empty($_POST['token'])) {

            require_once 'assets/libraries/iyzipay/samples/config.php';
            # create request class
            $request = new \Iyzipay\Request\RetrieveCheckoutFormRequest();
            $request->setLocale(\Iyzipay\Model\Locale::TR);
            $request->setConversationId($_GET['ConversationId']);
            $request->setToken($_POST['token']);

            # make request
            $checkoutForm = \Iyzipay\Model\CheckoutForm::retrieve($request, IyzipayConfig::options());

            # print result
            if ($checkoutForm->getPaymentStatus() == 'SUCCESS') {
                $amount          = floatval($_GET['amount']);
                // SECURITY: ignore $_GET['user_id'] — always credit the logged-in user.
                // Previously trusted GET param, allowing attacker to redirect any valid
                // payment callback to an arbitrary victim's user_id (IDOR).
                if (!$wo['loggedin'] || empty($wo['user']['user_id'])) {
                    header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
                    exit();
                }
                $safe_userid = intval($wo['user']['user_id']);
                $safe_amount = floatval($amount);
                $db->where('user_id', $safe_userid)->update(T_USERS, array(
                    'wallet' => $db->inc($safe_amount)
                ));
                cache($safe_userid, 'users', 'delete');
                $create_payment_log             = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $safe_userid . "', 'WALLET', '" . $safe_amount . "', 'iyzipay')");
                $_SESSION['replenished_amount'] = $amount;
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
            }
        }
        header("Location: " . Wo_SeoLink('index.php?link1=wallet'));
        exit();
    }
}
