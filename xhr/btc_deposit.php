<?php
require_once('../assets/init.php');

if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = array();
    
    // Validate required fields
    $wallet_type = isset($_POST['wallet_type']) ? Wo_Secure($_POST['wallet_type']) : '';
    $transaction_hash = isset($_POST['transaction_hash']) ? Wo_Secure($_POST['transaction_hash']) : '';
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    
    // Validate wallet type
    if (!in_array($wallet_type, ['0', '1'])) {
        $response['status'] = 400;
        $response['message'] = 'Invalid wallet type selected';
        echo json_encode($response);
        exit();
    }
    
    // Validate transaction hash
    if (empty($transaction_hash)) {
        $response['status'] = 400;
        $response['message'] = 'Transaction hash is required';
        echo json_encode($response);
        exit();
    }
    
    // Validate amount
    if ($amount <= 0) {
        $response['status'] = 400;
        $response['message'] = 'Amount must be greater than 0';
        echo json_encode($response);
        exit();
    }
    
    // Handle file upload
    $image_path = '';
    if (isset($_FILES['transaction_image']) && $_FILES['transaction_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $file_type = $_FILES['transaction_image']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $response['status'] = 400;
            $response['message'] = 'Invalid image format. Only JPG, PNG, and GIF are allowed';
            echo json_encode($response);
            exit();
        }
        
        $file_size = $_FILES['transaction_image']['size'];
        if ($file_size > 5 * 1024 * 1024) { // 5MB limit
            $response['status'] = 400;
            $response['message'] = 'Image size must be less than 5MB';
            echo json_encode($response);
            exit();
        }
        
        // Create upload directory if it doesn't exist
        $upload_dir = '../uploads/btc_deposits/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Generate unique filename
        $file_extension = pathinfo($_FILES['transaction_image']['name'], PATHINFO_EXTENSION);
        $filename = 'btc_deposit_' . time() . '_' . $wo['user']['user_id'] . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['transaction_image']['tmp_name'], $upload_path)) {
            $image_path = 'uploads/btc_deposits/' . $filename;
        } else {
            $response['status'] = 400;
            $response['message'] = 'Failed to upload image';
            echo json_encode($response);
            exit();
        }
    } else {
        $response['status'] = 400;
        $response['message'] = 'Transaction screenshot is required';
        echo json_encode($response);
        exit();
    }
    
    // Get wallet addresses based on type
    $to_address = '';
    $from_address = '';
    
    if ($wallet_type == '0') { // TRDC
        $to_address = $wo['config']['trdc_wallet_address'] ?? '';
        $payment_mode = 'TRDC';
    } else { // USDT
        $to_address = $wo['config']['usdt_wallet_address'] ?? '';
        $payment_mode = 'USDT';
    }
    
    // Insert into database
    try {
        $current_time = time();
        $entry_date = date('Y-m-d H:i:s', $current_time);
        
        $sql = "INSERT INTO `btc_deposite` (`entry_date`, `user_id`, `payment_mode`, `transaction_number`, `amount`, `time_stamp`, `to_address`, `from_address`, `image`, `status`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $stmt = $sqlConnect->prepare($sql);
        $stmt->bind_param("sisdsisss", 
            $entry_date,
            $wo['user']['user_id'],
            $payment_mode,
            $transaction_hash,
            $amount,
            $current_time,
            $to_address,
            $from_address,
            $image_path
        );
        
        if ($stmt->execute()) {
            $deposit_id = $stmt->insert_id;
            
            // Update user wallet balance (optional - you might want to wait for admin approval)
            // $new_balance = $wo['user']['wallet'] + $amount;
            // $update_sql = "UPDATE " . T_USERS . " SET wallet = ? WHERE user_id = ?";
            // $update_stmt = $sqlConnect->prepare($update_sql);
            // $update_stmt->bind_param("di", $new_balance, $wo['user']['user_id']);
            // $update_stmt->execute();
            
            $response['status'] = 200;
            $response['message'] = 'Deposit request submitted successfully! Your transaction is being reviewed.';
            $response['deposit_id'] = $deposit_id;
            
            // Log the transaction
            $log_data = array(
                'user_id' => $wo['user']['user_id'],
                'type' => 'btc_deposit',
                'amount' => $amount,
                'payment_mode' => $payment_mode,
                'transaction_hash' => $transaction_hash,
                'status' => 'pending',
                'timestamp' => $current_time
            );
            
            // You can add logging here if needed
            
        } else {
            $response['status'] = 500;
            $response['message'] = 'Database error occurred';
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $response['status'] = 500;
        $response['message'] = 'An error occurred while processing your request';
    }
    
    echo json_encode($response);
    exit();
    
} else {
    // Invalid request method
    $response['status'] = 405;
    $response['message'] = 'Method not allowed';
    echo json_encode($response);
    exit();
}
?> 