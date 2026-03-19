<?php
if ($f == 'trading_bot') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Handle bot start/stop controls
    if (!empty($_POST['bot_control']) && !empty($_POST['bot_action'])) {
        $bot = $_POST['bot_control'];
        $action = $_POST['bot_action'];
        if (!in_array($bot, ['grid', 'arbitrage']) || !in_array($action, ['start', 'stop'])) {
            header("Content-type: application/json");
            echo json_encode(array('status' => 400, 'message' => 'Invalid parameters'));
            exit();
        }

        $currentMode = Wo_GetConfig('bot_mode') ?: 'both';
        $currentEnabled = Wo_GetConfig('bot_enabled') ?: '0';

        // Determine which strategies should be active after this action
        $gridActive = in_array($currentMode, ['both', 'market_making']);
        $arbActive = in_array($currentMode, ['both', 'arbitrage']);

        if ($bot === 'grid' && $action === 'start') {
            $gridActive = true;
        } elseif ($bot === 'grid' && $action === 'stop') {
            $gridActive = false;
        } elseif ($bot === 'arbitrage' && $action === 'start') {
            $arbActive = true;
        } elseif ($bot === 'arbitrage' && $action === 'stop') {
            $arbActive = false;
        }

        // Determine new mode
        if ($gridActive && $arbActive) {
            $newMode = 'both';
        } elseif ($gridActive) {
            $newMode = 'market_making';
        } elseif ($arbActive) {
            $newMode = 'arbitrage';
        } else {
            $newMode = 'both'; // will be disabled via bot_enabled
        }

        // If both are stopped, disable the bot entirely
        $newEnabled = ($gridActive || $arbActive) ? '1' : '0';

        Wo_SaveConfig('bot_mode', $newMode);
        Wo_SaveConfig('bot_enabled', $newEnabled);

        // Restart or stop the systemd service
        if ($newEnabled === '1') {
            @shell_exec('sudo systemctl restart trading-bot 2>&1');
            $msg = ucfirst($bot) . ' bot started. Mode: ' . $newMode;
        } else {
            @shell_exec('sudo systemctl stop trading-bot 2>&1');
            $msg = 'Both bots stopped. Service disabled.';
        }

        header("Content-type: application/json");
        echo json_encode(array(
            'status' => 200,
            'message' => $msg,
            'grid_running' => $gridActive,
            'arb_running' => $arbActive,
            'bot_mode' => $newMode,
            'bot_enabled' => $newEnabled
        ));
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
        'bot_order_size_min'    => 'int',
        'bot_order_size_max'    => 'int',
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
