<?php
// +------------------------------------------------------------------------+
// | Bitchat TRDC Reward Engine
// | Central orchestrator for ALL reward types.
// | Config stored in Wo_Rewards_Config table — admin-editable, no hardcodes.
// | Every reward flows through Wo_TriggerReward().
// +------------------------------------------------------------------------+

/**
 * Get config for a single reward type.
 * Uses static cache — loads all configs once per request.
 *
 * @param string $rewardKey Reward key (e.g., 'post_create', 'daily_login')
 * @return array|false      Config row as assoc array, or false if not found
 */
function Wo_GetRewardConfig($rewardKey) {
    static $cache = null;

    if ($cache === null) {
        $cache = array();
        global $sqlConnect;
        $result = mysqli_query($sqlConnect,
            "SELECT * FROM Wo_Rewards_Config ORDER BY sort_order ASC"
        );
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $cache[$row['reward_key']] = $row;
            }
        }
    }

    return isset($cache[$rewardKey]) ? $cache[$rewardKey] : false;
}

/**
 * Get all reward configs for admin panel display.
 *
 * @return array All reward config rows ordered by sort_order
 */
function Wo_GetAllRewardConfigs() {
    global $sqlConnect;

    $configs = array();
    $result = mysqli_query($sqlConnect,
        "SELECT * FROM Wo_Rewards_Config ORDER BY sort_order ASC"
    );
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $configs[] = $row;
        }
    }
    return $configs;
}

/**
 * Central reward trigger — ALL rewards flow through here.
 *
 * Checks: enabled → master switch → cooldown → daily cap → account age → guard → award.
 *
 * @param int    $userId    Recipient user ID
 * @param string $rewardKey Reward key from Wo_Rewards_Config
 * @param array  $context   Extra data for guards (post_id, post_text, comment_text, etc.)
 * @return bool  True if reward was granted
 */
function Wo_TriggerReward($userId, $rewardKey, $context = array()) {
    global $wo, $sqlConnect;

    $userId = intval($userId);
    if ($userId <= 0) return false;

    // 1. Load reward config
    $config = Wo_GetRewardConfig($rewardKey);
    if (!$config || empty($config['enabled'])) {
        return false;
    }

    $amount = floatval($config['reward_amount']);
    if ($amount <= 0) return false;

    // 2. Master switch
    if (empty($wo['config']['trdc_creator_rewards_enabled']) || $wo['config']['trdc_creator_rewards_enabled'] != '1') {
        return false;
    }

    $rewardsTable = T_TRDC_REWARDS;

    // 3. Cooldown check
    $cooldownHours = intval($config['cooldown_hours']);
    if ($cooldownHours > 0) {
        $cooldownSince = time() - ($cooldownHours * 3600);
        $keySafe = mysqli_real_escape_string($sqlConnect, $rewardKey);
        $cq = mysqli_query($sqlConnect,
            "SELECT created_at FROM {$rewardsTable}
             WHERE user_id = {$userId} AND milestone_type = '{$keySafe}'
             ORDER BY created_at DESC LIMIT 1"
        );
        if ($cq && ($crow = mysqli_fetch_assoc($cq))) {
            if (intval($crow['created_at']) > $cooldownSince) {
                return false;
            }
        }
    }

    // 4. Daily cap check
    $maxPerDay = intval($config['max_per_day']);
    if ($maxPerDay > 0) {
        $since24h = time() - 86400;
        $keySafe = mysqli_real_escape_string($sqlConnect, $rewardKey);
        $dq = mysqli_query($sqlConnect,
            "SELECT COUNT(*) AS cnt FROM {$rewardsTable}
             WHERE user_id = {$userId} AND milestone_type = '{$keySafe}'
               AND created_at > {$since24h}"
        );
        if ($dq && ($drow = mysqli_fetch_assoc($dq))) {
            if (intval($drow['cnt']) >= $maxPerDay) {
                return false;
            }
        }
    }

    // 5. Account age check
    $minAgeDays = intval($config['min_account_age_days']);
    if ($minAgeDays > 0) {
        $aq = mysqli_query($sqlConnect,
            "SELECT joined FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1"
        );
        if ($aq && ($arow = mysqli_fetch_assoc($aq))) {
            if ((time() - intval($arow['joined'])) < ($minAgeDays * 86400)) {
                return false;
            }
        } else {
            return false;
        }
    }

    // 6. Guard function (custom anti-abuse logic)
    $guardFn = $config['guard_function'];
    if (!empty($guardFn) && function_exists($guardFn)) {
        $guardResult = Wo_CallGuardFunction($guardFn, $userId, $rewardKey, $context);
        if (!$guardResult) {
            return false;
        }
    }

    // 7. Award via Wo_AwardTRDC
    $postId = !empty($context['post_id']) ? intval($context['post_id']) : 0;
    $reason = $config['title'];

    // For comment dedup, store the text hash in reason field
    if ($rewardKey === 'comment_create' && !empty($context['comment_text'])) {
        $normalized = mb_strtolower(trim($context['comment_text']), 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        $reason = (mb_strlen($normalized) >= 10)
            ? md5($normalized)
            : 'short_' . (!empty($context['comment_id']) ? $context['comment_id'] : '0');
    }

    // For first_post, also trigger the first-action check via the engine
    if ($rewardKey === 'post_create') {
        $firstConfig = Wo_GetRewardConfig('first_post');
        if ($firstConfig && !empty($firstConfig['enabled'])) {
            $firstAmount = floatval($firstConfig['reward_amount']);
            if ($firstAmount > 0 && function_exists('Wo_RewardGuard_FirstAction') && Wo_RewardGuard_FirstAction($userId, 'post')) {
                if (Wo_AwardTRDC($userId, $firstAmount, "First post bonus", 'first_post', $postId)) {
                    Wo_QueueRewardToast($userId, $firstAmount, 'first_post', $firstConfig);
                }
            }
        }
    }

    $awarded = Wo_AwardTRDC($userId, $amount, $reason, $rewardKey, $postId);

    // Queue toast notification for the user
    if ($awarded) {
        Wo_QueueRewardToast($userId, $amount, $rewardKey, $config);

        // Affiliate staking reward: give referrer 10% of the earned reward
        // Skip if this is already a referral-type reward to prevent loops
        if (!in_array($rewardKey, ['referral_signup', 'referral_staking'])) {
            Wo_AwardAffiliateStaking($userId, $amount, $rewardKey);
        }
    }

    return $awarded;
}

/**
 * Award the referrer a percentage of a user's staking/activity reward.
 * Percentage is configurable via admin panel (staking_affiliate_percent).
 *
 * @param int    $userId    The user who earned the reward
 * @param float  $amount    The reward amount earned
 * @param string $rewardKey The reward type key
 */
function Wo_AwardAffiliateStaking($userId, $amount, $rewardKey) {
    global $sqlConnect, $wo;

    $userId = intval($userId);
    if ($userId <= 0 || $amount <= 0) return;

    // Get affiliate commission percentage from admin config (default 10%)
    $affiliatePercent = floatval($wo['config']['staking_affiliate_percent'] ?? 10);
    if ($affiliatePercent <= 0) return;

    // Look up referrer
    $q = mysqli_query($sqlConnect, "SELECT referrer FROM " . T_USERS . " WHERE user_id = {$userId} LIMIT 1");
    if (!$q || !($row = mysqli_fetch_assoc($q))) return;

    $referrerId = intval($row['referrer']);
    if ($referrerId <= 0 || $referrerId === $userId) return;

    // Calculate affiliate commission
    $commission = round($amount * ($affiliatePercent / 100), 4);
    if ($commission <= 0) return;

    // Use a unique reason per reward instance to allow tracking
    $reasonSafe = mysqli_real_escape_string($sqlConnect, "{$affiliatePercent}% affiliate reward ({$rewardKey} by user #{$userId})");
    $now = time();

    // Insert into TRDC rewards (no unique constraint conflict since milestone_type + post_id combo is unique per event)
    $sql = "INSERT INTO " . T_TRDC_REWARDS . " (user_id, amount, reason, milestone_type, post_id, created_at)
            VALUES ({$referrerId}, {$commission}, '{$reasonSafe}', 'referral_staking', NULL, {$now})";
    $result = mysqli_query($sqlConnect, $sql);

    if ($result && mysqli_affected_rows($sqlConnect) > 0) {
        // Update referrer's wallet
        mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET wallet = wallet + {$commission} WHERE user_id = {$referrerId}");
        cache($referrerId, 'users', 'delete');
    }
}

/**
 * Queue a reward toast notification for display on next page load or AJAX response.
 * Stores in $_SESSION for full page loads and in a global array for AJAX responses.
 *
 * @param int    $userId    User ID who earned the reward
 * @param float  $amount    TRDC amount earned
 * @param string $rewardKey Reward key
 * @param array  $config    Reward config row from DB
 */
function Wo_QueueRewardToast($userId, $amount, $rewardKey, $config) {
    global $wo;

    // Only queue for the currently logged-in user
    if (empty($wo['user']['user_id']) || intval($wo['user']['user_id']) !== intval($userId)) {
        return;
    }

    $toast = array(
        'amount'    => floatval($amount),
        'type'      => $rewardKey,
        'title'     => $config['title'] ?? '',
        'punchline' => $config['punchline'] ?? '',
        'timestamp' => time()
    );

    // Store in session for next page load
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['pending_reward_toasts'])) {
            $_SESSION['pending_reward_toasts'] = array();
        }
        $_SESSION['pending_reward_toasts'][] = $toast;
    }

    // Also store in global for immediate AJAX response
    if (!isset($GLOBALS['bc_pending_toasts'])) {
        $GLOBALS['bc_pending_toasts'] = array();
    }
    $GLOBALS['bc_pending_toasts'][] = $toast;
}

/**
 * Get and clear pending reward toasts for current page render.
 * Call this in the footer template to output toast data.
 *
 * @return array Array of toast objects
 */
function Wo_GetPendingRewardToasts() {
    $toasts = array();

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['pending_reward_toasts'])) {
        $toasts = $_SESSION['pending_reward_toasts'];
        unset($_SESSION['pending_reward_toasts']);
    }

    return $toasts;
}

/**
 * Get pending toasts from the current request (for AJAX responses).
 *
 * @return array Array of toast objects
 */
function Wo_GetCurrentRequestToasts() {
    return isset($GLOBALS['bc_pending_toasts']) ? $GLOBALS['bc_pending_toasts'] : array();
}

/**
 * Call a guard function with the appropriate parameters based on its name.
 *
 * @param string $guardFn   Guard function name
 * @param int    $userId    User ID
 * @param string $rewardKey Reward key
 * @param array  $context   Context data
 * @return bool True if guard passes (reward allowed)
 */
function Wo_CallGuardFunction($guardFn, $userId, $rewardKey, $context) {
    switch ($guardFn) {
        case 'Wo_RewardGuard_Post':
            return Wo_RewardGuard_Post(
                $userId,
                !empty($context['post_id']) ? intval($context['post_id']) : 0,
                !empty($context['post_text']) ? $context['post_text'] : '',
                !empty($context['post_link']) ? $context['post_link'] : ''
            );

        case 'Wo_RewardGuard_Comment':
            return Wo_RewardGuard_Comment(
                $userId,
                !empty($context['comment_text']) ? $context['comment_text'] : '',
                !empty($context['post_author_id']) ? intval($context['post_author_id']) : 0
            );

        case 'Wo_RewardGuard_Milestone':
            return Wo_RewardGuard_Milestone(
                $userId,
                !empty($context['post_id']) ? intval($context['post_id']) : 0,
                !empty($context['threshold']) ? intval($context['threshold']) : 0
            );

        case 'Wo_RewardGuard_Referral':
            return Wo_RewardGuard_Referral(
                $userId,
                !empty($context['ip']) ? $context['ip'] : ''
            );

        case 'Wo_RewardGuard_FirstAction':
            return Wo_RewardGuard_FirstAction(
                $userId,
                !empty($context['action_type']) ? $context['action_type'] : 'post'
            );

        default:
            // Unknown guard — fail open (allow reward)
            return true;
    }
}

/**
 * Update reward config from admin panel.
 *
 * @param string $rewardKey Reward key to update
 * @param array  $data      Fields to update (enabled, reward_amount, cooldown_hours, max_per_day)
 * @return bool
 */
function Wo_UpdateRewardConfig($rewardKey, $data) {
    global $sqlConnect;

    $keySafe = mysqli_real_escape_string($sqlConnect, $rewardKey);
    $sets = array();
    $now  = time();

    if (isset($data['enabled'])) {
        $sets[] = "enabled = " . (intval($data['enabled']) ? 1 : 0);
    }
    if (isset($data['reward_amount'])) {
        $sets[] = "reward_amount = " . floatval($data['reward_amount']);
    }
    if (isset($data['cooldown_hours'])) {
        $sets[] = "cooldown_hours = " . intval($data['cooldown_hours']);
    }
    if (isset($data['max_per_day'])) {
        $sets[] = "max_per_day = " . intval($data['max_per_day']);
    }

    if (empty($sets)) return false;

    $sets[] = "updated_at = {$now}";
    $setStr = implode(', ', $sets);

    $result = mysqli_query($sqlConnect,
        "UPDATE Wo_Rewards_Config SET {$setStr} WHERE reward_key = '{$keySafe}'"
    );

    return ($result && mysqli_affected_rows($sqlConnect) >= 0);
}
