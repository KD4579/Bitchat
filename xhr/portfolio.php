<?php
if ($f == 'portfolio') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    if (empty($wo['config']['portfolio_tracker_enabled']) || $wo['config']['portfolio_tracker_enabled'] != '1') {
        $data['message'] = 'Portfolio tracker is disabled';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $userId = intval($wo['user']['user_id']);
    $action = Wo_Secure($_POST['action'] ?? 'list');

    // List user's portfolio holdings
    if ($action == 'list') {
        $holdings = $db->where('user_id', $userId)->orderBy('created_at', 'ASC')->get(T_USER_PORTFOLIO);
        $result = array();
        if (!empty($holdings)) {
            foreach ($holdings as $h) {
                $result[] = array(
                    'id' => $h['id'],
                    'coin_id' => $h['coin_id'],
                    'coin_symbol' => strtoupper($h['coin_symbol']),
                    'coin_name' => $h['coin_name'],
                    'quantity' => floatval($h['quantity']),
                    'avg_buy_price' => floatval($h['avg_buy_price'])
                );
            }
        }
        $data = array('status' => 200, 'holdings' => $result);
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Add or update a holding
    if ($action == 'add') {
        $coinId = Wo_Secure($_POST['coin_id'] ?? '');
        $coinSymbol = strtoupper(Wo_Secure($_POST['coin_symbol'] ?? ''));
        $coinName = Wo_Secure($_POST['coin_name'] ?? '');
        $quantity = floatval($_POST['quantity'] ?? 0);
        $avgPrice = floatval($_POST['avg_buy_price'] ?? 0);

        if (empty($coinId) || empty($coinSymbol) || $quantity <= 0) {
            $data['message'] = 'Coin and quantity are required';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Max 20 holdings per user
        $count = $db->where('user_id', $userId)->getValue(T_USER_PORTFOLIO, 'COUNT(*)');
        $existing = $db->where('user_id', $userId)->where('coin_id', $coinId)->getOne(T_USER_PORTFOLIO);

        if (!empty($existing)) {
            // Update existing
            $db->where('id', $existing['id'])->update(T_USER_PORTFOLIO, array(
                'quantity' => $quantity,
                'avg_buy_price' => $avgPrice,
                'updated_at' => time()
            ));
            $data = array('status' => 200, 'message' => 'Holding updated');
        } elseif ($count >= 20) {
            $data['message'] = 'Maximum 20 holdings allowed';
        } else {
            $db->insert(T_USER_PORTFOLIO, array(
                'user_id' => $userId,
                'coin_id' => $coinId,
                'coin_symbol' => $coinSymbol,
                'coin_name' => $coinName,
                'quantity' => $quantity,
                'avg_buy_price' => $avgPrice,
                'created_at' => time(),
                'updated_at' => time()
            ));
            $data = array('status' => 200, 'message' => 'Holding added');
        }

        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Remove a holding
    if ($action == 'remove') {
        $holdingId = intval($_POST['holding_id'] ?? 0);
        $db->where('id', $holdingId)->where('user_id', $userId)->delete(T_USER_PORTFOLIO);
        $data = array('status' => 200, 'message' => 'Holding removed');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
