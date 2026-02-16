<?php
if ($f == 'ghost_activity') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    if (isset($_POST['ghost_activity_enabled'])) {
        Wo_SaveConfig('ghost_activity_enabled', ($_POST['ghost_activity_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['ghost_activity_accounts'])) {
        // Sanitize: only allow comma-separated integers
        $raw = $_POST['ghost_activity_accounts'];
        $ids = array_filter(array_map('intval', explode(',', $raw)));
        Wo_SaveConfig('ghost_activity_accounts', implode(',', $ids));
    }
    if (isset($_POST['ghost_activity_min_delay'])) {
        $min = max(300, intval($_POST['ghost_activity_min_delay']));
        Wo_SaveConfig('ghost_activity_min_delay', strval($min));
    }
    if (isset($_POST['ghost_activity_max_delay'])) {
        $max = max(360, intval($_POST['ghost_activity_max_delay']));
        Wo_SaveConfig('ghost_activity_max_delay', strval($max));
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
