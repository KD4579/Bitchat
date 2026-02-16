<?php
if ($f == 'feed_algorithm') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    // Save feed algorithm settings
    if (isset($_POST['feed_algorithm_enabled'])) {
        Wo_SaveConfig('feed_algorithm_enabled', ($_POST['feed_algorithm_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['feed_weights'])) {
        $weights = json_decode($_POST['feed_weights'], true);
        if (is_array($weights)) {
            Wo_SaveConfig('feed_weights', json_encode($weights));
        }
    }
    if (isset($_POST['feed_candidate_pool'])) {
        $pool = max(20, min(200, intval($_POST['feed_candidate_pool'])));
        Wo_SaveConfig('feed_candidate_pool', strval($pool));
    }
    if (isset($_POST['feed_max_same_user'])) {
        $max = max(1, min(10, intval($_POST['feed_max_same_user'])));
        Wo_SaveConfig('feed_max_same_user', strval($max));
    }
    if (isset($_POST['feed_spam_window_hours'])) {
        $hours = max(1, min(168, intval($_POST['feed_spam_window_hours'])));
        Wo_SaveConfig('feed_spam_window_hours', strval($hours));
    }

    // Invalidate all ranked feed caches
    if (class_exists('BitchatCache')) {
        BitchatCache::deletePattern('ranked_feed:*');
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
