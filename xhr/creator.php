<?php
if ($f == 'creator') {
    if ($wo['loggedin'] == false) {
        $data = array('status' => 401, 'message' => 'Please login');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    // GET action: load more reward history
    if (isset($_GET['action']) && $_GET['action'] == 'load_rewards') {
        $offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
        $limit = 10;
        if (function_exists('Wo_GetRewardHistory')) {
            $rewards = Wo_GetRewardHistory($wo['user']['user_id'], $limit + 1, $offset);
            $hasMore = count($rewards) > $limit;
            if ($hasMore) array_pop($rewards);
            $html = '';
            foreach ($rewards as $rh) {
                $amt = number_format($rh['amount'], 4);
                $reason = htmlspecialchars($rh['reason']);
                $date = date('M j, Y', $rh['created_at']);
                $html .= '<div class="bc-cd-history-item"><div class="bc-cd-hi-left"><span class="bc-cd-hi-amount">+' . $amt . ' TRDC</span><span class="bc-cd-hi-reason">' . $reason . '</span></div><span class="bc-cd-hi-date">' . $date . '</span></div>';
            }
            $data = array('status' => 200, 'html' => $html, 'has_more' => $hasMore);
        } else {
            $data = array('status' => 400, 'html' => '', 'has_more' => false);
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';

    // Admin actions — always accessible regardless of creator_mode_enabled
    if ($action == 'save_settings' && Wo_IsAdmin()) {
        if (isset($_POST['creator_mode_enabled'])) {
            $val = ($_POST['creator_mode_enabled'] == '1') ? '1' : '0';
            Wo_SaveConfig('creator_mode_enabled', $val);
            if (function_exists('Wo_LogAdminAction')) {
                Wo_LogAdminAction('config_creator', "Creator mode " . ($val == '1' ? 'enabled' : 'disabled'));
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    if ($action == 'freeze_trdc' && Wo_IsAdmin()) {
        $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if ($userId > 0) {
            global $sqlConnect;
            // Set a freeze flag — TRDC reward processing checks this
            Wo_UpdateUserData($userId, array('trdc_frozen' => '1'));
            // Also insert a config entry per user for the freeze
            mysqli_query($sqlConnect, "INSERT INTO " . T_CONFIG . " (`name`, `value`) VALUES ('trdc_frozen_{$userId}', '1') ON DUPLICATE KEY UPDATE `value` = '1'");
            if (function_exists('Wo_LogAdminAction')) {
                Wo_LogAdminAction('user_freeze_trdc', "Froze TRDC for user ID: {$userId}");
            }
        } else {
            $data = array('status' => 400, 'message' => 'Invalid user ID');
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
