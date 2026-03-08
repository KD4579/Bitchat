<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

// Check if creator mode is enabled
if (empty($wo['config']['creator_mode_enabled']) || $wo['config']['creator_mode_enabled'] != '1') {
    header("Location: " . Wo_SeoLink('index.php?link1=home'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'creator_dashboard';
$wo['title']       = 'Creator Dashboard';

// Get creator stats if user is a creator
$wo['is_creator'] = false;
$wo['creator_stats'] = array();
$wo['reward_history'] = array();
$wo['milestone_progress'] = array();
$wo['weekly_engagement'] = array();
$wo['total_trdc_earned'] = 0;
$wo['creator_rank'] = array('rank' => 'Rising Star', 'color' => '#10b981', 'bg' => '#d1fae5');

if (function_exists('Wo_IsCreator') && Wo_IsCreator($wo['user'])) {
    $wo['is_creator'] = true;
    if (function_exists('Wo_GetCreatorStats')) {
        $wo['creator_stats'] = Wo_GetCreatorStats($wo['user']['user_id']);
    }
    if (function_exists('Wo_GetCreatorRank') && !empty($wo['creator_stats'])) {
        $wo['creator_rank'] = Wo_GetCreatorRank($wo['creator_stats']);
    }
    if (function_exists('Wo_GetRewardHistory')) {
        $wo['reward_history'] = Wo_GetRewardHistory($wo['user']['user_id'], 10);
    }
    if (function_exists('Wo_GetMilestoneProgress')) {
        $wo['milestone_progress'] = Wo_GetMilestoneProgress($wo['user']['user_id']);
    }
    if (function_exists('Wo_GetCreatorWeeklyEngagement')) {
        $wo['weekly_engagement'] = Wo_GetCreatorWeeklyEngagement($wo['user']['user_id']);
    }
    // Total TRDC earned from rewards
    $trdcSql = "SELECT COALESCE(SUM(amount), 0) AS total FROM " . T_TRDC_REWARDS . " WHERE user_id = " . intval($wo['user']['user_id']);
    $trdcResult = mysqli_query($sqlConnect, $trdcSql);
    if ($trdcResult) {
        $trdcRow = mysqli_fetch_assoc($trdcResult);
        $wo['total_trdc_earned'] = floatval($trdcRow['total']);
    }
}

$wo['content'] = Wo_LoadPage('creator_dashboard/content');
