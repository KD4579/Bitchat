<?php
if ($f == 'trading_signal') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    if (empty($wo['config']['trading_signals_enabled']) || $wo['config']['trading_signals_enabled'] != '1') {
        $data['message'] = 'Trading signals are disabled';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $userId = intval($wo['user']['user_id']);

    // Handle signal status update
    if (!empty($_POST['action']) && $_POST['action'] == 'update_status') {
        $signalId = intval($_POST['signal_id'] ?? 0);
        $newStatus = Wo_Secure($_POST['new_status'] ?? '');

        $signal = $db->where('id', $signalId)->where('user_id', $userId)->getOne(T_TRADING_SIGNALS);
        if (empty($signal)) {
            $data['message'] = 'Signal not found';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $validStatuses = array('HIT_TARGET', 'HIT_STOPLOSS', 'CLOSED', 'EXPIRED');
        if (!in_array($newStatus, $validStatuses)) {
            $data['message'] = 'Invalid status';
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }

        $db->where('id', $signalId)->update(T_TRADING_SIGNALS, array(
            'status' => $newStatus,
            'closed_at' => time()
        ));

        $data = array('status' => 200, 'message' => 'Signal updated');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Create new signal
    $cryptoPair = Wo_Secure($_POST['crypto_pair'] ?? '');
    $signalType = strtoupper(Wo_Secure($_POST['signal_type'] ?? 'BUY'));
    $entryPrice = floatval($_POST['entry_price'] ?? 0);
    $targetPrice = floatval($_POST['target_price'] ?? 0);
    $stopLoss = floatval($_POST['stop_loss'] ?? 0);
    $timeframe = Wo_Secure($_POST['timeframe'] ?? '1h');
    $confidence = max(1, min(5, intval($_POST['confidence'] ?? 3)));
    $analysis = Wo_Secure($_POST['analysis'] ?? '');

    // Validate crypto pair against allowed list
    $allowedPairs = array_map('trim', explode(',', $wo['config']['trading_signals_pairs'] ?? ''));
    if (empty($cryptoPair) || !in_array($cryptoPair, $allowedPairs)) {
        $data['message'] = 'Invalid crypto pair';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Validate signal type
    $validTypes = array('BUY', 'SELL', 'HOLD', 'LONG', 'SHORT');
    if (!in_array($signalType, $validTypes)) {
        $data['message'] = 'Invalid signal type';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Validate timeframe
    $validTimeframes = array('15m', '1h', '4h', '1d', '1w');
    if (!in_array($timeframe, $validTimeframes)) {
        $timeframe = '1h';
    }

    if ($entryPrice <= 0) {
        $data['message'] = 'Entry price is required';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Rate limit: max 10 signals per day
    $dayAgo = time() - 86400;
    $signalCount = $db->where('user_id', $userId)->where('created_at', $dayAgo, '>')->getValue(T_TRADING_SIGNALS, 'COUNT(*)');
    if ($signalCount >= 10) {
        $data['message'] = 'Signal limit reached (10 per day)';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Build post text
    $emoji = ($signalType == 'BUY' || $signalType == 'LONG') ? '🟢' : (($signalType == 'SELL' || $signalType == 'SHORT') ? '🔴' : '🟡');
    $postText = $emoji . ' ' . $signalType . ' ' . $cryptoPair . ' @ ' . $entryPrice;
    if ($targetPrice > 0) {
        $postText .= ' | Target: ' . $targetPrice;
    }
    if ($stopLoss > 0) {
        $postText .= ' | SL: ' . $stopLoss;
    }
    $postText .= ' | ' . $timeframe;
    if (!empty($analysis)) {
        $postText .= "\n" . $analysis;
    }

    // Create the post
    $postData = array(
        'user_id' => $userId,
        'postText' => $postText,
        'postType' => 'trading_signal',
        'postPrivacy' => '0',
        'time' => time()
    );

    $postId = Wo_RegisterPost($postData);

    if ($postId) {
        // Insert signal record
        $db->insert(T_TRADING_SIGNALS, array(
            'post_id' => intval($postId),
            'user_id' => $userId,
            'crypto_pair' => $cryptoPair,
            'signal_type' => $signalType,
            'entry_price' => $entryPrice,
            'target_price' => $targetPrice,
            'stop_loss' => $stopLoss,
            'timeframe' => $timeframe,
            'confidence' => $confidence,
            'status' => 'ACTIVE',
            'created_at' => time()
        ));

        cache($userId, 'posts', 'delete');

        $data = array(
            'status' => 200,
            'message' => 'Signal posted!',
            'post_id' => $postId
        );
    } else {
        $data['message'] = 'Failed to create post';
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
