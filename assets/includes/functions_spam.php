<?php
// +------------------------------------------------------------------------+
// | Bitchat Spam Tracking
// | Tracks domain frequency and text similarity for feed scoring.
// | Non-blocking — failures here never affect post creation.
// +------------------------------------------------------------------------+

/**
 * Track spam metrics after a post is created.
 * Called from Wo_RegisterPost() success block via function_exists() guard.
 *
 * @param int    $userId   Post author
 * @param string $postText Post text content
 * @param string $postLink Post link URL (if any)
 */
function Wo_TrackPostSpam($userId, $postText = '', $postLink = '') {
    global $sqlConnect;

    $spamTable = defined('T_SPAM_TRACKING') ? T_SPAM_TRACKING : 'Wo_Spam_Tracking';

    $userId   = intval($userId);
    $now      = time();
    $domain   = Wo_ExtractDomainFromPost($postText, $postLink);
    $textHash = Wo_GenerateTextHash($postText);

    // Get the last post ID for this user (the one just created)
    $query = mysqli_query($sqlConnect,
        "SELECT id FROM Wo_Posts WHERE user_id = {$userId} ORDER BY id DESC LIMIT 1"
    );
    $postId = 0;
    if ($query && mysqli_num_rows($query)) {
        $row = mysqli_fetch_assoc($query);
        $postId = intval($row['id']);
    }

    if ($postId <= 0) {
        return;
    }

    // Sanitize for SQL
    $domainSafe   = $domain ? "'" . mysqli_real_escape_string($sqlConnect, $domain) . "'" : 'NULL';
    $textHashSafe = $textHash ? "'" . mysqli_real_escape_string($sqlConnect, $textHash) . "'" : 'NULL';

    $sql = "INSERT INTO {$spamTable} (user_id, domain, text_hash, post_id, created_at)
            VALUES ({$userId}, {$domainSafe}, {$textHashSafe}, {$postId}, {$now})";

    @mysqli_query($sqlConnect, $sql);
}

/**
 * Extract the primary domain from post text or link.
 *
 * @param string $postText Post text (may contain URLs)
 * @param string $postLink Explicit post link
 * @return string|null     Domain name (e.g., 'example.com') or null
 */
function Wo_ExtractDomainFromPost($postText = '', $postLink = '') {
    // Try explicit link first
    if (!empty($postLink)) {
        $parsed = parse_url($postLink);
        if (!empty($parsed['host'])) {
            return strtolower(preg_replace('/^www\./', '', $parsed['host']));
        }
    }

    // Try to extract URL from text (WoWonder encodes links as [a]base64[/a])
    if (!empty($postText)) {
        // Check for WoWonder encoded links
        if (preg_match('/\[a\]([^[]+)\[\/a\]/', $postText, $matches)) {
            $decoded = base64_decode($matches[1]);
            if ($decoded) {
                $parsed = parse_url($decoded);
                if (!empty($parsed['host'])) {
                    return strtolower(preg_replace('/^www\./', '', $parsed['host']));
                }
            }
        }

        // Check for plain URLs
        if (preg_match('/(https?:\/\/[^\s<\]]+)/i', $postText, $matches)) {
            $parsed = parse_url($matches[1]);
            if (!empty($parsed['host'])) {
                return strtolower(preg_replace('/^www\./', '', $parsed['host']));
            }
        }
    }

    return null;
}

/**
 * Generate a simple hash of post text for duplicate detection.
 * Normalizes text before hashing: lowercase, strip whitespace/punctuation.
 *
 * @param string $postText Raw post text
 * @return string|null     MD5 hash of normalized text, or null if empty
 */
function Wo_GenerateTextHash($postText = '') {
    if (empty($postText)) {
        return null;
    }

    // Strip WoWonder link encoding, mentions, hashtags
    $text = preg_replace('/\[a\][^[]*\[\/a\]/', '', $postText);
    $text = preg_replace('/@\[\d+\]/', '', $text);
    $text = preg_replace('/#\[\d+\]/', '', $text);

    // Normalize: lowercase, strip extra whitespace and punctuation
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
    $text = trim($text);

    if (mb_strlen($text) < 10) {
        // Too short to meaningfully hash — skip
        return null;
    }

    return md5($text);
}

/**
 * Clean up old spam tracking records (called by cron).
 * Removes entries older than 48 hours.
 */
function Wo_CleanupSpamTracking() {
    global $sqlConnect;

    $spamTable = defined('T_SPAM_TRACKING') ? T_SPAM_TRACKING : 'Wo_Spam_Tracking';
    $cutoff = time() - (48 * 3600);

    @mysqli_query($sqlConnect, "DELETE FROM {$spamTable} WHERE created_at < {$cutoff}");
}
