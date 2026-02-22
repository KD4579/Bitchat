<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'my_points';
$wo['title']       = 'Earn & Rewards';

// Load user balance data for the template
$wo['setting'] = array_merge($wo['setting'] ?? array(), array(
    'balance' => floatval($wo['user']['wallet'] ?? 0),
    'points'  => intval($wo['user']['points'] ?? 0)
));

$wo['content'] = Wo_LoadPage('my_points/content');
