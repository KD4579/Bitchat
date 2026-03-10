<?php
if ($f == 'staking_admin') {
    if (!Wo_IsAdmin()) {
        echo json_encode(array('status' => 400, 'message' => 'Unauthorized'));
        exit();
    }

    $validKeys = array(
        'staking_enabled'          => 'bool',
        'staking_min_amount'       => 'int',
        'staking_max_amount'       => 'int',
        'staking_affiliate_percent'=> 'float',
        'staking_onchain_enabled'  => 'bool',
        'staking_offchain_enabled' => 'bool',
        'staking_plan_1_days'      => 'int',
        'staking_plan_1_apy'       => 'float',
        'staking_plan_1_enabled'   => 'bool',
        'staking_plan_2_days'      => 'int',
        'staking_plan_2_apy'       => 'float',
        'staking_plan_2_enabled'   => 'bool',
        'staking_plan_3_days'      => 'int',
        'staking_plan_3_apy'       => 'float',
        'staking_plan_3_enabled'   => 'bool',
        'staking_plan_4_days'      => 'int',
        'staking_plan_4_apy'       => 'float',
        'staking_plan_4_enabled'   => 'bool',
    );

    $saved = 0;
    foreach ($validKeys as $key => $type) {
        if (!isset($_POST[$key])) continue;

        $val = $_POST[$key];
        switch ($type) {
            case 'bool':
                $val = ($val == '1') ? '1' : '0';
                break;
            case 'int':
                $val = strval(max(0, intval($val)));
                break;
            case 'float':
                $val = strval(max(0, floatval($val)));
                break;
        }

        Wo_SaveConfig($key, $val);
        $saved++;
    }

    echo json_encode(array('status' => 200, 'message' => "Staking settings saved ({$saved} values updated)"));
    exit();
}
?>
