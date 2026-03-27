<?php
/**
 * Bitchat Public Ecosystem Stats API
 * Called by Tradex24 to display social proof on the TRDC trading pair page.
 *
 * Public endpoint — no auth required.
 * Rate limited: 60 req/min per IP.
 * Redis cached: 60s TTL.
 *
 * GET /api/ecosystem/stats.php
 * Response: JSON
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');          // Public — Tradex24 fetches from their frontend
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=60');
header_remove('Server');

require_once('../../assets/init.php');

// ── Rate limit: 60 req/min per IP ──────────────────────────────────────────
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

$ip        = get_client_ip();
$rl_key    = 'ecosystem_stats_rl:' . md5($ip);
$cache_key = 'ecosystem_stats_v1';

// Try Redis
$redis = null;
try {
    if (class_exists('Redis')) {
        $redis = new Redis();
        if (!@$redis->connect('127.0.0.1', 6379, 1)) {
            $redis = null;
        } else {
            $redis->select(2); // data cache DB
        }
    }
} catch (Exception $e) {
    $redis = null;
}

// Rate limit check
if ($redis) {
    $redis->select(1); // sessions DB for rate limiting
    $hits = $redis->incr($rl_key);
    if ($hits === 1) {
        $redis->expire($rl_key, 60);
    }
    if ($hits > 60) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests', 'retry_after' => 60]);
        exit();
    }
    $redis->select(2); // back to data cache

    // Return cached response if available
    $cached = $redis->get($cache_key);
    if ($cached) {
        echo $cached;
        exit();
    }
}

// ── Build stats ────────────────────────────────────────────────────────────
$stats = [
    'total_users'             => 0,
    'trdc_distributed_week'   => 0,
    'trdc_distributed_total'  => 0,
    'active_users_today'      => 0,
    'active_users_week'       => 0,
    'top_earner_week'         => null,
    'trdc_contract'           => '0x39006641dB2d9C3618523a1778974c0D7e98e39d',
    'trdc_network'            => 'BSC',
    'platform'                => 'Bitchat',
    'generated_at'            => time(),
];

try {
    // Total registered users
    $r = mysqli_query($sqlConnect, "SELECT COUNT(*) AS cnt FROM " . T_USERS . " WHERE `activated` = '1'");
    if ($r && $row = mysqli_fetch_assoc($r)) {
        $stats['total_users'] = intval($row['cnt']);
    }

    // TRDC distributed this week
    $week_start = time() - (7 * 86400);
    if (defined('T_TRDC_REWARDS')) {
        $r = mysqli_query($sqlConnect,
            "SELECT COALESCE(SUM(amount), 0) AS total FROM " . T_TRDC_REWARDS .
            " WHERE created_at >= {$week_start}"
        );
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $stats['trdc_distributed_week'] = round(floatval($row['total']), 4);
        }

        // TRDC distributed all time
        $r = mysqli_query($sqlConnect,
            "SELECT COALESCE(SUM(amount), 0) AS total FROM " . T_TRDC_REWARDS
        );
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $stats['trdc_distributed_total'] = round(floatval($row['total']), 4);
        }

        // Top earner this week (opt-in: show_on_leaderboard = 1)
        $r = mysqli_query($sqlConnect,
            "SELECT u.username, u.first_name, u.last_name, SUM(tr.amount) AS earned
             FROM " . T_TRDC_REWARDS . " tr
             JOIN " . T_USERS . " u ON u.user_id = tr.user_id
             WHERE tr.created_at >= {$week_start}
               AND (u.show_on_leaderboard = 1 OR u.show_on_leaderboard IS NULL)
             GROUP BY tr.user_id
             ORDER BY earned DESC
             LIMIT 1"
        );
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $stats['top_earner_week'] = [
                'username' => htmlspecialchars($row['username'], ENT_QUOTES),
                'earned'   => round(floatval($row['earned']), 4),
            ];
        }
    }

    // Active users today (posted, liked, or commented in last 24h)
    $day_start = time() - 86400;
    $r = mysqli_query($sqlConnect,
        "SELECT COUNT(DISTINCT user_id) AS cnt FROM " . T_POSTS .
        " WHERE `time` >= {$day_start} AND `active` = '1'"
    );
    if ($r && $row = mysqli_fetch_assoc($r)) {
        $stats['active_users_today'] = intval($row['cnt']);
    }

    // Active users this week
    $r = mysqli_query($sqlConnect,
        "SELECT COUNT(DISTINCT user_id) AS cnt FROM " . T_POSTS .
        " WHERE `time` >= {$week_start} AND `active` = '1'"
    );
    if ($r && $row = mysqli_fetch_assoc($r)) {
        $stats['active_users_week'] = intval($row['cnt']);
    }

} catch (Exception $e) {
    // Return partial stats — never expose DB errors publicly
}

$json = json_encode($stats);

// Cache in Redis for 60 seconds
if ($redis) {
    $redis->setex($cache_key, 60, $json);
}

echo $json;
exit();
