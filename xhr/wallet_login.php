<?php
if ($f == 'wallet_login') {
    // Clear any existing session (consistent with google_login.php pattern)
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['user_id'] = '';
        unset($_SESSION['user_id']);
    }
    if (!empty($_COOKIE['user_id'])) {
        $_COOKIE['user_id'] = '';
        unset($_COOKIE['user_id']);
        setcookie('user_id', null, -1);
        setcookie('user_id', null, -1, '/');
    }

    $data['status'] = 400;

    // SECURITY: rate limit wallet login attempts per IP
    if (!bitchat_rate_limit('wallet_login', get_ip_address(), 10, 300)) {
        $data['error'] = 'Too many login attempts. Please try again later.';
        header('Content-type: application/json');
        echo json_encode($data);
        exit();
    }

    // Validate inputs: wallet address and signature format
    $wallet_raw  = $_POST['wallet_address'] ?? '';
    $signature   = $_POST['signature'] ?? '';

    if (
        !empty($wallet_raw) &&
        !empty($signature) &&
        preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet_raw) &&
        preg_match('/^0x[0-9a-fA-F]{130}$/', $signature)
    ) {
        $wallet_address = strtolower(Wo_Secure($wallet_raw, 0));
        $signature      = Wo_Secure($signature, 0);

        // Verify session nonce: must exist, not expired, and match wallet address
        $nonce_ok = (
            !empty($_SESSION['wallet_nonce']) &&
            !empty($_SESSION['wallet_nonce_address']) &&
            !empty($_SESSION['wallet_nonce_time']) &&
            (time() - intval($_SESSION['wallet_nonce_time'])) <= 300 &&
            $_SESSION['wallet_nonce_address'] === $wallet_address
        );

        if (!$nonce_ok) {
            $data['error'] = 'Login challenge expired or invalid. Please click Connect Wallet again.';
        } else {
            $nonce = $_SESSION['wallet_nonce'];

            // Clear nonce immediately — single-use, prevents replay attacks
            unset($_SESSION['wallet_nonce'], $_SESSION['wallet_nonce_address'], $_SESSION['wallet_nonce_time']);

            try {
                require_once dirname(__FILE__, 2) . '/assets/libraries/ethereum/vendor/autoload.php';

                $util = new \Web3p\EthereumUtil\Util();

                // Build the same message the frontend signed
                $message = "Login to Bitchat\nNonce: " . $nonce;

                // Hash with EIP-191 personal_sign prefix
                $hash = $util->hashPersonalMessage($message);

                // Parse signature: 0x + r(64) + s(64) + v(2) = 132 chars total
                $sig_hex = $util->stripZero($signature); // 130 hex chars
                $r       = '0x' . substr($sig_hex, 0, 64);
                $s       = '0x' . substr($sig_hex, 64, 64);
                $v       = hexdec(substr($sig_hex, 128, 2));

                // Normalize v: MetaMask uses 27/28, secp256k1 uses 0/1
                if ($v >= 27) {
                    $v -= 27;
                }

                // Recover public key and derive Ethereum address
                $public_key       = $util->recoverPublicKey($hash, $r, $s, $v);
                $recovered_address = strtolower($util->publicKeyToAddress($public_key));

                if ($recovered_address === $wallet_address) {
                    // Signature valid — look up existing wallet account
                    $existing = mysqli_fetch_assoc(mysqli_query(
                        $sqlConnect,
                        "SELECT `user_id`, `email`, `active` FROM `" . T_USERS . "`
                         WHERE `wallet_address` = '" . $wallet_address . "' LIMIT 1"
                    ));

                    if (!empty($existing['user_id'])) {
                        // Existing wallet user
                        if ($existing['active'] == 2) {
                            $data['error'] = 'This account has been disabled.';
                        } else {
                            // Mark onboarding complete so user lands on feed, not setup wizard
                            mysqli_query($sqlConnect, "UPDATE `" . T_USERS . "` SET `onboarding_completed` = 1 WHERE `user_id` = '" . $existing['user_id'] . "' AND (`onboarding_completed` IS NULL OR `onboarding_completed` = '' OR `onboarding_completed` = 0)");
                            Wo_SetLoginWithSession($existing['email']);
                            $data['status']   = 200;
                            $data['location'] = $wo['config']['site_url'] . '/?cache=' . time();
                        }
                    } else {
                        // New wallet — auto-create account (same pattern as google_login.php)
                        $short        = substr($wallet_address, 2, 8);
                        // SECURITY: random_int() replaces rand() for unpredictable username
                        $user_uniq_id = 'user_' . $short . '_' . random_int(100, 999);

                        if (Wo_UserExists($user_uniq_id) !== false) {
                            $user_uniq_id = 'user_' . bin2hex(random_bytes(4));
                        }

                        $gen_email = 'wallet_' . $short . '@bitchat.live';
                        if (Wo_EmailExists($gen_email) === true) {
                            $gen_email = 'wallet_' . bin2hex(random_bytes(4)) . '@bitchat.live';
                        }

                        $re_data = array(
                            'username'            => Wo_Secure($user_uniq_id, 0),
                            'email'               => Wo_Secure($gen_email, 0),
                            'password'            => bin2hex(random_bytes(16)), // Wo_RegisterUser() will hash this
                            'email_code'          => Wo_Secure(bin2hex(random_bytes(16)), 0), // SECURITY: replaces md5(time())
                            'first_name'          => Wo_Secure('Bitchat', 0),
                            'last_name'           => Wo_Secure('User', 0),
                            'wallet_address'      => Wo_Secure($wallet_address, 0),
                            'wallet_verified'     => 1,
                            'lastseen'            => time(),
                            'src'                 => 'wallet',
                            'social_login'        => 1,
                            'active'              => '1',
                            'onboarding_completed' => 1,
                        );

                        if (Wo_RegisterUser($re_data) === true) {
                            Wo_SetLoginWithSession($gen_email);

                            $new_user_id = Wo_UserIdFromEmail($gen_email);

                            if (!empty($wo['config']['auto_friend_users'])) {
                                Wo_AutoFollow($new_user_id);
                            }
                            if (!empty($wo['config']['auto_page_like'])) {
                                Wo_AutoPageLike($new_user_id);
                            }
                            if (!empty($wo['config']['auto_group_join'])) {
                                Wo_AutoGroupJoin($new_user_id);
                            }

                            $data['status']   = 200;
                            $data['location'] = $wo['config']['site_url'] . '/?cache=' . time();
                        } else {
                            $data['error'] = 'Failed to create account. Please try again.';
                        }
                    }
                } else {
                    $data['error'] = 'Signature verification failed. Please try again.';
                }
            } catch (Exception $e) {
                $data['error'] = 'Signature verification error. Please try again.';
            }
        }
    } else {
        $data['error'] = 'Invalid request parameters.';
    }

    header('Content-type: application/json');
    echo json_encode($data);
    exit();
}
