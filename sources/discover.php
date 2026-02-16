<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'discover';
$wo['title']       = 'Discover - ' . $wo['config']['siteTitle'];

// Trending posts (last 24h, top engagement)
$wo['discover_trending'] = array();
if (function_exists('Wo_GetTrendingPosts')) {
    $wo['discover_trending'] = Wo_GetTrendingPosts(8);
}

// Suggested creators
$wo['discover_creators'] = array();
if (function_exists('Wo_GetFeaturedCreators') && !empty($wo['config']['creator_mode_enabled']) && $wo['config']['creator_mode_enabled'] == '1') {
    $wo['discover_creators'] = Wo_GetFeaturedCreators(8, $wo['user']['user_id']);
}

// Trending hashtags
$wo['discover_hashtags'] = array();
if (function_exists('Wa_GetTrendingHashs')) {
    $wo['discover_hashtags'] = Wa_GetTrendingHashs('popular');
}

// People you may know
$wo['discover_people'] = array();
if (function_exists('Wo_UserSug')) {
    $wo['discover_people'] = Wo_UserSug(12);
}

$wo['content'] = Wo_LoadPage('discover/content');
