<?php
/**
 * Error Log Viewer — Bitchat
 * Usage: https://bitchat.live/bc-errorlog.php?key=bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec
 * Last N lines: add &lines=200
 *
 * Delete or restrict this file after diagnosing issues.
 */
define('ACCESS_KEY', 'bitchat_webhook_secret_8e296a067a37563370ded05f5a3bf3ec');

if (($_GET['key'] ?? '') !== ACCESS_KEY) {
    http_response_code(403);
    echo '<h2>403 Forbidden</h2>';
    exit;
}

$lines_to_show = max(50, min(500, (int)($_GET['lines'] ?? 100)));

// Common PHP error log locations for HestiaCP / cPanel / generic Linux
$candidates = [
    ini_get('error_log'),
    __DIR__ . '/error_log',
    __DIR__ . '/../logs/error_log',
    __DIR__ . '/../logs/php_errors.log',
    '/home/KamalDave/logs/error_log',
    '/home/KamalDave/web/bitchat.live/log/error.log',
    '/var/log/php_errors.log',
    '/tmp/php_errors.log',
];

$log_file = null;
foreach ($candidates as $c) {
    if (!empty($c) && file_exists($c) && is_readable($c)) {
        $log_file = $c;
        break;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Bitchat Error Log</title>
<style>
  body { background:#0f0f0f; color:#d4d4d4; font-family:monospace; font-size:13px; margin:0; padding:16px; }
  h2 { color:#f97316; margin:0 0 8px; }
  .meta { color:#888; margin-bottom:16px; font-size:11px; }
  .log-box { background:#1a1a1a; border:1px solid #333; border-radius:6px; padding:12px; white-space:pre-wrap; word-break:break-all; max-height:85vh; overflow-y:auto; }
  .err  { color:#f87171; }
  .warn { color:#fbbf24; }
  .info { color:#86efac; }
  .notice { color:#93c5fd; }
  .line  { display:block; border-bottom:1px solid #1e1e1e; padding:2px 0; }
  .no-log { color:#f87171; padding:20px; }
  a { color:#f97316; }
  .actions { margin-bottom:12px; }
  .btn { display:inline-block; padding:6px 14px; background:#1e1e1e; border:1px solid #444; border-radius:4px; color:#d4d4d4; text-decoration:none; margin-right:8px; font-size:12px; }
  .btn:hover { background:#2a2a2a; }
</style>
</head>
<body>
<h2>Bitchat — PHP Error Log</h2>
<div class="meta">
  Log file: <strong><?php echo htmlspecialchars($log_file ?? 'Not found'); ?></strong>
  &nbsp;|&nbsp; Showing last <strong><?php echo $lines_to_show; ?></strong> lines
  &nbsp;|&nbsp; Server time: <strong><?php echo date('Y-m-d H:i:s T'); ?></strong>
</div>
<div class="actions">
  <a class="btn" href="?key=<?php echo ACCESS_KEY; ?>&lines=100">Last 100</a>
  <a class="btn" href="?key=<?php echo ACCESS_KEY; ?>&lines=200">Last 200</a>
  <a class="btn" href="?key=<?php echo ACCESS_KEY; ?>&lines=500">Last 500</a>
  <a class="btn" href="bc-opcache.php?key=<?php echo ACCESS_KEY; ?>" target="_blank">Flush OPcache</a>
</div>
<?php if (!$log_file): ?>
<div class="no-log">
  No readable error log found. Check PHP ini: <code>error_log</code> directive.<br><br>
  Tried:<br>
  <?php foreach ($candidates as $c) echo htmlspecialchars($c ?: '(empty ini_get)') . '<br>'; ?>
  <br>
  <strong>Tip:</strong> Add <code>error_log = /home/KamalDave/logs/php_errors.log</code> to your .user.ini or php.ini.
</div>
<?php else:
    // Read last N lines efficiently
    $all_lines = [];
    $fp = @fopen($log_file, 'r');
    if ($fp) {
        $buffer = '';
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $chunk = 8192;
        $collected = [];
        while ($pos > 0 && count($collected) < $lines_to_show) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $data = fread($fp, $read);
            $lines = explode("\n", $data . $buffer);
            $buffer = array_shift($lines);
            $collected = array_merge(array_reverse($lines), $collected);
        }
        fclose($fp);
        if (!empty($buffer)) array_unshift($collected, $buffer);
        $all_lines = array_slice($collected, -$lines_to_show);
    }
?>
<div class="log-box"><?php
    foreach ($all_lines as $line) {
        if (empty(trim($line))) continue;
        $safe = htmlspecialchars($line);
        if (stripos($line, 'fatal') !== false || stripos($line, 'error') !== false) {
            echo '<span class="line err">' . $safe . '</span>';
        } elseif (stripos($line, 'warning') !== false) {
            echo '<span class="line warn">' . $safe . '</span>';
        } elseif (stripos($line, 'notice') !== false || stripos($line, 'deprecated') !== false) {
            echo '<span class="line notice">' . $safe . '</span>';
        } else {
            echo '<span class="line">' . $safe . '</span>';
        }
    }
?></div>
<?php endif; ?>
</body>
</html>
