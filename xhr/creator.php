<?php
if ($f == 'creator') {
    if ($wo['loggedin'] == false) {
        $data = array('status' => 401, 'message' => 'Please login');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);
    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';

    // Admin actions — always accessible regardless of creator_mode_enabled
    if ($action == 'save_settings' && Wo_IsAdmin()) {
        if (isset($_POST['creator_mode_enabled'])) {
            Wo_SaveConfig('creator_mode_enabled', ($_POST['creator_mode_enabled'] == '1') ? '1' : '0');
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    if ($action == 'admin_toggle' && Wo_IsAdmin()) {
        $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $enable = isset($_POST['enable']) ? ($_POST['enable'] == '1') : false;
        if ($userId > 0) {
            if ($enable && function_exists('Wo_EnableCreatorMode')) {
                Wo_EnableCreatorMode($userId);
            } elseif (!$enable && function_exists('Wo_DisableCreatorMode')) {
                Wo_DisableCreatorMode($userId);
            }
        } else {
            $data = array('status' => 400, 'message' => 'Invalid user ID');
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // User actions — require creator mode to be enabled system-wide
    if (empty($wo['config']['creator_mode_enabled']) || $wo['config']['creator_mode_enabled'] != '1') {
        $data = array('status' => 400, 'message' => 'Creator mode is not available');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    if ($action == 'enable') {
        if (function_exists('Wo_EnableCreatorMode')) {
            $result = Wo_EnableCreatorMode($wo['user']['user_id']);
            if (!$result) {
                $data = array('status' => 400, 'message' => 'Could not enable creator mode');
            }
        } else {
            $data = array('status' => 400, 'message' => 'Creator mode not available');
        }
    } elseif ($action == 'disable') {
        if (function_exists('Wo_DisableCreatorMode')) {
            $result = Wo_DisableCreatorMode($wo['user']['user_id']);
            if (!$result) {
                $data = array('status' => 400, 'message' => 'Could not disable creator mode');
            }
        } else {
            $data = array('status' => 400, 'message' => 'Creator mode not available');
        }
    } else {
        $data = array('status' => 400, 'message' => 'Unknown action');
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
