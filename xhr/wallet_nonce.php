<?php
if ($f == 'wallet_nonce') {
    $data['status'] = 400;

    if (
        !empty($_POST['wallet_address']) &&
        preg_match('/^0x[0-9a-fA-F]{40}$/', $_POST['wallet_address'])
    ) {
        $wallet_address = strtolower(Wo_Secure($_POST['wallet_address'], 0));

        // Generate single-use random nonce stored server-side (session only)
        $nonce = bin2hex(random_bytes(16));

        $_SESSION['wallet_nonce']         = $nonce;
        $_SESSION['wallet_nonce_address'] = $wallet_address;
        $_SESSION['wallet_nonce_time']    = time();

        $data['status'] = 200;
        $data['nonce']  = $nonce;
    } else {
        $data['error'] = 'Invalid wallet address.';
    }

    header('Content-type: application/json');
    echo json_encode($data);
    exit();
}
