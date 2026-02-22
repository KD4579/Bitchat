<?php
if ($f == 'trdc_rewards') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    // Save master switch
    if (isset($_POST['trdc_creator_rewards_enabled'])) {
        Wo_SaveConfig('trdc_creator_rewards_enabled', ($_POST['trdc_creator_rewards_enabled'] == '1') ? '1' : '0');
    }

    // Save reward engine configs (new engine)
    if (!empty($_POST['reward_configs']) && function_exists('Wo_UpdateRewardConfig')) {
        $configs = json_decode($_POST['reward_configs'], true);
        if (is_array($configs)) {
            foreach ($configs as $cfg) {
                if (empty($cfg['key'])) continue;
                Wo_UpdateRewardConfig($cfg['key'], array(
                    'enabled'        => isset($cfg['enabled']) ? intval($cfg['enabled']) : 0,
                    'reward_amount'  => isset($cfg['amount']) ? floatval($cfg['amount']) : 0,
                    'max_per_day'    => isset($cfg['max_per_day']) ? intval($cfg['max_per_day']) : 0,
                    'cooldown_hours' => isset($cfg['cooldown_hours']) ? intval($cfg['cooldown_hours']) : 0
                ));
            }
        }
    }

    // Backwards compat: old milestone format
    if (isset($_POST['trdc_reward_milestones'])) {
        $milestones = json_decode($_POST['trdc_reward_milestones'], true);
        if (is_array($milestones)) {
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
