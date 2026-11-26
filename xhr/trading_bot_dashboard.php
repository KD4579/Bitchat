<?php
if ($f == 'trading_bot_dashboard') {
    header("Content-type: application/json");

    if (!Wo_IsAdmin()) {
        echo json_encode(array('status' => 403));
        exit();
    }

    $action = isset($_GET['action']) ? $_GET['action'] : 'trades';

    // Check if Wo_Bot_Trades table exists
    $tableCheck = mysqli_query(
        $sqlConnect,
        "SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'Wo_Bot_Trades'"
    );
    if (!$tableCheck) {
        error_log('Trading bot dashboard could not inspect the Wo_Bot_Trades table: ' . mysqli_error($sqlConnect));
        http_response_code(500);
        echo json_encode(array('status' => 500, 'message' => 'Could not check the trading bot database schema.'));
        exit();
    }
    $tableExists = (intval(mysqli_fetch_assoc($tableCheck)['table_count']) > 0);

    if ($action === 'status') {
        // Get bot status stats from Wo_Config
        $stats = array();
        $keys = array('bot_enabled','bot_mode','bot_daily_pnl','bot_last_cycle','bot_next_cycle',
                       'bot_cycle_count','bot_next_direction','bot_cooldown_seconds','bot_spread_percent',
                       'bot_order_size_trdc','bot_arb_monitor_status','bot_last_arb','bot_arb_count',
                       'bot_arb_poll_seconds','bot_arb_cooldown');
        foreach ($keys as $k) {
            $stats[$k] = isset($wo['config'][$k]) ? $wo['config'][$k] : '';
        }

        $runtimeConfig = array();
        $runtimeQuery = mysqli_query(
            $sqlConnect,
            "SELECT `name`, `value` FROM `Wo_Config` WHERE `name` IN ('bot_runtime_heartbeat', 'bot_runtime_wallet_address', 'bot_runtime_status', 'bot_gas_balance', 'bot_arb_status', 'bot_arb_detail', 'bot_arb_count', 'bot_arb_fail_count', 'bot_last_arb')"
        );
        if (!$runtimeQuery) {
            error_log('Trading bot dashboard could not read runtime state: ' . mysqli_error($sqlConnect));
            http_response_code(500);
            echo json_encode(array('status' => 500, 'message' => 'Could not read the trading bot runtime state.'));
            exit();
        }
        while ($runtimeRow = mysqli_fetch_assoc($runtimeQuery)) {
            $runtimeConfig[$runtimeRow['name']] = $runtimeRow['value'];
        }

        $heartbeat = isset($runtimeConfig['bot_runtime_heartbeat']) ? intval($runtimeConfig['bot_runtime_heartbeat']) : 0;
        $heartbeatAge = time() - $heartbeat;
        $wallet = isset($wo['config']['bot_wallet_address']) ? trim($wo['config']['bot_wallet_address']) : '';
        $walletConfigured = preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet) === 1;
        $runtimeWallet = isset($runtimeConfig['bot_runtime_wallet_address']) ? trim($runtimeConfig['bot_runtime_wallet_address']) : '';
        $stats['bot_runtime_online'] = ($heartbeat > 0 && $heartbeatAge >= 0 && $heartbeatAge <= 90);
        $stats['bot_wallet_configured'] = $walletConfigured;
        $stats['bot_runtime_wallet_matches_config'] = (
            $walletConfigured
            && preg_match('/^0x[a-fA-F0-9]{40}$/', $runtimeWallet) === 1
            && strcasecmp($wallet, $runtimeWallet) === 0
        );
        // The private key lives in a root-only .env (mode 600) and must never be readable by
        // the web user. The bot records its runtime wallet only after deriving it from a valid
        // BOT_PRIVATE_KEY, so a recorded runtime wallet proves the key is configured.
        $stats['bot_private_key_configured'] = preg_match('/^0x[a-fA-F0-9]{40}$/', $runtimeWallet) === 1;
        $stats['bot_runtime_status'] = isset($runtimeConfig['bot_runtime_status']) ? $runtimeConfig['bot_runtime_status'] : '';
        $stats['bot_gas_balance'] = isset($runtimeConfig['bot_gas_balance']) ? $runtimeConfig['bot_gas_balance'] : '';
        // Arbitrage runtime state written by the bot every poll (read fresh, not from cached config)
        $stats['bot_arb_status'] = isset($runtimeConfig['bot_arb_status']) ? $runtimeConfig['bot_arb_status'] : '';
        $arbDetail = isset($runtimeConfig['bot_arb_detail']) ? json_decode($runtimeConfig['bot_arb_detail'], true) : null;
        $stats['bot_arb_detail'] = is_array($arbDetail) ? $arbDetail : new stdClass();
        foreach (array('bot_arb_count', 'bot_arb_fail_count', 'bot_last_arb') as $arbKey) {
            if (isset($runtimeConfig[$arbKey])) {
                $stats[$arbKey] = $runtimeConfig[$arbKey];
            }
        }
        $botDirectory = dirname(__DIR__) . '/nodejs/trading-bot';
        $stats['bot_dependencies_installed'] = (
            is_file($botDirectory . '/node_modules/ethers/package.json')
            && is_file($botDirectory . '/node_modules/dotenv/package.json')
            && is_file($botDirectory . '/node_modules/mysql2/package.json')
        );
        $stats['bot_trades_table_available'] = $tableExists;

        if ($tableExists) {
            // Get today's trade count, gas, and P&L (including gas cost in USD)
            $today = date('Y-m-d');
            $q = mysqli_query($sqlConnect,
                "SELECT COUNT(*) as cnt,
                        COALESCE(SUM(gas_cost_bnb),0) as total_gas,
                        COALESCE(SUM(pnl_usd),0) as spread_pnl,
                        COALESCE(SUM(gas_cost_bnb),0) as gas_bnb,
                        COALESCE(SUM(CASE WHEN strategy='grid' THEN 1 ELSE 0 END),0) as grid_cnt,
                        COALESCE(SUM(CASE WHEN strategy='arbitrage' THEN 1 ELSE 0 END),0) as arb_cnt
                 FROM Wo_Bot_Trades WHERE DATE(created_at) = '{$today}'");
            if ($q) {
                $row = mysqli_fetch_assoc($q);
                $stats['today_trades'] = $row['cnt'];
                $stats['today_gas_bnb'] = $row['total_gas'];
                $stats['today_grid'] = $row['grid_cnt'];
                $stats['today_arb'] = $row['arb_cnt'];
                // P&L including gas: pnl_usd already includes gas for new trades,
                // but for older trades recorded without gas, we also provide gas totals
                // so the frontend can show both
                $stats['today_pnl_usd'] = $row['spread_pnl'];
                $stats['today_gas_bnb_total'] = $row['gas_bnb'];
            }

            // Total all-time stats (P&L + gas breakdown)
            $q2 = mysqli_query($sqlConnect,
                "SELECT COUNT(*) as cnt,
                        COALESCE(SUM(gas_cost_bnb),0) as total_gas,
                        COALESCE(SUM(pnl_usd),0) as total_pnl
                 FROM Wo_Bot_Trades");
            if ($q2) {
                $row2 = mysqli_fetch_assoc($q2);
                $stats['all_trades'] = $row2['cnt'];
                $stats['all_gas_bnb'] = $row2['total_gas'];
                $stats['all_pnl_usd'] = $row2['total_pnl'];
            }
        } else {
            $stats['today_trades'] = '0';
            $stats['today_gas_bnb'] = '0';
            $stats['today_grid'] = '0';
            $stats['today_arb'] = '0';
            $stats['today_pnl_usd'] = '0';
            $stats['today_gas_bnb_total'] = '0';
            $stats['all_trades'] = '0';
            $stats['all_gas_bnb'] = '0';
            $stats['all_pnl_usd'] = '0';
        }

        echo json_encode(array('status' => 200, 'data' => $stats));
        exit();
    }

    if ($action === 'balances') {
        $wallet = isset($wo['config']['bot_wallet_address']) ? trim($wo['config']['bot_wallet_address']) : '';
        $rpcUrl = isset($wo['config']['bot_rpc_url']) ? $wo['config']['bot_rpc_url'] : 'https://bsc-dataseed1.binance.org';

        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet) !== 1) {
            echo json_encode(array('status' => 400, 'message' => 'A valid bot wallet address is not configured in Wo_Config.'));
            exit();
        }

        $tokens = array(
            'TRDC' => '0x39006641db2d9c3618523a1778974c0d7e98e39d',
            'USDT' => '0x55d398326f99059fF775485246999027B3197955',
            'WBNB' => '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c',
        );

        $balances = array();
        $rpcCall = function ($method, $params) use ($rpcUrl) {
            $payload = json_encode(array(
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params
            ));
            $ch = curl_init($rpcUrl);
            if ($ch === false) {
                throw new RuntimeException('Could not initialize the BSC RPC request.');
            }
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ));
            $response = curl_exec($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
                throw new RuntimeException('BSC RPC request failed (HTTP ' . intval($httpStatus) . ').');
            }
            $json = json_decode($response, true);
            if (!is_array($json) || isset($json['error']) || !array_key_exists('result', $json)) {
                throw new RuntimeException('BSC RPC provider returned an invalid response.');
            }
            return $json['result'];
        };

        try {
            $bnbHex = $rpcCall('eth_getBalance', array($wallet, 'latest'));
            if (!is_string($bnbHex) || !preg_match('/^0x[0-9a-fA-F]{1,64}$/', $bnbHex)) {
                throw new RuntimeException('BSC RPC provider returned an invalid BNB balance.');
            }
            $balances['BNB'] = number_format(hexdec(substr($bnbHex, 2)) / 1e18, 6);

            // balanceOf(address): 0x70a08231 followed by the padded wallet address.
            $paddedWallet = str_pad(strtolower(substr($wallet, 2)), 64, '0', STR_PAD_LEFT);
            $callData = '0x70a08231' . $paddedWallet;
            foreach ($tokens as $name => $tokenAddr) {
                $hex = $rpcCall('eth_call', array(array('to' => $tokenAddr, 'data' => $callData), 'latest'));
                if (!is_string($hex) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $hex)) {
                    throw new RuntimeException('BSC RPC provider returned an invalid ' . $name . ' balance.');
                }
                $hex = strtolower(substr($hex, 2));
                if (function_exists('bcdiv')) {
                    $decimal = '0';
                    $hex = ltrim($hex, '0') ?: '0';
                    for ($i = 0; $i < strlen($hex); $i++) {
                        $decimal = bcmul($decimal, '16');
                        $decimal = bcadd($decimal, strval(hexdec($hex[$i])));
                    }
                    $balances[$name] = rtrim(rtrim(bcdiv($decimal, bcpow('10', '18'), 4), '0'), '.');
                } else {
                    $balances[$name] = number_format(hexdec($hex) / 1e18, 4);
                }
            }
        } catch (RuntimeException $error) {
            error_log('Trading bot wallet balance lookup failed: ' . $error->getMessage());
            http_response_code(502);
            echo json_encode(array('status' => 502, 'message' => $error->getMessage()));
            exit();
        }

        echo json_encode(array('status' => 200, 'wallet' => $wallet, 'balances' => $balances));
        exit();
    }

    if ($action === 'trades') {
        if (!$tableExists) {
            echo json_encode(array('status' => 200, 'trades' => array(), 'total' => 0, 'page' => 1, 'pages' => 0));
            exit();
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

        $where = '';
        if ($filter === 'grid') $where = "WHERE strategy = 'grid'";
        elseif ($filter === 'arbitrage') $where = "WHERE strategy = 'arbitrage'";

        $trades = array();
        $q = mysqli_query($sqlConnect,
            "SELECT * FROM Wo_Bot_Trades {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $trades[] = $row;
            }
        }

        $total = 0;
        $q2 = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM Wo_Bot_Trades {$where}");
        if ($q2) {
            $total = mysqli_fetch_assoc($q2)['cnt'];
        }

        echo json_encode(array('status' => 200, 'trades' => $trades, 'total' => $total, 'page' => $page, 'pages' => ceil($total / max(1, $limit))));
        exit();
    }

    echo json_encode(array('status' => 400, 'message' => 'Invalid action'));
    exit();
}
