<?php
// +------------------------------------------------------------------------+
// | Bitchat TRDC Ecosystem Rewards
// | Rewards creators with TRDC tokens for engagement milestones.
// | Uses existing wallet system (Wo_Users.wallet column).
// | Toggle via Wo_Config 'trdc_creator_rewards_enabled'
// +------------------------------------------------------------------------+

/**
 * Process milestone rewards for all creators. Called by cron.
 * Checks if any creator's posts hit reward thresholds.
 */
function Wo_ProcessMilestoneRewards() {
    global $wo, $sqlConnect;

    $rewardsTable = T_TRDC_REWARDS;
    $milestones   = Wo_GetRewardMilestones();

    if (empty($milestones)) return;

    // Get all creators (or all users if creator mode is disabled)
    $creatorFilter = '';
    if (!empty($wo['config']['creator_mode_enabled']) && $wo['config']['creator_mode_enabled'] == '1') {
        $creatorFilter = "AND is_creator = 1";
    }

    $users = mysqli_query($sqlConnect, "SELECT user_id FROM " . T_USERS . " WHERE active = '1' {$creatorFilter} LIMIT 500");
    if (!$users) return;

    $now = time();

    while ($user = mysqli_fetch_assoc($users)) {
        $userId = intval($user['user_id']);

        // Check post-based milestones (post_likes_100, post_likes_500, post_likes_1000)
        foreach ($milestones as $milestoneType => $rewardAmount) {
            if (strpos($milestoneType, 'post_likes_') === 0) {
                $threshold = intval(str_replace('post_likes_', '', $milestoneType));
                Wo_CheckPostLikeMilestone($userId, $threshold, $milestoneType, $rewardAmount);
            } elseif ($milestoneType == 'first_video_post') {
                Wo_CheckFirstVideoMilestone($userId, $milestoneType, $rewardAmount);
            }
        }
    }
}

/**
 * Check if any of user's posts reached a like/reaction milestone.
 */
function Wo_CheckPostLikeMilestone($userId, $threshold, $milestoneType, $rewardAmount) {
    global $sqlConnect;

    $rewardsTable   = T_TRDC_REWARDS;
    $reactionsTable = T_REACTIONS;
    $postsTable     = T_POSTS;

    // Find posts that reached the threshold
    $sql = "SELECT p.id
            FROM {$postsTable} p
            WHERE p.user_id = {$userId}
              AND (SELECT COUNT(*) FROM {$reactionsTable} WHERE post_id = p.id) >= {$threshold}
              AND p.id NOT IN (
                  SELECT COALESCE(post_id, 0) FROM {$rewardsTable}
                  WHERE user_id = {$userId} AND milestone_type = '{$milestoneType}'
              )
            LIMIT 5";

    $result = mysqli_query($sqlConnect, $sql);
    if (!$result) return;

    while ($row = mysqli_fetch_assoc($result)) {
        Wo_AwardTRDC($userId, $rewardAmount, "Post reached {$threshold} reactions", $milestoneType, intval($row['id']));
    }
}

/**
 * Check if user posted their first video and hasn't been rewarded yet.
 */
function Wo_CheckFirstVideoMilestone($userId, $milestoneType, $rewardAmount) {
    global $sqlConnect;

    $rewardsTable = T_TRDC_REWARDS;
    $postsTable   = T_POSTS;

    // Check if already rewarded
    $check = mysqli_query($sqlConnect, "SELECT id FROM {$rewardsTable} WHERE user_id = {$userId} AND milestone_type = '{$milestoneType}' LIMIT 1");
    if ($check && mysqli_num_rows($check) > 0) return;

    // Check if user has a video post
    $video = mysqli_query($sqlConnect,
        "SELECT id FROM {$postsTable} WHERE user_id = {$userId}
         AND (postFile LIKE '%_video%' OR postYoutube != '' OR postVimeo != '' OR postPlaytube != '')
         LIMIT 1"
    );

    if ($video && mysqli_num_rows($video) > 0) {
        $row = mysqli_fetch_assoc($video);
        Wo_AwardTRDC($userId, $rewardAmount, "First video post", $milestoneType, intval($row['id']));
    }
}

/**
 * Award TRDC tokens to a user's wallet.
 *
 * @param int    $userId        Recipient user ID
 * @param float  $amount        TRDC amount
 * @param string $reason        Human-readable reason
 * @param string $milestoneType Milestone key for dedup
 * @param int    $postId        Related post ID (0 if none)
 * @return bool
 */
function Wo_AwardTRDC($userId, $amount, $reason, $milestoneType, $postId = 0) {
    global $sqlConnect;

    $userId        = intval($userId);
    $amount        = floatval($amount);
    $postId        = intval($postId);
    $rewardsTable  = T_TRDC_REWARDS;
    $now           = time();

    if ($userId <= 0 || $amount <= 0) return false;

    $reasonSafe    = mysqli_real_escape_string($sqlConnect, $reason);
    $milestoneSafe = mysqli_real_escape_string($sqlConnect, $milestoneType);

    // Insert reward record (unique constraint prevents duplicates)
    $postIdVal = $postId > 0 ? $postId : 'NULL';
    $sql = "INSERT IGNORE INTO {$rewardsTable} (user_id, amount, reason, milestone_type, post_id, created_at)
            VALUES ({$userId}, {$amount}, '{$reasonSafe}', '{$milestoneSafe}', {$postIdVal}, {$now})";

    $result = mysqli_query($sqlConnect, $sql);
    if (!$result || mysqli_affected_rows($sqlConnect) == 0) {
        return false; // Already rewarded or error
    }

    // Update wallet balance
    mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet + {$amount} WHERE user_id = {$userId}");

    // Clear user cache
    cache($userId, 'users', 'delete');

    // Send notification
    if (function_exists('Wo_RegisterNotification')) {
        Wo_RegisterNotification(array(
            'recipient_id' => $userId,
            'type'         => 'remaining', // reuse existing type for system messages
            'text'         => "You earned {$amount} TRDC: {$reason}",
            'url'          => 'index.php?link1=wallet'
        ));
    }

    return true;
}

/**
 * Get reward history for a user.
 *
 * @param int $userId User ID
 * @param int $limit  Max records
 * @return array
 */
function Wo_GetRewardHistory($userId, $limit = 50) {
    global $sqlConnect;

    $userId       = intval($userId);
    $limit        = max(1, min(200, intval($limit)));
    $rewardsTable = T_TRDC_REWARDS;

    $result = mysqli_query($sqlConnect, "SELECT * FROM {$rewardsTable} WHERE user_id = {$userId} ORDER BY created_at DESC LIMIT {$limit}");

    $rewards = array();
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rewards[] = $row;
        }
    }
    return $rewards;
}

/**
 * Get configured reward milestones from admin config.
 *
 * @return array Milestone type => TRDC amount
 */
function Wo_GetRewardMilestones() {
    global $wo;

    $defaults = array(
        'post_likes_100'   => 0.5,
        'post_likes_500'   => 2.0,
        'post_likes_1000'  => 5.0,
        'first_video_post' => 0.25,
    );

    if (!empty($wo['config']['trdc_reward_milestones'])) {
        $parsed = json_decode($wo['config']['trdc_reward_milestones'], true);
        if (is_array($parsed)) {
            return array_merge($defaults, $parsed);
        }
    }

    return $defaults;
}
