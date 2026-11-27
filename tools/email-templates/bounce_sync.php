<?php
/**
 * Feed exim delivery results back into Bitchat (root cron, every 5 min):
 *   0-59/5 * * * * cd /home/KamalDave/web/bitchat.live/public_html && /usr/bin/php tools/email-templates/bounce_sync.php >/dev/null 2>&1
 *
 * - Hard bounce for a non-existent mailbox  -> Wo_Mail_Suppression (never emailed again)
 * - Any permanent failure of a campaign email -> queue row 'bounced' (counts toward auto-pause)
 * - Temporary deferral (e.g. Gmail 421 rate limiting) -> queue row deferrals++ (auto-pause signal)
 *
 * Reads /var/log/exim4/mainlog incrementally (offset + inode state, survives rotation).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__, 2));
require 'config.php';
$db = new mysqli($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
$db->set_charset('utf8mb4');

$log   = '/var/log/exim4/mainlog';
$state = '/root/.bitchat_bounce_sync.json';
$st    = is_file($state) ? json_decode(file_get_contents($state), true) : array();
$st    = is_array($st) ? $st : array();
$st += array('inode' => 0, 'offset' => 0, 'campaign_ids' => array());

$files = array();
$inode = @fileinode($log);
if ($st['inode'] && $st['inode'] !== $inode && is_file($log . '.1') && @fileinode($log . '.1') === $st['inode']) {
    $files[] = array($log . '.1', $st['offset']);   // finish the rotated file first
    $files[] = array($log, 0);
} else {
    $offset = ($st['inode'] === $inode && $st['offset'] <= @filesize($log)) ? $st['offset'] : 0;
    $files[] = array($log, $offset);
}

$campaign_ids = $st['campaign_ids'];   // exim message ids sent by the campaign sender => time
$from = 'hello@bitchat.social';
$r = $db->query("SELECT value FROM Wo_Config WHERE name = 'campaign_from_email'");
if ($r && ($row = $r->fetch_row()) && !empty($row[0])) {
    $from = strtolower($row[0]);
}

$hard = $soft = $policy = 0;
$is_mailbox_gone = function ($msg) {
    return (bool) preg_match('/\b5\.1\.[0-9]\b|\b5\.2\.1\b|user unknown|no such user|does not exist|unknown user|mailbox unavailable|invalid recipient|recipient address rejected|address rejected|mailbox not found|account.*disabled|unrouteable address|all relevant MX records point to non-existent hosts|no mx|Domain .* not found/i', $msg);
};

foreach ($files as $f) {
    list($path, $offset) = $f;
    $fh = @fopen($path, 'r');
    if (!$fh) {
        continue;
    }
    fseek($fh, $offset);
    while (($line = fgets($fh)) !== false) {
        // 2026-11-27 10:00:00 1xABCD-000000-xxxx <= hello@bitchat.social U=...
        if (!preg_match('/^\S+ \S+ (\S+) (<=|\*\*|==) (\S+)(.*)$/', $line, $m)) {
            continue;
        }
        list(, $msgid, $flag, $addr, $rest) = $m;
        $addr = strtolower(trim($addr, '<>'));
        if ($flag === '<=') {
            if ($addr === $from) {
                $campaign_ids[$msgid] = time();
            }
            continue;
        }
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $detail = mb_substr(trim(preg_replace('/^.*?: /', '', $rest)), 0, 240);
        $e = $db->real_escape_string($addr);
        $d = $db->real_escape_string($detail);
        if ($flag === '**') {
            if ($is_mailbox_gone($rest)) {
                $db->query("INSERT INTO Wo_Mail_Suppression (email, reason, detail, time) VALUES ('{$e}', 'bounce', '{$d}', " . time() . ")
                            ON DUPLICATE KEY UPDATE detail = VALUES(detail)");
                $hard++;
            } else {
                $policy++;
            }
            if (isset($campaign_ids[$msgid])) {
                $db->query("UPDATE Wo_Mail_Campaign_Queue SET status = 'bounced', error = '{$d}'
                            WHERE email = '{$e}' AND status = 'sent' ORDER BY sent_at DESC LIMIT 1");
            }
        } elseif ($flag === '==' && isset($campaign_ids[$msgid])
                  // Only real rate/reputation throttling counts; skip mailbox-full (4.2.2)
                  // and exim's local "retry time not reached" re-queue lines.
                  && strpos($rest, 'retry time not reached') === false
                  && preg_match('/\b421\b|\b4\.7\.\d+|rate limit|unusual rate|too many/i', $rest)) {
            $db->query("UPDATE Wo_Mail_Campaign_Queue SET deferrals = deferrals + 1, error = '{$d}'
                        WHERE email = '{$e}' AND status = 'sent' ORDER BY sent_at DESC LIMIT 1");
            $soft++;
        }
    }
    $st['offset'] = ftell($fh);
    $st['inode']  = @fileinode($path);
    fclose($fh);
}
if (end($files)[0] === $log) {
    $st['inode'] = $inode;
}
// keep 4 days of campaign message ids
$cut = time() - 4 * 86400;
$st['campaign_ids'] = array_filter($campaign_ids, function ($t) use ($cut) {
    return $t >= $cut;
});
file_put_contents($state, json_encode($st));
echo date('c') . " hard=$hard policy=$policy deferred=$soft tracked=" . count($st['campaign_ids']) . "\n";
