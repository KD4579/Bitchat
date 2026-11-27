<?php
/**
 * Bitchat Updates promo bot: one-time setup + helpers. CLI only, run as KamalDave from the docroot.
 *
 *   php tools/promo-bot/setup.php install            create the account, bot row, avatar, followers
 *   php tools/promo-bot/setup.php preview <dir>      render today's 3 posts to <dir> (nothing is posted)
 *   php tools/promo-bot/setup.php post <slot>        post one slot now (morning|reward|evening), once per day
 *
 * After install the normal cron (news_bots section of cron-job.php) posts 3 times a day.
 * Pause/resume or "run now" from /admin-cp/news-bots.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__, 2));
require 'assets/init.php';
require_once 'assets/includes/functions_news_bots.php';
require_once 'assets/includes/functions_promo_bot.php';

$USERNAME = 'bitchatupdates';
$NAME     = 'Bitchat Updates';
$CONTENT  = 'assets/bot_content/bitchat_promo.json';

$cmd = $argv[1] ?? '';
$db  = new MysqliDb($sqlConnect);

function pb_bot_row($db, $username) {
    $db->where('username', $username);
    return $db->getOne('Wo_Bot_Accounts');
}

if ($cmd === 'install') {
    // 1. Allow content_type 'promo'
    $col = mysqli_fetch_assoc(mysqli_query($sqlConnect, "SHOW COLUMNS FROM Wo_Bot_Accounts LIKE 'content_type'"));
    if (strpos($col['Type'], "'promo'") === false) {
        mysqli_query($sqlConnect, "ALTER TABLE Wo_Bot_Accounts MODIFY content_type ENUM('rss','template','promo') NOT NULL DEFAULT 'rss'");
        echo "content_type enum extended\n";
    }

    // 2. User account
    $u = mysqli_fetch_assoc(mysqli_query($sqlConnect, "SELECT user_id FROM " . T_USERS . " WHERE username = '{$USERNAME}' LIMIT 1"));
    if ($u) {
        $uid = intval($u['user_id']);
        echo "user exists: {$uid}\n";
    } else {
        $pw = Wo_Secure(password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
        $about = Wo_Secure('Official updates from Bitchat: news, TRDC rewards and Engage-to-Earn tips. bitchat.social');
        $ok = mysqli_query($sqlConnect, "INSERT INTO " . T_USERS . " (username, email, password, first_name, last_name, about, active, verified,
                start_up, startup_image, start_up_info, startup_follow, emailNotification, joined, registered, language, order_posts_by)
            VALUES ('{$USERNAME}', '{$USERNAME}@bot.bitchat.local', '{$pw}', 'Bitchat', 'Updates', '{$about}', '1', '1',
                '1', '1', '1', '1', '0', " . time() . ", '" . date('n') . '/' . date('Y') . "', '" . Wo_Secure($wo['config']['defualtLang']) . "', '" . Wo_Secure($wo['config']['order_posts_by']) . "')");
        if (!$ok) {
            fwrite(STDERR, 'user insert failed: ' . mysqli_error($sqlConnect) . "\n");
            exit(1);
        }
        $uid = mysqli_insert_id($sqlConnect);
        mysqli_query($sqlConnect, "INSERT INTO " . T_USERS_FIELDS . " (user_id) VALUES ({$uid})");
        echo "user created: {$uid}\n";
    }

    // 3. Avatar: brand gradient with the logo on a white disc
    $dir = 'upload/photos/' . gmdate('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $S = 512;
    $im = imagecreatetruecolor($S, $S);
    $st = array_map('pb_hex', array('#ff7a2f', '#e8336e', '#8b3fd9'));
    for ($y = 0; $y < $S; $y++) {
        for ($x = 0; $x < $S; $x++) {
            $t = ($x + $y) / (2 * $S);
            list($a, $b, $k) = $t < 0.5 ? array($st[0], $st[1], $t * 2) : array($st[1], $st[2], ($t - 0.5) * 2);
            imagesetpixel($im, $x, $y, ((int) ($a[0] + ($b[0] - $a[0]) * $k) << 16) | ((int) ($a[1] + ($b[1] - $a[1]) * $k) << 8) | (int) ($a[2] + ($b[2] - $a[2]) * $k));
        }
    }
    pb_rounded($im, 76, 76, $S - 76, $S - 76, 180, array(255, 255, 255));
    $logo = imagecreatefrompng('themes/wondertag/img/email-logo.png');
    imagealphablending($im, true);
    $lw = 280;
    $lh = (int) (imagesy($logo) * $lw / imagesx($logo));
    imagecopyresampled($im, $logo, (int) (($S - $lw) / 2), (int) (($S - $lh) / 2), 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
    $avatar = $dir . '/bitchatupdates_avatar_' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($im, $avatar, 92);
    // Cover: a wide brand banner
    $cover = pb_banner(array('kind' => 'morning', 'title' => 'Bitchat Updates', 'sub' => 'News, rewards and Engage-to-Earn tips', 'seed' => 0));
    mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET avatar = '" . Wo_Secure($avatar) . "'" . ($cover ? ", cover = '" . Wo_Secure($cover) . "'" : '') . " WHERE user_id = {$uid}");
    echo "avatar: {$avatar}\ncover: {$cover}\n";

    // 4. Bot row
    if (!pb_bot_row($db, $USERNAME)) {
        $db->insert('Wo_Bot_Accounts', array(
            'user_id' => $uid, 'name' => $NAME, 'username' => $USERNAME, 'category' => 'promo',
            'content_type' => 'promo', 'content_file' => $CONTENT, 'news_sources' => '',
            'post_frequency' => 60, 'max_posts_per_day' => 3, 'include_thumbnail' => 1, 'enabled' => 1,
        ));
        echo "bot row created\n";
    }

    // 5. Existing active members follow the account (new signups already auto-follow enabled bots)
    mysqli_query($sqlConnect, "INSERT INTO " . T_FOLLOWERS . " (following_id, follower_id, active, time)
        SELECT {$uid}, u.user_id, 1, " . time() . " FROM " . T_USERS . " u
        WHERE u.active = '1' AND u.user_id <> {$uid}
          AND u.user_id NOT IN (SELECT user_id FROM Wo_Bot_Accounts)
          AND NOT EXISTS (SELECT 1 FROM " . T_FOLLOWERS . " f WHERE f.following_id = {$uid} AND f.follower_id = u.user_id)");
    echo 'followers added: ' . mysqli_affected_rows($sqlConnect) . "\n";
    cache($uid, 'users', 'delete');
    exit(0);
}

$bot = pb_bot_row($db, $USERNAME);
if (!$bot) {
    fwrite(STDERR, "Run 'install' first.\n");
    exit(1);
}

if ($cmd === 'preview') {
    $out = rtrim($argv[2] ?? '/tmp/promo-preview', '/');
    if (!is_dir($out)) {
        mkdir($out, 0755, true);
    }
    $day = intdiv(time(), 86400) + intval($argv[3] ?? 0);
    foreach (array_keys(unserialize(PB_SLOTS)) as $slot) {
        $b = pb_build($bot, $slot, $day);
        if (!$b) {
            echo "== {$slot}: nothing to post\n";
            continue;
        }
        echo "== {$slot}\n{$b[0]}\n";
        if ($b[1]) {
            pb_banner($b[1], "{$out}/{$slot}.jpg");
            echo "[banner: {$out}/{$slot}.jpg]\n";
        }
        echo "\n";
    }
    exit(0);
}

if ($cmd === 'post') {
    $slot = $argv[2] ?? '';
    if (!array_key_exists($slot, unserialize(PB_SLOTS))) {
        fwrite(STDERR, "slot must be one of: " . implode(', ', array_keys(unserialize(PB_SLOTS))) . "\n");
        exit(1);
    }
    $hash = md5('promo:' . gmdate('Y-m-d') . ':' . $slot);
    $db->where('bot_id', $bot->id);
    $db->where('article_hash', $hash);
    if ($db->getOne('Wo_Bot_Posted')) {
        echo "{$slot} already posted today\n";
        exit(0);
    }
    list($text, $banner) = pb_build($bot, $slot, intdiv(time(), 86400));
    $image = $banner ? (pb_banner($banner) ?: '') : '';
    $id = pb_insert_post($bot->user_id, $text, $image);
    if ($id) {
        $db->insert('Wo_Bot_Posted', array('bot_id' => $bot->id, 'article_hash' => $hash, 'post_id' => $id));
        echo "posted {$slot}: " . rtrim($wo['config']['site_url'], '/') . "/post/{$id}\n";
    }
    exit($id ? 0 : 1);
}

fwrite(STDERR, "usage: php tools/promo-bot/setup.php install | preview <dir> [dayOffset] | post <slot>\n");
exit(1);
