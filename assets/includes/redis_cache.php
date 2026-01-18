<?php
// +------------------------------------------------------------------------+
// | Redis Caching Helper Functions for Bitchat
// | Provides caching layer to reduce database load
// +------------------------------------------------------------------------+

class BitchatCache {
    private static $redis = null;
    private static $enabled = false;
    private static $prefix = 'bitchat:';

    // Cache TTL constants (in seconds)
    const TTL_NEWS_FEED = 60;        // 1 minute
    const TTL_NOTIF_COUNT = 10;      // 10 seconds
    const TTL_SUGGESTIONS = 300;     // 5 minutes
    const TTL_TRENDING = 120;        // 2 minutes
    const TTL_USER_DATA = 60;        // 1 minute
    const TTL_SHORT = 30;            // 30 seconds

    /**
     * Initialize Redis connection
     */
    public static function init() {
        if (self::$redis !== null) {
            return self::$enabled;
        }

        try {
            self::$redis = new Redis();
            $connected = self::$redis->connect('127.0.0.1', 6379, 2.5);

            if ($connected) {
                self::$redis->select(2); // Use database 2 for caching (1 is for sessions)
                self::$enabled = true;
            }
        } catch (Exception $e) {
            self::$enabled = false;
            error_log("Redis cache connection failed: " . $e->getMessage());
        }

        return self::$enabled;
    }

    /**
     * Check if cache is available
     */
    public static function isEnabled() {
        if (self::$redis === null) {
            self::init();
        }
        return self::$enabled;
    }

    /**
     * Get cached value
     * @param string $key Cache key
     * @return mixed|false Returns cached data or false if not found
     */
    public static function get($key) {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            $data = self::$redis->get(self::$prefix . $key);
            if ($data !== false) {
                return json_decode($data, true);
            }
        } catch (Exception $e) {
            error_log("Redis get error: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Set cached value
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public static function set($key, $value, $ttl = 60) {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            $data = json_encode($value);
            return self::$redis->setex(self::$prefix . $key, $ttl, $data);
        } catch (Exception $e) {
            error_log("Redis set error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete cached value
     * @param string $key Cache key
     * @return bool Success status
     */
    public static function delete($key) {
        if (!self::isEnabled()) {
            return false;
        }

        try {
            return self::$redis->del(self::$prefix . $key) > 0;
        } catch (Exception $e) {
            error_log("Redis delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete multiple keys by pattern
     * @param string $pattern Key pattern (e.g., "feed:*")
     * @return int Number of keys deleted
     */
    public static function deletePattern($pattern) {
        if (!self::isEnabled()) {
            return 0;
        }

        try {
            $keys = self::$redis->keys(self::$prefix . $pattern);
            if (!empty($keys)) {
                return self::$redis->del($keys);
            }
        } catch (Exception $e) {
            error_log("Redis deletePattern error: " . $e->getMessage());
        }

        return 0;
    }

    /**
     * Increment a counter
     * @param string $key Cache key
     * @param int $ttl Time to live (only set on first increment)
     * @return int New value
     */
    public static function increment($key, $ttl = 60) {
        if (!self::isEnabled()) {
            return 0;
        }

        try {
            $fullKey = self::$prefix . $key;
            $value = self::$redis->incr($fullKey);
            if ($value === 1) {
                self::$redis->expire($fullKey, $ttl);
            }
            return $value;
        } catch (Exception $e) {
            error_log("Redis increment error: " . $e->getMessage());
            return 0;
        }
    }

    // =========================================================================
    // Specific Cache Methods for Bitchat Features
    // =========================================================================

    /**
     * Get cached news feed for user
     */
    public static function getNewsFeed($userId, $page = 1) {
        return self::get("feed:{$userId}:page:{$page}");
    }

    /**
     * Set cached news feed for user
     */
    public static function setNewsFeed($userId, $page, $posts) {
        return self::set("feed:{$userId}:page:{$page}", $posts, self::TTL_NEWS_FEED);
    }

    /**
     * Invalidate user's feed cache
     */
    public static function invalidateFeed($userId) {
        return self::deletePattern("feed:{$userId}:*");
    }

    /**
     * Get cached notification count
     */
    public static function getNotificationCount($userId) {
        return self::get("notif_count:{$userId}");
    }

    /**
     * Set cached notification count
     */
    public static function setNotificationCount($userId, $count) {
        return self::set("notif_count:{$userId}", $count, self::TTL_NOTIF_COUNT);
    }

    /**
     * Invalidate notification count cache
     */
    public static function invalidateNotificationCount($userId) {
        return self::delete("notif_count:{$userId}");
    }

    /**
     * Get cached user suggestions
     */
    public static function getSuggestions($userId) {
        return self::get("suggestions:{$userId}");
    }

    /**
     * Set cached user suggestions
     */
    public static function setSuggestions($userId, $suggestions) {
        return self::set("suggestions:{$userId}", $suggestions, self::TTL_SUGGESTIONS);
    }

    /**
     * Get cached trending posts
     */
    public static function getTrending() {
        return self::get("trending");
    }

    /**
     * Set cached trending posts
     */
    public static function setTrending($posts) {
        return self::set("trending", $posts, self::TTL_TRENDING);
    }

    /**
     * Rate limiting helper
     * @param string $action Action being rate limited
     * @param string $identifier User ID or IP
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $window Time window in seconds
     * @return bool True if action is allowed, false if rate limited
     */
    public static function rateLimit($action, $identifier, $maxAttempts = 10, $window = 60) {
        $key = "ratelimit:{$action}:{$identifier}";
        $attempts = self::increment($key, $window);
        return $attempts <= $maxAttempts;
    }
}

// Initialize cache on include
BitchatCache::init();
