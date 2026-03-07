<?php
/**
 * News Bot RSS Fetcher & Auto-Poster
 * Fetches RSS feeds, parses articles, and creates posts as bot user accounts.
 */

/**
 * Run a single bot: fetch RSS feeds, create posts for new articles.
 *
 * @param int $bot_id The bot ID from Wo_Bot_Accounts
 * @param mysqli $sqlConnect Database connection
 * @param array $wo Global config array
 * @return int Number of posts created
 */
function bc_run_single_bot($bot_id, $sqlConnect, $wo) {
    $db = new MysqliDb($sqlConnect);

    // Get bot record
    $db->where('id', intval($bot_id));
    $bot = $db->getOne('Wo_Bot_Accounts');
    if (!$bot || !$bot->enabled) {
        return 0;
    }

    // Check daily post limit
    $today = date('Y-m-d');
    if ($bot->posts_today_date == $today && $bot->posts_today >= $bot->max_posts_per_day) {
        return 0;
    }

    // Check frequency limit (don't post too soon after last post)
    if ($bot->last_posted_at > 0) {
        $minNextPost = $bot->last_posted_at + ($bot->post_frequency * 60);
        if (time() < $minNextPost) {
            return 0;
        }
    }

    // Parse RSS feed URLs
    $feedUrls = array_filter(array_map('trim', explode("\n", $bot->news_sources)));
    if (empty($feedUrls)) {
        return 0;
    }

    // Reset daily counter if new day
    $postsToday = ($bot->posts_today_date == $today) ? $bot->posts_today : 0;
    $remaining = $bot->max_posts_per_day - $postsToday;
    if ($remaining <= 0) {
        return 0;
    }

    // Collect articles from all feeds
    $articles = [];
    foreach ($feedUrls as $feedUrl) {
        $feedUrl = trim($feedUrl);
        if (empty($feedUrl) || !filter_var($feedUrl, FILTER_VALIDATE_URL)) {
            continue;
        }
        $feedArticles = bc_fetch_rss_feed($feedUrl);
        foreach ($feedArticles as $article) {
            $articles[] = $article;
        }
    }

    if (empty($articles)) {
        return 0;
    }

    // Shuffle to mix sources, then limit
    shuffle($articles);

    $posted = 0;
    foreach ($articles as $article) {
        if ($posted >= $remaining) {
            break;
        }

        // Check if already posted (by article URL hash)
        $articleHash = md5($article['link']);
        $db->where('bot_id', $bot->id);
        $db->where('article_hash', $articleHash);
        $existing = $db->getOne('Wo_Bot_Posted');
        if ($existing) {
            continue;
        }

        // Create the post
        $postId = bc_create_bot_post($bot, $article, $sqlConnect, $wo);
        if ($postId) {
            // Track posted article
            $db->insert('Wo_Bot_Posted', [
                'bot_id' => $bot->id,
                'article_hash' => $articleHash,
                'post_id' => $postId
            ]);
            $posted++;
        }
    }

    // Update bot stats
    if ($posted > 0) {
        $db->where('id', $bot->id);
        $db->update('Wo_Bot_Accounts', [
            'last_posted_at' => time(),
            'posts_today' => $postsToday + $posted,
            'posts_today_date' => $today
        ]);
    }

    return $posted;
}

/**
 * Fetch and parse an RSS/Atom feed URL.
 *
 * @param string $url Feed URL
 * @return array Array of articles with title, description, link, thumbnail, pubDate
 */
function bc_fetch_rss_feed($url) {
    $articles = [];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Bitchat News Bot/1.0 (+https://bitchat.live)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return $articles;
    }

    // Suppress XML warnings
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    libxml_clear_errors();

    if ($xml === false) {
        return $articles;
    }

    // RSS 2.0 format
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $article = bc_parse_rss_item($item);
            if ($article) {
                $articles[] = $article;
            }
        }
    }
    // Atom format
    elseif (isset($xml->entry)) {
        foreach ($xml->entry as $entry) {
            $article = bc_parse_atom_entry($entry);
            if ($article) {
                $articles[] = $article;
            }
        }
    }
    // RDF/RSS 1.0 format
    elseif ($xml->getName() === 'RDF' || isset($xml->item)) {
        foreach ($xml->item as $item) {
            $article = bc_parse_rss_item($item);
            if ($article) {
                $articles[] = $article;
            }
        }
    }

    return $articles;
}

/**
 * Parse a single RSS 2.0 <item> element.
 */
function bc_parse_rss_item($item) {
    $title = trim((string)$item->title);
    $link = trim((string)$item->link);
    $description = trim((string)$item->description);

    if (empty($title) || empty($link)) {
        return null;
    }

    // Clean HTML from description, limit length
    $description = strip_tags($description);
    $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
    $description = trim(preg_replace('/\s+/', ' ', $description));
    if (mb_strlen($description) > 300) {
        $description = mb_substr($description, 0, 297) . '...';
    }

    // Try to get thumbnail image
    $thumbnail = '';

    // Check media:thumbnail
    $namespaces = $item->getNamespaces(true);
    if (isset($namespaces['media'])) {
        $media = $item->children($namespaces['media']);
        if (isset($media->thumbnail)) {
            $thumbnail = (string)$media->thumbnail->attributes()->url;
        }
        if (empty($thumbnail) && isset($media->content)) {
            $attrs = $media->content->attributes();
            if (isset($attrs->url) && isset($attrs->medium) && (string)$attrs->medium === 'image') {
                $thumbnail = (string)$attrs->url;
            }
            if (empty($thumbnail) && isset($attrs->url) && preg_match('/\.(jpg|jpeg|png|gif|webp)/i', (string)$attrs->url)) {
                $thumbnail = (string)$attrs->url;
            }
        }
    }

    // Check enclosure
    if (empty($thumbnail) && isset($item->enclosure)) {
        $encAttrs = $item->enclosure->attributes();
        if (isset($encAttrs->type) && strpos((string)$encAttrs->type, 'image') !== false) {
            $thumbnail = (string)$encAttrs->url;
        }
    }

    // Try to extract image from description HTML (original, before strip_tags)
    if (empty($thumbnail)) {
        $origDesc = (string)$item->description;
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $origDesc, $imgMatch)) {
            $thumbnail = $imgMatch[1];
        }
    }

    $pubDate = '';
    if (!empty((string)$item->pubDate)) {
        $pubDate = (string)$item->pubDate;
    }

    return [
        'title' => $title,
        'description' => $description,
        'link' => $link,
        'thumbnail' => $thumbnail,
        'pubDate' => $pubDate
    ];
}

/**
 * Parse a single Atom <entry> element.
 */
function bc_parse_atom_entry($entry) {
    $title = trim((string)$entry->title);
    $link = '';

    // Atom links can have multiple <link> elements
    if (isset($entry->link)) {
        foreach ($entry->link as $l) {
            $attrs = $l->attributes();
            $rel = isset($attrs->rel) ? (string)$attrs->rel : 'alternate';
            if ($rel === 'alternate' || empty($link)) {
                $link = (string)$attrs->href;
            }
        }
    }

    $description = '';
    if (!empty((string)$entry->summary)) {
        $description = (string)$entry->summary;
    } elseif (!empty((string)$entry->content)) {
        $description = (string)$entry->content;
    }

    if (empty($title) || empty($link)) {
        return null;
    }

    // Clean HTML
    $rawDesc = $description;
    $description = strip_tags($description);
    $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
    $description = trim(preg_replace('/\s+/', ' ', $description));
    if (mb_strlen($description) > 300) {
        $description = mb_substr($description, 0, 297) . '...';
    }

    // Try thumbnail from media namespace
    $thumbnail = '';
    $namespaces = $entry->getNamespaces(true);
    if (isset($namespaces['media'])) {
        $media = $entry->children($namespaces['media']);
        if (isset($media->thumbnail)) {
            $thumbnail = (string)$media->thumbnail->attributes()->url;
        }
    }

    // Try from content HTML
    if (empty($thumbnail) && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $rawDesc, $imgMatch)) {
        $thumbnail = $imgMatch[1];
    }

    $pubDate = '';
    if (!empty((string)$entry->published)) {
        $pubDate = (string)$entry->published;
    } elseif (!empty((string)$entry->updated)) {
        $pubDate = (string)$entry->updated;
    }

    return [
        'title' => $title,
        'description' => $description,
        'link' => $link,
        'thumbnail' => $thumbnail,
        'pubDate' => $pubDate
    ];
}

/**
 * Create a post in Wo_Posts as the bot user.
 *
 * @param object $bot Bot record from Wo_Bot_Accounts
 * @param array $article Article data (title, description, link, thumbnail)
 * @param mysqli $sqlConnect Database connection
 * @param array $wo Global config array
 * @return int|false Post ID on success, false on failure
 */
function bc_create_bot_post($bot, $article, $sqlConnect, $wo) {
    // Build post text: title + description + link
    $title = htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8');
    $desc = htmlspecialchars($article['description'], ENT_QUOTES, 'UTF-8');

    $postText = $title;
    if (!empty($desc) && $desc !== $title) {
        $postText .= "\n\n" . $desc;
    }
    $postText .= "\n\n" . $article['link'];

    // Secure the text for DB insertion
    $postTextSafe = mysqli_real_escape_string($sqlConnect, $postText);
    $postLink = mysqli_real_escape_string($sqlConnect, $article['link']);
    $postLinkTitle = mysqli_real_escape_string($sqlConnect, $article['title']);
    $postLinkContent = mysqli_real_escape_string($sqlConnect, $article['description']);

    // Handle thumbnail
    $postLinkImage = '';
    if ($bot->include_thumbnail && !empty($article['thumbnail'])) {
        // Download and save the thumbnail locally
        $savedImage = bc_download_article_image($article['thumbnail'], $wo);
        if ($savedImage) {
            $postLinkImage = mysqli_real_escape_string($sqlConnect, $savedImage);
        }
    }

    $now = time();
    $registered = date('n') . '/' . date('Y');

    $query = "INSERT INTO " . T_POSTS . " (
        `user_id`, `postText`, `postLink`, `postLinkTitle`, `postLinkImage`, `postLinkContent`,
        `postPrivacy`, `postType`, `time`, `registered`, `active`
    ) VALUES (
        {$bot->user_id},
        '{$postTextSafe}',
        '{$postLink}',
        '{$postLinkTitle}',
        '{$postLinkImage}',
        '{$postLinkContent}',
        '0',
        'post',
        {$now},
        '{$registered}',
        1
    )";

    $result = mysqli_query($sqlConnect, $query);
    if ($result) {
        $postId = mysqli_insert_id($sqlConnect);
        // Set post_id = id (WoWonder convention)
        mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `post_id` = {$postId} WHERE `id` = {$postId}");

        // Invalidate feed cache if available
        if (class_exists('BitchatCache')) {
            BitchatCache::delete('trending');
        }

        return $postId;
    }

    return false;
}

/**
 * Download an article thumbnail image and save locally.
 *
 * @param string $imageUrl Remote image URL
 * @param array $wo Global config
 * @return string|false Relative path to saved image, or false on failure
 */
function bc_download_article_image($imageUrl, $wo) {
    if (empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        return false;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $imageUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Bitchat News Bot/1.0',
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($imageData)) {
        return false;
    }

    // Determine extension from content type
    $ext = 'jpg';
    if (strpos($contentType, 'png') !== false) {
        $ext = 'png';
    } elseif (strpos($contentType, 'gif') !== false) {
        $ext = 'gif';
    } elseif (strpos($contentType, 'webp') !== false) {
        $ext = 'webp';
    }

    // Save to upload directory
    $uploadDir = 'upload/photos/' . date('Y/m');
    $fullDir = realpath(dirname(__FILE__) . '/../../') . '/' . $uploadDir;
    if (!is_dir($fullDir)) {
        mkdir($fullDir, 0755, true);
    }

    $filename = 'bot_' . md5($imageUrl . time()) . '.' . $ext;
    $filepath = $fullDir . '/' . $filename;

    if (file_put_contents($filepath, $imageData) !== false) {
        return $uploadDir . '/' . $filename;
    }

    return false;
}

/**
 * Run all enabled bots (called from cron job).
 *
 * @param mysqli $sqlConnect Database connection
 * @param array $wo Global config array
 * @return array Summary of bot runs [bot_id => posts_count]
 */
function bc_run_all_bots($sqlConnect, $wo) {
    $db = new MysqliDb($sqlConnect);
    $db->where('enabled', 1);
    $bots = $db->get('Wo_Bot_Accounts');

    $results = [];
    foreach ($bots as $bot) {
        $count = bc_run_single_bot($bot->id, $sqlConnect, $wo);
        $results[$bot->id] = $count;
    }

    return $results;
}
