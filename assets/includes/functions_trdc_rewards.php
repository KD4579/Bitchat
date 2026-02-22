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

    $users = mysqli_query($sqlConnect, "SELECT user_id FROM " . T_USERS . " WHERE active = '1' AND src != 'Fake' {$creatorFilter} LIMIT 500");
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

        // Check for rank upgrade notification
        if (function_exists('Wo_GetCreatorStats') && function_exists('Wo_GetCreatorRank')) {
            Wo_CheckRankUpgrade($userId);
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
        // Route through Reward Engine if available
        $milestoneKey = 'milestone_' . $threshold;
        if (function_exists('Wo_TriggerReward')) {
            Wo_TriggerReward($userId, $milestoneKey, [
                'post_id'   => intval($row['id']),
                'threshold' => $threshold
            ]);
        } else {
            // Fallback: direct award with guard
            if (function_exists('Wo_RewardGuard_Milestone') && !Wo_RewardGuard_Milestone($userId, intval($row['id']), $threshold)) {
                continue;
            }
            Wo_AwardTRDC($userId, $rewardAmount, "Post reached {$threshold} reactions", $milestoneType, intval($row['id']));
        }
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

    // Skip fake/generated users
    $srcCheck = mysqli_query($sqlConnect, "SELECT src FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1");
    if ($srcCheck && ($srcRow = mysqli_fetch_assoc($srcCheck)) && $srcRow['src'] === 'Fake') {
        return false;
    }

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

/**
 * Get milestone progress for a creator.
 * Shows current progress toward each milestone with percentage.
 *
 * @param int $userId Creator user ID
 * @return array [{type, label, threshold, current, percent, reward, claimed}, ...]
 */
function Wo_GetMilestoneProgress($userId) {
    global $sqlConnect;
    $userId = intval($userId);

    $milestones     = Wo_GetRewardMilestones();
    $rewardsTable   = T_TRDC_REWARDS;
    $reactionsTable = T_REACTIONS;
    $postsTable     = T_POSTS;

    // Get total reactions across all user's posts
    $rq = mysqli_query($sqlConnect, "SELECT COUNT(*) AS cnt FROM {$reactionsTable} r JOIN {$postsTable} p ON r.post_id = p.id WHERE p.user_id = {$userId}");
    $totalReactions = 0;
    if ($rq) { $r = mysqli_fetch_assoc($rq); $totalReactions = intval($r['cnt']); }

    // Check if user has a video post
    $vq = mysqli_query($sqlConnect, "SELECT COUNT(*) AS cnt FROM {$postsTable} WHERE user_id = {$userId} AND (postFile LIKE '%_video%' OR postYoutube != '' OR postVimeo != '' OR postPlaytube != '') LIMIT 1");
    $hasVideo = false;
    if ($vq) { $v = mysqli_fetch_assoc($vq); $hasVideo = (intval($v['cnt']) > 0); }

    // Get claimed milestones
    $cq = mysqli_query($sqlConnect, "SELECT milestone_type, COUNT(*) AS cnt FROM {$rewardsTable} WHERE user_id = {$userId} GROUP BY milestone_type");
    $claimed = array();
    if ($cq) {
        while ($row = mysqli_fetch_assoc($cq)) {
            $claimed[$row['milestone_type']] = intval($row['cnt']);
        }
    }

    $progress = array();
    foreach ($milestones as $type => $reward) {
        $label     = '';
        $threshold = 0;
        $current   = 0;

        if (strpos($type, 'post_likes_') === 0) {
            $threshold = intval(str_replace('post_likes_', '', $type));
            $label = number_format($threshold) . ' Reactions';
            $current = min($totalReactions, $threshold);
        } elseif ($type == 'first_video_post') {
            $threshold = 1;
            $label = 'First Video Post';
            $current = $hasVideo ? 1 : 0;
        } else {
            continue;
        }

        $percent = ($threshold > 0) ? min(100, round(($current / $threshold) * 100)) : 0;
        $isClaimed = !empty($claimed[$type]);

        $progress[] = array(
            'type'      => $type,
            'label'     => $label,
            'threshold' => $threshold,
            'current'   => $current,
            'percent'   => $percent,
            'reward'    => floatval($reward),
            'claimed'   => $isClaimed,
        );
    }

    return $progress;
}

/**
 * Check if a creator's rank has upgraded and send a notification.
 * Stores the last known rank in config to avoid duplicate notifications.
 */
function Wo_CheckRankUpgrade($userId) {
    global $sqlConnect, $wo;

    $userId = intval($userId);
    $configKey = "creator_rank_{$userId}";

    // Get stored rank
    $storedRank = '';
    $q = mysqli_query($sqlConnect, "SELECT `value` FROM " . T_CONFIG . " WHERE `name` = '{$configKey}' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $storedRank = $r['value'];
    }

    // Get current rank
    $stats = function_exists('Wo_GetCreatorStats') ? Wo_GetCreatorStats($userId) : array();
    if (empty($stats)) return;

    $rankInfo = Wo_GetCreatorRank($stats);
    $currentRank = $rankInfo['rank'];

    if (empty($storedRank)) {
        // First time — store rank, no notification
        mysqli_query($sqlConnect, "INSERT INTO " . T_CONFIG . " (`name`, `value`) VALUES ('{$configKey}', '" . mysqli_real_escape_string($sqlConnect, $currentRank) . "') ON DUPLICATE KEY UPDATE `value` = '" . mysqli_real_escape_string($sqlConnect, $currentRank) . "'");
        return;
    }

    if ($storedRank !== $currentRank) {
        // Rank changed — determine if it's an upgrade
        $rankOrder = array('Rising Star' => 1, 'Contributor' => 2, 'Influencer' => 3, 'Champion' => 4);
        $oldLevel = $rankOrder[$storedRank] ?? 0;
        $newLevel = $rankOrder[$currentRank] ?? 0;

        if ($newLevel > $oldLevel && function_exists('Wo_RegisterNotification')) {
            Wo_RegisterNotification(array(
                'recipient_id' => $userId,
                'type'         => 'remaining',
                'text'         => "Congratulations! You've been promoted to {$currentRank} rank!",
                'url'          => 'index.php?link1=creator-dashboard'
            ));
        }

        // Update stored rank
        mysqli_query($sqlConnect, "UPDATE " . T_CONFIG . " SET `value` = '" . mysqli_real_escape_string($sqlConnect, $currentRank) . "' WHERE `name` = '{$configKey}'");
    }
}
