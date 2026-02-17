<?php
// TRDC Post Boost — spend TRDC to temporarily boost a post's feed ranking
if ($f == 'trdc_boost_post') {
    $data = array('status' => 400, 'message' => 'Error');

    if (!$wo['loggedin'] || !Wo_CheckMainSession($hash_id)) {
        $data['message'] = 'Not authorized';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $boostCost = 5.0; // TRDC cost per boost (24 hours)
    $boostDuration = 86400; // 24 hours

    if ($postId <= 0) {
        $data['message'] = 'Invalid post';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Verify post ownership
    $post = Wo_PostData($postId);
    if (empty($post) || $post['user_id'] != $wo['user']['user_id']) {
        $data['message'] = 'You can only boost your own posts';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Check if already boosted
    if (!empty($post['trdc_boosted']) && $post['trdc_boost_expires'] > time()) {
        $data['message'] = 'Post is already boosted';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Check wallet balance
    $userData = Wo_UserData($wo['user']['user_id']);
    if (floatval($userData['wallet']) < $boostCost) {
        $data['message'] = 'Insufficient TRDC balance. Need ' . $boostCost . ' TRDC.';
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Deduct TRDC and boost post
    $userId = intval($wo['user']['user_id']);
    $expires = time() + $boostDuration;

    mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet - {$boostCost} WHERE user_id = {$userId}");
    mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET trdc_boosted = 1, trdc_boost_expires = {$expires} WHERE id = {$postId}");

    cache($userId, 'users', 'delete');

    $data = array(
        'status' => 200,
        'message' => 'Post boosted for 24 hours!',
        'new_balance' => floatval($userData['wallet']) - $boostCost,
        'expires' => $expires
    );

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
