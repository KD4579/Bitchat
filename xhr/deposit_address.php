<?php
// BSC Deposit Address — get_address, history, balances
if ($f == 'deposit_address') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Check if deposit system is enabled
    if (empty($wo['config']['deposit_enabled']) || $wo['config']['deposit_enabled'] != '1') {
        $data['message'] = 'Deposit system is currently disabled';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';
    $userId = intval($wo['user']['user_id']);

    // ---- GET OR CREATE DEPOSIT ADDRESS ----
    if ($action === 'get_address') {
        // Check if user already has a deposit address in DB
        $addrQ = mysqli_query($sqlConnect,
            "SELECT address, derivation_index, created_at FROM " . T_DEPOSIT_ADDRESSES . " WHERE user_id = {$userId} LIMIT 1"
        );

        if ($addrQ && ($addrRow = mysqli_fetch_assoc($addrQ))) {
            $data = array(
                'status'  => 200,
                'address' => $addrRow['address'],
                'message' => 'Deposit address retrieved'
            );
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // No address yet — call Node.js derive-address.js
        $nodePath = realpath(dirname(__FILE__) . '/../nodejs/deposit-monitor');
        $cmd = "cd " . escapeshellarg($nodePath) . " && node derive-address.js " . escapeshellarg($userId) . " 2>&1";
        $output = shell_exec($cmd);

        if (!$output) {
            $data['message'] = 'Failed to generate deposit address. Please try again later.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $result = json_decode(trim($output), true);

        if (!$result || isset($result['error'])) {
            $data['message'] = 'Address generation error: ' . ($result['error'] ?? 'Unknown error');
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $data = array(
            'status'  => 200,
            'address' => $result['address'],
            'message' => $result['created'] ? 'New deposit address generated' : 'Deposit address retrieved'
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- DEPOSIT HISTORY ----
    if ($action === 'history') {
        $page  = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $deposits = array();
        $q = mysqli_query($sqlConnect,
            "SELECT id, token, amount, tx_hash, block_number, confirmations, status, credited_at, created_at
             FROM " . T_DEPOSITS . "
             WHERE user_id = {$userId}
             ORDER BY created_at DESC
             LIMIT {$limit} OFFSET {$offset}"
        );

        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $row['amount'] = rtrim(rtrim($row['amount'], '0'), '.');
                $deposits[] = $row;
            }
        }

        // Get total count
        $countQ = mysqli_query($sqlConnect,
            "SELECT COUNT(*) AS total FROM " . T_DEPOSITS . " WHERE user_id = {$userId}"
        );
        $total = ($countQ && ($cr = mysqli_fetch_assoc($countQ))) ? intval($cr['total']) : 0;

        $data = array(
            'status'   => 200,
            'deposits' => $deposits,
            'total'    => $total,
            'page'     => $page,
            'pages'    => ceil($total / $limit)
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- USER BALANCES ----
    if ($action === 'balances') {
        $q = mysqli_query($sqlConnect,
            "SELECT wallet, balance_bnb, balance_usdt FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1"
        );

        if ($q && ($row = mysqli_fetch_assoc($q))) {
            $data = array(
                'status'   => 200,
                'balances' => array(
                    'trdc' => rtrim(rtrim(number_format(floatval($row['wallet']), 4, '.', ''), '0'), '.'),
                    'bnb'  => rtrim(rtrim(number_format(floatval($row['balance_bnb']), 8, '.', ''), '0'), '.'),
                    'usdt' => rtrim(rtrim(number_format(floatval($row['balance_usdt']), 4, '.', ''), '0'), '.'),
                )
            );
        } else {
            $data['message'] = 'Could not fetch balances';
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- CONFIG (public deposit info) ----
    if ($action === 'config') {
        $requiredConf = !empty($wo['config']['deposit_confirmations']) ? intval($wo['config']['deposit_confirmations']) : 15;
        $data = array(
            'status' => 200,
            'config' => array(
                'confirmations' => $requiredConf,
                'min_bnb'  => floatval($wo['config']['deposit_min_bnb'] ?? 0.001),
                'min_usdt' => floatval($wo['config']['deposit_min_usdt'] ?? 1),
                'min_trdc' => floatval($wo['config']['deposit_min_trdc'] ?? 10),
            )
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Unknown action
    $data['message'] = 'Invalid action';
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
