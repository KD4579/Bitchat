<?php
// +------------------------------------------------------------------------+
// | Bitchat Feed Algorithm v1
// | Score-based ranking: engagement + media + freshness - spam penalties
// | Sits alongside Wo_GetPosts() — never modifies it.
// | Toggle via Wo_Config 'feed_algorithm_enabled' (0 = off, 1 = on)
// +------------------------------------------------------------------------+

/**
 * Get ranked feed posts. Drop-in alternative to Wo_GetPosts() for the home feed.
 * Falls back to Wo_GetPosts() if algorithm disabled, Redis down, or non-home-feed context.
 *
 * @param array $data  Keys: limit (int), page (int), publisher_id, group_id, page_id, event_id
 * @return array       Array of post data arrays (same format as Wo_GetPosts)
 */
function Wo_GetRankedPosts($data = array()) {
    global $wo, $sqlConnect;

    $limit = isset($data['limit']) ? max(1, intval($data['limit'])) : 10;
    $page  = isset($data['page'])  ? max(1, intval($data['page']))  : 1;

    // --- Guard: only rank the main home feed ---
    if (!$wo['loggedin'] || !empty($data['publisher_id']) || !empty($data['group_id'])
        || !empty($data['page_id']) || !empty($data['event_id'])) {
        return Wo_GetPosts($data);
    }

    // --- Guard: algorithm must be enabled ---
    if (empty($wo['config']['feed_algorithm_enabled']) || $wo['config']['feed_algorithm_enabled'] != '1') {
        return Wo_GetPosts(array(
            'limit'        => $limit,
            'publisher_id' => 0,
            'placement'    => 'multi_image_post',
            'anonymous'    => true
        ));
    }

    $userId = intval($wo['user']['user_id']);

    // --- Try Redis cache for this page ---
    if (class_exists('BitchatCache') && BitchatCache::isEnabled()) {
        $cacheKey = "ranked_feed:{$userId}:page:{$page}";
        $cached   = BitchatCache::get($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    // --- Build or retrieve the full ranked ID list ---
    $rankedIds = false;
    if (class_exists('BitchatCache') && BitchatCache::isEnabled()) {
        $rankedIds = BitchatCache::get("ranked_feed:{$userId}:ids");
    }

    if ($rankedIds === false || !is_array($rankedIds)) {
        $poolSize  = intval($wo['config']['feed_candidate_pool'] ?? 50);
        $rankedIds = Wo_BuildRankedFeedIds($userId, $poolSize);

        if (class_exists('BitchatCache') && BitchatCache::isEnabled()) {
            BitchatCache::set("ranked_feed:{$userId}:ids", $rankedIds, 30);
        }
    }

    // --- Slice for current page ---
    $offset  = ($page - 1) * $limit;
    $pageIds = array_slice($rankedIds, $offset, $limit);

    if (empty($pageIds)) {
        // Exhausted ranked posts — fall back to chronological for older content
        $lastRankedId = !empty($rankedIds) ? end($rankedIds) : 0;
        return Wo_GetPosts(array(
            'limit'         => $limit,
            'after_post_id' => $lastRankedId,
            'publisher_id'  => 0,
            'placement'     => 'multi_image_post',
            'anonymous'     => true
        ));
    }

    // --- Load full post data for this page slice only ---
    $results = array();
    foreach ($pageIds as $postId) {
        $post = Wo_PostData($postId);
        if (is_array($post)) {
            $results[] = $post;
        }
    }

    // --- Cache this page ---
    if (class_exists('BitchatCache') && BitchatCache::isEnabled()) {
        BitchatCache::set("ranked_feed:{$userId}:page:{$page}", $results, 30);
    }

    return $results;
}

/**
 * Build an ordered list of post IDs ranked by score.
 * Uses a single SQL query with inline scoring — no N+1.
 *
 * @param int $userId   Current user ID
 * @param int $poolSize Max candidates to score (default 50)
 * @return array        Ordered array of post IDs (highest score first)
 */
function Wo_BuildRankedFeedIds($userId, $poolSize = 50) {
    global $wo, $sqlConnect;

    $userId   = intval($userId);
    $poolSize = max(20, min(200, intval($poolSize)));
    $weights  = Wo_GetFeedWeights();
    $spamWindow = intval($wo['config']['feed_spam_window_hours'] ?? 24);
    $maxSameUser = intval($wo['config']['feed_max_same_user'] ?? 2);

    // --- Build WHERE clause (mirrors Wo_GetPosts home feed logic) ---
    $where = Wo_BuildFeedWhereClause($userId);

    // --- Time window: only score posts from the last 48 hours for performance ---
    $timeThreshold = time() - (48 * 3600);

    // Reactions table name
    $reactionsTable = T_REACTIONS;
    $commentsTable  = T_COMMENTS;
    $postsTable     = T_POSTS;
    $usersTable     = T_USERS;
    $spamTable      = defined('T_SPAM_TRACKING') ? T_SPAM_TRACKING : 'Wo_Spam_Tracking';
    $botTable       = 'Wo_Bot_Accounts';

    // Engagement weight values
    $wEng     = floatval($weights['engagement']);
    $wComm    = floatval($weights['comments']);
    $wShare   = floatval($weights['shares']);
    $wMedia   = floatval($weights['media_bonus']);
    $wFresh   = floatval($weights['freshness_decay']);
    $wPro     = floatval($weights['pro_boost']);
    $wSpam    = floatval($weights['spam_penalty']);
    $wLink    = floatval($weights['link_penalty']);
    $wFreq    = floatval($weights['frequency_penalty']);

    $now = time();
    $oneHourAgo = $now - 3600;
    $spamWindowTime = $now - ($spamWindow * 3600);
    $sevenDaysAgo = $now - (7 * 86400);
    $wNewCreator = 5.0; // Boost for accounts < 7 days old
    $wFirstPosts = 3.0; // Extra boost for user's first 3 posts

    // --- Single scoring SQL query ---
    $sql = "
        SELECT
            p.id,
            p.user_id,
            -- Engagement score
            (
                (SELECT COUNT(*) FROM {$reactionsTable} r WHERE r.post_id = p.id) * {$wEng} +
                (SELECT COUNT(*) FROM {$commentsTable} c WHERE c.post_id = p.id) * {$wComm} +
                (SELECT COUNT(*) FROM {$postsTable} sp WHERE sp.parent_id = p.id AND sp.postShare = 1) * {$wShare}
            ) AS engagement_score,

            -- Media bonus
            (CASE
                WHEN p.postFile LIKE '%_image%' OR p.postFile LIKE '%_video%' OR p.postFile LIKE '%_soundFile%'
                    OR p.multi_image = 1 OR p.album_name != '' THEN {$wMedia}
                WHEN p.postYoutube != '' OR p.postVimeo != '' OR p.postDailymotion != ''
                    OR p.postFacebook != '' OR p.postPlaytube != '' THEN ({$wMedia} * 0.5)
                ELSE 0
            END) AS media_bonus,

            -- Freshness (decays ~1pt/hr, max 10, floor 0)
            GREATEST(0, 10.0 - (({$now} - p.time) / 3600.0) * {$wFresh}) AS freshness_score,

            -- PRO boost
            (CASE WHEN u.is_pro = 1 THEN {$wPro} ELSE 0 END) AS pro_boost,

            -- New creator boost (account < 7 days old)
            (CASE WHEN u.joined > {$sevenDaysAgo} THEN {$wNewCreator} ELSE 0 END) AS new_creator_boost,

            -- First posts boost (user's first 3 posts get extra visibility)
            (CASE WHEN (SELECT COUNT(*) FROM {$postsTable} fp2 WHERE fp2.user_id = p.user_id AND fp2.id < p.id) < 3 THEN {$wFirstPosts} ELSE 0 END) AS first_posts_boost,

            -- TRDC boost (paid boost, +10 score while active)
            (CASE WHEN p.trdc_boosted = 1 AND p.trdc_boost_expires > {$now} THEN 10.0 ELSE 0 END) AS trdc_boost,

            -- News bot boost (ensures bot posts appear in feed)
            (CASE WHEN p.user_id IN (SELECT user_id FROM {$botTable} WHERE enabled = 1) THEN 2.0 ELSE 0 END) AS news_bot_boost,

            -- Link penalty (exempt news bots — their links are legitimate articles)
            (CASE
                WHEN p.user_id IN (SELECT user_id FROM {$botTable} WHERE enabled = 1) THEN 0
                WHEN p.postLink != '' AND p.postText = '' AND p.postFile = ''
                    AND p.postYoutube = '' AND p.postVimeo = '' THEN {$wLink}
                WHEN p.postLink != '' THEN ({$wLink} * 0.5)
                ELSE 0
            END) AS link_penalty,

            -- Frequency penalty (posts by this user in last hour beyond threshold of 2, bots exempt)
            (CASE WHEN p.user_id IN (SELECT user_id FROM {$botTable} WHERE enabled = 1) THEN 0 ELSE
                GREATEST(0,
                    (SELECT COUNT(*) FROM {$postsTable} fp
                     WHERE fp.user_id = p.user_id AND fp.time > {$oneHourAgo} AND fp.id != p.id) - 1
                ) * {$wFreq}
            END) AS frequency_penalty,

            -- Spam penalty (duplicate text hashes in spam window)
            COALESCE(
                (SELECT GREATEST(0, COUNT(*) - 1) * {$wSpam}
                 FROM {$spamTable} st
                 WHERE st.user_id = p.user_id
                   AND st.text_hash IS NOT NULL
                   AND st.text_hash != ''
                   AND st.text_hash = (
                       SELECT st2.text_hash FROM {$spamTable} st2
                       WHERE st2.post_id = p.id LIMIT 1
                   )
                   AND st.created_at > {$spamWindowTime}
                ), 0
            ) AS spam_penalty

        FROM {$postsTable} p
        JOIN {$usersTable} u ON p.user_id = u.user_id
        WHERE p.time > {$timeThreshold}
          AND p.boosted = '0'
          AND p.postType NOT IN ('profile_picture_deleted', 'profile_picture', 'profile_cover_picture')
          AND p.multi_image_post = 0
          {$where}
        ORDER BY (
            -- Engagement
            (SELECT COUNT(*) FROM {$reactionsTable} r WHERE r.post_id = p.id) * {$wEng} +
            (SELECT COUNT(*) FROM {$commentsTable} c WHERE c.post_id = p.id) * {$wComm} +
            (SELECT COUNT(*) FROM {$postsTable} sp WHERE sp.parent_id = p.id AND sp.postShare = 1) * {$wShare}
            -- Media bonus
            + (CASE
                WHEN p.postFile LIKE '%_image%' OR p.postFile LIKE '%_video%' OR p.postFile LIKE '%_soundFile%'
                    OR p.multi_image = 1 OR p.album_name != '' THEN {$wMedia}
                WHEN p.postYoutube != '' OR p.postVimeo != '' OR p.postDailymotion != ''
                    OR p.postFacebook != '' OR p.postPlaytube != '' THEN ({$wMedia} * 0.5)
                ELSE 0 END)
            -- Freshness
            + GREATEST(0, 10.0 - (({$now} - p.time) / 3600.0) * {$wFresh})
            -- PRO boost
            + (CASE WHEN u.is_pro = 1 THEN {$wPro} ELSE 0 END)
            -- New creator boost (account < 7 days)
            + (CASE WHEN u.joined > {$sevenDaysAgo} THEN {$wNewCreator} ELSE 0 END)
            -- First posts boost (first 3 posts)
            + (CASE WHEN (SELECT COUNT(*) FROM {$postsTable} fp2 WHERE fp2.user_id = p.user_id AND fp2.id < p.id) < 3 THEN {$wFirstPosts} ELSE 0 END)
            -- TRDC boost
            + (CASE WHEN p.trdc_boosted = 1 AND p.trdc_boost_expires > {$now} THEN 10.0 ELSE 0 END)
            -- News bot boost
            + (CASE WHEN p.user_id IN (SELECT user_id FROM {$botTable} WHERE enabled = 1) THEN 2.0 ELSE 0 END)
            -- Penalties (subtracted, bots exempt from link penalty)
            - (CASE
                WHEN p.user_id IN (SELECT user_id FROM {$botTable} WHERE enabled = 1) THEN 0
                WHEN p.postLink != '' AND p.postText = '' AND p.postFile = ''
                    AND p.postYoutube = '' AND p.postVimeo = '' THEN {$wLink}
                WHEN p.postLink != '' THEN ({$wLink} * 0.5)
                ELSE 0 END)
            - (CASE WHEN p.user_id IN (SELECT user_id FROM {$botTable} WHERE enabled = 1) THEN 0 ELSE
                GREATEST(0,
                    (SELECT COUNT(*) FROM {$postsTable} fp
                     WHERE fp.user_id = p.user_id AND fp.time > {$oneHourAgo} AND fp.id != p.id) - 1
                ) * {$wFreq}
              END)
        ) DESC, p.id DESC
        LIMIT {$poolSize}
    ";

    $result = mysqli_query($sqlConnect, $sql);
    if (!$result) {
        // Query failed — fall back to chronological
        error_log("Bitchat Feed Algorithm SQL error: " . mysqli_error($sqlConnect));
        return Wo_GetChronologicalFeedIds($userId, $poolSize);
    }

    // --- Collect results and apply same-user diversity limit ---
    $allPosts   = array();
    $userCounts = array();

    while ($row = mysqli_fetch_assoc($result)) {
        $allPosts[] = $row;
    }
    mysqli_free_result($result);

    // Build set of bot user IDs for total bot cap
    $botUserIds = array();
    $botQ = mysqli_query($sqlConnect, "SELECT user_id FROM {$botTable} WHERE enabled = 1");
    if ($botQ) {
        while ($bRow = mysqli_fetch_assoc($botQ)) {
            $botUserIds[intval($bRow['user_id'])] = true;
        }
    }
    $totalBotCount = 0;
    $maxTotalBots  = 4; // Max bot posts in ranked feed
    $maxPerBot     = 1; // Max posts per individual bot

    $rankedIds  = array();
    $overflow   = array();

    foreach ($allPosts as $row) {
        $postUserId = intval($row['user_id']);
        if (!isset($userCounts[$postUserId])) {
            $userCounts[$postUserId] = 0;
        }

        // Apply bot total cap in addition to per-user cap
        $isBot = isset($botUserIds[$postUserId]);
        if ($isBot && $totalBotCount >= $maxTotalBots) {
            continue; // Drop excess bot posts entirely
        }

        $userLimit = $isBot ? $maxPerBot : $maxSameUser;
        if ($userCounts[$postUserId] < $userLimit) {
            $rankedIds[] = intval($row['id']);
            $userCounts[$postUserId]++;
            if ($isBot) $totalBotCount++;
        } elseif (!$isBot) {
            $overflow[] = intval($row['id']); // Only non-bot overflow gets appended
        }
    }

    // Append overflow posts at the end (they still show, just lower)
    $rankedIds = array_merge($rankedIds, $overflow);

    // --- Quality gate: ensure first 5 slots prioritize media/engagement posts ---
    if (count($rankedIds) >= 5 && count($allPosts) >= 5) {
        $postMeta = array();
        foreach ($allPosts as $row) {
            $postMeta[intval($row['id'])] = $row;
        }

        $quality = array();
        $other   = array();
        foreach ($rankedIds as $pid) {
            $meta = isset($postMeta[$pid]) ? $postMeta[$pid] : null;
            $isQuality = false;
            if ($meta) {
                $hasMedia = (floatval($meta['media_bonus']) > 0);
                $hasEngagement = (floatval($meta['engagement_score']) >= 5);
                $isQuality = ($hasMedia || $hasEngagement);
            }
            if ($isQuality) {
                $quality[] = $pid;
            } else {
                $other[] = $pid;
            }
        }

        // Fill first 5: take quality posts first, then others
        $first5 = array();
        $qIdx = 0; $oIdx = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($qIdx < count($quality)) {
                $first5[] = $quality[$qIdx++];
            } elseif ($oIdx < count($other)) {
                $first5[] = $other[$oIdx++];
            }
        }
        // Remaining quality + remaining other
        $rest = array_merge(array_slice($quality, $qIdx), array_slice($other, $oIdx));
        $rankedIds = array_merge($first5, $rest);
    }

    return $rankedIds;
}

/**
 * Build WHERE clause that mirrors Wo_GetPosts() home feed filters.
 * Extracted so both functions apply identical visibility rules.
 *
 * @param int $userId  Current logged-in user ID
 * @return string      SQL WHERE fragment (starts with AND)
 */
function Wo_BuildFeedWhereClause($userId) {
    global $wo, $sqlConnect;

    $userId = intval($userId);
    $where  = '';

    // --- Following/connection filter (mirrors Wo_GetPosts lines 6271-6290) ---
    $addFilterQuery = false;
    if ($wo['config']['order_posts_by'] == 0) {
        if (!empty($wo['user']['order_posts_by']) && $wo['user']['order_posts_by'] == 1) {
            $addFilterQuery = true;
        }
    } else {
        $addFilterQuery = true;
    }

    if ($addFilterQuery) {
        $followersTable   = T_FOLLOWERS;
        $pagesTable       = T_PAGES;
        $pagesLikesTable  = T_PAGES_LIKES;
        $groupsTable      = T_GROUPS;
        $groupMembersTable = T_GROUP_MEMBERS;
        $eventsGoingTable = defined('T_EVENTS_GOING') ? T_EVENTS_GOING : 'Wo_Events_Going';

        $where .= "
            AND (
                p.user_id IN (SELECT following_id FROM {$followersTable} WHERE follower_id = {$userId} AND active = '1')
                OR p.recipient_id IN (SELECT following_id FROM {$followersTable} WHERE follower_id = {$userId} AND active = '1')
                OR p.user_id = {$userId}
                OR p.page_id IN (SELECT page_id FROM {$pagesTable} WHERE user_id = {$userId} AND active = '1')
                OR p.page_id IN (SELECT page_id FROM {$pagesLikesTable} WHERE user_id = {$userId} AND active = '1')
                OR p.group_id IN (SELECT id FROM {$groupsTable} WHERE user_id = {$userId})
                OR p.event_id IN (SELECT event_id FROM {$eventsGoingTable} WHERE user_id = {$userId})
                OR p.group_id IN (SELECT group_id FROM {$groupMembersTable} WHERE user_id = {$userId})
            )
        ";
    }

    // --- Privacy filters (mirrors Wo_GetPosts lines 6291-6294) ---
    $where .= " AND (p.postPrivacy <> '3' OR (p.user_id = {$userId} AND p.postPrivacy >= '0'))";

    if ($wo['config']['website_mode'] == 'linkedin') {
        $jobTable = defined('T_JOB') ? T_JOB : 'Wo_Job';
        $where .= " AND (p.postPrivacy <> '5' OR (p.postPrivacy = '5' AND p.user_id = '{$userId}') OR (p.postPrivacy = '5' AND p.user_id IN (SELECT user_id FROM {$jobTable})))";
    }

    // --- Exclude shared posts and anonymous (mirrors lines 6295, 6321-6323) ---
    $where .= " AND p.postShare NOT IN (1)";
    $where .= " AND p.postPrivacy <> '4'";

    // --- Exclude groups user hasn't joined (mirrors lines 6260-6299) ---
    $groupMembersTable = T_GROUP_MEMBERS;
    $where .= " AND (p.group_id = 0 OR p.group_id IN (SELECT group_id FROM {$groupMembersTable} WHERE user_id = {$userId} AND active = '1'))";

    // --- Exclude self-shared ---
    $where .= " AND p.shared_from <> {$userId}";

    // --- Exclude hidden posts ---
    $hiddenTable = defined('T_HIDDEN_POSTS') ? T_HIDDEN_POSTS : 'Wo_Hidden_Posts';
    $where .= " AND p.id NOT IN (SELECT post_id FROM {$hiddenTable} WHERE user_id = {$userId})";

    // --- Exclude blocked users ---
    $blocksTable = T_BLOCKS;
    $where .= " AND p.user_id NOT IN (SELECT blocked FROM {$blocksTable} WHERE blocker = {$userId})";
    $where .= " AND p.user_id NOT IN (SELECT blocker FROM {$blocksTable} WHERE blocked = {$userId})";

    // --- Job system exclusion ---
    if ($wo['config']['job_system'] != 1) {
        $where .= " AND p.job_id = '0'";
    } else {
        $where .= " AND p.job_id = '0'";
    }

    // --- Post approval ---
    if ($wo['config']['post_approval'] == 1) {
        $where .= " AND p.active = '1'";
    } elseif ($wo['config']['blog_approval'] == 1) {
        $where .= " AND p.active = '1'";
    }

    return $where;
}

/**
 * Fallback: get chronological feed IDs (used when scoring query fails).
 */
function Wo_GetChronologicalFeedIds($userId, $limit = 50) {
    global $wo;

    $posts = Wo_GetPosts(array(
        'limit'        => $limit,
        'publisher_id' => 0,
        'placement'    => 'multi_image_post',
        'anonymous'    => true
    ));

    $ids = array();
    foreach ($posts as $post) {
        if (!empty($post['id'])) {
            $ids[] = intval($post['id']);
        }
    }
    return $ids;
}

/**
 * Get feed algorithm weights from config.
 * Returns array with default values if config is missing/invalid.
 */
function Wo_GetFeedWeights() {
    global $wo;

    $defaults = array(
        'engagement'       => 1.0,
        'comments'         => 2.0,
        'shares'           => 1.5,
        'media_bonus'      => 2.0,
        'freshness_decay'  => 0.95,
        'pro_boost'        => 3.0,
        'spam_penalty'     => 5.0,
        'link_penalty'     => 2.0,
        'frequency_penalty' => 3.0,
    );

    if (!empty($wo['config']['feed_weights'])) {
        $parsed = json_decode($wo['config']['feed_weights'], true);
        if (is_array($parsed)) {
            return array_merge($defaults, $parsed);
        }
    }

    return $defaults;
}

/**
 * Get trending posts from the last 24 hours.
 * Ranked by engagement (reactions + comments*2 + shares*1.5).
 * Max 1 per user, requires media or 50+ char text, cached for 5 minutes.
 *
 * @param int $limit Number of trending posts to return (default 5)
 * @return array Array of post data arrays via Wo_PostData()
 */
function Wo_GetTrendingPosts($limit = 5) {
    global $wo, $sqlConnect;

    $limit = max(1, min(10, intval($limit)));
    $cacheKey = 'trending_posts:' . $limit;

    // Check Redis cache (5-minute TTL)
    if (class_exists('BitchatCache')) {
        $cached = BitchatCache::get($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    $postsTable     = T_POSTS;
    $reactionsTable = T_REACTIONS;
    $commentsTable  = T_COMMENTS;
    $usersTable     = T_USERS;
    $blocksTable    = T_BLOCKS;

    $now = time();
    $dayAgo = $now - (24 * 3600);
    $currentUserId = !empty($wo['user']['user_id']) ? intval($wo['user']['user_id']) : 0;

    // Build blocked-users exclusion
    $blockWhere = '';
    if ($currentUserId > 0) {
        $blockWhere = "AND p.user_id NOT IN (
            SELECT blocked FROM {$blocksTable} WHERE blocker = {$currentUserId}
            UNION
            SELECT blocker FROM {$blocksTable} WHERE blocked = {$currentUserId}
        )";
    }

    // Single query: top engagement posts from last 24h, max 1 per user
    $sql = "
        SELECT
            p.id,
            p.user_id,
            p.postText,
            p.postFile,
            p.multi_image,
            (
                (SELECT COUNT(*) FROM {$reactionsTable} r WHERE r.post_id = p.id) +
                (SELECT COUNT(*) FROM {$commentsTable} c WHERE c.post_id = p.id) * 2 +
                (SELECT COUNT(*) FROM {$postsTable} sp WHERE sp.parent_id = p.id AND sp.postShare = 1) * 1.5
            ) AS trend_score
        FROM {$postsTable} p
        JOIN {$usersTable} u ON p.user_id = u.user_id
        WHERE p.time >= {$dayAgo}
          AND p.active = '1'
          AND p.boosted = '0'
          AND u.active = '1'
          {$blockWhere}
          AND (
              p.postFile LIKE '%_image%'
              OR p.postFile LIKE '%_video%'
              OR p.postFile LIKE '%_soundFile%'
              OR p.multi_image = 1
              OR p.album_name != ''
              OR p.postYoutube != ''
              OR p.postVimeo != ''
              OR CHAR_LENGTH(p.postText) >= 50
          )
          AND p.page_id = 0
          AND p.group_id = 0
          AND p.event_id = 0
        GROUP BY p.user_id
        HAVING trend_score > 0
        ORDER BY trend_score DESC
        LIMIT {$limit}
    ";

    $result = mysqli_query($sqlConnect, $sql);
    if (!$result) {
        return array();
    }

    $posts = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $postData = Wo_PostData($row['id']);
        if (!empty($postData)) {
            $postData['trend_score'] = floatval($row['trend_score']);
            $posts[] = $postData;
        }
    }

    // Cache for 5 minutes
    if (class_exists('BitchatCache') && !empty($posts)) {
        BitchatCache::set($cacheKey, $posts, 300);
    }

    return $posts;
}

/**
 * Check if a user is posting too frequently (anti-flood nudge).
 * Returns cooldown info if 3+ posts in last hour, null otherwise.
 *
 * @param int $userId
 * @return array|null  {count, next_optimal_minutes} or null
 */
function Wo_GetPostingCooldownInfo($userId) {
    global $sqlConnect;

    $userId = intval($userId);
    $oneHourAgo = time() - 3600;

    $sql = "SELECT COUNT(*) AS cnt FROM " . T_POSTS . "
            WHERE user_id = {$userId} AND time >= {$oneHourAgo}";
    $result = mysqli_query($sqlConnect, $sql);
    if (!$result) return null;

    $row = mysqli_fetch_assoc($result);
    $count = intval($row['cnt']);

    if ($count >= 3) {
        // Suggest waiting: spread remaining posts over the hour
        $minutesLeft = 60 - intval((time() - $oneHourAgo) / 60);
        $optimalWait = max(5, intval($minutesLeft / 2));
        return array(
            'count' => $count,
            'next_optimal_minutes' => $optimalWait
        );
    }

    return null;
}
