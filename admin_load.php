<?php
// Prevent caching of AJAX-loaded admin pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

require_once('assets/init.php');
$is_admin     = Wo_IsAdmin();
$is_moderoter = Wo_IsModerator();
$login_url = Wo_SeoLink('index.php?link1=welcome');
if ($wo['config']['maintenance_mode'] == 1) {
    if ($wo['loggedin'] == false) {
        header("Location: " . $login_url . $wo['marker'] . 'm=true');
        exit();
    } else {
        if ($is_admin === false) {
            header("Location: " . $login_url . $wo['marker'] . 'm=true');
            exit();
        }
    }
}
if ($is_admin == false && $is_moderoter == false) {
    http_response_code(401);
    echo '<div style="text-align:center;padding:60px 20px;">';
    echo '<i class="material-icons" style="font-size:48px;color:#f44336;display:block;margin-bottom:15px;">lock</i>';
    echo '<h4 style="margin:0 0 10px;">Session Expired</h4>';
    echo '<p style="color:#666;margin:0 0 20px;">Your admin session has expired. Please log in again.</p>';
    echo '<a href="' . htmlspecialchars($login_url) . '" class="btn btn-primary">Log In</a>';
    echo '</div>';
    exit();
}
if (!empty($_GET)) {
    foreach ($_GET as $key => $value) {
        $value      = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
        $_GET[$key] = strip_tags($value);
    }
}
if (!empty($_REQUEST)) {
    foreach ($_REQUEST as $key => $value) {
        $value          = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
        $_REQUEST[$key] = strip_tags($value);
    }
}
if (!empty($_POST)) {
    foreach ($_POST as $key => $value) {
        $value       = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
        $_POST[$key] = strip_tags($value);
    }
}
$path  = (!empty($_GET['path'])) ? getPageFromPath($_GET['path']) : null;
$files = scandir('admin-panel/pages');
unset($files[0]);
unset($files[1]);
unset($files[2]);
$page = 'dashboard';

if (!empty($path['page']) && in_array($path['page'], $files) && file_exists('admin-panel/pages/' . $path['page'] . '/content.phtml')) {
    $page = $path['page'];
} else {
    $page = 'dashboard';
}
$wo['user']['permission'] = !empty($wo['user']['permission']) ? json_decode($wo['user']['permission'], true) : [];
if (!empty($wo['user']['permission'][$page])) {
  if (!empty($wo['user']['permission']) && $wo['user']['permission'][$page] == 0) {
      header("Location: " . Wo_LoadAdminLinkSettings(''));
      exit();
  }
}
$wo['decode_android_v']  = $wo['config']['footer_background'];
$wo['decode_android_value']  = base64_decode('I2FhYQ==');

$wo['decode_android_n_v']  = $wo['config']['footer_background_n'];
$wo['decode_android_n_value']  = base64_decode('I2FhYQ==');

$wo['decode_ios_v']  = $wo['config']['footer_background_2'];
$wo['decode_ios_value']  = base64_decode('I2FhYQ==');

$wo['decode_windwos_v']  = $wo['config']['footer_text_color'];
$wo['decode_windwos_value']  = base64_decode('I2RkZA==');
$data = array();
$wo['script_root'] = dirname(__FILE__);
$text = Wo_LoadAdminPage($page . '/content');
?><input type="hidden" id="json-data" value='<?php echo htmlspecialchars(json_encode($data)); ?>'><?php
echo $text;
