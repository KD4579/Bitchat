<?php
if (empty($_GET['app_id']) && empty($_GET['app_secret'])) {
	die('');
}

if (Wo_AccessToken($_GET['app_id'], $_GET['app_secret']) === true) {
	// SECURITY: was hardcoded to http://localhost/wowonder_update/oauth — broken on live server.
	header('Location: ' . rtrim($wo['config']['site_url'], '/') . '/oauth?app_id=' . urlencode($_GET['app_id']));
	exit();
}

die('Wrong APP ID or APP Secret.');
?>
