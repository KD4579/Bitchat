<?php
if ($f == 'braintree') {
	if ($s == 'checkout') {
		$data['status'] = 400;
		if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0 && !empty($_POST['nonce'])) {
			require_once 'assets/libraries/braintree/vendor/autoload.php';

			$gateway = new Braintree\Gateway([
			    'environment' => $wo['config']['braintree_mode'],
			    'merchantId' => $wo['config']['braintree_merchant_id'],
			    'publicKey' => $wo['config']['braintree_public_key'],
			    'privateKey' => $wo['config']['braintree_private_key']
			]);


			$amount = $_POST["amount"];
			$nonce = $_POST["nonce"];

			$result = $gateway->transaction()->sale([
			    'amount' => $amount,
			    'paymentMethodNonce' => $nonce,
			    'options' => [
			        'submitForSettlement' => true
			    ]
			]);

			if ($result->success || !is_null($result->transaction)) {
				$db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
                    'wallet' => $db->inc($amount)
                ));

				cache($wo['user']['user_id'], 'users', 'delete');

                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'braintree')");

                $_SESSION['replenished_amount'] = $amount;
                // SECURITY: validate same-origin before redirecting via cookie (open redirect fix)
                if (!empty($_COOKIE['redirect_page'])) {
                    $parsed_redir = parse_url($_COOKIE['redirect_page']);
                    $site_host    = parse_url($wo['config']['site_url'], PHP_URL_HOST);
                    $has_host     = !empty($parsed_redir['host']);
                    $same_host    = $has_host && $parsed_redir['host'] === $site_host;
                    $is_relative  = !$has_host && strncmp($_COOKIE['redirect_page'], '//', 2) !== 0;
                    $url = ($is_relative || $same_host) ? $_COOKIE['redirect_page'] : Wo_SeoLink('index.php?link1=wallet');
                }
                else{
                	$url = Wo_SeoLink('index.php?link1=wallet');
                }

				$data['status'] = 200;
				$data['url'] = $url;

			} else {
			    $errorString = "";

			    foreach($result->errors->deepAll() as $error) {
			        $errorString .= 'Error: ' . $error->code . ": " . $error->message . "\n";
			    }

			    $data['message'] = $errorString;
			}
		}
		else{
			$data['message'] = $wo['lang']['something_wrong'];
		}

		header("Content-type: application/json");
        echo json_encode($data);
        exit();
	}
}