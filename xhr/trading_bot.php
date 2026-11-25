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

        $currentMode = !empty($wo['config']['bot_mode']) ? $wo['config']['bot_mode'] : 'both';
        $currentEnabled = isset($wo['config']['bot_enabled']) ? $wo['config']['bot_enabled'] : '0';

        // Determine which strategies are currently active
        // If bot is disabled, nothing is running regardless of mode
        if ($currentEnabled === '0') {
            $gridActive = false;
            $arbActive = false;
        } else {
            $gridActive = in_array($currentMode, ['both', 'market_making']);
            $arbActive = in_array($currentMode, ['both', 'arbitrage']);
        }

        // Apply the requested action
        if ($bot === 'grid' && $action === 'start') {
            $gridActive = true;
        } elseif ($bot === 'grid' && $action === 'stop') {
            $gridActive = false;
        } elseif ($bot === 'arbitrage' && $action === 'start') {
            $arbActive = true;
        } elseif ($bot === 'arbitrage' && $action === 'stop') {
            $arbActive = false;
        }

        // Determine new mode from active states
        if ($gridActive && $arbActive) {
            $newMode = 'both';
        } elseif ($gridActive) {
            $newMode = 'market_making';
        } elseif ($arbActive) {
            $newMode = 'arbitrage';
        } else {
            $newMode = $currentMode; // preserve last mode when both stopped
        }

        // If both are stopped, disable the bot entirely
        $newEnabled = ($gridActive || $arbActive) ? '1' : '0';

        Wo_SaveConfig('bot_mode', $newMode);
        Wo_SaveConfig('bot_enabled', $newEnabled);

        // Persisting enabled state does not prove that the service started or stopped.
        $serviceAction = ($newEnabled === '1') ? 'restart' : 'stop';
        $systemctl = function_exists('shell_exec') ? trim((string) shell_exec('command -v systemctl 2>/dev/null')) : '';
        $serviceRunning = false;
        if ($systemctl === '') {
            $msg = 'Bot settings saved, but systemctl is unavailable; the service was not ' . ($newEnabled === '1' ? 'started.' : 'stopped.');
            $responseStatus = 503;
        } elseif (!function_exists('exec') || !function_exists('shell_exec')) {
            $msg = 'Bot settings saved, but PHP cannot execute service commands; the service state was not verified.';
            $responseStatus = 503;
        } else {
            $command = 'sudo -n ' . escapeshellarg($systemctl) . ' ' . $serviceAction . ' trading-bot 2>&1';
            $commandOutput = array();
            $exitCode = 1;
            exec($command, $commandOutput, $exitCode);
            $serviceState = trim((string) shell_exec('sudo -n ' . escapeshellarg($systemctl) . ' is-active trading-bot 2>/dev/null'));
            $serviceRunning = ($serviceState === 'active');
            $serviceStopped = in_array($serviceState, array('inactive', 'failed'), true);

            if ($exitCode !== 0 || ($newEnabled === '1' && !$serviceRunning) || ($newEnabled === '0' && !$serviceStopped)) {
                $detail = trim(implode("\n", $commandOutput));
                $msg = 'Bot settings saved, but the service did not reach the requested state (' . ($serviceState !== '' ? $serviceState : 'unknown') . ').';
                if ($detail !== '') {
                    $msg .= ' ' . $detail;
                }
                $responseStatus = 503;
            } else {
                $msg = ($newEnabled === '1')
                    ? ucfirst($bot) . ' service is active. Runtime health is shown on the dashboard.'
                    : 'Both bots stopped. Service is inactive.';
                $responseStatus = 200;
            }
        }

        $runtimeOnline = false;
        $heartbeatQuery = mysqli_query($sqlConnect, "SELECT `value` FROM `Wo_Config` WHERE `name` = 'bot_runtime_heartbeat' LIMIT 1");
        if ($heartbeatQuery) {
            $heartbeatRow = mysqli_fetch_assoc($heartbeatQuery);
            $heartbeat = $heartbeatRow ? intval($heartbeatRow['value']) : 0;
            $runtimeAge = time() - $heartbeat;
            $runtimeOnline = $heartbeat > 0 && $runtimeAge >= 0 && $runtimeAge <= 90;
        } else {
            error_log('Trading bot control could not read runtime heartbeat: ' . mysqli_error($sqlConnect));
        }

        header("Content-type: application/json");
        echo json_encode(array(
            'status' => $responseStatus,
            'message' => $msg,
            'grid_running' => $gridActive,
            'arb_running' => $arbActive,
            'bot_mode' => $newMode,
            'bot_enabled' => $newEnabled,
            'service_running' => $serviceRunning,
            'runtime_online' => $runtimeOnline
        ));
        exit();
    }

    $data = array('status' => 200);

    // Allowed bot config keys and their validation rules
    $allowedKeys = array(
        'bot_enabled'           => 'bool',
        'bot_wallet_address'    => 'address',
        'bot_mode'              => 'mode',
        'bot_rpc_url'           => 'url',
        'bot_spread_percent'    => 'float',
        'bot_grid_levels'       => 'int',
        'bot_grid_spacing'      => 'float',
        'bot_order_size_trdc'   => 'int',
        'bot_order_size_min'    => 'int',
        'bot_order_size_max'    => 'int',
        'bot_max_consecutive'   => 'int',
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
        'bot_arb_poll_seconds'  => 'int',
        'bot_arb_cooldown'      => 'int',
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
                if ($key === 'bot_wallet_address' && $value === '') {
                    break;
                }
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
