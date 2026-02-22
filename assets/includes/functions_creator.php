<?php
// +------------------------------------------------------------------------+
// | Bitchat Creator Mode
// | Creator badge, feed boost, stats dashboard, story boost.
// | Toggle via Wo_Config 'creator_mode_enabled'
// +------------------------------------------------------------------------+

/**
 * Enable creator mode for a user.
 *
 * @param int $userId User ID
 * @return bool
 */
function Wo_EnableCreatorMode($userId) {
    global $sqlConnect;
    $userId = intval($userId);
    if ($userId <= 0) return false;

    $result = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET is_creator = 1 WHERE user_id = {$userId}");
    if ($result) {
        cache($userId, 'users', 'delete');
        return true;
    }
    return false;
}

/**
 * Disable creator mode for a user.
 *
 * @param int $userId User ID
 * @return bool
 */
function Wo_DisableCreatorMode($userId) {
    global $sqlConnect;
    $userId = intval($userId);
    if ($userId <= 0) return false;

    $result = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET is_creator = 0 WHERE user_id = {$userId}");
    if ($result) {
        cache($userId, 'users', 'delete');
        return true;
    }
    return false;
}

/**
 * Check if user is a creator.
 *
 * @param int|array $user User ID or user data array
 * @return bool
 */
function Wo_IsCreator($user) {
    if (is_array($user)) {
        return !empty($user['is_creator']) && $user['is_creator'] == 1;
    }
    $userData = Wo_UserData(intval($user));
    return !empty($userData['is_creator']) && $userData['is_creator'] == 1;
}

/**
 * Get creator statistics for dashboard.
 *
 * @param int $userId Creator user ID
 * @return array Stats array
 */
function Wo_GetCreatorStats($userId) {
    global $sqlConnect;
    $userId = intval($userId);

    $stats = array(
        'total_posts'     => 0,
        'total_reactions'  => 0,
        'total_comments'   => 0,
        'total_shares'     => 0,
        'total_followers'  => 0,
        'total_views'      => 0,
        'posts_this_week'  => 0,
        'reactions_this_week' => 0,
        'reach_score'      => 0,
        'invited_users'    => 0,
        'total_engagement' => 0,
    );

    // Total posts
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_POSTS . " WHERE user_id = {$userId} AND postType = 'post'");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['total_posts'] = intval($r['cnt']); }

    // Total reactions on user's posts
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_REACTIONS . " r JOIN " . T_POSTS . " p ON r.post_id = p.id WHERE p.user_id = {$userId}");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['total_reactions'] = intval($r['cnt']); }

    // Total comments on user's posts
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_COMMENTS . " c JOIN " . T_POSTS . " p ON c.post_id = p.id WHERE p.user_id = {$userId}");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['total_comments'] = intval($r['cnt']); }

    // Total shares
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_POSTS . " WHERE parent_id IN (SELECT id FROM " . T_POSTS . " WHERE user_id = {$userId}) AND postShare = 1");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['total_shares'] = intval($r['cnt']); }

    // Followers
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_FOLLOWERS . " WHERE following_id = {$userId} AND active = '1'");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['total_followers'] = intval($r['cnt']); }

    // Total views (reach)
    $q = mysqli_query($sqlConnect, "SELECT COALESCE(SUM(post_views), 0) as total FROM " . T_POSTS . " WHERE user_id = {$userId}");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['total_views'] = intval($r['total']); }

    // This week
    $weekAgo = time() - (7 * 86400);
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_POSTS . " WHERE user_id = {$userId} AND time > {$weekAgo}");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['posts_this_week'] = intval($r['cnt']); }

    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_REACTIONS . " r JOIN " . T_POSTS . " p ON r.post_id = p.id WHERE p.user_id = {$userId} AND p.time > {$weekAgo}");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['reactions_this_week'] = intval($r['cnt']); }

    // Total engagement (reactions + comments + shares)
    $stats['total_engagement'] = $stats['total_reactions'] + $stats['total_comments'] + $stats['total_shares'];

    // Invited users (referral count)
    $refCount = Wo_CountRefs($userId);
    $stats['invited_users'] = ($refCount !== false) ? intval($refCount) : 0;

    // Reach score: composite growth metric
    // Formula: followers*3 + engagement*2 + views/100 + invited*5 + posts
    $stats['reach_score'] = ($stats['total_followers'] * 3)
                          + ($stats['total_engagement'] * 2)
                          + intval($stats['total_views'] / 100)
                          + ($stats['invited_users'] * 5)
                          + $stats['total_posts'];

    return $stats;
}

/**
 * Check if user has an active (non-expired) story.
 * Used for story boost in feed scoring.
 *
 * @param int $userId User ID
 * @return bool
 */
function Wo_UserHasActiveStory($userId) {
    global $sqlConnect;
    $userId = intval($userId);
    $now = time();

    $storyTable = defined('T_USER_STORY') ? T_USER_STORY : 'Wo_UserStory';
    $q = mysqli_query($sqlConnect, "SELECT id FROM {$storyTable} WHERE user_id = {$userId} AND expire > {$now} LIMIT 1");

    return ($q && mysqli_num_rows($q) > 0);
}

/**
 * Get featured creators for sidebar widget.
 * Excludes current user and already-followed creators.
 * Orders by engagement (total reactions) for relevance.
 *
 * @param int $limit         Max creators to return
 * @param int $excludeUserId User ID to exclude (current user)
 * @return array Array of user data arrays
 */
function Wo_GetFeaturedCreators($limit = 5, $excludeUserId = 0) {
    global $sqlConnect;
    $limit = max(1, min(20, intval($limit)));
    $excludeUserId = intval($excludeUserId);

    $cacheKey = 'suggested_creators:' . $excludeUserId . ':' . $limit;
    if (class_exists('BitchatCache')) {
        $cached = BitchatCache::get($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    $usersTable     = T_USERS;
    $followersTable = T_FOLLOWERS;
    $reactionsTable = T_REACTIONS;
    $postsTable     = T_POSTS;

    // Exclude current user and users they already follow
    $excludeWhere = '';
    if ($excludeUserId > 0) {
        $excludeWhere = "AND u.user_id != {$excludeUserId}
            AND u.user_id NOT IN (
                SELECT following_id FROM {$followersTable}
                WHERE follower_id = {$excludeUserId} AND active = '1'
            )";
    }

    // Order by total reactions on their posts (engagement-based)
    $sql = "SELECT u.user_id,
                (SELECT COUNT(*) FROM {$reactionsTable} r
                 JOIN {$postsTable} p ON r.post_id = p.id
                 WHERE p.user_id = u.user_id) AS total_engagement
            FROM {$usersTable} u
            WHERE u.is_creator = 1
              AND u.active = '1'
              {$excludeWhere}
            ORDER BY total_engagement DESC, u.user_id DESC
            LIMIT {$limit}";

    $q = mysqli_query($sqlConnect, $sql);

    $creators = array();
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $userData = Wo_UserData($row['user_id']);
            if (!empty($userData)) {
                $creators[] = $userData;
            }
        }
    }

    if (class_exists('BitchatCache') && !empty($creators)) {
        BitchatCache::set($cacheKey, $creators, 300);
    }

    return $creators;
}

/**
 * Get creator's daily engagement for the last 7 days.
 * Returns array of 7 items with day label, reaction count, and comment count.
 *
 * @param int $userId Creator user ID
 * @return array [{day: 'Mon', reactions: N, comments: N}, ...]
 */
function Wo_GetCreatorWeeklyEngagement($userId) {
    global $sqlConnect;
    $userId = intval($userId);

    $reactionsTable = T_REACTIONS;
    $commentsTable  = T_COMMENTS;
    $postsTable     = T_POSTS;

    $days = array();
    for ($i = 6; $i >= 0; $i--) {
        $dayStart = strtotime("-{$i} days midnight");
        $dayEnd   = $dayStart + 86400;
        $dayLabel = date('D', $dayStart);

        // Reactions on this user's posts published this day (Wo_Reactions has no time column)
        $rSql = "SELECT COUNT(*) AS cnt FROM {$reactionsTable} r
                 JOIN {$postsTable} p ON r.post_id = p.id
                 WHERE p.user_id = {$userId} AND p.time >= {$dayStart} AND p.time < {$dayEnd}";
        $rResult = mysqli_query($sqlConnect, $rSql);
        $reactions = 0;
        if ($rResult) { $r = mysqli_fetch_assoc($rResult); $reactions = intval($r['cnt']); }

        // Comments on this user's posts for this day
        $cSql = "SELECT COUNT(*) AS cnt FROM {$commentsTable} c
                 JOIN {$postsTable} p ON c.post_id = p.id
                 WHERE p.user_id = {$userId} AND c.time >= {$dayStart} AND c.time < {$dayEnd}";
        $cResult = mysqli_query($sqlConnect, $cSql);
        $comments = 0;
        if ($cResult) { $c = mysqli_fetch_assoc($cResult); $comments = intval($c['cnt']); }

        $days[] = array(
            'day'       => $dayLabel,
            'reactions' => $reactions,
            'comments'  => $comments,
            'total'     => $reactions + $comments,
        );
    }

    return $days;
}

/**
 * Get creator rank badge based on engagement, activity, and referrals.
 *
 * @param array $stats Creator stats from Wo_GetCreatorStats()
 * @return array ['rank' => string, 'color' => string, 'icon' => string]
 */
function Wo_GetCreatorRank($stats) {
    $engagement = intval($stats['total_engagement'] ?? 0);
    $posts      = intval($stats['total_posts'] ?? 0);
    $invited    = intval($stats['invited_users'] ?? 0);
    $followers  = intval($stats['total_followers'] ?? 0);

    // Composite score for ranking
    $score = $engagement + ($posts * 2) + ($invited * 10) + ($followers * 3);

    if ($score >= 2000) {
        return array('rank' => 'Champion', 'color' => '#f59e0b', 'bg' => '#fef3c7');
    } elseif ($score >= 800) {
        return array('rank' => 'Influencer', 'color' => '#8b5cf6', 'bg' => '#ede9fe');
    } elseif ($score >= 200) {
        return array('rank' => 'Contributor', 'color' => '#3b82f6', 'bg' => '#dbeafe');
    } else {
        return array('rank' => 'Rising Star', 'color' => '#10b981', 'bg' => '#d1fae5');
    }
}

/**
 * Get creator rank for post display with per-request static cache.
 * Avoids repeated DB queries for the same user within a single page load.
 *
 * @param int $userId User ID
 * @return array|false Rank array or false if not a creator
 */
function Wo_GetCreatorRankForDisplay($userId) {
    static $cache = array();
    $userId = intval($userId);
    if ($userId <= 0) return false;

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    if (!function_exists('Wo_GetCreatorStats') || !function_exists('Wo_GetCreatorRank')) {
        return false;
    }

    $stats = Wo_GetCreatorStats($userId);
    $rank  = Wo_GetCreatorRank($stats);
    $cache[$userId] = $rank;
    return $rank;
}
