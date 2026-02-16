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
        'posts_this_week'  => 0,
        'reactions_this_week' => 0,
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

    // This week
    $weekAgo = time() - (7 * 86400);
    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_POSTS . " WHERE user_id = {$userId} AND time > {$weekAgo}");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['posts_this_week'] = intval($r['cnt']); }

    $q = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_REACTIONS . " r JOIN " . T_POSTS . " p ON r.post_id = p.id WHERE p.user_id = {$userId} AND p.time > {$weekAgo}");
    if ($q) { $r = mysqli_fetch_assoc($q); $stats['reactions_this_week'] = intval($r['cnt']); }

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
 *
 * @param int $limit Max creators to return
 * @return array Array of user data arrays
 */
function Wo_GetFeaturedCreators($limit = 5) {
    global $sqlConnect;
    $limit = max(1, min(20, intval($limit)));

    $q = mysqli_query($sqlConnect, "SELECT user_id FROM " . T_USERS . " WHERE is_creator = 1 AND active = '1' ORDER BY RAND() LIMIT {$limit}");

    $creators = array();
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $userData = Wo_UserData($row['user_id']);
            if (!empty($userData)) {
                $creators[] = $userData;
            }
        }
    }
    return $creators;
}
