<?php
use SecurionPay\SecurionPayGateway;
use SecurionPay\Exception\SecurionPayException;
use SecurionPay\Request\CheckoutRequestCharge;
use SecurionPay\Request\CheckoutRequest;
if ($f == "securionpay") {
	if ($s == 'create') {
		if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0) {
			require_once 'assets/libraries/securionpay/vendor/autoload.php';
			$price = Wo_Secure($_POST['amount']);
			$securionPay = new SecurionPayGateway($wo['config']['securionpay_secret_key']);

            $checkoutCharge = new CheckoutRequestCharge();
            $checkoutCharge->amount(($price * 100))->currency('USD')->metadata(array('user_key' => $wo['user']['user_id'],
                                                                                     'type' => 'Top Up Wallet'));

            $checkoutRequest = new CheckoutRequest();
            $checkoutRequest->charge($checkoutCharge);

            $signedCheckoutRequest = $securionPay->signCheckoutRequest($checkoutRequest);
            if (!empty($signedCheckoutRequest)) {
                $data['status'] = 200;
                $data['token'] = $signedCheckoutRequest;
            }
            else{
                $data['status'] = 400;
                $data['error'] = $wo['lang']['something_wrong'];
            }
		}
		else{
	        $data['status'] = 400;
	        $data['error'] = $wo['lang']['invalid_amount_value'];
	    }
		header("Content-type: application/json");
        echo json_encode($data);
        exit();
	}
	if ($s == 'handle') {
		if (!empty($_POST) && !empty($_POST['charge']) && !empty($_POST['charge']['id'])) {
	        $url = "https://api.securionpay.com/charges?limit=10";

	        $curl = curl_init($url);
	        curl_setopt($curl, CURLOPT_URL, $url);
	        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	        // SECURITY: SSL verification must remain enabled to prevent MITM attacks against the payment API.
	        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
	        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
	        curl_setopt($curl, CURLOPT_USERPWD, $wo['config']['securionpay_secret_key'].":password");
	        $resp = curl_exec($curl);
	        curl_close($curl);
	        $resp = json_decode($resp,true);
	        if (!empty($resp) && !empty($resp['list'])) {
	            foreach ($resp['list'] as $key => $value) {
	                if ($value['id'] == $_POST['charge']['id']) {
	                    if (!empty($value['metadata']) && !empty($value['metadata']['user_key']) && !empty($value['amount'])) {
	                        if ($wo['user']['user_id'] == $value['metadata']['user_key']) {
	                        	$amount = intval(Wo_Secure($value['amount'])) / 100;
	                        	if (Wo_ReplenishingUserBalance($amount)) {
		                            $create_payment_log             = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'securionpay')");
					                $_SESSION['replenished_amount'] = $amount;
					                $url = Wo_SeoLink('index.php?link1=wallet');
					                // SECURITY: validate same-origin before redirecting via cookie (open redirect fix)
					                if (!empty($_COOKIE['redirect_page'])) {
					                    $parsed_redir = parse_url($_COOKIE['redirect_page']);
					                    $site_host    = parse_url($wo['config']['site_url'], PHP_URL_HOST);
					                    $has_host     = !empty($parsed_redir['host']);
					                    $same_host    = $has_host && $parsed_redir['host'] === $site_host;
					                    $is_relative  = !$has_host && strncmp($_COOKIE['redirect_page'], '//', 2) !== 0;
					                    $url = ($is_relative || $same_host) ? $_COOKIE['redirect_page'] : Wo_SeoLink('index.php?link1=wallet');
					                }
					                $data['status'] = 200;
	               					$data['url'] = $url;
	                        	}
	                        }
	                    }
	                }
	            }
	        }
	        else{
	        	$data['status'] = 400;
                $data['error'] = $wo['lang']['something_wrong'];
	        }
	    }
	    else{
	    	$data['status'] = 400;
	        $data['error'] = $wo['lang']['please_check_details'];
	    }
	    header("Content-type: application/json");
        echo json_encode($data);
        exit();
	}
}