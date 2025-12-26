<?php 
if ($f == "busd_bep20_payment") {
    if (Wo_CheckSession($hash_id) === true) {
        $request   = array();
        $request[] = (empty($_POST["busd_bep20Address"]) || empty($_POST["busd_bep20Amount"]));
        if (in_array(true, $request)) {
            $error = $error_icon . $wo['lang']['please_check_details'];
        }
        if (empty($error)) {
            $address       = Wo_Secure($_POST['busd_bep20Address']);
            $amount        = floatval($_POST['busd_bep20Amount']);
            
            // Implement logic to store BUSD BEP-20 payment details in the database
            // For example, insert the payment details into a 'wallet_payments' table
            
            $insert_id = Wo_InsertWalletPayment(array(
                'user_id' => $wo['user']['id'],
                'wallet_method' => 'BUSD BEP-20',
                'wallet_address' => $address,
                'amount' => $amount
            ));

            if (!empty($insert_id)) {
                $data = array(
                    'message' => $success_icon . $wo['lang']['busd_bep20_payment_request'],
                    'status' => 200
                );
            }
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}

?>