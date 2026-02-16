<?php
// +------------------------------------------------------------------------+
// | Bitchat Scheduled Posting
// | Admin-only scheduled posts via cron. Uses Wo_RegisterPost() for publishing.
// | Toggle via Wo_Config 'scheduled_posts_enabled'
// +------------------------------------------------------------------------+

/**
 * Create a scheduled post.
 *
 * @param int   $userId    Author user ID
 * @param array $postData  Same fields as Wo_RegisterPost() expects
 * @param int   $publishAt Unix timestamp for publishing
 * @return int|false       Scheduled post ID or false
 */
function Wo_CreateScheduledPost($userId, $postData, $publishAt) {
    global $sqlConnect;

    $userId    = intval($userId);
    $publishAt = intval($publishAt);
    $now       = time();

    if ($userId <= 0 || $publishAt <= $now || empty($postData)) {
        return false;
    }

    $postDataJson = mysqli_real_escape_string($sqlConnect, json_encode($postData));
    $table = T_SCHEDULED_POSTS;

    $sql = "INSERT INTO {$table} (user_id, post_data, publish_at, status, created_at, updated_at)
            VALUES ({$userId}, '{$postDataJson}', {$publishAt}, 'pending', {$now}, {$now})";

    $result = mysqli_query($sqlConnect, $sql);
    if ($result) {
        return mysqli_insert_id($sqlConnect);
    }
    return false;
}

/**
 * Get scheduled posts for a user (or all if admin).
 *
 * @param int    $userId  User ID (0 for all)
 * @param string $status  Filter by status (empty for all)
 * @param int    $limit   Max results
 * @return array          Array of scheduled post records
 */
function Wo_GetScheduledPosts($userId = 0, $status = '', $limit = 50) {
    global $sqlConnect;

    $table = T_SCHEDULED_POSTS;
    $where = "1=1";

    if ($userId > 0) {
        $userId = intval($userId);
        $where .= " AND user_id = {$userId}";
    }
    if (!empty($status)) {
        $status = mysqli_real_escape_string($sqlConnect, $status);
        $where .= " AND status = '{$status}'";
    }

    $limit = max(1, min(200, intval($limit)));
    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY publish_at ASC LIMIT {$limit}";
    $result = mysqli_query($sqlConnect, $sql);

    $posts = array();
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['post_data_decoded'] = json_decode($row['post_data'], true);
            $row['user_data'] = Wo_UserData($row['user_id']);
            $posts[] = $row;
        }
    }
    return $posts;
}

/**
 * Delete/cancel a scheduled post.
 *
 * @param int $id     Scheduled post ID
 * @param int $userId Owner user ID (0 = admin override)
 * @return bool
 */
function Wo_DeleteScheduledPost($id, $userId = 0) {
    global $sqlConnect;

    $id    = intval($id);
    $table = T_SCHEDULED_POSTS;
    $where = "id = {$id} AND status = 'pending'";

    if ($userId > 0) {
        $userId = intval($userId);
        $where .= " AND user_id = {$userId}";
    }

    return (bool) mysqli_query($sqlConnect, "UPDATE {$table} SET status = 'cancelled', updated_at = " . time() . " WHERE {$where}");
}

/**
 * Publish all due scheduled posts. Called by cron-job.php.
 * Each post published via Wo_RegisterPost() — identical to normal posting.
 */
function Wo_PublishScheduledPosts() {
    global $wo, $sqlConnect;

    $table = T_SCHEDULED_POSTS;
    $now   = time();

    // Get up to 10 due posts
    $sql = "SELECT * FROM {$table} WHERE status = 'pending' AND publish_at <= {$now} ORDER BY publish_at ASC LIMIT 10";
    $result = mysqli_query($sqlConnect, $sql);

    if (!$result || mysqli_num_rows($result) == 0) {
        return;
    }

    // Save current user context
    $originalUser    = isset($wo['user']) ? $wo['user'] : null;
    $originalLoggedin = isset($wo['loggedin']) ? $wo['loggedin'] : false;

    while ($scheduled = mysqli_fetch_assoc($result)) {
        $scheduledId = intval($scheduled['id']);
        $postData    = json_decode($scheduled['post_data'], true);

        if (!is_array($postData)) {
            mysqli_query($sqlConnect, "UPDATE {$table} SET status = 'failed', error_message = 'Invalid post data JSON', updated_at = {$now} WHERE id = {$scheduledId}");
            continue;
        }

        try {
            // Set user context to the scheduled post's author
            $authorData = Wo_UserData($scheduled['user_id']);
            if (empty($authorData) || empty($authorData['user_id'])) {
                mysqli_query($sqlConnect, "UPDATE {$table} SET status = 'failed', error_message = 'Author not found', updated_at = {$now} WHERE id = {$scheduledId}");
                continue;
            }

            $wo['user']     = $authorData;
            $wo['loggedin'] = true;

            // Publish using the standard post registration function
            $newPostId = Wo_RegisterPost($postData);

            if ($newPostId && is_numeric($newPostId) && $newPostId > 0) {
                mysqli_query($sqlConnect, "UPDATE {$table} SET status = 'published', published_post_id = {$newPostId}, updated_at = {$now} WHERE id = {$scheduledId}");
            } else {
                mysqli_query($sqlConnect, "UPDATE {$table} SET status = 'failed', error_message = 'Wo_RegisterPost returned false', updated_at = {$now} WHERE id = {$scheduledId}");
            }
        } catch (Exception $e) {
            $errorMsg = mysqli_real_escape_string($sqlConnect, substr($e->getMessage(), 0, 490));
            mysqli_query($sqlConnect, "UPDATE {$table} SET status = 'failed', error_message = '{$errorMsg}', updated_at = {$now} WHERE id = {$scheduledId}");
        }
    }

    // Restore original user context
    if ($originalUser !== null) {
        $wo['user'] = $originalUser;
    }
    $wo['loggedin'] = $originalLoggedin;
}
