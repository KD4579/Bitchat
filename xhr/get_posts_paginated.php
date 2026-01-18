<?php
/**
 * Paginated Posts API Endpoint
 * Returns posts with proper pagination support for infinite scroll
 *
 * Parameters:
 * - page: Page number (default: 1)
 * - limit: Posts per page (default: 10, max: 20)
 * - after_post_id: For cursor-based pagination
 * - filter_by: all, most_liked, text, photos, video, etc.
 * - user_id: Filter by specific user
 * - page_id: Filter by page
 * - group_id: Filter by group
 */

if ($f == 'get_posts_paginated') {

    // Validate and sanitize inputs
    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $limit = isset($_POST['limit']) ? min(20, max(1, intval($_POST['limit']))) : 10;
    $after_post_id = isset($_POST['after_post_id']) ? intval($_POST['after_post_id']) : 0;
    $filter_by = isset($_POST['filter_by']) ? Wo_Secure($_POST['filter_by']) : 'all';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $page_id = isset($_POST['page_id']) ? intval($_POST['page_id']) : 0;
    $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;

    $response = array(
        'status' => 200,
        'posts' => array(),
        'posts_html' => '',
        'has_more' => false,
        'next_page' => $page + 1,
        'last_post_id' => 0,
        'cached' => false
    );

    // Build cache key
    $cache_key = '';
    if ($wo['loggedin'] && BitchatCache::isEnabled()) {
        $cache_key = "feed:{$wo['user']['user_id']}";
        if ($user_id > 0) $cache_key .= ":user:{$user_id}";
        if ($page_id > 0) $cache_key .= ":page:{$page_id}";
        if ($group_id > 0) $cache_key .= ":group:{$group_id}";
        $cache_key .= ":filter:{$filter_by}:p:{$page}:l:{$limit}";

        // Try to get from cache (only for page 1-3 to keep cache manageable)
        if ($page <= 3 && $after_post_id == 0) {
            $cached = BitchatCache::get($cache_key);
            if ($cached !== false) {
                $cached['cached'] = true;
                echo json_encode($cached);
                exit();
            }
        }
    }

    // Build query parameters
    $query_params = array(
        'filter_by' => $filter_by,
        'limit' => $limit + 1, // Fetch one extra to check if more exist
        'publisher_id' => $user_id,
        'page_id' => $page_id,
        'group_id' => $group_id,
        'event_id' => $event_id
    );

    if ($after_post_id > 0) {
        $query_params['after_post_id'] = $after_post_id;
    }

    // Get posts
    $posts = Wo_GetPosts($query_params);

    // Check if there are more posts
    if (count($posts) > $limit) {
        $response['has_more'] = true;
        array_pop($posts); // Remove the extra post
    }

    // Generate HTML for posts
    $posts_html = '';
    $last_post_id = 0;

    if (!empty($posts)) {
        foreach ($posts as $post) {
            $wo['story'] = $post;
            $posts_html .= Wo_LoadPage('story/content');
            $last_post_id = $post['id'];
        }
    }

    $response['posts'] = $posts;
    $response['posts_html'] = $posts_html;
    $response['last_post_id'] = $last_post_id;
    $response['count'] = count($posts);

    // Cache the response (only for first 3 pages)
    if (!empty($cache_key) && $page <= 3 && $after_post_id == 0) {
        BitchatCache::set($cache_key, $response, BitchatCache::TTL_NEWS_FEED);
    }

    echo json_encode($response);
    exit();
}

/**
 * Get notification count with caching
 */
if ($f == 'get_notification_count_cached') {

    if (!$wo['loggedin']) {
        echo json_encode(array('status' => 401, 'count' => 0));
        exit();
    }

    $user_id = $wo['user']['user_id'];

    // Try cache first
    if (BitchatCache::isEnabled()) {
        $cached_count = BitchatCache::getNotificationCount($user_id);
        if ($cached_count !== false) {
            echo json_encode(array('status' => 200, 'count' => $cached_count, 'cached' => true));
            exit();
        }
    }

    // Get from database
    $count = Wo_CountNotifications(array('unread' => true));

    // Cache it
    if (BitchatCache::isEnabled()) {
        BitchatCache::setNotificationCount($user_id, $count);
    }

    echo json_encode(array('status' => 200, 'count' => $count, 'cached' => false));
    exit();
}

/**
 * Get user suggestions with caching
 */
if ($f == 'get_suggestions_cached') {

    if (!$wo['loggedin']) {
        echo json_encode(array('status' => 401, 'users' => array()));
        exit();
    }

    $user_id = $wo['user']['user_id'];
    $limit = isset($_POST['limit']) ? min(20, max(1, intval($_POST['limit']))) : 5;

    // Try cache first
    if (BitchatCache::isEnabled()) {
        $cached = BitchatCache::getSuggestions($user_id);
        if ($cached !== false) {
            echo json_encode(array('status' => 200, 'users' => $cached, 'cached' => true));
            exit();
        }
    }

    // Get from database
    $suggestions = Wo_UserSug($limit);

    // Generate HTML
    $html = '';
    if (!empty($suggestions)) {
        foreach ($suggestions as $user) {
            $wo['suggestedUser'] = $user;
            $html .= Wo_LoadPage('sidebar/sidebar-user-list');
        }
    }

    $result = array(
        'users' => $suggestions,
        'html' => $html
    );

    // Cache it
    if (BitchatCache::isEnabled()) {
        BitchatCache::setSuggestions($user_id, $result);
    }

    echo json_encode(array('status' => 200, 'users' => $result, 'cached' => false));
    exit();
}

/**
 * Get trending posts with caching
 */
if ($f == 'get_trending_cached') {

    $limit = isset($_POST['limit']) ? min(20, max(1, intval($_POST['limit']))) : 10;

    // Try cache first
    if (BitchatCache::isEnabled()) {
        $cached = BitchatCache::getTrending();
        if ($cached !== false) {
            echo json_encode(array('status' => 200, 'posts' => $cached, 'cached' => true));
            exit();
        }
    }

    // Get from database
    $posts = Wo_GetPosts(array(
        'filter_by' => 'most_liked',
        'limit' => $limit,
        'publisher_id' => 0
    ));

    // Generate HTML
    $html = '';
    if (!empty($posts)) {
        foreach ($posts as $post) {
            $wo['story'] = $post;
            $html .= Wo_LoadPage('story/content');
        }
    }

    $result = array(
        'posts' => $posts,
        'html' => $html
    );

    // Cache it
    if (BitchatCache::isEnabled()) {
        BitchatCache::setTrending($result);
    }

    echo json_encode(array('status' => 200, 'posts' => $result, 'cached' => false));
    exit();
}

/**
 * Invalidate cache when user creates/updates post
 */
if ($f == 'invalidate_feed_cache') {

    if (!$wo['loggedin']) {
        echo json_encode(array('status' => 401));
        exit();
    }

    if (BitchatCache::isEnabled()) {
        BitchatCache::invalidateFeed($wo['user']['user_id']);
    }

    echo json_encode(array('status' => 200));
    exit();
}
