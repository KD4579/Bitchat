<?php
if ($f == 'auto_backup_settings') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    if (isset($_POST['auto_backup_enabled'])) {
        Wo_SaveConfig('auto_backup_enabled', ($_POST['auto_backup_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['auto_backup_interval'])) {
        $interval = intval($_POST['auto_backup_interval']);
        $allowed = array(43200, 86400, 604800);
        if (in_array($interval, $allowed)) {
            Wo_SaveConfig('auto_backup_interval', (string)$interval);
        }
    }

    if (function_exists('Wo_LogAdminAction')) {
        Wo_LogAdminAction('config_backup', 'Updated auto backup settings');
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
