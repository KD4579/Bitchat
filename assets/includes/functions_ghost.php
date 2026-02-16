<?php
// +------------------------------------------------------------------------+
// | Bitchat Ghost Activity Layer
// | Delayed reactions from admin accounts for perceived engagement.
// | Uses REAL reactions via Wo_AddReactions() — not fake data.
// | Toggle via Wo_Config 'ghost_activity_enabled'
// +------------------------------------------------------------------------+

/**
 * Queue a ghost reaction for a new post.
 * Called from Wo_RegisterPost() success block via function_exists() guard.
 *
 * @param int $postId  The newly created post ID
 */
function Wo_QueueGhostReaction($postId) {
    global $wo, $sqlConnect;

    $postId = intval($postId);
    if ($postId <= 0) return;

    // Don't ghost-react to admin/system posts
    if (!empty($wo['user']['admin']) && $wo['user']['admin'] == 1) return;

    $ghostTable = T_GHOST_QUEUE;

    // Check if already queued for this post (max 1 ghost reaction per post)
    $check = mysqli_query($sqlConnect, "SELECT id FROM {$ghostTable} WHERE post_id = {$postId} LIMIT 1");
    if ($check && mysqli_num_rows($check) > 0) return;

    // Get ghost account IDs from config
    $ghostAccounts = Wo_GetGhostAccounts();
    if (empty($ghostAccounts)) return;

    // Pick a random ghost account
    $actorId = $ghostAccounts[array_rand($ghostAccounts)];

    // Don't ghost-react to your own post
    if ($actorId == $wo['user']['user_id']) {
        // Pick another if available
        $others = array_diff($ghostAccounts, array($wo['user']['user_id']));
        if (empty($others)) return;
        $actorId = $others[array_rand($others)];
    }

    // Calculate delay
    $minDelay = intval($wo['config']['ghost_activity_min_delay'] ?? 1800);
    $maxDelay = intval($wo['config']['ghost_activity_max_delay'] ?? 7200);
    $minDelay = max(300, $minDelay);  // Minimum 5 minutes
    $maxDelay = max($minDelay + 60, $maxDelay);

    // Check if this is the user's first post (welcome engagement — shorter delay)
    $authorId = intval($wo['user']['user_id']);
    $postCount = mysqli_query($sqlConnect, "SELECT COUNT(*) as cnt FROM " . T_POSTS . " WHERE user_id = {$authorId}");
    $isFirstPost = false;
    if ($postCount) {
        $row = mysqli_fetch_assoc($postCount);
        if (intval($row['cnt']) <= 1) {
            $isFirstPost = true;
            $minDelay = max(300, intval($minDelay / 3));  // 1/3 of normal delay for first post
            $maxDelay = max($minDelay + 60, intval($maxDelay / 3));
        }
    }

    $delay     = rand($minDelay, $maxDelay);
    $executeAt = time() + $delay;

    // Random reaction type (1=like, 2=love, 3=haha, 4=wow — keep positive)
    $reactionTypes = array('1', '2', '4'); // like, love, wow
    $reactionData  = $reactionTypes[array_rand($reactionTypes)];

    $actorId = intval($actorId);
    $reactionDataSafe = mysqli_real_escape_string($sqlConnect, $reactionData);

    $sql = "INSERT INTO {$ghostTable} (post_id, actor_user_id, action_type, action_data, execute_at, status)
            VALUES ({$postId}, {$actorId}, 'reaction', '{$reactionDataSafe}', {$executeAt}, 'pending')";

    @mysqli_query($sqlConnect, $sql);
}

/**
 * Process the ghost activity queue. Called by cron-job.php.
 * Executes mature (ready) ghost reactions using real reaction functions.
 */
function Wo_ProcessGhostQueue() {
    global $wo, $sqlConnect;

    $ghostTable = T_GHOST_QUEUE;
    $now = time();

    // Get up to 20 ready items
    $sql = "SELECT * FROM {$ghostTable} WHERE status = 'pending' AND execute_at <= {$now} ORDER BY execute_at ASC LIMIT 20";
    $result = mysqli_query($sqlConnect, $sql);

    if (!$result || mysqli_num_rows($result) == 0) return;

    // Save current user context
    $originalUser     = isset($wo['user']) ? $wo['user'] : null;
    $originalLoggedin = isset($wo['loggedin']) ? $wo['loggedin'] : false;

    while ($item = mysqli_fetch_assoc($result)) {
        $itemId  = intval($item['id']);
        $postId  = intval($item['post_id']);
        $actorId = intval($item['actor_user_id']);

        // Verify post still exists
        $postCheck = mysqli_query($sqlConnect, "SELECT id FROM " . T_POSTS . " WHERE id = {$postId} LIMIT 1");
        if (!$postCheck || mysqli_num_rows($postCheck) == 0) {
            mysqli_query($sqlConnect, "UPDATE {$ghostTable} SET status = 'cancelled', executed_at = {$now} WHERE id = {$itemId}");
            continue;
        }

        // Set user context to the ghost actor
        $actorData = Wo_UserData($actorId);
        if (empty($actorData)) {
            mysqli_query($sqlConnect, "UPDATE {$ghostTable} SET status = 'cancelled', executed_at = {$now} WHERE id = {$itemId}");
            continue;
        }

        $wo['user']     = $actorData;
        $wo['loggedin'] = true;

        // Execute the reaction using the real function
        if ($item['action_type'] == 'reaction') {
            // Wo_AddReactions expects (post_id, reaction_type)
            // This creates a real entry in Wo_Reactions + sends notification
            if (function_exists('Wo_AddReactions')) {
                Wo_AddReactions($postId, $item['action_data']);
            }
        }

        mysqli_query($sqlConnect, "UPDATE {$ghostTable} SET status = 'executed', executed_at = {$now} WHERE id = {$itemId}");
    }

    // Restore original user context
    if ($originalUser !== null) {
        $wo['user'] = $originalUser;
    }
    $wo['loggedin'] = $originalLoggedin;

    // Clean up old executed/cancelled items (older than 7 days)
    $cutoff = $now - (7 * 86400);
    @mysqli_query($sqlConnect, "DELETE FROM {$ghostTable} WHERE status IN ('executed','cancelled') AND execute_at < {$cutoff}");
}

/**
 * Get configured ghost activity account IDs.
 *
 * @return array Array of user IDs
 */
function Wo_GetGhostAccounts() {
    global $wo;

    $configVal = $wo['config']['ghost_activity_accounts'] ?? '';
    if (empty($configVal)) return array();

    $ids = array_filter(array_map('intval', explode(',', $configVal)));
    return array_values($ids);
}
