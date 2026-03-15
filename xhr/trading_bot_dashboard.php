<?php
if ($f == 'trading_bot_dashboard') {
    header("Content-type: application/json");

    if (!Wo_IsAdmin()) {
        echo json_encode(array('status' => 403));
        exit();
    }

    $action = isset($_GET['action']) ? $_GET['action'] : 'trades';

    // Check if Wo_Bot_Trades table exists
    $tableCheck = mysqli_query($sqlConnect, "SHOW TABLES LIKE 'Wo_Bot_Trades'");
    $tableExists = ($tableCheck && mysqli_num_rows($tableCheck) > 0);

    if ($action === 'status') {
        // Get bot status stats from Wo_Config
        $stats = array();
        $keys = array('bot_enabled','bot_mode','bot_daily_pnl','bot_last_cycle','bot_next_cycle',
                       'bot_cycle_count','bot_next_direction','bot_cooldown_seconds','bot_spread_percent',
                       'bot_order_size_trdc');
        foreach ($keys as $k) {
            $stats[$k] = isset($wo['config'][$k]) ? $wo['config'][$k] : '';
        }

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
