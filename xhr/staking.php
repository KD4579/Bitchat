<?php
if ($f == 'staking') {
    if (!isset($wo['user']['user_id']) || $wo['loggedin'] == false) {
        echo json_encode(array('status' => 400, 'message' => 'Not logged in'));
        exit();
    }

    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';

    // ---- CREATE OFFCHAIN STAKE ----
    if ($action === 'create_offchain') {
        $amount   = floatval($_POST['amount'] ?? 0);
        $lockDays = intval($_POST['lock_days'] ?? 0);
        $userId   = intval($wo['user']['user_id']);

        // Validate lock period and APY
        $validPlans = array(
            30  => 5.00,
            90  => 8.00,
            180 => 12.00,
            365 => 18.00,
        );

        if (!isset($validPlans[$lockDays])) {
            echo json_encode(array('status' => 400, 'message' => 'Invalid staking period'));
            exit();
        }

        if ($amount < 100) {
            echo json_encode(array('status' => 400, 'message' => 'Minimum stake is 100 TRDC'));
            exit();
        }

        // Check balance
        $balQ = mysqli_query($sqlConnect, "SELECT wallet FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1");
        if (!$balQ || !($balRow = mysqli_fetch_assoc($balQ))) {
            echo json_encode(array('status' => 400, 'message' => 'Could not verify balance'));
            exit();
        }

        $currentBalance = floatval($balRow['wallet']);
        if ($currentBalance < $amount) {
            echo json_encode(array('status' => 400, 'message' => 'Insufficient TRDC balance. You have ' . number_format($currentBalance, 4) . ' TRDC'));
            exit();
        }

        $apyRate   = $validPlans[$lockDays];
        $now       = time();
        $unlockAt  = $now + ($lockDays * 86400);

        // Deduct from wallet and create stake record
        mysqli_begin_transaction($sqlConnect);
        try {
            // Deduct balance
            $deductQ = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet - {$amount} WHERE user_id = {$userId} AND wallet >= {$amount}");
            if (!$deductQ || mysqli_affected_rows($sqlConnect) == 0) {
                throw new Exception('Balance deduction failed');
            }

            // Insert stake record
            $insertQ = mysqli_query($sqlConnect, "INSERT INTO Wo_Staking (user_id, stake_type, amount, apy_rate, lock_days, status, started_at, unlock_at, created_at)
                VALUES ({$userId}, 'offchain', {$amount}, {$apyRate}, {$lockDays}, 'active', {$now}, {$unlockAt}, {$now})");
            if (!$insertQ) {
                throw new Exception('Stake record creation failed');
            }

            $stakeId = mysqli_insert_id($sqlConnect);
            mysqli_commit($sqlConnect);

            // Clear user cache
            cache($userId, 'users', 'delete');

            echo json_encode(array(
                'status'   => 200,
                'message'  => 'Successfully staked ' . number_format($amount, 2) . ' TRDC for ' . $lockDays . ' days at ' . $apyRate . '% APY',
                'stake_id' => $stakeId,
            ));
        } catch (Exception $e) {
            mysqli_rollback($sqlConnect);
            echo json_encode(array('status' => 400, 'message' => $e->getMessage()));
        }
        exit();
    }

    // ---- GET STAKES ----
    if ($action === 'get_stakes') {
        $userId = intval($wo['user']['user_id']);
        $stakes = array();
        $q = mysqli_query($sqlConnect, "SELECT * FROM Wo_Staking WHERE user_id = {$userId} ORDER BY created_at DESC LIMIT 50");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $row['amount']        = floatval($row['amount']);
                $row['apy_rate']      = floatval($row['apy_rate']);
                $row['earned_reward'] = floatval($row['earned_reward']);
                $stakes[] = $row;
            }
        }
        echo json_encode(array('status' => 200, 'stakes' => $stakes));
        exit();
    }

    echo json_encode(array('status' => 400, 'message' => 'Invalid action'));
    exit();
}
?>
