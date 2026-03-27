<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'leaderboard';
$wo['title']       = 'Leaderboard';

$wo['lb_top_creators'] = array();
$wo['lb_top_inviters'] = array();
$wo['lb_top_earners']  = array();

// Top Creators — by total engagement (reactions + comments on their posts)
// Use derived tables instead of correlated subqueries for performance
$sql = "SELECT p.user_id,
            COALESCE(rc.cnt, 0) AS total_reactions,
            COALESCE(cc.cnt, 0) AS total_comments
        FROM (SELECT user_id FROM " . T_POSTS . " GROUP BY user_id) p
        JOIN " . T_USERS . " u ON p.user_id = u.user_id
        LEFT JOIN (
            SELECT p2.user_id, COUNT(*) AS cnt
            FROM " . T_REACTIONS . " r2
            JOIN " . T_POSTS . " p2 ON r2.post_id = p2.id
            GROUP BY p2.user_id
        ) rc ON p.user_id = rc.user_id
        LEFT JOIN (
            SELECT p3.user_id, COUNT(*) AS cnt
            FROM " . T_COMMENTS . " c2
            JOIN " . T_POSTS . " p3 ON c2.post_id = p3.id
            GROUP BY p3.user_id
        ) cc ON p.user_id = cc.user_id
        WHERE u.active = '1' AND u.admin = '0' AND u.src != 'Fake'
        ORDER BY (COALESCE(rc.cnt, 0) + COALESCE(cc.cnt, 0)) DESC
        LIMIT 10";
$q = mysqli_query($sqlConnect, $sql);
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $userData = Wo_UserData($row['user_id']);
        if (!empty($userData)) {
            $wo['lb_top_creators'][] = array(
                'user' => $userData,
                'reactions' => intval($row['total_reactions']),
                'comments'  => intval($row['total_comments']),
                'engagement' => intval($row['total_reactions']) + intval($row['total_comments']),
            );
        }
    }
}

// Top Inviters — by referral count
$sql = "SELECT u2.referrer AS user_id, COUNT(*) AS ref_count
        FROM " . T_USERS . " u2
        JOIN " . T_USERS . " u3 ON u2.referrer = u3.user_id
        WHERE u2.referrer != '0' AND u2.referrer != '' AND u3.src != 'Fake'
        GROUP BY u2.referrer ORDER BY ref_count DESC LIMIT 10";
$q = mysqli_query($sqlConnect, $sql);
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $userData = Wo_UserData($row['user_id']);
        if (!empty($userData)) {
            $wo['lb_top_inviters'][] = array(
                'user' => $userData,
                'invited' => intval($row['ref_count']),
            );
        }
    }
}

// Top TRDC Earners — by wallet balance (excludes admins)
$sql = "SELECT user_id, wallet FROM " . T_USERS . "
        WHERE active = '1' AND admin = '0' AND src != 'Fake' AND wallet > 0
        ORDER BY wallet DESC LIMIT 10";
$q = mysqli_query($sqlConnect, $sql);
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $userData = Wo_UserData($row['user_id']);
        if (!empty($userData)) {
            $wo['lb_top_earners'][] = array(
                'user' => $userData,
                'balance' => floatval($row['wallet']),
            );
        }
    }
}

// Top TRDC Earners THIS WEEK — from rewards log
$wo['lb_top_earners_week'] = array();
$week_start = time() - (7 * 86400);
if (defined('T_TRDC_REWARDS')) {
    $sql = "SELECT tr.user_id, SUM(tr.amount) AS earned_week
            FROM " . T_TRDC_REWARDS . " tr
            JOIN " . T_USERS . " u ON u.user_id = tr.user_id
            WHERE tr.created_at >= {$week_start}
              AND u.active = '1' AND u.admin = '0' AND u.src != 'Fake'
            GROUP BY tr.user_id
            ORDER BY earned_week DESC
            LIMIT 10";
    $q = mysqli_query($sqlConnect, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $userData = Wo_UserData($row['user_id']);
            if (!empty($userData)) {
                $wo['lb_top_earners_week'][] = array(
                    'user'        => $userData,
                    'earned_week' => round(floatval($row['earned_week']), 4),
                );
            }
        }
    }
}

// Tradex24 referral link for logged-in user
$wo['tradex24_referral_url'] = 'https://tradex24.com/register?ref=bitchat&uid=' . intval($wo['user']['user_id'])
    . '&utm_source=bitchat&utm_medium=leaderboard&utm_campaign=ecosystem_referral';

$wo['content'] = Wo_LoadPage('leaderboard/content');
