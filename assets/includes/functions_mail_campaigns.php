<?php
/**
 * Mail campaigns: throttled, warm-up aware bulk email for /admin-cp/send_email.
 *
 * - Audience is built with one SQL query and always excludes banned, fake,
 *   opted-out, suppressed (bounced / unsubscribed) and disposable addresses.
 * - Sending happens from cron-job.php in small, evenly spread batches with a
 *   domain warm-up schedule (bitchat.social registered 2026-11-25).
 * - Every email carries List-Unsubscribe (+ one-click), a plain-text part and
 *   a per-recipient signed unsubscribe link.
 * - Hard bounces / deferrals are fed back by tools/email-templates/bounce_sync.php
 *   (root cron, reads the exim log) and auto-pause a campaign when they spike.
 *
 * Tables: Wo_Mail_Campaigns, Wo_Mail_Campaign_Queue, Wo_Mail_Suppression
 * (created by tools/email-templates/migrate_campaigns.php).
 */

define('T_MAIL_CAMPAIGNS', 'Wo_Mail_Campaigns');
define('T_MAIL_QUEUE', 'Wo_Mail_Campaign_Queue');
define('T_MAIL_SUPPRESSION', 'Wo_Mail_Suppression');

/* ------------------------------------------------------------------ config */

function Wo_McConfig($key, $default = null) {
    global $wo;
    $defaults = array(
        'campaign_from_email'   => 'hello@bitchat.social',
        'campaign_from_name'    => 'Bitchat',
        'campaign_reply_to'     => 'support@bitchat.social',
        'campaign_hour_cap'     => 150,
        'campaign_daily_max'    => 3000,
        'campaign_window_start' => 8,   // server-local hour
        'campaign_window_end'   => 22,
        'campaign_warmup_start' => 0,   // unix day the first campaign email went out
    );
    if (isset($wo['config'][$key]) && $wo['config'][$key] !== '') {
        return $wo['config'][$key];
    }
    return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
}

function Wo_McSetConfig($key, $value) {
    global $sqlConnect, $wo;
    $k = mysqli_real_escape_string($sqlConnect, $key);
    $v = mysqli_real_escape_string($sqlConnect, (string) $value);
    $exists = mysqli_query($sqlConnect, "SELECT 1 FROM " . T_CONFIG . " WHERE `name` = '{$k}' LIMIT 1");
    if ($exists && mysqli_num_rows($exists)) {
        mysqli_query($sqlConnect, "UPDATE " . T_CONFIG . " SET `value` = '{$v}' WHERE `name` = '{$k}'");
    } else {
        mysqli_query($sqlConnect, "INSERT INTO " . T_CONFIG . " (`name`, `value`) VALUES ('{$k}', '{$v}')");
    }
    $wo['config'][$key] = (string) $value;
    if (class_exists('BitchatCache')) {
        BitchatCache::delete('site_config');
    }
}

function Wo_McTablesReady() {
    global $sqlConnect;
    static $ready = null;
    if ($ready === null) {
        $r = @mysqli_query($sqlConnect, "SHOW TABLES LIKE '" . T_MAIL_QUEUE . "'");
        $ready = ($r && mysqli_num_rows($r) > 0);
    }
    return $ready;
}

/* ---------------------------------------------------------------- audience */

function Wo_McDisposableDomains() {
    static $list = null;
    if ($list === null) {
        $list = include __DIR__ . '/disposable_domains.php';
        if (!is_array($list)) {
            $list = array();
        }
    }
    return $list;
}

function Wo_McAudiences() {
    return array(
        'google'    => 'Google sign-ups (verified Gmail) — best for warm-up',
        'clean'     => 'All real users (clean list)',
        'site'      => 'Email/password sign-ups only',
        'dormant'   => "Haven't logged in for 1+ year",
        'lapsed'    => 'Last seen 3–12 months ago',
        'recent'    => 'Active in the last 90 days',
        'selected'  => 'Selected users only',
    );
}

/**
 * WHERE clause (on Wo_Users alias u) for an audience. Always applies hygiene filters.
 */
function Wo_McAudienceWhere($audience, $selected_ids = array()) {
    global $sqlConnect;
    $domains = array_map(function ($d) use ($sqlConnect) {
        return "'" . mysqli_real_escape_string($sqlConnect, strtolower($d)) . "'";
    }, Wo_McDisposableDomains());
    $w = array(
        "u.email <> ''",
        "u.email LIKE '%_@_%._%'",
        "u.banned = '0'",
        "u.active = '1'",
        "u.src <> 'Fake'",
        "u.emailNotification = '1'",
        "NOT EXISTS (SELECT 1 FROM " . T_MAIL_SUPPRESSION . " s WHERE s.email = u.email)",
    );
    if (!empty($domains)) {
        $w[] = "LOWER(SUBSTRING_INDEX(u.email, '@', -1)) NOT IN (" . implode(',', $domains) . ")";
    }
    $now = time();
    switch ($audience) {
        case 'google':
            $w[] = "u.src = 'google'";
            break;
        case 'site':
            $w[] = "u.src <> 'google'";
            break;
        case 'dormant':
            $w[] = "u.lastseen < " . ($now - 365 * 86400);
            break;
        case 'lapsed':
            $w[] = "u.lastseen BETWEEN " . ($now - 365 * 86400) . " AND " . ($now - 90 * 86400);
            break;
        case 'recent':
            $w[] = "u.lastseen > " . ($now - 90 * 86400);
            break;
        case 'selected':
            $ids = array_filter(array_map('intval', (array) $selected_ids));
            $w[] = empty($ids) ? "0 = 1" : "u.user_id IN (" . implode(',', $ids) . ")";
            break;
        case 'clean':
        default:
            break;
    }
    return implode(' AND ', $w);
}

/**
 * Recipient count plus a breakdown of why people are excluded (for the admin UI).
 */
function Wo_McAudienceStats($audience, $selected_ids = array()) {
    global $sqlConnect;
    $where = Wo_McAudienceWhere($audience, $selected_ids);
    $r = mysqli_query($sqlConnect, "SELECT COUNT(*) c FROM " . T_USERS . " u WHERE {$where}");
    $count = $r ? intval(mysqli_fetch_assoc($r)['c']) : 0;

    $domains = array_map(function ($d) use ($sqlConnect) {
        return "'" . mysqli_real_escape_string($sqlConnect, strtolower($d)) . "'";
    }, Wo_McDisposableDomains());
    $disp = empty($domains) ? "0" : "LOWER(SUBSTRING_INDEX(email, '@', -1)) IN (" . implode(',', $domains) . ")";
    $q = "SELECT COUNT(*) total,
            SUM({$disp}) disposable,
            SUM(src = 'Fake') fake,
            SUM(banned = '1') banned,
            SUM(emailNotification <> '1') opted_out,
            SUM(active <> '1') unconfirmed,
            (SELECT COUNT(*) FROM " . T_MAIL_SUPPRESSION . ") suppressed
          FROM " . T_USERS;
    $b = mysqli_query($sqlConnect, $q);
    $breakdown = $b ? array_map('intval', mysqli_fetch_assoc($b)) : array();
    return array('count' => $count, 'excluded' => $breakdown);
}

/* ----------------------------------------------------------------- tokens */

function Wo_McSecret() {
    $secret = Wo_McConfig('campaign_unsub_secret', '');
    if (empty($secret)) {
        $secret = bin2hex(random_bytes(32));
        Wo_McSetConfig('campaign_unsub_secret', $secret);
    }
    return $secret;
}

function Wo_McUnsubToken($user_id, $email) {
    return substr(hash_hmac('sha256', intval($user_id) . '|' . strtolower(trim($email)), Wo_McSecret()), 0, 32);
}

function Wo_McUnsubUrl($user_id, $email, $campaign_id = 0) {
    global $wo;
    return rtrim($wo['config']['site_url'], '/') . '/unsubscribe.php?u=' . intval($user_id)
        . '&c=' . intval($campaign_id) . '&t=' . Wo_McUnsubToken($user_id, $email);
}

function Wo_McSuppress($email, $reason, $detail = '') {
    global $sqlConnect;
    $e = mysqli_real_escape_string($sqlConnect, strtolower(trim($email)));
    $r = mysqli_real_escape_string($sqlConnect, $reason);
    $d = mysqli_real_escape_string($sqlConnect, mb_substr($detail, 0, 250));
    if ($e === '') {
        return false;
    }
    return mysqli_query($sqlConnect, "INSERT INTO " . T_MAIL_SUPPRESSION . " (`email`, `reason`, `detail`, `time`)
        VALUES ('{$e}', '{$r}', '{$d}', " . time() . ") ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`), `detail` = VALUES(`detail`)");
}

/* -------------------------------------------------------------- rendering */

function Wo_McFirstName($user) {
    $first = trim((string) ($user['first_name'] ?? ''));
    if ($first === '') {
        $first = trim((string) ($user['username'] ?? ''));
    }
    $first = preg_replace('/\s+.*/u', '', $first);
    return mb_convert_case($first, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Personalise a campaign for one recipient. Returns [subject, html, text, unsub_url].
 */
function Wo_McRender($campaign, $user) {
    global $wo;
    $unsub = Wo_McUnsubUrl($user['user_id'], $user['email'], $campaign['id'] ?? 0);
    $name  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $bonus = floatval($campaign['bonus_trdc'] ?? 0);
    $until = intval($campaign['bonus_until'] ?? 0);
    $vars = array(
        'FIRST_NAME'      => htmlspecialchars(Wo_McFirstName($user), ENT_QUOTES, 'UTF-8'),
        'NAME'            => htmlspecialchars($name !== '' ? $name : $user['username'], ENT_QUOTES, 'UTF-8'),
        'USERNAME'        => htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'),
        'EMAIL'           => htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'),
        'SITE_URL'        => rtrim($wo['config']['site_url'], '/'),
        'SITE_NAME'       => 'Bitchat',
        'UNSUBSCRIBE_URL' => htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8'),
        'BONUS'           => rtrim(rtrim(number_format($bonus, 2, '.', ','), '0'), '.'),
        'BONUS_UNTIL'     => $until ? date('j F Y', $until) : '',
        'YEAR'            => date('Y'),
    );
    $html = (string) $campaign['html'];
    // Optional bonus section: <!--BONUS-->...<!--/BONUS-->
    if ($bonus > 0 && $until > time()) {
        $html = str_replace(array('<!--BONUS-->', '<!--/BONUS-->'), '', $html);
    } else {
        $html = preg_replace('/<!--BONUS-->.*?<!--\/BONUS-->/s', '', $html);
    }
    $subject   = $campaign['subject'];
    $preheader = (string) ($campaign['preheader'] ?? '');
    foreach ($vars as $k => $v) {
        $html      = str_replace('{{' . $k . '}}', $v, $html);
        $plain     = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
        $subject   = str_replace('{{' . $k . '}}', $plain, $subject);
        $preheader = str_replace('{{' . $k . '}}', $plain, $preheader);
    }
    if (function_exists('Wo_ReplaceText')) {
        $html = Wo_ReplaceText($html); // {{LANG key}} support
    }
    // Every bulk email must offer an unsubscribe link
    if (strpos($html, $vars['UNSUBSCRIBE_URL']) === false) {
        $html .= '<p style="text-align:center;font-family:Arial,sans-serif;font-size:12px;color:#8a85a0;margin:16px 0;">'
            . 'Don\'t want these emails? <a href="' . $vars['UNSUBSCRIBE_URL'] . '" style="color:#8a85a0;">Unsubscribe</a></p>';
    }
    $text = Wo_HtmlToPlainText($html) . "\n\n--\nUnsubscribe: " . $unsub;
    if ($preheader !== '') {
        $html = '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
            . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . str_repeat('&#847;&zwnj;&nbsp;', 40) . '</div>' . $html;
    }
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;padding:0;background-color:#f1ecfa;">' . $html . '</body></html>';
    return array($subject, $html, $text, $unsub);
}

/**
 * Build the Wo_SendMessage payload for one recipient.
 */
function Wo_McMessage($campaign, $user, $subject_prefix = '') {
    global $wo;
    list($subject, $html, $text, $unsub) = Wo_McRender($campaign, $user);
    $from = Wo_McConfig('campaign_from_email');
    $msg = array(
        'from_email'   => $from,
        'from_name'    => Wo_McConfig('campaign_from_name'),
        'to_email'     => $user['email'],
        'to_name'      => html_entity_decode(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'], ENT_QUOTES, 'UTF-8'),
        'subject'      => $subject_prefix . $subject,
        'charSet'      => 'utf-8',
        'message_body' => $html,
        'alt_body'     => $text,
        'is_html'      => true,
        'reply-to'     => Wo_McConfig('campaign_reply_to'),
        'return'       => 'error',
        'headers'      => array(
            'List-Unsubscribe'      => '<' . $unsub . '>, <mailto:' . Wo_McConfig('campaign_reply_to') . '?subject=unsubscribe>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'Feedback-ID'           => 'c' . intval($campaign['id'] ?? 0) . ':campaign:bitchat',
            'Precedence'            => 'bulk',
        ),
    );
    $pass = Wo_McConfig('campaign_smtp_password', '');
    if (!empty($pass) && strtolower($from) !== strtolower($wo['config']['smtp_username'])) {
        $msg['smtp_username'] = $from;
        $msg['smtp_password'] = openssl_decrypt($pass, "AES-128-ECB", "mysecretkey1234");
    }
    return $msg;
}

/* ------------------------------------------------------------- campaigns */

function Wo_McGetCampaign($id) {
    global $sqlConnect;
    $id = intval($id);
    $r = mysqli_query($sqlConnect, "SELECT * FROM " . T_MAIL_CAMPAIGNS . " WHERE id = {$id} LIMIT 1");
    return ($r && mysqli_num_rows($r)) ? mysqli_fetch_assoc($r) : null;
}

/**
 * Create a campaign and enqueue its recipients (one INSERT … SELECT).
 * Most recently active users first, Google sign-ups first: engaged
 * recipients early build sender reputation for the rest.
 */
function Wo_McCreateCampaign($data) {
    global $sqlConnect, $wo;
    $subject   = mysqli_real_escape_string($sqlConnect, mb_substr(trim($data['subject']), 0, 250));
    $preheader = mysqli_real_escape_string($sqlConnect, mb_substr(trim($data['preheader'] ?? ''), 0, 250));
    $html      = mysqli_real_escape_string($sqlConnect, $data['html']);
    $audience  = array_key_exists($data['audience'], Wo_McAudiences()) ? $data['audience'] : 'clean';
    $bonus     = max(0, floatval($data['bonus_trdc'] ?? 0));
    $until     = intval($data['bonus_until'] ?? 0);
    $template  = mysqli_real_escape_string($sqlConnect, preg_replace('/[^a-z0-9_\-]/i', '', $data['template'] ?? ''));
    $by        = intval($wo['user']['user_id'] ?? 0);
    $now       = time();
    $ok = mysqli_query($sqlConnect, "INSERT INTO " . T_MAIL_CAMPAIGNS . "
        (`subject`, `preheader`, `html`, `template`, `audience`, `status`, `bonus_trdc`, `bonus_until`, `created_by`, `created`)
        VALUES ('{$subject}', '{$preheader}', '{$html}', '{$template}', '{$audience}', 'sending', {$bonus}, {$until}, {$by}, {$now})");
    if (!$ok) {
        return array('error' => mysqli_error($sqlConnect));
    }
    $cid   = mysqli_insert_id($sqlConnect);
    $where = Wo_McAudienceWhere($audience, $data['selected_ids'] ?? array());
    mysqli_query($sqlConnect, "INSERT IGNORE INTO " . T_MAIL_QUEUE . " (`campaign_id`, `user_id`, `email`, `priority`, `status`)
        SELECT {$cid}, u.user_id, LOWER(TRIM(u.email)),
               (CASE WHEN u.src = 'google' THEN 1000000000 ELSE 0 END) + u.lastseen, 'pending'
        FROM " . T_USERS . " u WHERE {$where}");
    $total = mysqli_affected_rows($sqlConnect);
    // Never email the same address twice within one campaign (duplicate accounts)
    mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " q JOIN (
            SELECT email, MAX(priority) keep_p FROM " . T_MAIL_QUEUE . " WHERE campaign_id = {$cid} GROUP BY email HAVING COUNT(*) > 1
        ) d ON d.email = q.email SET q.status = 'skipped', q.error = 'duplicate address'
        WHERE q.campaign_id = {$cid} AND q.priority < d.keep_p");
    mysqli_query($sqlConnect, "UPDATE " . T_MAIL_CAMPAIGNS . " SET `total` = " . intval($total) . " WHERE id = {$cid}");
    return array('id' => $cid, 'total' => $total);
}

function Wo_McSetStatus($id, $status, $reason = '') {
    global $sqlConnect;
    $allowed = array('sending', 'paused', 'done', 'cancelled');
    if (!in_array($status, $allowed)) {
        return false;
    }
    $id = intval($id);
    $r  = mysqli_real_escape_string($sqlConnect, mb_substr($reason, 0, 250));
    return mysqli_query($sqlConnect, "UPDATE " . T_MAIL_CAMPAIGNS . " SET `status` = '{$status}', `status_reason` = '{$r}' WHERE id = {$id}");
}

function Wo_McCampaignStats($id) {
    global $sqlConnect;
    $id = intval($id);
    $r = mysqli_query($sqlConnect, "SELECT status, COUNT(*) n FROM " . T_MAIL_QUEUE . " WHERE campaign_id = {$id} GROUP BY status");
    $s = array('pending' => 0, 'sent' => 0, 'failed' => 0, 'bounced' => 0, 'skipped' => 0);
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $s[$row['status']] = intval($row['n']);
    }
    $x = mysqli_fetch_assoc(mysqli_query($sqlConnect, "SELECT
            SUM(returned_at > 0) returned, SUM(deferrals > 0) deferred, SUM(bonus_paid = 1) bonus_paid
        FROM " . T_MAIL_QUEUE . " WHERE campaign_id = {$id}"));
    $s['returned']   = intval($x['returned']);
    $s['deferred']   = intval($x['deferred']);
    $s['bonus_paid'] = intval($x['bonus_paid']);
    $e = mysqli_query($sqlConnect, "SELECT email, status, error, sent_at FROM " . T_MAIL_QUEUE . "
        WHERE campaign_id = {$id} AND error <> '' AND status <> 'skipped' ORDER BY sent_at DESC LIMIT 20");
    $s['errors'] = array();
    while ($e && ($row = mysqli_fetch_assoc($e))) {
        $s['errors'][] = $row;
    }
    return $s;
}

/* ---------------------------------------------------------------- sending */

/**
 * Domain warm-up: daily cap by day index since the first campaign email.
 */
function Wo_McDailyCap() {
    $start = intval(Wo_McConfig('campaign_warmup_start'));
    $day   = $start > 0 ? max(0, intval(floor((strtotime('today') - $start) / 86400))) : 0;
    $ramp  = array(50, 100, 200, 350, 500, 750, 1000);
    $cap   = $day < count($ramp) ? $ramp[$day] : intval(round(1000 * pow(1.25, $day - count($ramp) + 1)));
    return min($cap, intval(Wo_McConfig('campaign_daily_max')));
}

function Wo_McSentSince($since) {
    global $sqlConnect;
    $r = mysqli_query($sqlConnect, "SELECT COUNT(*) c FROM " . T_MAIL_QUEUE . " WHERE status IN ('sent','bounced') AND sent_at >= " . intval($since));
    return $r ? intval(mysqli_fetch_assoc($r)['c']) : 0;
}

/**
 * How many emails this cron tick (every 5 min) may send: respects the
 * sending window, the hourly cap and spreads the daily cap evenly.
 */
function Wo_McBatchAllowance() {
    $hour  = intval(date('G'));
    $start = intval(Wo_McConfig('campaign_window_start'));
    $end   = intval(Wo_McConfig('campaign_window_end'));
    if ($hour < $start || $hour >= $end) {
        return 0;
    }
    $daily      = Wo_McDailyCap();
    $left_today = $daily - Wo_McSentSince(strtotime('today'));
    $left_hour  = intval(Wo_McConfig('campaign_hour_cap')) - Wo_McSentSince(time() - 3600);
    if ($left_today <= 0 || $left_hour <= 0) {
        return 0;
    }
    $window_end = strtotime('today') + $end * 3600;
    $ticks_left = max(1, intval(ceil(($window_end - time()) / 300)));
    $per_tick   = intval(ceil($left_today / $ticks_left));
    return max(0, min($per_tick, $left_today, $left_hour, 60));
}

/**
 * Auto-pause on bad signals from the last 100 sends (bounce data comes from bounce_sync.php).
 */
function Wo_McHealthCheck($cid) {
    global $sqlConnect;
    $cid = intval($cid);
    $r = mysqli_fetch_assoc(mysqli_query($sqlConnect, "SELECT COUNT(*) n, SUM(status = 'bounced') b, SUM(status = 'failed') f, SUM(deferrals > 0) d
        FROM (SELECT status, deferrals FROM " . T_MAIL_QUEUE . " WHERE campaign_id = {$cid} AND status IN ('sent','bounced','failed')
              ORDER BY sent_at DESC LIMIT 100) t"));
    $n = intval($r['n']);
    $bad = intval($r['b']) + intval($r['f']);
    if ($n < 50) {
        // Small sample: one stray bounce is noise; only stop on a clear problem.
        if ($bad >= 3) {
            Wo_McSetStatus($cid, 'paused', sprintf('Auto-paused: %d bounces/failures in the first %d emails', $bad, $n));
            return false;
        }
        return true;
    }
    $bounce = $bad / $n;
    $defer  = intval($r['d']) / $n;
    if ($bounce > 0.02 && $bad >= 3) {
        Wo_McSetStatus($cid, 'paused', sprintf('Auto-paused: %.1f%% bounces/failures in the last %d emails', $bounce * 100, $n));
        return false;
    }
    if ($defer > 0.05 && intval($r['d']) >= 3) {
        Wo_McSetStatus($cid, 'paused', sprintf('Auto-paused: %.1f%% delayed by the receiving server (rate-limited) in the last %d emails', $defer * 100, $n));
        return false;
    }
    return true;
}

/**
 * Cron entry point. Returns number of emails handed to the mail server.
 */
function Wo_McCronTick() {
    global $sqlConnect;
    if (!Wo_McTablesReady()) {
        return 0;
    }
    $c = mysqli_query($sqlConnect, "SELECT * FROM " . T_MAIL_CAMPAIGNS . " WHERE status = 'sending' ORDER BY id ASC LIMIT 1");
    if (!$c || !mysqli_num_rows($c)) {
        return 0;
    }
    $campaign = mysqli_fetch_assoc($c);
    $cid      = intval($campaign['id']);
    if (!Wo_McHealthCheck($cid)) {
        return 0;
    }
    $allow = Wo_McBatchAllowance();
    if ($allow <= 0) {
        return 0;
    }
    $q = mysqli_query($sqlConnect, "SELECT q.id qid, u.user_id, u.username, u.first_name, u.last_name, q.email,
               u.banned, u.emailNotification
        FROM " . T_MAIL_QUEUE . " q JOIN " . T_USERS . " u ON u.user_id = q.user_id
        WHERE q.campaign_id = {$cid} AND q.status = 'pending'
        ORDER BY q.priority DESC LIMIT {$allow}");
    if (!$q || !mysqli_num_rows($q)) {
        $pending = mysqli_fetch_assoc(mysqli_query($sqlConnect, "SELECT COUNT(*) c FROM " . T_MAIL_QUEUE . " WHERE campaign_id = {$cid} AND status = 'pending'"));
        if (intval($pending['c']) === 0) {
            Wo_McSetStatus($cid, 'done', 'All emails sent');
        }
        return 0;
    }
    if (intval(Wo_McConfig('campaign_warmup_start')) === 0) {
        Wo_McSetConfig('campaign_warmup_start', strtotime('today'));
    }
    $sent = 0;
    while ($row = mysqli_fetch_assoc($q)) {
        $qid = intval($row['qid']);
        // Re-check at send time: user may have opted out / been banned / bounced since enqueueing
        $sup = mysqli_query($sqlConnect, "SELECT 1 FROM " . T_MAIL_SUPPRESSION . " WHERE email = '" . mysqli_real_escape_string($sqlConnect, $row['email']) . "' LIMIT 1");
        if ($row['banned'] == '1' || $row['emailNotification'] != '1' || ($sup && mysqli_num_rows($sup))) {
            mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " SET status = 'skipped', error = 'opted out or suppressed' WHERE id = {$qid}");
            continue;
        }
        // Claim the row first so an overlapping run can never send it twice
        mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " SET status = 'sending', sent_at = " . time() . " WHERE id = {$qid} AND status = 'pending'");
        if (mysqli_affected_rows($sqlConnect) !== 1) {
            continue;
        }
        $result = Wo_SendMessage(Wo_McMessage($campaign, $row));
        if ($result === true) {
            mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " SET status = 'sent', sent_at = " . time() . " WHERE id = {$qid}");
            $sent++;
        } else {
            $err = mysqli_real_escape_string($sqlConnect, mb_substr(is_string($result) ? $result : 'send failed', 0, 250));
            mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " SET status = 'failed', error = '{$err}', sent_at = " . time() . " WHERE id = {$qid}");
        }
        usleep(400000); // ~2.5 msg/s max, gentle on the local MTA
    }
    mysqli_query($sqlConnect, "UPDATE " . T_MAIL_CAMPAIGNS . " c SET
        c.sent = (SELECT COUNT(*) FROM " . T_MAIL_QUEUE . " WHERE campaign_id = {$cid} AND status IN ('sent','bounced')),
        c.failed = (SELECT COUNT(*) FROM " . T_MAIL_QUEUE . " WHERE campaign_id = {$cid} AND status IN ('failed','bounced'))
        WHERE c.id = {$cid}");
    return $sent;
}

/* ------------------------------------------------------- welcome-back bonus */

/**
 * Once per session: if this user received a campaign email, mark them as
 * returned and pay the campaign's TRDC bonus (before its deadline).
 * Wo_AwardTRDC dedups on (user_id, milestone_type, post_id) — post_id is
 * the campaign id so the unique key is never NULL.
 */
function Wo_MaybeAwardWelcomeBack() {
    global $wo, $sqlConnect;
    if (empty($wo['loggedin']) || empty($wo['user']['user_id']) || !Wo_McTablesReady()) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['mc_wb_checked'])) {
            return;
        }
        $_SESSION['mc_wb_checked'] = 1;
    }
    $uid = intval($wo['user']['user_id']);
    $r = mysqli_query($sqlConnect, "SELECT q.id, q.campaign_id, q.sent_at, c.bonus_trdc, c.bonus_until
        FROM " . T_MAIL_QUEUE . " q JOIN " . T_MAIL_CAMPAIGNS . " c ON c.id = q.campaign_id
        WHERE q.user_id = {$uid} AND q.status IN ('sent','sending') AND (q.returned_at = 0 OR (q.bonus_paid = 0 AND c.bonus_trdc > 0))");
    if (!$r) {
        return;
    }
    $now = time();
    while ($row = mysqli_fetch_assoc($r)) {
        $qid = intval($row['id']);
        mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " SET returned_at = {$now} WHERE id = {$qid} AND returned_at = 0");
        $bonus = floatval($row['bonus_trdc']);
        if ($bonus > 0 && $now <= intval($row['bonus_until']) && function_exists('Wo_AwardTRDC')) {
            $cid = intval($row['campaign_id']);
            if (Wo_AwardTRDC($uid, $bonus, 'Welcome back to Bitchat!', 'welcome_back', $cid)) {
                mysqli_query($sqlConnect, "UPDATE " . T_MAIL_QUEUE . " SET bonus_paid = 1 WHERE id = {$qid}");
            }
        }
    }
}
