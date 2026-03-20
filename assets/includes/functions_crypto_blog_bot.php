<?php
/**
 * Crypto Trading Views Blog Bot
 * Scrapes cryptocurrency news from TradingView and crypto RSS feeds,
 * then creates blog posts on Bitchat.
 */

/**
 * Run the Crypto Trading Views blog bot.
 * Called from cron-job.php.
 *
 * @param mysqli $sqlConnect Database connection
 * @param array $wo Global config array
 * @return int Number of blog posts created
 */
function bc_run_crypto_blog_bot($sqlConnect, $wo) {
    $db = new MysqliDb($sqlConnect);

    // Find the crypto blog bot account
    $db->where('username', 'cryptotradingviews');
    $db->where('enabled', 1);
    $bot = $db->getOne('Wo_Bot_Accounts');

    if (!$bot) {
        return 0;
    }

    // Check daily post limit
    $today = date('Y-m-d');
    if ($bot->posts_today_date == $today && $bot->posts_today >= $bot->max_posts_per_day) {
        return 0;
    }

    // Check frequency limit
    if ($bot->last_posted_at > 0) {
        $minNextPost = $bot->last_posted_at + ($bot->post_frequency * 60);
        if (time() < $minNextPost) {
            return 0;
        }
    }

    // Reset daily counter if new day
    $postsToday = ($bot->posts_today_date == $today) ? $bot->posts_today : 0;
    $remaining = $bot->max_posts_per_day - $postsToday;
    if ($remaining <= 0) {
        return 0;
    }

    // Fetch articles from TradingView and RSS feeds
    $articles = bc_fetch_crypto_news();

    if (empty($articles)) {
        return 0;
    }

    // Shuffle for variety, post 1 per cron run
    shuffle($articles);

    $posted = 0;
    $maxPerRun = 1;

    foreach ($articles as $article) {
        if ($posted >= $maxPerRun || $posted >= $remaining) {
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

        // Create the blog post
        $blogId = bc_create_crypto_blog_post($bot, $article, $sqlConnect, $wo);
        if ($blogId) {
            $db->insert('Wo_Bot_Posted', [
                'bot_id' => $bot->id,
                'article_hash' => $articleHash,
                'post_id' => $blogId
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
 * Fetch cryptocurrency news articles from multiple sources.
 *
 * @return array Array of articles with title, description, content, link, thumbnail, tags
 */
function bc_fetch_crypto_news() {
    $allArticles = [];

    // 1. Try scraping TradingView crypto news page
    $tvArticles = bc_scrape_tradingview_crypto();
    if (!empty($tvArticles)) {
        $allArticles = array_merge($allArticles, $tvArticles);
    }

    // 2. Fetch from crypto RSS feeds (same providers TradingView aggregates)
    $rssFeeds = [
        'https://cointelegraph.com/rss',
        'https://www.newsbtc.com/feed/',
        'https://coinpedia.org/feed/',
        'https://www.theblock.co/rss.xml',
    ];

    foreach ($rssFeeds as $feedUrl) {
        $feedArticles = bc_fetch_crypto_rss_feed($feedUrl);
        if (!empty($feedArticles)) {
            $allArticles = array_merge($allArticles, $feedArticles);
        }
    }

    // Deduplicate by title similarity
    $seen = [];
    $unique = [];
    foreach ($allArticles as $article) {
        $key = md5(strtolower(trim($article['title'])));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $article;
        }
    }

    return $unique;
}

/**
 * Scrape TradingView cryptocurrency news page.
 *
 * @return array Array of articles
 */
function bc_scrape_tradingview_crypto() {
    $url = 'https://in.tradingview.com/markets/cryptocurrencies/news/';
    $articles = [];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return $articles;
    }

    // Try to extract article data from embedded JSON (__NEXT_DATA__ or similar)
    if (preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $response, $match)) {
        $jsonData = json_decode($match[1], true);
        if ($jsonData && !empty($jsonData['props']['pageProps'])) {
            $pageProps = $jsonData['props']['pageProps'];
            // Extract articles from the pageProps structure
            $newsItems = [];
            // TradingView may store news in various keys
            foreach (['news', 'articles', 'items', 'stories'] as $key) {
                if (!empty($pageProps[$key]) && is_array($pageProps[$key])) {
                    $newsItems = $pageProps[$key];
                    break;
                }
            }
            foreach ($newsItems as $item) {
                $title = $item['title'] ?? $item['headline'] ?? '';
                $link = $item['link'] ?? $item['url'] ?? $item['storyPath'] ?? '';
                $desc = $item['description'] ?? $item['summary'] ?? $item['shortDescription'] ?? '';
                $thumb = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? '';

                if (!empty($title) && !empty($link)) {
                    $articles[] = [
                        'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                        'description' => html_entity_decode(strip_tags($desc), ENT_QUOTES, 'UTF-8'),
                        'content' => '',
                        'link' => $link,
                        'thumbnail' => $thumb,
                        'tags' => 'cryptocurrency,crypto,trading,blockchain',
                        'provider' => $item['provider'] ?? $item['source'] ?? 'TradingView'
                    ];
                }
            }
        }
    }

    // Fallback: parse HTML for article elements
    if (empty($articles)) {
        // Look for article titles and links in the HTML
        // TradingView uses data attributes and specific class patterns
        if (preg_match_all('/<a[^>]*href=["\']([^"\']*\/news\/[^"\']*)["\'][^>]*>.*?<[^>]*>([^<]+)<\/[^>]*>/s', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $link = $match[1];
                $title = trim(strip_tags($match[2]));
                if (strlen($title) > 20) {
                    // Make absolute URL if relative
                    if (strpos($link, 'http') !== 0) {
                        $link = 'https://in.tradingview.com' . $link;
                    }
                    $articles[] = [
                        'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                        'description' => '',
                        'content' => '',
                        'link' => $link,
                        'thumbnail' => '',
                        'tags' => 'cryptocurrency,crypto,trading,blockchain',
                        'provider' => 'TradingView'
                    ];
                }
            }
        }

        // Also try parsing JSON-LD structured data
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s', $response, $ldMatches)) {
            foreach ($ldMatches[1] as $ldJson) {
                $ld = json_decode($ldJson, true);
                if ($ld && isset($ld['@type']) && $ld['@type'] === 'ItemList' && !empty($ld['itemListElement'])) {
                    foreach ($ld['itemListElement'] as $item) {
                        $title = $item['name'] ?? $item['headline'] ?? '';
                        $link = $item['url'] ?? '';
                        if (!empty($title) && !empty($link)) {
                            $articles[] = [
                                'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                                'description' => $item['description'] ?? '',
                                'content' => '',
                                'link' => $link,
                                'thumbnail' => $item['image'] ?? '',
                                'tags' => 'cryptocurrency,crypto,trading,blockchain',
                                'provider' => 'TradingView'
                            ];
                        }
                    }
                }
            }
        }
    }

    return $articles;
}

/**
 * Fetch and parse a crypto RSS feed for blog content.
 *
 * @param string $url RSS feed URL
 * @return array Array of articles
 */
function bc_fetch_crypto_rss_feed($url) {
    $articles = [];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Bitchat CryptoBlogBot/1.0 (+https://bitchat.live)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return $articles;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NONET);
    libxml_clear_errors();

    if ($xml === false) {
        return $articles;
    }

    $items = [];
    if (isset($xml->channel->item)) {
        $items = $xml->channel->item;
    } elseif (isset($xml->entry)) {
        $items = $xml->entry;
    }

    $count = 0;
    foreach ($items as $item) {
        if ($count >= 10) break; // Limit per feed

        $title = trim((string)($item->title ?? ''));
        $link = '';
        $description = '';
        $content = '';
        $thumbnail = '';

        // Get link
        if (!empty((string)$item->link)) {
            $link = trim((string)$item->link);
        } elseif (isset($item->link)) {
            foreach ($item->link as $l) {
                $attrs = $l->attributes();
                if (isset($attrs->href)) {
                    $link = (string)$attrs->href;
                    break;
                }
            }
        }

        // Get description
        if (!empty((string)$item->description)) {
            $description = (string)$item->description;
        } elseif (!empty((string)$item->summary)) {
            $description = (string)$item->summary;
        }

        // Get full content
        $namespaces = $item->getNamespaces(true);
        if (isset($namespaces['content'])) {
            $contentNs = $item->children($namespaces['content']);
            if (isset($contentNs->encoded)) {
                $content = (string)$contentNs->encoded;
            }
        }
        if (empty($content) && !empty((string)$item->content)) {
            $content = (string)$item->content;
        }

        // Get thumbnail
        if (isset($namespaces['media'])) {
            $media = $item->children($namespaces['media']);
            if (isset($media->thumbnail)) {
                $thumbnail = (string)$media->thumbnail->attributes()->url;
            }
            if (empty($thumbnail) && isset($media->content)) {
                $attrs = $media->content->attributes();
                if (isset($attrs->url)) {
                    $thumbnail = (string)$attrs->url;
                }
            }
        }
        if (empty($thumbnail) && isset($item->enclosure)) {
            $encAttrs = $item->enclosure->attributes();
            if (isset($encAttrs->type) && strpos((string)$encAttrs->type, 'image') !== false) {
                $thumbnail = (string)$encAttrs->url;
            }
        }
        // Extract from content/description HTML
        if (empty($thumbnail)) {
            $htmlToSearch = !empty($content) ? $content : $description;
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $htmlToSearch, $imgMatch)) {
                $thumbnail = $imgMatch[1];
            }
        }

        // Extract tags from categories
        $tags = ['cryptocurrency', 'crypto', 'trading'];
        if (isset($item->category)) {
            foreach ($item->category as $cat) {
                $catName = strtolower(trim((string)$cat));
                if (!empty($catName) && strlen($catName) < 30) {
                    $tags[] = preg_replace('/[^a-z0-9]/', '', $catName);
                }
            }
        }
        $tags = array_unique(array_filter($tags));

        if (empty($title) || empty($link)) {
            continue;
        }

        // Only include crypto-related articles
        $titleLower = strtolower($title);
        $cryptoKeywords = ['bitcoin', 'btc', 'ethereum', 'eth', 'crypto', 'blockchain', 'token', 'defi',
                          'nft', 'altcoin', 'mining', 'staking', 'wallet', 'exchange', 'binance',
                          'coinbase', 'solana', 'xrp', 'cardano', 'dogecoin', 'polygon', 'avalanche',
                          'web3', 'dao', 'dex', 'cex', 'halving', 'bull', 'bear', 'whale',
                          'usdt', 'usdc', 'stablecoin', 'sec', 'regulation', 'spot etf', 'ledger',
                          'memecoin', 'meme coin', 'layer 2', 'l2', 'rollup', 'airdrop'];
        $isCrypto = false;
        foreach ($cryptoKeywords as $kw) {
            if (strpos($titleLower, $kw) !== false) {
                $isCrypto = true;
                break;
            }
        }
        if (!$isCrypto) {
            continue;
        }

        $articles[] = [
            'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode(strip_tags($description), ENT_QUOTES, 'UTF-8'),
            'content' => $content,
            'link' => $link,
            'thumbnail' => $thumbnail,
            'tags' => implode(',', array_slice($tags, 0, 8)),
            'provider' => bc_get_provider_from_url($link)
        ];
        $count++;
    }

    return $articles;
}

/**
 * Extract provider name from article URL.
 */
function bc_get_provider_from_url($url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return 'Unknown';

    $host = strtolower($host);
    $providers = [
        'cointelegraph.com' => 'Cointelegraph',
        'newsbtc.com' => 'NewsBTC',
        'coinpedia.org' => 'Coinpedia',
        'theblock.co' => 'The Block',
        'coindesk.com' => 'CoinDesk',
        'decrypt.co' => 'Decrypt',
        'bitcoinist.com' => 'Bitcoinist',
        'tradingview.com' => 'TradingView',
    ];

    foreach ($providers as $domain => $name) {
        if (strpos($host, $domain) !== false) {
            return $name;
        }
    }

    return ucfirst(str_replace('www.', '', $host));
}

/**
 * Fetch full article content from a source URL.
 *
 * @param string $url Article URL
 * @return string HTML content of the article body
 */
function bc_fetch_article_content($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return '';
    }

    // Try to extract the main article content
    $content = '';

    // Method 1: Look for <article> tag
    if (preg_match('/<article[^>]*>(.*?)<\/article>/s', $response, $match)) {
        $content = $match[1];
    }
    // Method 2: Look for common content containers
    elseif (preg_match('/<div[^>]*class=["\'][^"\']*(?:post-content|article-body|entry-content|post_content|article__body|single-post-content)[^"\']*["\'][^>]*>(.*?)<\/div>/s', $response, $match)) {
        $content = $match[1];
    }
    // Method 3: JSON-LD articleBody
    elseif (preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s', $response, $match)) {
        $ld = json_decode($match[1], true);
        if ($ld && !empty($ld['articleBody'])) {
            $content = '<p>' . nl2br(htmlspecialchars($ld['articleBody'], ENT_QUOTES, 'UTF-8')) . '</p>';
        }
    }

    if (empty($content)) {
        return '';
    }

    // Clean up the content - keep safe HTML only
    $content = preg_replace('/<script[^>]*>.*?<\/script>/s', '', $content);
    $content = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $content);
    $content = preg_replace('/<iframe[^>]*>.*?<\/iframe>/s', '', $content);
    $content = preg_replace('/<form[^>]*>.*?<\/form>/s', '', $content);
    // Remove ads, social sharing, etc.
    $content = preg_replace('/<div[^>]*class=["\'][^"\']*(?:ad-|social|share|related|newsletter|subscribe|sidebar)[^"\']*["\'][^>]*>.*?<\/div>/s', '', $content);

    // Strip dangerous attributes
    $content = preg_replace('/\s*on\w+=["\'][^"\']*["\']/i', '', $content);
    $content = preg_replace('/\s*style=["\'][^"\']*["\']/i', '', $content);

    // Only allow safe HTML tags
    $content = strip_tags($content, '<p><br><h2><h3><h4><strong><b><em><i><ul><ol><li><blockquote><a><img>');

    // Limit content length for blog post
    if (strlen($content) > 15000) {
        $content = substr($content, 0, 15000);
        // Close at last complete paragraph
        $lastP = strrpos($content, '</p>');
        if ($lastP !== false && $lastP > 5000) {
            $content = substr($content, 0, $lastP + 4);
        }
    }

    return trim($content);
}

/**
 * Create a blog post from a crypto news article.
 *
 * @param object $bot Bot record from Wo_Bot_Accounts
 * @param array $article Article data
 * @param mysqli $sqlConnect Database connection
 * @param array $wo Global config array
 * @return int|false Blog ID on success, false on failure
 */
function bc_create_crypto_blog_post($bot, $article, $sqlConnect, $wo) {
    $title = $article['title'];
    $description = $article['description'];
    $link = $article['link'];
    $tags = $article['tags'] ?? 'cryptocurrency,crypto,trading';
    $provider = $article['provider'] ?? '';

    // Get or build full content
    $content = '';
    if (!empty($article['content'])) {
        $content = $article['content'];
    }

    // If no content from RSS, try fetching from source URL
    if (empty($content) || strlen(strip_tags($content)) < 100) {
        $fetched = bc_fetch_article_content($link);
        if (!empty($fetched)) {
            $content = $fetched;
        }
    }

    // Build blog content with attribution
    if (empty($content) || strlen(strip_tags($content)) < 100) {
        // Use description as content if no full article could be fetched
        $cleanDesc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $content = "<p>{$cleanDesc}</p>";
    }

    // Add source attribution at the end
    $sourceName = htmlspecialchars($provider, ENT_QUOTES, 'UTF-8');
    $sourceLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $content .= "\n<p><strong>Source:</strong> <a href=\"{$sourceLink}\" target=\"_blank\" rel=\"nofollow noopener\">{$sourceName}</a></p>";

    // Prepare description (max 290 chars)
    $cleanDescription = strip_tags($description);
    $cleanDescription = html_entity_decode($cleanDescription, ENT_QUOTES, 'UTF-8');
    $cleanDescription = trim(preg_replace('/\s+/', ' ', $cleanDescription));
    if (mb_strlen($cleanDescription) > 290) {
        $cleanDescription = mb_substr($cleanDescription, 0, 287) . '...';
    }
    if (mb_strlen($cleanDescription) < 32) {
        // Pad short descriptions
        $cleanDescription = $cleanDescription . ' — Cryptocurrency news and market analysis from ' . $provider;
        if (mb_strlen($cleanDescription) < 32) {
            $cleanDescription .= '. Read more about crypto trading, Bitcoin, Ethereum and blockchain.';
        }
    }

    // Find the crypto/technology blog category
    $categoryId = bc_get_crypto_blog_category($sqlConnect);

    // Sanitize for DB
    $titleSafe = mysqli_real_escape_string($sqlConnect, htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));
    $contentSafe = mysqli_real_escape_string($sqlConnect, $content);
    $descSafe = mysqli_real_escape_string($sqlConnect, htmlspecialchars($cleanDescription, ENT_QUOTES, 'UTF-8'));
    $tagsSafe = mysqli_real_escape_string($sqlConnect, htmlspecialchars($tags, ENT_QUOTES, 'UTF-8'));
    $now = time();

    // Insert blog post directly (bypassing Wo_InsertBlog which requires login)
    $blogQuery = "INSERT INTO " . T_BLOG . " (
        `user`, `title`, `content`, `description`, `posted`, `category`, `tags`, `active`
    ) VALUES (
        {$bot->user_id},
        '{$titleSafe}',
        '{$contentSafe}',
        '{$descSafe}',
        {$now},
        '{$categoryId}',
        '{$tagsSafe}',
        1
    )";

    $result = mysqli_query($sqlConnect, $blogQuery);
    if (!$result) {
        return false;
    }

    $blogId = mysqli_insert_id($sqlConnect);

    // Download and save thumbnail
    $thumbnailPath = '';
    if (!empty($article['thumbnail'])) {
        $thumbnailPath = bc_download_blog_thumbnail($article['thumbnail'], $wo);
    }

    // Update thumbnail if downloaded
    if (!empty($thumbnailPath)) {
        $thumbSafe = mysqli_real_escape_string($sqlConnect, $thumbnailPath);
        mysqli_query($sqlConnect, "UPDATE " . T_BLOG . " SET `thumbnail` = '{$thumbSafe}' WHERE `id` = {$blogId}");
    }

    // Create accompanying post in the feed
    $postTags = '';
    $tagsArr = explode(',', $tags);
    foreach ($tagsArr as $tag) {
        $tag = trim($tag);
        if (!empty($tag)) {
            $postTags .= '#' . $tag . ' ';
        }
    }

    $postText = htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' | ' . trim($postTags);
    $postTextSafe = mysqli_real_escape_string($sqlConnect, $postText);
    $registered = date('n') . '/' . date('Y');

    $postQuery = "INSERT INTO " . T_POSTS . " (
        `user_id`, `blog_id`, `postText`, `postPrivacy`, `postType`, `time`, `registered`, `active`
    ) VALUES (
        {$bot->user_id},
        {$blogId},
        '{$postTextSafe}',
        '0',
        'post',
        {$now},
        '{$registered}',
        1
    )";

    $postResult = mysqli_query($sqlConnect, $postQuery);
    if ($postResult) {
        $postId = mysqli_insert_id($sqlConnect);
        mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `post_id` = {$postId} WHERE `id` = {$postId}");
    }

    // Invalidate cache
    if (class_exists('BitchatCache')) {
        BitchatCache::delete('trending');
    }

    return $blogId;
}

/**
 * Get or create a crypto blog category.
 *
 * @param mysqli $sqlConnect Database connection
 * @return int Category ID
 */
function bc_get_crypto_blog_category($sqlConnect) {
    // Check for existing crypto category
    $result = mysqli_query($sqlConnect, "SELECT `id` FROM " . T_BLOGS_CATEGORY . " WHERE LOWER(`lang_key`) LIKE '%crypto%' OR LOWER(`lang_key`) LIKE '%blockchain%' LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['id'];
    }

    // Check for technology category
    $result = mysqli_query($sqlConnect, "SELECT `id` FROM " . T_BLOGS_CATEGORY . " WHERE LOWER(`lang_key`) LIKE '%tech%' LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['id'];
    }

    // Fallback: use first available category
    $result = mysqli_query($sqlConnect, "SELECT `id` FROM " . T_BLOGS_CATEGORY . " ORDER BY `id` ASC LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['id'];
    }

    return 1; // Default fallback
}

/**
 * Download article thumbnail for blog post.
 *
 * @param string $imageUrl Remote image URL
 * @param array $wo Global config
 * @return string|false Relative path to saved image, or false on failure
 */
function bc_download_blog_thumbnail($imageUrl, $wo) {
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
        CURLOPT_USERAGENT => 'Bitchat CryptoBlogBot/1.0',
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($imageData) || strlen($imageData) < 1000) {
        return false;
    }

    // Verify it's actually an image
    if (empty($contentType) || strpos($contentType, 'image') === false) {
        return false;
    }

    $uploadDir = 'upload/photos/' . date('Y/m');
    $fullDir = realpath(dirname(__FILE__) . '/../../') . '/' . $uploadDir;
    if (!is_dir($fullDir)) {
        mkdir($fullDir, 0755, true);
    }

    // Convert to WebP if possible
    if (function_exists('imagewebp')) {
        $srcImage = @imagecreatefromstring($imageData);
        if ($srcImage) {
            // Resize to 1200x600 for blog thumbnail
            $origW = imagesx($srcImage);
            $origH = imagesy($srcImage);
            $targetW = 1200;
            $targetH = 600;

            if ($origW <= 0 || $origH <= 0) {
                imagedestroy($srcImage);
                file_put_contents($filePath, $imageData);
                return $filePath;
            }

            $resized = imagecreatetruecolor($targetW, $targetH);
            // Preserve transparency for PNG
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            // Calculate crop dimensions (center crop)
            $srcRatio = $origW / $origH;
            $targetRatio = $targetW / $targetH;
            if ($srcRatio > $targetRatio) {
                $cropH = $origH;
                $cropW = intval($origH * $targetRatio);
                $cropX = intval(($origW - $cropW) / 2);
                $cropY = 0;
            } else {
                $cropW = $origW;
                $cropH = intval($origW / $targetRatio);
                $cropX = 0;
                $cropY = intval(($origH - $cropH) / 2);
            }

            imagecopyresampled($resized, $srcImage, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);

            $filename = 'blog_crypto_' . md5($imageUrl . time()) . '.webp';
            $filepath = $fullDir . '/' . $filename;

            if (imagewebp($resized, $filepath, 82)) {
                imagedestroy($srcImage);
                imagedestroy($resized);
                return $uploadDir . '/' . $filename;
            }
            imagedestroy($srcImage);
            imagedestroy($resized);
        }
    }

    // Fallback: save as-is
    $ext = 'jpg';
    if (strpos($contentType, 'png') !== false) $ext = 'png';
    elseif (strpos($contentType, 'webp') !== false) $ext = 'webp';

    $filename = 'blog_crypto_' . md5($imageUrl . time()) . '.' . $ext;
    $filepath = $fullDir . '/' . $filename;

    if (file_put_contents($filepath, $imageData) !== false) {
        return $uploadDir . '/' . $filename;
    }

    return false;
}
