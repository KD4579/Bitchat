<?php
if ($f == 'trading_bot') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    // Allowed bot config keys and their validation rules
    $allowedKeys = array(
        'bot_enabled'           => 'bool',
        'bot_mode'              => 'mode',
        'bot_rpc_url'           => 'url',
        'bot_spread_percent'    => 'float',
        'bot_grid_levels'       => 'int',
        'bot_grid_spacing'      => 'float',
        'bot_order_size_trdc'   => 'int',
        'bot_max_slippage'      => 'float',
        'bot_daily_loss_limit'  => 'float',
        'bot_cooldown_seconds'  => 'int',
        'bot_max_trade_percent' => 'float',
        'bot_min_tvl'           => 'float',
        'bot_min_arb_profit'    => 'float',
        'bot_arb_max_size'      => 'int',
        'bot_max_gas_gwei'      => 'int',
        'bot_lp_exit_alert'     => 'float',
        'bot_tvl_drop_alert'    => 'float',
        'bot_pool_trdc_usdt'    => 'address',
        'bot_pool_trdc_wbnb'    => 'address',
        'bot_pool_usdt_fee'     => 'fee',
        'bot_pool_wbnb_fee'     => 'fee',
    );

    $validModes = array('both', 'market_making', 'arbitrage');
    $validFees = array('100', '500', '2500', '10000');

    foreach ($allowedKeys as $key => $type) {
        if (!isset($_POST[$key])) continue;

        $value = $_POST[$key];

        switch ($type) {
            case 'bool':
                $value = ($value == '1') ? '1' : '0';
                break;
            case 'mode':
                if (!in_array($value, $validModes)) $value = 'both';
                break;
            case 'url':
                $value = filter_var($value, FILTER_SANITIZE_URL);
                if (empty($value)) continue 2;
                break;
            case 'float':
                $value = strval(max(0, floatval($value)));
                break;
            case 'int':
                $value = strval(max(0, intval($value)));
                break;
            case 'address':
                // Validate BSC address format (0x + 40 hex chars)
                if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $value)) continue 2;
                $value = strtolower($value);
                break;
            case 'fee':
                if (!in_array($value, $validFees)) $value = '100';
                break;
        }

        Wo_SaveConfig($key, $value);
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
