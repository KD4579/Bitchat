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
$sql = "SELECT p.user_id,
            (SELECT COUNT(*) FROM " . T_REACTIONS . " r2 JOIN " . T_POSTS . " p2 ON r2.post_id = p2.id WHERE p2.user_id = p.user_id) AS total_reactions,
            (SELECT COUNT(*) FROM " . T_COMMENTS . " c2 JOIN " . T_POSTS . " p3 ON c2.post_id = p3.id WHERE p3.user_id = p.user_id) AS total_comments
        FROM " . T_POSTS . " p
        JOIN " . T_USERS . " u ON p.user_id = u.user_id
        WHERE u.active = '1' AND u.admin = '0' AND u.src != 'Fake'
        GROUP BY p.user_id
        ORDER BY (total_reactions + total_comments) DESC
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

$wo['content'] = Wo_LoadPage('leaderboard/content');
