<?php
if ($f == 'growth_presets') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);
    $preset = isset($_POST['preset']) ? Wo_Secure($_POST['preset']) : '';

    $presets = array(
        'creator_growth' => array(
            'feed_algorithm_enabled' => '1',
            'feed_pro_boost' => '5.0',
            'ghost_activity_enabled' => '1',
            'ghost_activity_min_delay' => '600',
            'ghost_activity_max_delay' => '3600',
            'trdc_creator_rewards_enabled' => '1',
            'creator_mode_enabled' => '1',
        ),
        'referral_boost' => array(
            'feed_algorithm_enabled' => '1',
            'feed_new_user_boost' => '3.0',
            'ghost_activity_enabled' => '1',
            'ghost_activity_min_delay' => '300',
            'ghost_activity_max_delay' => '1800',
            'announcement_banner_enabled' => '1',
        ),
        'engagement_boost' => array(
            'feed_algorithm_enabled' => '1',
            'feed_engagement_weight' => '2.0',
            'feed_media_bonus' => '4.0',
            'feed_story_boost' => '3.0',
            'ghost_activity_enabled' => '1',
            'ghost_activity_min_delay' => '300',
            'ghost_activity_max_delay' => '2400',
        ),
        'custom' => array(),
    );

    if (!isset($presets[$preset])) {
        $data = array('status' => 400, 'message' => 'Unknown preset');
    } else {
        // Apply all config values for the preset
        foreach ($presets[$preset] as $key => $value) {
            Wo_SaveConfig($key, $value);
        }
        // Track active preset
        Wo_SaveConfig('growth_active_preset', $preset);
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
