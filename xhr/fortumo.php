<?php
if ($f == 'fortumo') {
	if ($s == 'pay') {
	    $data['status'] = 200;
		$data['url'] = 'https://pay.fortumo.com/mobile_payments/'.$wo['config']['fortumo_service_id'].'?cuid='.$wo['user']['user_id'];
		header("Content-type: application/json");
        echo json_encode($data);
        exit();
	}
	if ($s == 'success_fortumo') {
		if (!empty($_GET) && !empty($_GET['amount']) && !empty($_GET['status']) && $_GET['status'] == 'completed' && !empty($_GET['cuid']) && !empty($_GET['price'])) {
	        $user_id = Wo_Secure($_GET['cuid']);
	        $amount = (int) Wo_Secure($_GET['price']);
	        $user = $db->objectBuilder()->where('user_id',$user_id)->getOne(T_USERS);
	        if (!empty($user)) {
	        	$db->where('user_id', $user->user_id)->update(T_USERS, array(
                    'wallet' => $db->inc($amount)
                ));

				cache($user->user_id, 'users', 'delete');

                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $user->user_id . "', 'WALLET', '" . $amount . "', 'fortumo')");
                $_SESSION['replenished_amount'] = $amount;
	        }
	    }
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
}