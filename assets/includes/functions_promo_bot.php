<?php
/**
 * Bitchat promo bot ("Bitchat Updates"): 3 posts a day from a Wo_Bot_Accounts
 * row with content_type = 'promo'. Called through bc_run_single_bot() from cron.
 *
 *   morning  03:30 UTC (09:00 IST)  new banner every day + "We're back at bitchat.social" caption
 *   reward   09:30 UTC (15:00 IST)  one TRDC reward activity per day (live amounts) + reward card
 *   evening  14:30 UTC (20:00 IST)  alternates Engage-to-Earn vs other social media / TRDC exchange
 *
 * Content lives in the bot's content_file (assets/bot_content/bitchat_promo.json).
 * A slot that is missed by more than 4 hours is skipped, so posts never pile up.
 */

define('PB_SLOTS', serialize(array(
    'morning' => '03:30',
    'reward'  => '09:30',
    'evening' => '14:30',
)));
define('PB_SLOT_GRACE', 4 * 3600);

function pb_root() {
    return realpath(dirname(__FILE__) . '/../../');
}

function pb_content($bot) {
    $file = !empty($bot->content_file) ? $bot->content_file : 'assets/bot_content/bitchat_promo.json';
    $data = json_decode(@file_get_contents(pb_root() . '/' . $file), true);
    return is_array($data) ? $data : array();
}

function pb_fmt_amount($v) {
    $v = floatval($v);
    return rtrim(rtrim(number_format($v, 4, '.', ','), '0'), '.');
}

function pb_fill($text, $vars = array()) {
    $vars += array('SITE' => 'https://bitchat.social');
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return $text;
}

function pb_pick($list, $index) {
    $list = array_values($list);
    return $list ? $list[$index % count($list)] : null;
}

/** Enabled rewards with an amount, in admin order. */
function pb_rewards() {
    global $sqlConnect;
    $out = array();
    $r = mysqli_query($sqlConnect, "SELECT reward_key, title, reward_amount, max_per_day FROM Wo_Rewards_Config
                                    WHERE enabled = 1 AND reward_amount > 0 ORDER BY sort_order ASC, id ASC");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[] = $row;
    }
    return $out;
}

/**
 * Build the post for a slot on a given day. Returns [text, banner_options|null].
 * $day = days since 1970 (UTC), drives the daily rotation.
 */
function pb_build($bot, $slot, $day) {
    $c = pb_content($bot);
    if ($slot === 'morning') {
        $cap = pb_pick($c['morning_captions'] ?? array(), $day);
        $ban = pb_pick($c['morning_banners'] ?? array(), $day * 5 + 3);
        if (!$cap || !$ban) {
            return null;
        }
        return array(pb_fill($cap), array(
            'kind' => 'morning', 'title' => $ban[0], 'sub' => $ban[1], 'seed' => $day,
        ));
    }
    if ($slot === 'reward') {
        $rewards = pb_rewards();
        $rw = pb_pick($rewards, $day);
        if (!$rw) {
            return null;
        }
        $copy = $c['rewards'][$rw['reward_key']] ?? null;
        $vars = array(
            'AMOUNT' => pb_fmt_amount($rw['reward_amount']),
            'MAX'    => intval($rw['max_per_day']),
        );
        if ($copy) {
            // A different caption each time this reward comes round again.
            $cap = pb_pick($copy['captions'], intdiv($day, max(1, count($rewards))));
            $card = $copy['card'];
        } else {
            $cap = "🪙 Earn {AMOUNT} TRDC on Bitchat: " . $rw['title'] . "\n\nEngage-to-Earn rewards you for being part of the community 👉 {SITE}\n\n#Bitchat #TRDC #EngageToEarn";
            $card = strtolower($rw['title']);
        }
        $per = intval($rw['max_per_day']) > 1 ? 'up to ' . intval($rw['max_per_day']) . '× a day' : (intval($rw['max_per_day']) == 1 ? 'one-time reward' : 'milestone bonus');
        if ($rw['reward_key'] === 'daily_login') {
            $per = 'every day';
        }
        return array(pb_fill($cap, $vars), array(
            'kind' => 'reward', 'amount' => $vars['AMOUNT'], 'title' => $card, 'sub' => $per, 'seed' => $day,
        ));
    }
    if ($slot === 'evening') {
        $list = ($day % 2 === 0) ? ($c['e2e'] ?? array()) : ($c['exchange'] ?? array());
        $cap = pb_pick($list, intdiv($day, 2));
        return $cap ? array(pb_fill($cap), null) : null;
    }
    return null;
}

/* ---------------------------------------------------------------- banners */

function pb_hex($h) {
    $h = ltrim($h, '#');
    return array(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
}

function pb_palettes() {
    return array(
        array('#ff7a2f', '#e8336e', '#8b3fd9'), // brand
        array('#8b3fd9', '#5b2be0', '#1fa2ff'), // violet → sky
        array('#ff512f', '#f09819', '#ffcc33'), // sunrise
        array('#e8336e', '#b0249f', '#4a148c'), // magenta night
        array('#00b09b', '#1d976c', '#0f5f7a'), // emerald
        array('#ff6a88', '#ff99ac', '#fcb69f'), // peach
        array('#141e30', '#35267a', '#e8336e'), // midnight neon
        array('#1fa2ff', '#12d8fa', '#a6ffcb'), // ocean
    );
}

function pb_font($w) {
    return pb_root() . '/assets/fonts/poppins/Poppins-' . $w . '.ttf';
}

function pb_text_w($size, $font, $text) {
    $b = imagettfbbox($size, 0, $font, $text);
    return abs($b[2] - $b[0]);
}

function pb_wrap($size, $font, $text, $maxW) {
    $lines = array();
    $line = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        $try = $line === '' ? $word : $line . ' ' . $word;
        if ($line !== '' && pb_text_w($size, $font, $try) > $maxW) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

/** Largest size (step 4) at which $text fits in $maxLines lines of $maxW. */
function pb_fit($text, $font, $maxW, $maxLines, $start, $min) {
    for ($s = $start; $s > $min; $s -= 4) {
        $lines = pb_wrap($s, $font, $text, $maxW);
        if (count($lines) <= $maxLines) {
            return array($s, $lines);
        }
    }
    return array($min, pb_wrap($min, $font, $text, $maxW));
}

/** Anti-aliased rounded rectangle, blended at $op (0..1) opacity; no overlap artefacts. */
function pb_rounded($im, $x1, $y1, $x2, $y2, $r, $rgb, $op = 1.0) {
    $W = imagesx($im);
    $H = imagesy($im);
    for ($y = max(0, $y1); $y <= min($H - 1, $y2); $y++) {
        for ($x = max(0, $x1); $x <= min($W - 1, $x2); $x++) {
            $dx = max($x1 + $r - $x, 0, $x - ($x2 - $r));
            $dy = max($y1 + $r - $y, 0, $y - ($y2 - $r));
            $cov = ($dx > 0 && $dy > 0) ? max(0, min(1, $r + 0.5 - sqrt($dx * $dx + $dy * $dy))) : 1;
            if ($cov <= 0) {
                continue;
            }
            $a = $cov * $op;
            $c = imagecolorat($im, $x, $y);
            imagesetpixel($im, $x, $y,
                ((int) ((($c >> 16) & 255) * (1 - $a) + $rgb[0] * $a) << 16)
              | ((int) ((($c >> 8) & 255) * (1 - $a) + $rgb[1] * $a) << 8)
              | (int) (($c & 255) * (1 - $a) + $rgb[2] * $a));
        }
    }
}

/** Pixel advance per line: GD sizes are points (96 dpi), so 1pt ~ 1.333px. */
function pb_adv($size, $lh) {
    return (int) round($size * 1.333 * $lh);
}

/** Draw text lines from top $y; $align = 'center' | 'left'. Returns y after the block. */
function pb_lines($im, $lines, $size, $font, $x, $y, $w, $align, $col, $shadow, $lh = 1.0) {
    $adv = pb_adv($size, $lh);
    foreach ($lines as $ln) {
        $tw = pb_text_w($size, $font, $ln);
        $tx = $align === 'center' ? (int) ($x + ($w - $tw) / 2) : $x;
        $base = $y + (int) round($size * 1.333 * 0.80) + (int) (($adv - $size * 1.333) / 2);
        if ($shadow !== null) {
            imagettftext($im, $size, 0, $tx + 2, $base + 5, $shadow, $font, $ln);
        }
        imagettftext($im, $size, 0, $tx, $base, $col, $font, $ln);
        $y += $adv;
    }
    return $y;
}

/**
 * Render a 1200x675 JPEG banner. $o = [kind, title, sub, amount?, seed].
 * Returns the relative upload path, or false.
 */
function pb_banner($o, $outRel = null) {
    if (!function_exists('imagettftext')) {
        return false;
    }
    $W = 1200;
    $H = 675;
    $seed = intval($o['seed']);
    mt_srand($seed * 7919 + ($o['kind'] === 'reward' ? 17 : 0));
    $pals = pb_palettes();
    $pal = $pals[($seed + ($o['kind'] === 'reward' ? 3 : 0)) % count($pals)];
    $stops = array_map('pb_hex', $pal);
    $angle = deg2rad(array(20, 35, 135, 160, 60)[$seed % 5]);

    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    imageantialias($im, true);

    // Three-stop diagonal gradient
    $cx = cos($angle);
    $cy = sin($angle);
    $min = min(0, $W * $cx) + min(0, $H * $cy);
    $max = max(0, $W * $cx) + max(0, $H * $cy);
    $span = $max - $min;
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $t = (($x * $cx + $y * $cy) - $min) / $span;
            if ($t < 0.5) {
                $a = $stops[0]; $b = $stops[1]; $u = $t * 2;
            } else {
                $a = $stops[1]; $b = $stops[2]; $u = ($t - 0.5) * 2;
            }
            imagesetpixel($im, $x, $y, ((int) ($a[0] + ($b[0] - $a[0]) * $u) << 16)
                                     | ((int) ($a[1] + ($b[1] - $a[1]) * $u) << 8)
                                     | (int) ($a[2] + ($b[2] - $a[2]) * $u));
        }
    }

    // Soft bubbles
    for ($i = 0; $i < 9; $i++) {
        $r = mt_rand(60, 300);
        $col = imagecolorallocatealpha($im, 255, 255, 255, mt_rand(108, 120));
        imagefilledellipse($im, mt_rand(-80, $W + 80), mt_rand(-80, $H + 80), $r * 2, $r * 2, $col);
    }
    // Rings
    for ($i = 0; $i < 3; $i++) {
        $col = imagecolorallocatealpha($im, 255, 255, 255, 95);
        imagesetthickness($im, mt_rand(3, 6));
        $r = mt_rand(120, 260);
        imageellipse($im, mt_rand(0, $W), mt_rand(0, $H), $r * 2, $r * 2, $col);
    }
    imagesetthickness($im, 1);
    // Confetti for the morning banner
    if ($o['kind'] === 'morning') {
        $conf = array(array(255, 214, 10), array(255, 255, 255), array(0, 229, 255), array(255, 90, 160), array(170, 255, 120));
        for ($i = 0; $i < 46; $i++) {
            $c = $conf[mt_rand(0, count($conf) - 1)];
            $col = imagecolorallocatealpha($im, $c[0], $c[1], $c[2], mt_rand(10, 55));
            $x = mt_rand(0, $W);
            $y = mt_rand(0, $H);
            // keep the text area calmer
            $leftLayout = ($seed % 2 === 1);
            if ($y > 140 && $y < $H - 140 && ($leftLayout ? ($x > 50 && $x < 940) : ($x > 140 && $x < $W - 140))) {
                continue;
            }
            if (mt_rand(0, 1)) {
                imagefilledellipse($im, $x, $y, mt_rand(8, 16), mt_rand(8, 16), $col);
            } else {
                $s = mt_rand(5, 9);
                $l = mt_rand(14, 26);
                $a = deg2rad(mt_rand(0, 180));
                $pts = array();
                foreach (array(array(-$l, -$s), array($l, -$s), array($l, $s), array(-$l, $s)) as $p) {
                    $pts[] = (int) ($x + $p[0] * cos($a) - $p[1] * sin($a));
                    $pts[] = (int) ($y + $p[0] * sin($a) + $p[1] * cos($a));
                }
                if (PHP_VERSION_ID >= 80000) {
                    imagefilledpolygon($im, $pts, $col);
                } else {
                    imagefilledpolygon($im, $pts, 4, $col);
                }
            }
        }
    }

    $white  = imagecolorallocate($im, 255, 255, 255);
    $soft   = imagecolorallocatealpha($im, 255, 255, 255, 14);
    $shadow = imagecolorallocatealpha($im, 0, 0, 0, 100);
    $dark   = imagecolorallocate($im, 40, 22, 70);
    $fBlack = pb_font('ExtraBold');
    $fSemi  = pb_font('SemiBold');

    $left  = ($o['kind'] === 'morning' && $seed % 2 === 1);
    $align = $left ? 'left' : 'center';
    $padX  = $left ? 90 : 80;
    $boxW  = $left ? 800 : $W - 160;

    // Logo pill
    $pillW = 176;
    $pillH = 84;
    $px = $left ? $padX : (int) (($W - $pillW) / 2);
    $py = 46;
    pb_rounded($im, $px, $py + 6, $px + $pillW, $py + $pillH + 6, 42, array(0, 0, 0), 0.16);
    pb_rounded($im, $px, $py, $px + $pillW, $py + $pillH, 42, array(255, 255, 255));
    $logo = @imagecreatefrompng(pb_root() . '/themes/wondertag/img/email-logo.png');
    if ($logo) {
        imagealphablending($im, true);
        $lh = 60;
        $lw = (int) (imagesx($logo) * $lh / imagesy($logo));
        imagecopyresampled($im, $logo, $px + (int) (($pillW - $lw) / 2), $py + 12, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
        imagedestroy($logo);
    }

    // Text blocks: [lines, size, font, color, shadow, line-height, gap-before]
    $blocks = array();
    if ($o['kind'] === 'reward') {
        $blocks[] = array(array('ENGAGE TO EARN'), 24, $fSemi, $soft, null, 1.2, 0);
        list($s, $l) = pb_fit('+' . $o['amount'] . ' TRDC', $fBlack, $boxW, 1, 116, 60);
        $blocks[] = array($l, $s, $fBlack, $white, $shadow, 1.08, 4);
        list($s, $l) = pb_fit(ucfirst($o['title']), $fSemi, $boxW - 60, 2, 40, 26);
        $blocks[] = array($l, $s, $fSemi, $white, $shadow, 1.25, 14);
        $chip = $o['sub'];
    } else {
        list($s, $l) = pb_fit($o['title'], $fBlack, $boxW, 2, 84, 48);
        $blocks[] = array($l, $s, $fBlack, $white, $shadow, 1.08, 0);
        list($s, $l) = pb_fit($o['sub'], $fSemi, $boxW, 2, 32, 22);
        $blocks[] = array($l, $s, $fSemi, $soft, null, 1.3, 22);
        $chip = null;
    }
    $top = $py + $pillH + 24;
    $bottom = $H - 130;
    $total = 0;
    foreach ($blocks as $bl) {
        $total += $bl[6] + count($bl[0]) * pb_adv($bl[1], $bl[5]);
    }
    $y = $top + (int) max(0, ($bottom - $top - $total) / 2);
    foreach ($blocks as $bl) {
        $y = pb_lines($im, $bl[0], $bl[1], $bl[2], $padX, $y + $bl[6], $boxW, $align, $bl[3], $bl[4], $bl[5]);
    }

    // Bottom chips: optional detail + the address
    $chips = array();
    if ($chip) {
        $chips[] = array($chip, false);
    }
    $chips[] = array('bitchat.social', true);
    $cs = 24;
    $gap = 16;
    $widths = array();
    foreach ($chips as $ch) {
        $widths[] = pb_text_w($cs, $fSemi, $ch[0]) + 60;
    }
    $sum = array_sum($widths) + $gap * (count($chips) - 1);
    $cx0 = $left ? $padX : (int) (($W - $sum) / 2);
    $cyTop = $H - 106;
    foreach ($chips as $i => $ch) {
        $w = $widths[$i];
        pb_rounded($im, $cx0, $cyTop, $cx0 + $w, $cyTop + 58, 29, array(255, 255, 255), $ch[1] ? 1.0 : 0.24);
        imagettftext($im, $cs, 0, $cx0 + 30, $cyTop + 40, $ch[1] ? $dark : $white, $fSemi, $ch[0]);
        $cx0 += $w + $gap;
    }

    if ($outRel === null) {
        $dir = 'upload/photos/' . gmdate('Y/m');
        if (!is_dir(pb_root() . '/' . $dir)) {
            mkdir(pb_root() . '/' . $dir, 0755, true);
        }
        $outRel = $dir . '/promo_' . gmdate('Ymd') . '_' . $o['kind'] . '_' . bin2hex(random_bytes(6)) . '.jpg';
    }
    $ok = imagejpeg($im, (strpos($outRel, '/') === 0 ? $outRel : pb_root() . '/' . $outRel), 90);
    imagedestroy($im);
    return $ok ? $outRel : false;
}

/* ------------------------------------------------------------------ posts */

/** Store text the way Wo_RegisterPost does: escaped, <br> line breaks, [a] links, #[id] hashtags. */
function pb_format_text($text) {
    $t = htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8');
    $t = preg_replace_callback('/(https?:\/\/[^\s<]+)/i', function ($m) {
        return '[a]' . urlencode($m[1]) . '[/a]';
    }, $t);
    if (function_exists('Wo_GetHashtag')) {
        $t = preg_replace_callback('/#([A-Za-z0-9_]+)/', function ($m) {
            $tag = mb_strtolower($m[1], 'UTF-8');
            if (is_numeric($tag)) {
                return $m[0];
            }
            $h = Wo_GetHashtag($tag);
            return is_array($h) && !empty($h['id']) ? '#[' . intval($h['id']) . ']' : '#' . $tag;
        }, $t);
    }
    return str_replace(array("\r\n", "\n", "\r"), ' <br>', $t);
}

function pb_insert_post($userId, $text, $image) {
    global $sqlConnect;
    $userId = intval($userId);
    $t = mysqli_real_escape_string($sqlConnect, pb_format_text($text));
    $f = $image ? mysqli_real_escape_string($sqlConnect, $image) : '';
    $fn = $image ? 'postFile_image' : '';
    $now = time();
    $reg = date('n') . '/' . date('Y');
    $ok = mysqli_query($sqlConnect, "INSERT INTO " . T_POSTS . " (`user_id`, `postText`, `postFile`, `postFileName`, `postPrivacy`, `postType`, `time`, `registered`, `active`)
        VALUES ({$userId}, '{$t}', '{$f}', '{$fn}', '0', 'post', {$now}, '{$reg}', 1)");
    if (!$ok) {
        return false;
    }
    $id = mysqli_insert_id($sqlConnect);
    mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `post_id` = {$id} WHERE `id` = {$id}");
    if (class_exists('BitchatCache')) {
        BitchatCache::delete('trending');
    }
    if (function_exists('Wo_TrackPostSpam')) {
        Wo_TrackPostSpam($userId, $text, '');
    }
    return $id;
}

/** Which slot (if any) is due now and not yet posted today. */
function pb_due_slot($bot, $db, $now = null) {
    $now = $now ?: time();
    $date = gmdate('Y-m-d', $now);
    foreach (unserialize(PB_SLOTS) as $slot => $hm) {
        $at = strtotime($date . ' ' . $hm . ':00 UTC');
        if ($now < $at || $now > $at + PB_SLOT_GRACE) {
            continue;
        }
        $db->where('bot_id', $bot->id);
        $db->where('article_hash', md5('promo:' . $date . ':' . $slot));
        if (!$db->getOne('Wo_Bot_Posted')) {
            return $slot;
        }
    }
    return null;
}

/** Entry point from bc_run_single_bot(). Posts at most one slot per run. */
function bc_run_promo_bot($bot, $db, $sqlConnect, $wo, $postsToday) {
    $slot = pb_due_slot($bot, $db);
    if (!$slot) {
        return 0;
    }
    $now = time();
    $day = intdiv($now, 86400);
    $built = pb_build($bot, $slot, $day);
    if (!$built) {
        return 0;
    }
    list($text, $banner) = $built;
    $image = '';
    if ($banner && $bot->include_thumbnail) {
        $image = pb_banner($banner) ?: '';
    }
    $postId = pb_insert_post($bot->user_id, $text, $image);
    if (!$postId) {
        return 0;
    }
    $db->insert('Wo_Bot_Posted', array(
        'bot_id'       => $bot->id,
        'article_hash' => md5('promo:' . gmdate('Y-m-d', $now) . ':' . $slot),
        'post_id'      => $postId,
    ));
    $db->where('id', $bot->id);
    $db->update('Wo_Bot_Accounts', array(
        'last_posted_at'   => $now,
        'posts_today'      => $postsToday + 1,
        'posts_today_date' => date('Y-m-d'),
    ));
    return 1;
}
