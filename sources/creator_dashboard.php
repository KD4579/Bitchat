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

if (function_exists('Wo_IsCreator') && Wo_IsCreator($wo['user'])) {
    $wo['is_creator'] = true;
    if (function_exists('Wo_GetCreatorStats')) {
        $wo['creator_stats'] = Wo_GetCreatorStats($wo['user']['user_id']);
    }
    if (function_exists('Wo_GetRewardHistory')) {
        $wo['reward_history'] = Wo_GetRewardHistory($wo['user']['user_id'], 20);
    }
}

$wo['content'] = Wo_LoadPage('creator_dashboard/content');
