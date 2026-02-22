<?php
// +------------------------------------------------------------------------+
// | Bitchat TRDC Reward Guards
// | Anti-abuse protection layer for instant and milestone rewards.
// | Every reward must pass: Human Effort + Uniqueness + Time Friction.
// +------------------------------------------------------------------------+

/**
 * Count rewards of a given type prefix for a user in the last 24 hours.
 *
 * @param int    $userId         User ID
 * @param string $milestonePrefix Prefix to match (e.g., 'instant_post')
 * @return int
 */
function Wo_GetUserDailyRewardCount($userId, $milestonePrefix) {
    global $sqlConnect;
    $userId   = intval($userId);
    $prefix   = mysqli_real_escape_string($sqlConnect, $milestonePrefix);
    $since    = time() - 86400;
    $table    = T_TRDC_REWARDS;

    $q = mysqli_query($sqlConnect,
        "SELECT COUNT(*) AS cnt FROM {$table}
         WHERE user_id = {$userId}
           AND milestone_type LIKE '{$prefix}%'
           AND created_at > {$since}"
    );

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return intval($row['cnt']);
    }
    return 0;
}

/**
 * Guard: Can this post earn an instant TRDC reward?
 *
 * Checks: daily cap, min text length, duplicate hash, link-only filter, account age.
 *
 * @param int    $userId   Post author
 * @param int    $postId   Post ID
 * @param string $postText Raw post text
 * @param string $postLink Post link URL
 * @return bool  True if reward is allowed
 */
function Wo_RewardGuard_Post($userId, $postId, $postText = '', $postLink = '') {
    global $sqlConnect;
    $userId = intval($userId);

    // 1. Account age >= 3 days
    $q = mysqli_query($sqlConnect,
        "SELECT joined FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1"
    );
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        if ((time() - intval($row['joined'])) < 259200) { // 3 days
            return false;
        }
    } else {
        return false;
    }

    // 2. Daily cap: max 5 rewarded posts per 24h
    if (Wo_GetUserDailyRewardCount($userId, 'instant_post') >= 5) {
        return false;
    }

    // 3. Normalize text and check min length (20 chars after stripping URLs/mentions/hashtags)
    $cleanText = $postText;
    $cleanText = preg_replace('/\[a\][^[]*\[\/a\]/', '', $cleanText);    // WoWonder encoded URLs
    $cleanText = preg_replace('/@\[\d+\]/', '', $cleanText);              // mentions
    $cleanText = preg_replace('/#\[\d+\]/', '', $cleanText);              // hashtags
    $cleanText = preg_replace('/(https?:\/\/[^\s<\]]+)/i', '', $cleanText); // plain URLs
    $cleanText = trim(preg_replace('/\s+/', ' ', $cleanText));

    if (mb_strlen($cleanText) < 20) {
        return false;
    }

    // 4. Duplicate content check (same text hash rewarded in last 24h)
    if (function_exists('Wo_GenerateTextHash')) {
        $hash = Wo_GenerateTextHash($postText);
        if ($hash) {
            $hashSafe = mysqli_real_escape_string($sqlConnect, $hash);
            $since    = time() - 86400;
            $table    = T_TRDC_REWARDS;
            $spamTbl  = defined('T_SPAM_TRACKING') ? T_SPAM_TRACKING : 'Wo_Spam_Tracking';

            // Check if this exact content already earned a reward
            $dq = mysqli_query($sqlConnect,
                "SELECT r.id FROM {$table} r
                 JOIN {$spamTbl} s ON s.post_id = r.post_id AND s.user_id = r.user_id
                 WHERE r.user_id = {$userId}
                   AND s.text_hash = '{$hashSafe}'
                   AND r.milestone_type = 'instant_post'
                   AND r.created_at > {$since}
                 LIMIT 1"
            );
            if ($dq && mysqli_num_rows($dq) > 0) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Guard: Can this comment earn an instant TRDC reward?
 *
 * Checks: min length, cooldown, daily cap, uniqueness, not self-comment.
 *
 * @param int    $userId      Comment author
 * @param string $commentText Raw comment text
 * @param int    $postAuthorId The post's author ID (to detect self-commenting)
 * @return bool  True if reward is allowed
 */
function Wo_RewardGuard_Comment($userId, $commentText, $postAuthorId = 0) {
    global $sqlConnect;
    $userId       = intval($userId);
    $postAuthorId = intval($postAuthorId);

    // 1. Cannot earn reward commenting on own post
    if ($postAuthorId > 0 && $userId === $postAuthorId) {
        return false;
    }

    // 2. Min length: 15 chars after trimming
    $trimmed = trim($commentText);
    if (mb_strlen($trimmed) < 15) {
        return false;
    }

    // 3. Cooldown: last rewarded comment must be > 60s ago
    $table = T_TRDC_REWARDS;
    $q = mysqli_query($sqlConnect,
        "SELECT created_at FROM {$table}
         WHERE user_id = {$userId} AND milestone_type = 'instant_comment'
         ORDER BY created_at DESC LIMIT 1"
    );
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        if ((time() - intval($row['created_at'])) < 60) {
            return false;
        }
    }

    // 4. Daily cap: max 10 rewarded comments per 24h
    if (Wo_GetUserDailyRewardCount($userId, 'instant_comment') >= 10) {
        return false;
    }

    // 5. Uniqueness: normalize text → MD5 → check if same hash rewarded in last 24h
    $normalized = mb_strtolower(trim($trimmed), 'UTF-8');
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
    if (mb_strlen($normalized) >= 10) {
        $hash     = md5($normalized);
        $hashSafe = mysqli_real_escape_string($sqlConnect, $hash);
        $since    = time() - 86400;

        $dq = mysqli_query($sqlConnect,
            "SELECT id FROM {$table}
             WHERE user_id = {$userId}
               AND milestone_type = 'instant_comment'
               AND reason = '{$hashSafe}'
               AND created_at > {$since}
             LIMIT 1"
        );
        if ($dq && mysqli_num_rows($dq) > 0) {
            return false;
        }
    }

    return true;
}

/**
 * Guard: Should a milestone reward be granted?
 *
 * Checks reactor uniqueness and account age to prevent self-engagement farms.
 *
 * @param int $userId    Post author
 * @param int $postId    Post ID
 * @param int $threshold Reaction threshold (100/500/1000)
 * @return bool True if milestone reward is allowed
 */
function Wo_RewardGuard_Milestone($userId, $postId, $threshold) {
    global $sqlConnect;
    $userId = intval($userId);
    $postId = intval($postId);

    // Get all reactors for this post
    $reactors = mysqli_query($sqlConnect,
        "SELECT u.user_id, u.ip_address, u.joined
         FROM " . T_REACTIONS . " r
         JOIN " . T_USERS . " u ON r.user_id = u.user_id
         WHERE r.post_id = {$postId}"
    );

    if (!$reactors) return true; // fail open if query errors

    $total       = 0;
    $uniqueIps   = array();
    $agedCount   = 0;
    $threeDaysAgo = time() - 259200;

    while ($r = mysqli_fetch_assoc($reactors)) {
        $total++;
        if (!empty($r['ip_address'])) {
            $uniqueIps[$r['ip_address']] = true;
        }
        if (intval($r['joined']) < $threeDaysAgo) {
            $agedCount++;
        }
    }

    if ($total < 5) return true; // too few reactors to analyze meaningfully

    // Unique IP ratio: at least 50%
    $uniqueRatio = count($uniqueIps) / $total;
    if ($uniqueRatio < 0.50) {
        return false;
    }

    // Account age ratio: at least 70% of reactors > 3 days old
    $ageRatio = $agedCount / $total;
    if ($ageRatio < 0.70) {
        return false;
    }

    return true;
}

/**
 * Guard: Can this referral earn TRDC?
 *
 * Checks daily cap and broader IP overlap.
 *
 * @param int    $referrerId Referrer user ID
 * @param string $newUserIp  New user's IP address
 * @return bool  True if referral reward is allowed
 */
function Wo_RewardGuard_Referral($referrerId, $newUserIp = '') {
    global $sqlConnect;
    $referrerId = intval($referrerId);

    // 1. Daily cap: max 5 referral rewards per referrer per 24h
    if (Wo_GetUserDailyRewardCount($referrerId, 'referral') >= 5) {
        return false;
    }

    // 2. Broader IP check: block if any user registered from same IP in last 30 days
    if (!empty($newUserIp)) {
        $ipSafe  = mysqli_real_escape_string($sqlConnect, $newUserIp);
        $since30 = time() - (30 * 86400);

        $ipQ = mysqli_query($sqlConnect,
            "SELECT COUNT(*) AS cnt FROM " . T_USERS . "
             WHERE ip_address = '{$ipSafe}'
               AND joined > {$since30}
               AND user_id != {$referrerId}"
        );
        if ($ipQ && ($row = mysqli_fetch_assoc($ipQ))) {
            if (intval($row['cnt']) >= 3) {
                return false; // 3+ accounts from same IP in 30 days = suspicious
            }
        }
    }

    return true;
}

/**
 * Guard: Can this first-action bonus be claimed?
 *
 * Checks if already claimed by this user, and IP dedup across users.
 *
 * @param int    $userId     User ID
 * @param string $actionType Action type (e.g., 'post', 'comment')
 * @return bool  True if first-action bonus is allowed
 */
function Wo_RewardGuard_FirstAction($userId, $actionType) {
    global $sqlConnect;
    $userId        = intval($userId);
    $milestoneType = 'first_' . mysqli_real_escape_string($sqlConnect, $actionType);
    $table         = T_TRDC_REWARDS;

    // 1. Already claimed by this user?
    $q = mysqli_query($sqlConnect,
        "SELECT id FROM {$table}
         WHERE user_id = {$userId} AND milestone_type = '{$milestoneType}'
         LIMIT 1"
    );
    if ($q && mysqli_num_rows($q) > 0) {
        return false;
    }

    // 2. IP dedup: check if another user from same IP claimed this in last 30 days
    $userQ = mysqli_query($sqlConnect,
        "SELECT ip_address FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1"
    );
    if ($userQ && ($uRow = mysqli_fetch_assoc($userQ)) && !empty($uRow['ip_address'])) {
        $ipSafe  = mysqli_real_escape_string($sqlConnect, $uRow['ip_address']);
        $since30 = time() - (30 * 86400);

        $ipCheck = mysqli_query($sqlConnect,
            "SELECT r.id FROM {$table} r
             JOIN " . T_USERS . " u ON r.user_id = u.user_id
             WHERE r.milestone_type = '{$milestoneType}'
               AND u.ip_address = '{$ipSafe}'
               AND r.created_at > {$since30}
               AND r.user_id != {$userId}
             LIMIT 1"
        );
        if ($ipCheck && mysqli_num_rows($ipCheck) > 0) {
            return false;
        }
    }

    return true;
}

/**
 * Safe orchestrator: Award TRDC for creating a post.
 *
 * Runs all guards, then awards via Wo_AwardTRDC() (functions_trdc_rewards.php).
 * Uses INSERT IGNORE to prevent double-awarding the same post.
 *
 * @param int    $userId   Post author
 * @param int    $postId   Post ID
 * @param string $postText Raw post text
 * @param string $postLink Post link URL
 * @return bool
 */
function Wo_SafeRewardPost($userId, $postId, $postText = '', $postLink = '') {
    $userId = intval($userId);
    $postId = intval($postId);

    if ($userId <= 0 || $postId <= 0) return false;

    // Run post guard
    if (!Wo_RewardGuard_Post($userId, $postId, $postText, $postLink)) {
        return false;
    }

    // Check first-post bonus
    if (Wo_RewardGuard_FirstAction($userId, 'post')) {
        $firstAmount = function_exists('Wo_GetTRDCReward') ? Wo_GetTRDCReward('first_post') : 100;
        if ($firstAmount > 0) {
            Wo_AwardTRDC($userId, $firstAmount, "First post bonus", 'first_post', $postId);
        }
    }

    // Award instant post reward
    $amount = function_exists('Wo_GetTRDCReward') ? Wo_GetTRDCReward('post') : 50;
    if ($amount > 0) {
        return Wo_AwardTRDC($userId, $amount, "Post reward", 'instant_post', $postId);
    }

    return false;
}

/**
 * Safe orchestrator: Award TRDC for creating a comment.
 *
 * Runs all guards, then awards. Stores comment text hash in reason field for dedup.
 *
 * @param int    $userId      Comment author
 * @param int    $commentId   Comment ID
 * @param string $commentText Raw comment text
 * @param int    $postAuthorId Post author ID (for self-comment check)
 * @return bool
 */
function Wo_SafeRewardComment($userId, $commentId, $commentText = '', $postAuthorId = 0) {
    $userId    = intval($userId);
    $commentId = intval($commentId);

    if ($userId <= 0 || $commentId <= 0) return false;

    // Run comment guard
    if (!Wo_RewardGuard_Comment($userId, $commentText, $postAuthorId)) {
        return false;
    }

    // Generate hash for dedup tracking (stored in reason field)
    $normalized = mb_strtolower(trim($commentText), 'UTF-8');
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
    $hash = (mb_strlen($normalized) >= 10) ? md5($normalized) : 'short_' . $commentId;

    $amount = function_exists('Wo_GetTRDCReward') ? Wo_GetTRDCReward('comment') : 10;
    if ($amount > 0) {
        return Wo_AwardTRDC($userId, $amount, $hash, 'instant_comment', $commentId);
    }

    return false;
}
