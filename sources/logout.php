<?php
// Prevent caching of logout action and authenticated page content
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Save session ID before clearing so we can delete from DB
$session_token = !empty($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
$cookie_token  = !empty($_COOKIE['user_id']) ? $_COOKIE['user_id'] : '';

// Delete DB session records (both session and cookie tokens)
if (!empty($session_token)) {
    mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `session_id` = '" . Wo_Secure($session_token) . "'");
}
if (!empty($cookie_token) && $cookie_token !== $session_token) {
    mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `session_id` = '" . Wo_Secure($cookie_token) . "'");
}

// Clear PHP session
session_unset();
session_destroy();
$_SESSION = array();

// Clear persistent cookie
if (isset($_COOKIE['user_id'])) {
    $_COOKIE['user_id'] = '';
    unset($_COOKIE['user_id']);
    setcookie('user_id', '', -1, '/');
}

header("Location: " . $wo['config']['site_url'] . "/?cache=" . time());
exit();
