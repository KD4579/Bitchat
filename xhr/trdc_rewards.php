<?php
if ($f == 'trdc_rewards') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    if (isset($_POST['trdc_creator_rewards_enabled'])) {
        Wo_SaveConfig('trdc_creator_rewards_enabled', ($_POST['trdc_creator_rewards_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['trdc_reward_milestones'])) {
        $milestones = json_decode($_POST['trdc_reward_milestones'], true);
        if (is_array($milestones)) {
            // Sanitize values
            $clean = array();
            foreach ($milestones as $key => $val) {
                $cleanKey = preg_replace('/[^a-z0-9_]/', '', $key);
                $clean[$cleanKey] = max(0, floatval($val));
            }
            Wo_SaveConfig('trdc_reward_milestones', json_encode($clean));
        }
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
