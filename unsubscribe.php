<?php
/**
 * Campaign unsubscribe (RFC 8058 one-click + human confirmation page).
 *   GET  ?u=&c=&t=   -> confirmation page (GET never unsubscribes: link scanners prefetch URLs)
 *   POST ?u=&c=&t=   -> unsubscribe (Gmail/Yahoo one-click sends body List-Unsubscribe=One-Click)
 * Only campaign/marketing mail is stopped; account & security emails continue.
 */
require_once('assets/init.php');
require_once('assets/includes/functions_mail_campaigns.php');

$uid   = isset($_GET['u']) ? intval($_GET['u']) : 0;
$cid   = isset($_GET['c']) ? intval($_GET['c']) : 0;
$token = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', $_GET['t']) : '';
$user  = null;
if ($uid > 0 && strlen($token) === 32) {
    $r = mysqli_query($sqlConnect, "SELECT user_id, username, first_name, email FROM " . T_USERS . " WHERE user_id = {$uid} LIMIT 1");
    if ($r && mysqli_num_rows($r)) {
        $row = mysqli_fetch_assoc($r);
        if (hash_equals(Wo_McUnsubToken($row['user_id'], $row['email']), $token)) {
            $user = $row;
        }
    }
}

$state = 'invalid';
if ($user) {
    $state = 'confirm';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Wo_McSuppress($user['email'], 'unsubscribe', 'campaign ' . $cid);
        $e = mysqli_real_escape_string($sqlConnect, strtolower($user['email']));
        mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " SET status = 'skipped', error = 'unsubscribed' WHERE email = '{$e}' AND status = 'pending'");
        $state = 'done';
        if (empty($_POST['confirm'])) {
            // One-click request from the mail client: no page needed
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Unsubscribed';
            exit;
        }
    } else {
        $chk = mysqli_query($sqlConnect, "SELECT 1 FROM " . T_MAIL_SUPPRESSION . " WHERE email = '" . mysqli_real_escape_string($sqlConnect, strtolower($user['email'])) . "' LIMIT 1");
        if ($chk && mysqli_num_rows($chk)) {
            $state = 'done';
        }
    }
}

$site  = rtrim($wo['config']['site_url'], '/');
$h     = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$email = $user ? $user['email'] : '';
$masked = $email ? preg_replace('/^(.{2}).*(@.*)$/', '$1•••$2', $email) : '';
$titles = array(
    'invalid' => array('&#128279;', 'This link has expired', 'The unsubscribe link is incomplete or no longer valid. You can manage all email preferences from your account settings.'),
    'confirm' => array('&#9993;&#65039;', 'Unsubscribe from Bitchat emails?', 'You\'ll stop receiving news and announcements at <strong>' . $h($masked) . '</strong>. Important account and security emails will still reach you.'),
    'done'    => array('&#128075;', 'You\'re unsubscribed', 'We won\'t send news or announcements to <strong>' . $h($masked) . '</strong> anymore. Changed your mind? You can turn emails back on any time in Settings.'),
);
list($icon, $title, $text) = $titles[$state];
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo strip_tags($title); ?> · Bitchat</title>
<style>
  :root { --o:#ff7a2f; --p:#e8336e; --v:#8b3fd9; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
         font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; background:#f1ecfa; color:#1f1b2e; }
  .card { width:100%; max-width:460px; background:#fff; border-radius:20px; overflow:hidden; box-shadow:0 10px 40px rgba(80,40,140,.12); text-align:center; }
  .hero { background:linear-gradient(135deg,var(--o),var(--p) 55%,var(--v)); padding:34px 24px 28px; color:#fff; }
  .icon { width:64px; height:64px; line-height:64px; margin:0 auto; border-radius:50%; background:rgba(255,255,255,.22); font-size:30px; }
  h1 { margin:16px 0 0; font-size:22px; line-height:30px; }
  .body { padding:28px 30px 30px; font-size:15px; line-height:24px; color:#4a4560; }
  .btn { display:inline-block; margin-top:20px; padding:13px 30px; border:0; border-radius:999px; font-size:15px; font-weight:700; cursor:pointer;
         color:#fff; text-decoration:none; background:linear-gradient(135deg,var(--o),var(--p) 55%,var(--v)); }
  .link { display:inline-block; margin-top:14px; color:#8b3fd9; font-size:14px; text-decoration:none; }
  img.logo { display:block; margin:0 auto 18px; width:96px; height:58px; }
</style>
</head>
<body>
<div>
  <a href="<?php echo $h($site); ?>"><img class="logo" src="<?php echo $h($site); ?>/themes/wondertag/img/email-logo.png" alt="Bitchat"></a>
  <div class="card">
    <div class="hero"><div class="icon"><?php echo $icon; ?></div><h1><?php echo $title; ?></h1></div>
    <div class="body">
      <p style="margin:0;"><?php echo $text; ?></p>
      <?php if ($state === 'confirm') { ?>
        <form method="post" action="">
          <input type="hidden" name="confirm" value="1">
          <button class="btn" type="submit">Yes, unsubscribe me</button>
        </form>
        <a class="link" href="<?php echo $h($site); ?>">No thanks, take me to Bitchat</a>
      <?php } else { ?>
        <a class="btn" href="<?php echo $h($site); ?>">Go to Bitchat</a>
      <?php } ?>
    </div>
  </div>
</div>
</body>
</html>
