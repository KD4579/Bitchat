<?php 
if ($f == 'pay_with_trdc') {
    if ($s == 'pay') {
        if (!empty($_GET['amount']) && is_numeric($_GET['amount']) && $_GET['amount'] > 0) {
            $amount   = (int)Wo_Secure($_GET[ 'amount' ]);
            if (empty($wo['config']['coinpayments_coin'])) {
                $wo['config']['coinpayments_coin'] = 'trdc';
            }
           
            $db->insert(T_PENDING_PAYMENTS,array('user_id' => $wo['user']['user_id'],
                                                    'payment_data' => date('m-d-y i:a'),
                                                    'method_name' => 'trdc_payment',
                                                    'time' => time()));
            $data = array(
                'status' => 200,
                'url' => "https://www.google.com",
                'html' => '<div class="bank_info">
                <div class="dt_settings_header bg_gradient">
                    <div class="dt_settings_circle-1"></div>
                    <div class="dt_settings_circle-2"></div>
                    <div class="bank_info_innr">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M11.5,1L2,6V8H21V6M16,10V17H19V10M2,22H21V19H2M10,10V17H13V10M4,10V17H7V10H4Z"></path></svg>
                        <h4 class="bank_name">Garanti Bank</h4>
                        <div class="row">
                            <div class="col col-md-12">
                                <div class="bank_account">
                                    <p>4796824372433055</p>
                                    <span class="help-block">Account number / IBAN</span>
                                </div>
                            </div>
                            <div class="col col-md-12">
                                <div class="bank_account_holder">
                                    <p>Antoian Kordiyal</p>
                                    <span class="help-block">Account name</span>
                                </div>
                            </div>
                            <div class="col col-md-6">
                                <div class="bank_account_code">
                                    <p>TGBATRISXXX</p>
                                    <span class="help-block">Routing code</span>
                                </div>
                            </div>
                            <div class="col col-md-6">
                                <div class="bank_account_country">
                                    <p>United States</p>
                                    <span class="help-block">Country</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>                                ',
            );

            
        }else{
            $data = array(
                'status' => 400,
                'message' => $wo['lang']['empty_amount']
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'cancel_coinpayments') {
        $db->where('user_id', $wo['user']['user_id'])->where('method_name', 'coinpayments')->delete(T_PENDING_PAYMENTS);
        header('Location: ' . Wo_SeoLink('index.php?link1=wallet'));
        exit();
    }
}
