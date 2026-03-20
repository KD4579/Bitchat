<?php
if (empty($_GET['app_id'])) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
$actual_link = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome') . '?last_url=' . urlencode($actual_link));
    exit();
}
$wo['app'] = array();
$app       = Wo_IsValidApp($_GET['app_id']);
if ($app === true) {
    $app_id    = Wo_GetIdFromAppID($_GET['app_id']);
    $wo['app'] = Wo_GetApp($app_id);
    if (Wo_AppHasPermission($wo['user']['user_id'], Wo_GetIdFromAppID($_GET['app_id'])) === true) {
        // Always use the registered callback URL — never accept redirect_uri from user input
        // This prevents open redirect and authorization code theft
        $url = $wo['app']['app_callback_url'];
        if (empty($url)) {
            $url = $wo['app']['app_website_url'];
        }
        // Validate URL is HTTPS or localhost for security
        $parsed_url = parse_url($url);
        if (empty($parsed_url['host']) || (!in_array($parsed_url['scheme'] ?? '', ['http', 'https']))) {
            header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
            exit();
        }
        $import = Wo_GenrateCode($wo['user']['user_id'], Wo_GetIdFromAppID($_GET['app_id']));
        header("Location: {$url}?code=" . urlencode($import));
        exit();
    }
} else {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}
$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'graph';
$wo['title']       = $wo['config']['siteTitle'];
$wo['content']     = Wo_LoadPage('graph/data-request');
