<?php
if ($wo['loggedin'] == false) {
	header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
if (!isset($_GET['hash']) || empty($_GET['hash'])) {
	Wo_RedirectSmooth($wo['config']['site_url']);
}
$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = '';
$wo['page']        = 'hashtag';
$wo['title']       = '#' . htmlspecialchars($_GET['hash'], ENT_QUOTES, 'UTF-8') . ' | ' . $wo['config']['siteTitle'];
$wo['content'] = Wo_LoadPage('hashtags/content');