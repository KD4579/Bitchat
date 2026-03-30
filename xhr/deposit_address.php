<?php
// BSC Deposit Address handler
// Actions: get_address, balances, history
if ($f == 'deposit_address') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    if (empty($wo['config']['deposit_enabled']) || $wo['config']['deposit_enabled'] != '1') {
        $data['message'] = 'Deposits are currently disabled';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';
    $userId = intval($wo['user']['user_id']);

    // ---- GET OR CREATE DEPOSIT ADDRESS ----
    if ($action === 'get_address') {
        // Check if user already has an address in the DB
        $q = mysqli_query($sqlConnect, "SELECT address FROM " . T_DEPOSIT_ADDRESSES . " WHERE user_id = {$userId} LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            $data = array('status' => 200, 'address' => $row['address']);
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // No address yet — call the Node.js CLI to derive and store one
        $scriptPath = escapeshellarg(dirname(__FILE__) . '/../nodejs/deposit-monitor/derive-address.js');
        $safeUserId = escapeshellarg((string)$userId);

        // Try to find node binary
        $nodeBin = trim(shell_exec('which node 2>/dev/null') ?: '');
        if (empty($nodeBin)) {
            // Common paths on Linux servers
            foreach (array('/usr/bin/node', '/usr/local/bin/node', '/opt/nvm/versions/node/current/bin/node') as $path) {
                if (file_exists($path)) {
                    $nodeBin = $path;
                    break;
                }
            }
        }

        if (empty($nodeBin)) {
            $data['message'] = 'Address generation service unavailable. Please try again later.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $cmd = escapeshellcmd($nodeBin) . ' ' . $scriptPath . ' ' . $safeUserId . ' 2>&1';
        $output = shell_exec($cmd);

        if (empty($output)) {
            $data['message'] = 'Failed to generate deposit address. Please try again.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $result = json_decode($output, true);

        if (empty($result) || !empty($result['error'])) {
            $data['message'] = 'Failed to generate deposit address: ' . ($result['error'] ?? 'Unknown error');
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        if (!empty($result['address'])) {
            $data = array('status' => 200, 'address' => $result['address']);
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $data['message'] = 'Unexpected error generating address. Please try again.';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- BALANCES (BNB, USDT, TRDC) from deposit address ----
    if ($action === 'balances') {
        $q = mysqli_query($sqlConnect, "SELECT balance_bnb, balance_usdt FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1");
        $row = $q ? mysqli_fetch_assoc($q) : null;

        $data = array(
            'status'   => 200,
            'balances' => array(
                'bnb'  => $row ? number_format(floatval($row['balance_bnb']), 8) : '0',
                'usdt' => $row ? number_format(floatval($row['balance_usdt']), 4) : '0',
                'trdc' => number_format(floatval($wo['setting']['balance'] ?? 0), 4),
            ),
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- DEPOSIT HISTORY ----
    if ($action === 'history') {
        $page   = max(1, intval($_POST['page'] ?? 1));
        $offset = ($page - 1) * 20;
        $deposits = array();

        $q = mysqli_query($sqlConnect, "SELECT token, amount, tx_hash, status, confirmations, created_at FROM " . T_DEPOSITS . " WHERE user_id = {$userId} ORDER BY created_at DESC LIMIT 20 OFFSET {$offset}");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $row['amount'] = floatval($row['amount']);
                $row['confirmations'] = intval($row['confirmations']);
                $row['created_at_formatted'] = date('M j, Y g:i A', intval($row['created_at']));
                $deposits[] = $row;
            }
        }

        $data = array('status' => 200, 'deposits' => $deposits);
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data['message'] = 'Invalid action';
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
