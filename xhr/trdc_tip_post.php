<?php
// TRDC Post Tip — send TRDC tokens to a post's creator
if ($f == 'trdc_tip_post') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Check if tipping is enabled
    if (empty($wo['config']['trdc_tip_enabled']) || $wo['config']['trdc_tip_enabled'] != '1') {
        $data['message'] = 'Tipping is currently disabled';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $tipAmount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

    if ($postId <= 0 || $tipAmount <= 0) {
        $data['message'] = 'Invalid tip amount';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Validate amount against allowed presets (or custom if enabled)
    $allowedAmounts = array_map('floatval', explode(',', $wo['config']['trdc_tip_amounts'] ?? '1,5,10,25,50'));
    $customEnabled = !empty($wo['config']['trdc_tip_custom_enabled']) && $wo['config']['trdc_tip_custom_enabled'] == '1';

    if (!$customEnabled && !in_array($tipAmount, $allowedAmounts)) {
        $data['message'] = 'Invalid tip amount';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Cap custom tips at 1000 TRDC
    if ($tipAmount > 1000) {
        $data['message'] = 'Maximum tip is 1000 TRDC';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Get post data
    $post = Wo_PostData($postId);
    if (empty($post)) {
        $data['message'] = 'Post not found';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Can't tip your own post
    if ($post['user_id'] == $wo['user']['user_id']) {
        $data['message'] = "You can't tip your own post";
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Check wallet balance
    $userData = Wo_UserData($wo['user']['user_id']);
    if (floatval($userData['wallet']) < $tipAmount) {
        $data['message'] = 'Insufficient TRDC balance';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Rate limit: max 20 tips per hour per user
    $tipCountQuery = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_PAYMENT_TRANSACTIONS . " WHERE userid = " . intval($wo['user']['user_id']) . " AND kind = 'TIP_SENT' AND transaction_dt > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if ($tipCountQuery) {
        $tipCountRow = mysqli_fetch_assoc($tipCountQuery);
        if ($tipCountRow['cnt'] >= 20) {
            $data['message'] = 'Tip limit reached. Try again later.';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
    }

    // Execute tip: deduct from sender, add to recipient
    $senderId = intval($wo['user']['user_id']);
    $recipientId = intval($post['user_id']);
    $safeTipAmount = floatval($tipAmount);

    mysqli_begin_transaction($sqlConnect);
    try {
        // Deduct from sender
        $q1 = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet - {$safeTipAmount} WHERE user_id = {$senderId} AND wallet >= {$safeTipAmount}");
        if (!$q1 || mysqli_affected_rows($sqlConnect) == 0) {
            throw new Exception('Insufficient balance');
        }

        // Add to recipient
        $q2 = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet + {$safeTipAmount} WHERE user_id = {$recipientId}");
        if (!$q2) {
            throw new Exception('Transfer failed');
        }

        // Log transactions (transaction_dt auto-timestamps)
        $senderNote = Wo_Secure("Tipped post #{$postId}", 0);
        $recipientNote = Wo_Secure("Tip received on post #{$postId}", 0);
        mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (userid, kind, amount, notes) VALUES ({$senderId}, 'TIP_SENT', {$safeTipAmount}, '{$senderNote}')");
        mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (userid, kind, amount, notes) VALUES ({$recipientId}, 'TIP_RECEIVED', {$safeTipAmount}, '{$recipientNote}')");


        mysqli_commit($sqlConnect);
    } catch (Exception $e) {
        mysqli_rollback($sqlConnect);
        $data['message'] = $e->getMessage();
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Clear user caches
    cache($senderId, 'users', 'delete');
    cache($recipientId, 'users', 'delete');

    // Send notification to recipient
    $notification_data_array = array(
        'recipient_id' => $recipientId,
        'type' => 'liked', // reuse liked type for notification
        'post_id' => $postId,
        'text' => 'tipped you ' . $safeTipAmount . ' TRDC on',
        'url' => 'index.php?link1=post&id=' . $postId
    );
    Wo_RegisterNotification($notification_data_array);

    $newBalance = floatval($userData['wallet']) - $safeTipAmount;
    $data = array(
        'status' => 200,
        'message' => 'Tipped ' . $safeTipAmount . ' TRDC!',
        'new_balance' => $newBalance
    );

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
