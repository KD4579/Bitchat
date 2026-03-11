<?php
if ($f == 'trading_bot_dashboard') {
    if (!Wo_IsAdmin()) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 403));
        exit();
    }

    $action = isset($_GET['action']) ? $_GET['action'] : 'trades';

    if ($action === 'status') {
        // Get bot status stats from Wo_Config
        $stats = array();
        $keys = array('bot_enabled','bot_mode','bot_daily_pnl','bot_last_cycle','bot_next_cycle',
                       'bot_cycle_count','bot_next_direction','bot_cooldown_seconds','bot_spread_percent',
                       'bot_order_size_trdc');
        foreach ($keys as $k) {
            $stats[$k] = isset($wo['config'][$k]) ? $wo['config'][$k] : '';
        }

        // Get today's trade count and total gas
        $today = date('Y-m-d');
        $q = mysqli_query($sqlConnect,
            "SELECT COUNT(*) as cnt, COALESCE(SUM(gas_cost_bnb),0) as total_gas,
                    COALESCE(SUM(CASE WHEN strategy='grid' THEN 1 ELSE 0 END),0) as grid_cnt,
                    COALESCE(SUM(CASE WHEN strategy='arbitrage' THEN 1 ELSE 0 END),0) as arb_cnt
             FROM Wo_Bot_Trades WHERE DATE(created_at) = '{$today}'");
        $row = mysqli_fetch_assoc($q);
        $stats['today_trades'] = $row['cnt'];
        $stats['today_gas_bnb'] = $row['total_gas'];
        $stats['today_grid'] = $row['grid_cnt'];
        $stats['today_arb'] = $row['arb_cnt'];

        // Total all-time stats
        $q2 = mysqli_query($sqlConnect,
            "SELECT COUNT(*) as cnt, COALESCE(SUM(gas_cost_bnb),0) as total_gas,
                    COALESCE(SUM(pnl_usd),0) as total_pnl
             FROM Wo_Bot_Trades");
        $row2 = mysqli_fetch_assoc($q2);
        $stats['all_trades'] = $row2['cnt'];
        $stats['all_gas_bnb'] = $row2['total_gas'];
        $stats['all_pnl_usd'] = $row2['total_pnl'];

        header("Content-type: application/json");
        echo json_encode(array('status' => 200, 'data' => $stats));
        exit();
    }

    if ($action === 'trades') {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

        $where = '';
        if ($filter === 'grid') $where = "WHERE strategy = 'grid'";
        elseif ($filter === 'arbitrage') $where = "WHERE strategy = 'arbitrage'";

        $q = mysqli_query($sqlConnect,
            "SELECT * FROM Wo_Bot_Trades {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $trades = array();
        while ($row = mysqli_fetch_assoc($q)) {
            $trades[] = $row;
        }

        $q2 = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM Wo_Bot_Trades {$where}");
        $total = mysqli_fetch_assoc($q2)['cnt'];

        header("Content-type: application/json");
        echo json_encode(array('status' => 200, 'trades' => $trades, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)));
        exit();
    }
}
