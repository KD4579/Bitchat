<?php
/**
 * Weekly Digest Email — sends a summary of the past week's activity to opted-in users.
 * Called from cron-job.php once per week.
 */

function Wo_SendWeeklyDigests() {
    global $wo, $sqlConnect, $db;

    if (empty($wo['config']['weekly_digest_enabled']) || $wo['config']['weekly_digest_enabled'] != '1') {
        return 0;
    }

    $weekAgo = time() - 604800;
    $sentCount = 0;
    $batchSize = 50;
    $offset = 0;

    while (true) {
        $users = $db->where('e_weekly_digest', 1)
                     ->where('active', '1')
                     ->where('email', '', '!=')
                     ->orderBy('user_id', 'ASC')
                     ->get(T_USERS, array($offset, $batchSize), 'user_id, username, email, first_name, last_name, avatar, wallet');

        if (empty($users)) break;

        foreach ($users as $user) {
            $userId = intval($user['user_id']);
            $userName = trim($user['first_name'] . ' ' . $user['last_name']);
            if (empty($userName)) $userName = $user['username'];

            $digest = Wo_BuildDigestForUser($userId, $weekAgo);
            if (empty($digest['has_content'])) continue;

            $html = Wo_BuildDigestEmailHtml($userName, $digest);

            $mailData = array(
                'from_email' => $wo['config']['siteEmail'],
                'from_name'  => $wo['config']['siteName'],
                'to_email'   => $user['email'],
                'to_name'    => $userName,
                'subject'    => 'Your Weekly Summary — ' . $wo['config']['siteName'],
                'charSet'    => 'UTF-8',
                'message_body' => $html
            );

            Wo_SendMessage($mailData);
            $sentCount++;

            // Small delay to avoid SMTP rate limits
            if ($sentCount % 10 == 0) {
                usleep(500000); // 0.5s per 10 emails
            }
        }

        $offset += $batchSize;
    }

    return $sentCount;
}

function Wo_BuildDigestForUser($userId, $sinceTime) {
    global $sqlConnect, $db;

    $digest = array(
        'has_content' => false,
        'new_followers' => 0,
        'post_reactions' => 0,
        'post_comments' => 0,
        'profile_views' => 0,
        'trending_posts' => array(),
        'trdc_earned' => 0
    );

    // Use notifications table for activity counts (has time column and tracks all types)
    $notifTable = T_NOTIFICATION;

    // New followers this week
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) AS cnt FROM {$notifTable} WHERE recipient_id = {$userId} AND type = 'following' AND time > {$sinceTime}");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $digest['new_followers'] = intval($r['cnt']);
    }

    // Reactions on user's posts this week
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) AS cnt FROM {$notifTable} WHERE recipient_id = {$userId} AND type IN ('liked_post','wondered_post','reaction') AND time > {$sinceTime}");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $digest['post_reactions'] = intval($r['cnt']);
    }

    // Comments on user's posts this week
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) AS cnt FROM {$notifTable} WHERE recipient_id = {$userId} AND type = 'comment' AND time > {$sinceTime}");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $digest['post_comments'] = intval($r['cnt']);
    }

    // Profile visits this week
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) AS cnt FROM {$notifTable} WHERE recipient_id = {$userId} AND type = 'visited_profile' AND time > {$sinceTime}");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $digest['profile_views'] = intval($r['cnt']);
    }

    // TRDC earned this week
    if (defined('T_TRDC_REWARDS')) {
        $q = mysqli_query($sqlConnect, "SELECT COALESCE(SUM(amount), 0) AS total FROM " . T_TRDC_REWARDS . " WHERE user_id = {$userId} AND created_at > {$sinceTime}");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            $digest['trdc_earned'] = floatval($r['total']);
        }
    }

    // Top trending posts this week (platform-wide, top 3) — use reaction count from Wo_Reactions table
    $q = mysqli_query($sqlConnect, "SELECT p.id, p.postText, p.user_id,
        (SELECT COUNT(*) FROM " . T_REACTIONS . " WHERE post_id = p.id) AS reactions,
        (SELECT COUNT(*) FROM " . T_COMMENTS . " WHERE post_id = p.id) AS comments
        FROM " . T_POSTS . " p
        WHERE p.time > {$sinceTime} AND p.postPrivacy = '0' AND p.postType NOT IN ('profile_picture','profile_cover_picture','profile_picture_deleted')
        ORDER BY (reactions + comments) DESC
        LIMIT 3");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $postUser = Wo_UserData($row['user_id']);
            $text = strip_tags($row['postText']);
            if (mb_strlen($text) > 100) $text = mb_substr($text, 0, 100) . '...';
            $digest['trending_posts'][] = array(
                'text' => $text,
                'author' => !empty($postUser['name']) ? $postUser['name'] : 'User',
                'reactions' => intval($row['reactions']),
                'comments' => intval($row['comments'])
            );
        }
    }

    // Has content if any stat is > 0
    if ($digest['new_followers'] > 0 || $digest['post_reactions'] > 0 || $digest['post_comments'] > 0 ||
        $digest['profile_views'] > 0 || $digest['trdc_earned'] > 0 || !empty($digest['trending_posts'])) {
        $digest['has_content'] = true;
    }

    return $digest;
}

function Wo_BuildDigestEmailHtml($userName, $digest) {
    global $wo;

    $siteUrl = $wo['config']['site_url'];
    $siteName = htmlspecialchars($wo['config']['siteName']);

    $stats = '';
    if ($digest['new_followers'] > 0) {
        $stats .= '<div style="display:inline-block;text-align:center;padding:10px 20px;"><div style="font-size:24px;font-weight:700;color:#3b82f6;">' . $digest['new_followers'] . '</div><div style="font-size:12px;color:#666;">New Followers</div></div>';
    }
    if ($digest['post_reactions'] > 0) {
        $stats .= '<div style="display:inline-block;text-align:center;padding:10px 20px;"><div style="font-size:24px;font-weight:700;color:#22c55e;">' . $digest['post_reactions'] . '</div><div style="font-size:12px;color:#666;">Reactions</div></div>';
    }
    if ($digest['post_comments'] > 0) {
        $stats .= '<div style="display:inline-block;text-align:center;padding:10px 20px;"><div style="font-size:24px;font-weight:700;color:#f59e0b;">' . $digest['post_comments'] . '</div><div style="font-size:12px;color:#666;">Comments</div></div>';
    }
    if ($digest['profile_views'] > 0) {
        $stats .= '<div style="display:inline-block;text-align:center;padding:10px 20px;"><div style="font-size:24px;font-weight:700;color:#8b5cf6;">' . $digest['profile_views'] . '</div><div style="font-size:12px;color:#666;">Profile Views</div></div>';
    }
    if ($digest['trdc_earned'] > 0) {
        $earned = ($digest['trdc_earned'] >= 1) ? number_format($digest['trdc_earned'], 2) : number_format($digest['trdc_earned'], 4);
        $stats .= '<div style="display:inline-block;text-align:center;padding:10px 20px;"><div style="font-size:24px;font-weight:700;color:#ec4899;">' . $earned . '</div><div style="font-size:12px;color:#666;">TRDC Earned</div></div>';
    }

    $trending = '';
    if (!empty($digest['trending_posts'])) {
        $trending = '<h3 style="font-size:16px;margin:20px 0 10px;color:#1a1a2e;">Trending This Week</h3>';
        foreach ($digest['trending_posts'] as $tp) {
            $trending .= '<div style="padding:10px;margin-bottom:8px;background:#f8f9fa;border-radius:8px;border-left:3px solid #3b82f6;">';
            $trending .= '<div style="font-size:13px;color:#333;">' . htmlspecialchars($tp['text']) . '</div>';
            $trending .= '<div style="font-size:11px;color:#888;margin-top:4px;">by ' . htmlspecialchars($tp['author']) . ' · ' . $tp['reactions'] . ' reactions · ' . $tp['comments'] . ' comments</div>';
            $trending .= '</div>';
        }
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';
    $html .= '<div style="max-width:560px;margin:20px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">';

    // Header
    $html .= '<div style="background:linear-gradient(135deg,#3b82f6,#8b5cf6);padding:24px;text-align:center;">';
    $html .= '<h1 style="color:#fff;margin:0;font-size:20px;">' . $siteName . '</h1>';
    $html .= '<p style="color:rgba(255,255,255,0.85);margin:6px 0 0;font-size:14px;">Your Weekly Summary</p>';
    $html .= '</div>';

    // Body
    $html .= '<div style="padding:24px;">';
    $html .= '<p style="font-size:15px;color:#333;">Hey ' . htmlspecialchars($userName) . ', here\'s what happened this week:</p>';

    if (!empty($stats)) {
        $html .= '<div style="text-align:center;padding:16px 0;border:1px solid #eee;border-radius:10px;margin:16px 0;">' . $stats . '</div>';
    }

    $html .= $trending;

    // CTA
    $html .= '<div style="text-align:center;margin:24px 0 8px;">';
    $html .= '<a href="' . $siteUrl . '" style="display:inline-block;background:#3b82f6;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Open ' . $siteName . '</a>';
    $html .= '</div>';

    $html .= '</div>'; // end body

    // Footer
    $html .= '<div style="padding:16px 24px;background:#f8f9fa;text-align:center;font-size:11px;color:#888;">';
    $html .= 'You received this because you have weekly digest enabled. ';
    $html .= '<a href="' . $siteUrl . '/setting/email" style="color:#3b82f6;">Unsubscribe</a>';
    $html .= '</div>';

    $html .= '</div></body></html>';

    return $html;
}
