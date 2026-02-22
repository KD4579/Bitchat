<?php
/**
 * Bitchat Growth Engine Functions (GE-1: Action Prompt Engine)
 *
 * Contextual prompts that guide users to take actions based on their activity state.
 * Creates an engagement loop by suggesting relevant next actions.
 */


/**
 * Get user activity state for personalized prompts
 * @param int $user_id User ID
 * @return array User state data
 */
function Wo_GetUserActivityState($user_id) {
    global $sqlConnect;

    $user_id = Wo_Secure($user_id);
    $state = array(
        'is_new' => false,
        'is_trader' => false,
        'is_creator' => false,
        'is_inactive' => false,
        'days_since_last_post' => 0,
        'total_posts' => 0,
        'total_followers' => 0,
        'has_traded_today' => false,
        'trdc_balance' => 0
    );

    // Get user info
    $user_query = mysqli_query($sqlConnect, "SELECT * FROM " . T_USERS . " WHERE user_id = '$user_id'");
    if ($user_query && mysqli_num_rows($user_query) > 0) {
        $user = mysqli_fetch_assoc($user_query);

        // Check if new user (registered within last 7 days)
        $registered_timestamp = strtotime($user['registered']);
        $days_since_registration = (time() - $registered_timestamp) / 86400;
        $state['is_new'] = ($days_since_registration <= 7);

        // Get TRDC balance
        $state['trdc_balance'] = isset($user['trdc_balance']) ? intval($user['trdc_balance']) : 0;

        // Check creator status
        $state['is_creator'] = ($user['verified'] == 1 || $user['pro_type'] > 0);
    }

    // Get total posts (exclude profile_picture/profile_cover updates)
    $posts_query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_POSTS . " WHERE user_id = '$user_id' AND postType NOT IN ('profile_picture','profile_cover','profile_cover_picture')");
    if ($posts_query && mysqli_num_rows($posts_query) > 0) {
        $posts = mysqli_fetch_assoc($posts_query);
        $state['total_posts'] = intval($posts['total']);
    }

    // Get last post time (exclude profile updates)
    $last_post_query = mysqli_query($sqlConnect, "SELECT time FROM " . T_POSTS . " WHERE user_id = '$user_id' AND postType NOT IN ('profile_picture','profile_cover','profile_cover_picture') ORDER BY time DESC LIMIT 1");
    if ($last_post_query && mysqli_num_rows($last_post_query) > 0) {
        $last_post = mysqli_fetch_assoc($last_post_query);
        $last_post_timestamp = intval($last_post['time']);
        $state['days_since_last_post'] = (time() - $last_post_timestamp) / 86400;
    } else {
        $state['days_since_last_post'] = 999; // Never posted
    }

    // Check if inactive (no post in 7+ days)
    $state['is_inactive'] = ($state['days_since_last_post'] >= 7);

    // Get follower count
    $followers_query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_FOLLOWERS . " WHERE following_id = '$user_id' AND active = '1'");
    if ($followers_query && mysqli_num_rows($followers_query) > 0) {
        $followers = mysqli_fetch_assoc($followers_query);
        $state['total_followers'] = intval($followers['total']);
    }

    // Check for trading activity (posts with #btc, #eth, #nifty, #trading hashtags today)
    $today_start = strtotime('today 00:00:00');
    $trading_query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_POSTS . "
        WHERE user_id = '$user_id'
        AND time >= '$today_start'
        AND (postText LIKE '%#btc%' OR postText LIKE '%#eth%' OR postText LIKE '%#nifty%' OR postText LIKE '%#trading%')");
    if ($trading_query && mysqli_num_rows($trading_query) > 0) {
        $trading = mysqli_fetch_assoc($trading_query);
        $state['has_traded_today'] = (intval($trading['total']) > 0);
    }

    // Determine if user is a trader (has trading-related posts)
    $trader_query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_POSTS . "
        WHERE user_id = '$user_id'
        AND (postText LIKE '%#btc%' OR postText LIKE '%#eth%' OR postText LIKE '%#nifty%' OR postText LIKE '%#sensex%' OR postText LIKE '%#trading%')
        LIMIT 3");
    if ($trader_query && mysqli_num_rows($trader_query) > 0) {
        $trader = mysqli_fetch_assoc($trader_query);
        $state['is_trader'] = (intval($trader['total']) >= 1);
    }

    return $state;
}

/**
 * Get contextual action prompt based on user state
 * @param int $user_id User ID
 * @param string $username Username for personalization
 * @return array Prompt data (title, message, cta_text, cta_action)
 */
function Wo_GetActionPrompt($user_id, $username = '') {
    $state = Wo_GetUserActivityState($user_id);
    $prompts = array();

    $first_name = !empty($username) ? explode(' ', $username)[0] : 'there';

    // Priority 1: New user with 0 posts
    if ($state['is_new'] && $state['total_posts'] == 0) {
        return array(
            'type' => 'new_user',
            'title' => "Welcome to Bitchat, $first_name!",
            'message' => "Share your first post and start earning TRDC tokens. The community is waiting!",
            'cta_text' => "Create Your First Post",
            'cta_action' => "openComposer",
            'icon' => 'rocket'
        );
    }

    // Priority 2: Inactive user (7+ days no post)
    if ($state['is_inactive'] && $state['total_posts'] > 0) {
        $days = floor($state['days_since_last_post']);
        return array(
            'type' => 'inactive',
            'title' => "Welcome back, $first_name!",
            'message' => "It's been $days days since your last post. Your followers miss you! Share an update to earn TRDC.",
            'cta_text' => "Post an Update",
            'cta_action' => "openComposer",
            'icon' => 'comeback'
        );
    }

    // Priority 3: Trader who hasn't traded today
    if ($state['is_trader'] && !$state['has_traded_today']) {
        $time_of_day = date('H');
        if ($time_of_day < 12) {
            $market_msg = "Markets are opening. What's your play today?";
        } elseif ($time_of_day < 16) {
            $market_msg = "Markets are moving. Share your analysis and earn TRDC.";
        } else {
            $market_msg = "Evening session is live. Post your market insights.";
        }

        return array(
            'type' => 'trader',
            'title' => "Trading Session Active",
            'message' => $market_msg,
            'cta_text' => "Share Market Insight",
            'cta_action' => "openComposer",
            'icon' => 'chart'
        );
    }

    // Priority 4: Creator with TRDC balance
    if ($state['is_creator'] && $state['trdc_balance'] > 100) {
        return array(
            'type' => 'creator',
            'title' => "Your TRDC is growing! 🚀",
            'message' => "You have " . number_format($state['trdc_balance']) . " TRDC. Keep creating quality content to earn more!",
            'cta_text' => "Create Content",
            'cta_action' => "openComposer",
            'icon' => 'star'
        );
    }

    // Priority 5: New user with 1-5 posts
    if ($state['is_new'] && $state['total_posts'] > 0 && $state['total_posts'] <= 5) {
        return array(
            'type' => 'growing',
            'title' => "You're on a roll, $first_name!",
            'message' => "Post " . (5 - $state['total_posts']) . " more times this week to unlock rewards and grow your audience.",
            'cta_text' => "Keep Posting",
            'cta_action' => "openComposer",
            'icon' => 'fire'
        );
    }

    // Priority 6: Low follower count, encourage engagement
    if ($state['total_followers'] < 10 && $state['total_posts'] > 3) {
        return array(
            'type' => 'grow_audience',
            'title' => "Grow Your Audience",
            'message' => "Comment on trending posts and follow creators in your niche to grow your network.",
            'cta_text' => "Explore Trending",
            'cta_action' => "goToDiscover",
            'icon' => 'users'
        );
    }

    // Default: General engagement prompt
    $time_of_day = date('H');
    if ($time_of_day < 12) {
        $default_msg = "Start your day by sharing something valuable. Your TRDC rewards are waiting!";
    } elseif ($time_of_day < 18) {
        $default_msg = "Afternoon is the peak engagement time. Share your thoughts and earn TRDC!";
    } else {
        $default_msg = "Evening creators are earning now. Post your content and join them!";
    }

    return array(
        'type' => 'default',
        'title' => "Hey $first_name, what's on your mind?",
        'message' => $default_msg,
        'cta_text' => "Create Post",
        'cta_action' => "openComposer",
        'icon' => 'edit'
    );
}

/**
 * Get action prompt as JSON for AJAX calls
 * @param int $user_id User ID
 * @param string $username Username
 * @return string JSON encoded prompt
 */
function Wo_GetActionPromptJSON($user_id, $username = '') {
    $prompt = Wo_GetActionPrompt($user_id, $username);
    return json_encode($prompt);
}

/**
 * GE-2: TRDC Dopamine Feedback - Reward tracking functions
 */

/**
 * Award TRDC to user for an action
 * @param int $user_id User ID
 * @param int $amount TRDC amount to award
 * @param string $type Reward type (post, comment, like_received, etc.)
 * @param string $description Optional description
 * @return bool Success status
 */
if (!function_exists('Wo_AwardTRDC')):
function Wo_AwardTRDC($user_id, $amount, $type = 'general', $description = '') {
    global $sqlConnect;

    $user_id = Wo_Secure($user_id);
    $amount = intval($amount);
    $type = Wo_Secure($type);
    $description = Wo_Secure($description);

    if ($amount <= 0) {
        return false;
    }

    // Update user's TRDC balance
    $query = mysqli_query($sqlConnect, "UPDATE " . T_USERS . "
        SET trdc_balance = trdc_balance + $amount
        WHERE user_id = '$user_id'");

    if ($query) {
        // Log the transaction (optional - for history tracking)
        Wo_LogTRDCTransaction($user_id, $amount, $type, $description);
        return true;
    }

    return false;
}
endif; // Wo_AwardTRDC

/**
 * Log TRDC transaction for history
 * @param int $user_id User ID
 * @param int $amount TRDC amount
 * @param string $type Transaction type
 * @param string $description Description
 */
function Wo_LogTRDCTransaction($user_id, $amount, $type, $description) {
    global $sqlConnect;

    $user_id = Wo_Secure($user_id);
    $amount = intval($amount);
    $type = Wo_Secure($type);
    $description = Wo_Secure($description);
    $time = time();

    // Create transactions table if it doesn't exist (optional enhancement)
    // For now, we'll just skip logging if table doesn't exist
    $table_check = mysqli_query($sqlConnect, "SHOW TABLES LIKE 'trdc_transactions'");

    if ($table_check && mysqli_num_rows($table_check) > 0) {
        mysqli_query($sqlConnect, "INSERT INTO trdc_transactions
            (user_id, amount, type, description, time)
            VALUES ('$user_id', '$amount', '$type', '$description', '$time')");
    }
}

/**
 * Get TRDC reward amount for action type
 * @param string $type Action type
 * @return int TRDC amount
 */
function Wo_GetTRDCReward($type) {
    // Try Reward Engine DB config first
    if (function_exists('Wo_GetRewardConfig')) {
        // Map old type names to new reward_key names
        $keyMap = array(
            'post'    => 'post_create',
            'comment' => 'comment_create',
            'share'   => 'post_share',
        );
        $rewardKey = isset($keyMap[$type]) ? $keyMap[$type] : $type;
        $config = Wo_GetRewardConfig($rewardKey);
        if ($config) {
            return floatval($config['reward_amount']);
        }
    }

    // Fallback hardcoded values (safety net)
    $rewards = array(
        'post' => 50,
        'comment' => 10,
        'like_received' => 5,
        'share' => 15,
        'profile_view' => 2,
        'first_post' => 100,
        'daily_login' => 20,
        'verify_email' => 50,
        'complete_profile' => 75,
        'follow' => 3,
        'followed' => 5
    );

    return isset($rewards[$type]) ? $rewards[$type] : 0;
}

/**
 * Check if user should receive first post bonus
 * @param int $user_id User ID
 * @return bool
 */
function Wo_IsFirstPost($user_id) {
    global $sqlConnect;

    $user_id = Wo_Secure($user_id);

    $query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_POSTS . "
        WHERE user_id = '$user_id' AND postType = ''");

    if ($query && mysqli_num_rows($query) > 0) {
        $data = mysqli_fetch_assoc($query);
        return (intval($data['total']) === 1); // Returns true if this is exactly the first post
    }

    return false;
}

/**
 * GE-3: Gamification Badges - Achievement system
 */

/**
 * Define all available badges
 * @return array Badge definitions
 */
function Wo_GetBadgeDefinitions() {
    return array(
        'first_post' => array(
            'name' => 'First Post',
            'description' => 'Published your first post',
            'icon' => '🎉',
            'color' => '#f093fb'
        ),
        'post_master' => array(
            'name' => 'Post Master',
            'description' => 'Published 50+ posts',
            'icon' => '📝',
            'color' => '#667eea'
        ),
        'trending_creator' => array(
            'name' => 'Trending Creator',
            'description' => 'Got 1000+ total likes',
            'icon' => '🔥',
            'color' => '#fa709a'
        ),
        'market_master' => array(
            'name' => 'Market Master',
            'description' => 'Posted 25+ trading insights',
            'icon' => '📈',
            'color' => '#4facfe'
        ),
        'community_helper' => array(
            'name' => 'Community Helper',
            'description' => 'Posted 100+ comments',
            'icon' => '💬',
            'color' => '#30cfd0'
        ),
        'early_bird' => array(
            'name' => 'Early Bird',
            'description' => 'Logged in 7 days in a row',
            'icon' => '🌅',
            'color' => '#a8edea'
        ),
        'verified' => array(
            'name' => 'Verified',
            'description' => 'Verified email address',
            'icon' => '✅',
            'color' => '#00f2fe'
        ),
        'popular' => array(
            'name' => 'Popular',
            'description' => 'Got 100+ followers',
            'icon' => '⭐',
            'color' => '#f5576c'
        )
    );
}

/**
 * Check which badges user has earned
 * @param int $user_id User ID
 * @return array Array of earned badge keys
 */
function Wo_CheckUserBadges($user_id) {
    global $sqlConnect;
    $user_id = Wo_Secure($user_id);
    $earned_badges = array();

    // Get user data
    $state = Wo_GetUserActivityState($user_id);

    // First Post badge
    if ($state['total_posts'] >= 1) {
        $earned_badges[] = 'first_post';
    }

    // Post Master badge
    if ($state['total_posts'] >= 50) {
        $earned_badges[] = 'post_master';
    }

    // Market Master badge (trading posts)
    $trading_query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_POSTS . "
        WHERE user_id = '$user_id'
        AND (postText LIKE '%#btc%' OR postText LIKE '%#eth%' OR postText LIKE '%#nifty%' OR postText LIKE '%#trading%')");
    if ($trading_query && mysqli_num_rows($trading_query) > 0) {
        $trading = mysqli_fetch_assoc($trading_query);
        if (intval($trading['total']) >= 25) {
            $earned_badges[] = 'market_master';
        }
    }

    // Community Helper badge (comments)
    $comments_query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_COMMENTS . "
        WHERE user_id = '$user_id'");
    if ($comments_query && mysqli_num_rows($comments_query) > 0) {
        $comments = mysqli_fetch_assoc($comments_query);
        if (intval($comments['total']) >= 100) {
            $earned_badges[] = 'community_helper';
        }
    }

    // Trending Creator badge (likes received)
    $likes_query = mysqli_query($sqlConnect, "SELECT COUNT(*) as total FROM " . T_REACTIONS . "
        WHERE post_id IN (SELECT id FROM " . T_POSTS . " WHERE user_id = '$user_id')");
    if ($likes_query && mysqli_num_rows($likes_query) > 0) {
        $likes = mysqli_fetch_assoc($likes_query);
        if (intval($likes['total']) >= 1000) {
            $earned_badges[] = 'trending_creator';
        }
    }

    // Popular badge (followers)
    if ($state['total_followers'] >= 100) {
        $earned_badges[] = 'popular';
    }

    // Verified badge (email verified)
    $user_query = mysqli_query($sqlConnect, "SELECT email_code FROM " . T_USERS . " WHERE user_id = '$user_id'");
    if ($user_query && mysqli_num_rows($user_query) > 0) {
        $user = mysqli_fetch_assoc($user_query);
        if (empty($user['email_code'])) {
            $earned_badges[] = 'verified';
        }
    }

    return $earned_badges;
}

/**
 * Get user's badges with details
 * @param int $user_id User ID
 * @return array Array of badge objects with details
 */
function Wo_GetUserBadges($user_id) {
    $earned_keys = Wo_CheckUserBadges($user_id);
    $definitions = Wo_GetBadgeDefinitions();
    $badges = array();

    foreach ($earned_keys as $key) {
        if (isset($definitions[$key])) {
            $badge = $definitions[$key];
            $badge['key'] = $key;
            $badges[] = $badge;
        }
    }

    return $badges;
}
