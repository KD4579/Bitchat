<?php
if ($wo['config']['forum_visibility'] == 1) {
	if ($wo['loggedin'] == false) {
	  header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
	  exit();
	}
}
if ($wo['config']['forum'] == 0) {
	header("Location: " . $wo['config']['site_url']);
    exit();
}
if (isset($_GET['tid']) && is_numeric($_GET['tid'])) {
	$thread = Wo_GetForumThreads(array('id' => $_GET['tid'], 'user' => $wo['user']['id']));
	if (!empty($thread)) {
		if ($thread[0]['poster_id'] == $wo['user']['id'] || $wo['user']['admin'] == 1) {
			Wo_DeleteForumThread($_GET['tid']);
			header("Location: " . Wo_SeoLink('index.php?link1=forum'));
			exit();
		}
	}
}
header("Location: " . Wo_SeoLink('index.php?link1=forum'));
exit();
