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

    // Save boost settings
    if (isset($_POST['trdc_boost_cost'])) {
        Wo_SaveConfig('trdc_boost_cost', max(0, floatval($_POST['trdc_boost_cost'])));
    }
    if (isset($_POST['trdc_boost_duration_hours'])) {
        Wo_SaveConfig('trdc_boost_duration_hours', max(1, intval($_POST['trdc_boost_duration_hours'])));
    }

    // Save tip settings
    if (isset($_POST['trdc_tip_enabled'])) {
        Wo_SaveConfig('trdc_tip_enabled', ($_POST['trdc_tip_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['trdc_tip_amounts'])) {
        // Sanitize: only allow comma-separated numbers
        $amounts = preg_replace('/[^0-9.,]/', '', $_POST['trdc_tip_amounts']);
        Wo_SaveConfig('trdc_tip_amounts', $amounts);
    }
    if (isset($_POST['trdc_tip_custom_enabled'])) {
        Wo_SaveConfig('trdc_tip_custom_enabled', ($_POST['trdc_tip_custom_enabled'] == '1') ? '1' : '0');
    }

    // Save trading signals settings
    if (isset($_POST['trading_signals_enabled'])) {
        Wo_SaveConfig('trading_signals_enabled', ($_POST['trading_signals_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['trading_signals_pairs'])) {
        $pairs = preg_replace('/[^A-Za-z0-9\/,]/', '', $_POST['trading_signals_pairs']);
        Wo_SaveConfig('trading_signals_pairs', $pairs);
    }

    // Save token gate settings
    if (isset($_POST['trdc_gate_enabled'])) {
        Wo_SaveConfig('trdc_gate_enabled', ($_POST['trdc_gate_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['trdc_gate_default_amount'])) {
        Wo_SaveConfig('trdc_gate_default_amount', max(0, floatval($_POST['trdc_gate_default_amount'])));
    }
    if (isset($_POST['trdc_gate_amounts'])) {
        $gateAmounts = preg_replace('/[^0-9.,]/', '', $_POST['trdc_gate_amounts']);
        Wo_SaveConfig('trdc_gate_amounts', $gateAmounts);
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
