<?php
// TRDC On-Chain Withdrawal — request, history, cancel
if ($f == 'trdc_withdrawal') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Check if withdrawal is enabled
    if (empty($wo['config']['trdc_withdrawal_enabled']) || $wo['config']['trdc_withdrawal_enabled'] != '1') {
        $data['message'] = 'TRDC withdrawal is currently disabled';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';
    $userId = intval($wo['user']['user_id']);

    // ---- REQUEST WITHDRAWAL ----
    if ($action === 'request') {
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

        // Validate user has a verified Web3 wallet
        $walletQ = mysqli_query($sqlConnect, "SELECT wallet_address, wallet_verified, wallet FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1");
        if (!$walletQ || !($walletRow = mysqli_fetch_assoc($walletQ))) {
            $data['message'] = 'Could not verify account';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        if (empty($walletRow['wallet_address']) || empty($walletRow['wallet_verified'])) {
            $data['message'] = 'Please connect and verify your Web3 wallet first (login with wallet)';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $walletAddress = $walletRow['wallet_address'];
        $currentBalance = floatval($walletRow['wallet']);

        // Validate amount
        $minAmount = floatval($wo['config']['trdc_withdrawal_min'] ?? 100);
        $maxAmount = floatval($wo['config']['trdc_withdrawal_max'] ?? 50000);

        if ($amount < $minAmount) {
            $data['message'] = 'Minimum withdrawal is ' . number_format($minAmount, 0) . ' TRDC';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        if ($amount > $maxAmount) {
            $data['message'] = 'Maximum withdrawal is ' . number_format($maxAmount, 0) . ' TRDC';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check balance
        if ($currentBalance < $amount) {
            $data['message'] = 'Insufficient TRDC balance. You have ' . number_format($currentBalance, 4) . ' TRDC';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Rate limit: max 3 withdrawal requests per hour
        $rateLimitQ = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_TRDC_WITHDRAWALS . " WHERE user_id = {$userId} AND created_at > " . (time() - 3600));
        if ($rateLimitQ) {
            $rateLimitRow = mysqli_fetch_assoc($rateLimitQ);
            if ($rateLimitRow['cnt'] >= 3) {
                $data['message'] = 'Too many withdrawal requests. Try again later.';
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
        }

        // Check cooldown between withdrawals
        $cooldownHours = intval($wo['config']['trdc_withdrawal_cooldown_hours'] ?? 24);
        $cooldownQ = mysqli_query($sqlConnect, "SELECT id FROM " . T_TRDC_WITHDRAWALS . " WHERE user_id = {$userId} AND status IN ('completed','processing') AND created_at > " . (time() - ($cooldownHours * 3600)) . " LIMIT 1");
        if ($cooldownQ && mysqli_num_rows($cooldownQ) > 0) {
            $data['message'] = 'Please wait ' . $cooldownHours . ' hours between withdrawals';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Check max pending withdrawals
        $maxPending = intval($wo['config']['trdc_withdrawal_max_pending'] ?? 1);
        $pendingQ = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_TRDC_WITHDRAWALS . " WHERE user_id = {$userId} AND status IN ('pending','processing')");
        if ($pendingQ) {
            $pendingRow = mysqli_fetch_assoc($pendingQ);
            if ($pendingRow['cnt'] >= $maxPending) {
                $data['message'] = 'You already have a pending withdrawal. Please wait for it to complete.';
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
        }

        // Check global daily limit
        $dailyLimit = floatval($wo['config']['trdc_withdrawal_daily_limit'] ?? 100000);
        $todayStart = strtotime('today midnight');
        $dailyQ = mysqli_query($sqlConnect, "SELECT COALESCE(SUM(amount), 0) as total FROM " . T_TRDC_WITHDRAWALS . " WHERE status != 'cancelled' AND created_at >= {$todayStart}");
        if ($dailyQ) {
            $dailyRow = mysqli_fetch_assoc($dailyQ);
            if (floatval($dailyRow['total']) + $amount > $dailyLimit) {
                $data['message'] = 'Daily withdrawal limit reached. Please try again tomorrow.';
                header("Content-type: application/json");
                echo json_encode($data);
                exit();
            }
        }

        // Calculate fee
        $feePercent = floatval($wo['config']['trdc_withdrawal_fee_percent'] ?? 2);
        $fee = round($amount * $feePercent / 100, 4);
        $netAmount = round($amount - $fee, 4);

        if ($netAmount <= 0) {
            $data['message'] = 'Amount too small after fee deduction';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Execute: deduct balance and create withdrawal record
        $safeAmount = floatval($amount);
        $safeFee = floatval($fee);
        $safeNet = floatval($netAmount);
        $safeWalletAddress = Wo_Secure($walletAddress);
        $now = time();

        mysqli_begin_transaction($sqlConnect);
        try {
            // Atomic deduct
            $q1 = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet - {$safeAmount} WHERE user_id = {$userId} AND wallet >= {$safeAmount}");
            if (!$q1 || mysqli_affected_rows($sqlConnect) == 0) {
                throw new Exception('Insufficient balance');
            }

            // Insert withdrawal record
            $q2 = mysqli_query($sqlConnect, "INSERT INTO " . T_TRDC_WITHDRAWALS . " (user_id, amount, fee, net_amount, wallet_address, status, created_at) VALUES ({$userId}, {$safeAmount}, {$safeFee}, {$safeNet}, '{$safeWalletAddress}', 'pending', {$now})");
            if (!$q2) {
                throw new Exception('Could not create withdrawal request');
            }

            $withdrawalId = mysqli_insert_id($sqlConnect);

            // Log transaction
            $note = Wo_Secure("Withdrawal #{$withdrawalId} to {$walletAddress}", 0);
            mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (userid, kind, amount, notes) VALUES ({$userId}, 'WITHDRAWAL_REQUESTED', {$safeAmount}, '{$note}')");

            mysqli_commit($sqlConnect);
        } catch (Exception $e) {
            mysqli_rollback($sqlConnect);
            $data['message'] = $e->getMessage();
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        cache($userId, 'users', 'delete');

        $newBalance = $currentBalance - $safeAmount;
        $data = array(
            'status' => 200,
            'message' => 'Withdrawal request submitted! ' . number_format($safeNet, 4) . ' TRDC will be sent to your wallet.',
            'withdrawal_id' => $withdrawalId,
            'new_balance' => $newBalance,
            'fee' => $safeFee,
            'net_amount' => $safeNet
        );

        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- WITHDRAWAL HISTORY ----
    if ($action === 'history') {
        $withdrawals = array();
        $q = mysqli_query($sqlConnect, "SELECT id, amount, fee, net_amount, wallet_address, status, tx_hash, failure_reason, created_at, completed_at FROM " . T_TRDC_WITHDRAWALS . " WHERE user_id = {$userId} ORDER BY created_at DESC LIMIT 20");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $row['amount'] = floatval($row['amount']);
                $row['fee'] = floatval($row['fee']);
                $row['net_amount'] = floatval($row['net_amount']);
                $row['created_at_formatted'] = date('M j, Y g:i A', intval($row['created_at']));
                $row['completed_at_formatted'] = !empty($row['completed_at']) ? date('M j, Y g:i A', intval($row['completed_at'])) : null;
                $withdrawals[] = $row;
            }
        }

        $data = array('status' => 200, 'withdrawals' => $withdrawals);
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- CANCEL PENDING WITHDRAWAL ----
    if ($action === 'cancel') {
        $withdrawalId = isset($_POST['withdrawal_id']) ? intval($_POST['withdrawal_id']) : 0;

        if ($withdrawalId <= 0) {
            $data['message'] = 'Invalid withdrawal ID';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        // Get withdrawal — must be pending and owned by user
        $wQ = mysqli_query($sqlConnect, "SELECT id, amount, status FROM " . T_TRDC_WITHDRAWALS . " WHERE id = {$withdrawalId} AND user_id = {$userId} LIMIT 1");
        if (!$wQ || !($wRow = mysqli_fetch_assoc($wQ))) {
            $data['message'] = 'Withdrawal not found';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        if ($wRow['status'] !== 'pending') {
            $data['message'] = 'Only pending withdrawals can be cancelled';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $refundAmount = floatval($wRow['amount']);

        mysqli_begin_transaction($sqlConnect);
        try {
            // Refund full amount (including fee)
            $q1 = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet + {$refundAmount} WHERE user_id = {$userId}");
            if (!$q1) {
                throw new Exception('Refund failed');
            }

            // Update withdrawal status
            $q2 = mysqli_query($sqlConnect, "UPDATE " . T_TRDC_WITHDRAWALS . " SET status = 'cancelled' WHERE id = {$withdrawalId}");
            if (!$q2) {
                throw new Exception('Could not cancel withdrawal');
            }

            // Log refund
            $note = Wo_Secure("Withdrawal #{$withdrawalId} cancelled — refunded", 0);
            mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (userid, kind, amount, notes) VALUES ({$userId}, 'WITHDRAWAL_CANCELLED', {$refundAmount}, '{$note}')");

            mysqli_commit($sqlConnect);
        } catch (Exception $e) {
            mysqli_rollback($sqlConnect);
            $data['message'] = $e->getMessage();
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        cache($userId, 'users', 'delete');

        $data = array(
            'status' => 200,
            'message' => 'Withdrawal cancelled. ' . number_format($refundAmount, 4) . ' TRDC refunded to your balance.',
            'refunded' => $refundAmount
        );

        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // ---- GET CONFIG (for frontend) ----
    if ($action === 'config') {
        // Check if user has verified wallet
        $walletQ = mysqli_query($sqlConnect, "SELECT wallet_address, wallet_verified FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1");
        $walletRow = $walletQ ? mysqli_fetch_assoc($walletQ) : null;

        $data = array(
            'status' => 200,
            'min' => floatval($wo['config']['trdc_withdrawal_min'] ?? 100),
            'max' => floatval($wo['config']['trdc_withdrawal_max'] ?? 50000),
            'fee_percent' => floatval($wo['config']['trdc_withdrawal_fee_percent'] ?? 2),
            'cooldown_hours' => intval($wo['config']['trdc_withdrawal_cooldown_hours'] ?? 24),
            'wallet_verified' => !empty($walletRow['wallet_verified']) ? 1 : 0,
            'wallet_address' => !empty($walletRow['wallet_address']) ? $walletRow['wallet_address'] : null
        );

        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data['message'] = 'Invalid action';
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
