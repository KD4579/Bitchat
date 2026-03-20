<?php
// +------------------------------------------------------------------------+
// | @author Deen Doughouz (DoughouzForest)
// | @author_url 1: http://www.wowonder.com
// | @author_url 2: http://codecanyon.net/user/doughouzforest
// | @author_email: wowondersocial@gmail.com
// +------------------------------------------------------------------------+
// | WoWonder - The Ultimate Social Networking Platform
// | Copyright (c) 2018 WoWonder. All rights reserved.
// +------------------------------------------------------------------------+
$response_data = array(
    'api_status' => 400,
);
if (!empty(Wo_GetUserFromSessionID($_GET['access_token']))) {
	$cookie = Wo_Secure($_GET['access_token']);
	$_SESSION['user_id'] = $cookie;
	$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	setcookie("user_id", $cookie, [
	    'expires' => time() + (30 * 24 * 60 * 60),
	    'path' => '/',
	    'secure' => $isSecure,
	    'httponly' => true,
	    'samesite' => 'Lax'
	]);
	header("Location: " . Wo_SeoLink('index.php?link1=get_news_feed'));
	exit();
}