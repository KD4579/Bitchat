<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'welcome-setup';
$wo['title']       = 'Welcome - ' . $wo['config']['siteTitle'];

// Load follow suggestions: creators + general people
$wo['onboard_suggestions'] = array();
if (function_exists('Wo_GetFeaturedCreators') && !empty($wo['config']['creator_mode_enabled']) && $wo['config']['creator_mode_enabled'] == '1') {
    $wo['onboard_suggestions'] = Wo_GetFeaturedCreators(6, $wo['user']['user_id']);
}
if (function_exists('Wo_UserSug')) {
    $general = Wo_UserSug(12 - count($wo['onboard_suggestions']));
    if (!empty($general) && is_array($general)) {
        // Merge, avoid duplicates
        $existingIds = array_column($wo['onboard_suggestions'], 'user_id');
        foreach ($general as $g) {
            if (!in_array($g['user_id'], $existingIds)) {
                $wo['onboard_suggestions'][] = $g;
            }
        }
    }
}

$wo['content'] = Wo_LoadPage('welcome_setup/content');
