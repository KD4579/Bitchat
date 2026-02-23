<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'affiliates';
$wo['title']       = 'Invite & Earn';

// Set $wo['setting'] to current user data (affiliates template depends on this)
$wo['setting'] = Wo_UserData($wo['user']['user_id']);

$wo['content'] = Wo_LoadPage('affiliates/content');
