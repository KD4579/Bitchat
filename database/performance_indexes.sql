-- =====================================================
-- Bitchat Database Performance Optimization Indexes
-- =====================================================
-- Run this file to add recommended indexes for improved performance
-- Always backup your database before running any migrations!
-- Estimated time: 5-15 minutes depending on database size
-- =====================================================

-- Enable FULLTEXT search on commonly searched text fields
-- =====================================================

-- Posts: Add FULLTEXT index on post text for fast searching
-- This replaces LIKE '%keyword%' queries with FULLTEXT MATCH() AGAINST()
ALTER TABLE Wo_Posts
ADD FULLTEXT INDEX ft_postText (postText);

-- Users: Add FULLTEXT index on username and name for user search
ALTER TABLE Wo_Users
ADD FULLTEXT INDEX ft_username_name (username, first_name, last_name);

-- Pages: Add FULLTEXT index on page name and title
ALTER TABLE Wo_Pages
ADD FULLTEXT INDEX ft_page_name_title (page_name, page_title);

-- Groups: Add FULLTEXT index on group name and title
ALTER TABLE Wo_Groups
ADD FULLTEXT INDEX ft_group_name_title (group_name, group_title);

-- Hashtags: Add FULLTEXT index on tag name
ALTER TABLE Wo_Hashtags
ADD FULLTEXT INDEX ft_tag (tag);

-- Blog Posts: Add FULLTEXT index on blog titles and content
ALTER TABLE Wo_Blog
ADD FULLTEXT INDEX ft_blog_title_content (title, content);

-- Forum Threads: Add FULLTEXT index on thread titles
ALTER TABLE Wo_ForumThreads
ADD FULLTEXT INDEX ft_thread_headline (headline);

-- Products: Add FULLTEXT index on product names and descriptions
ALTER TABLE Wo_Products
ADD FULLTEXT INDEX ft_product_name_description (name, description);


-- Regular indexes for frequently queried fields
-- =====================================================

-- Users table optimization
ALTER TABLE Wo_Users
ADD INDEX idx_email (email),
ADD INDEX idx_active (active),
ADD INDEX idx_lastseen (lastseen),
ADD INDEX idx_verified (verified),
ADD INDEX idx_country_id (country_id),
ADD INDEX idx_gender (gender),
ADD INDEX idx_pro_type (pro_type),
ADD INDEX idx_admin (admin);

-- Posts table optimization
ALTER TABLE Wo_Posts
ADD INDEX idx_user_id_time (user_id, time DESC),
ADD INDEX idx_recipient_id (recipient_id),
ADD INDEX idx_page_id (page_id),
ADD INDEX idx_group_id (group_id),
ADD INDEX idx_event_id (event_id),
ADD INDEX idx_postType (postType),
ADD INDEX idx_time (time DESC),
ADD INDEX idx_boosted (boosted);

-- Comments optimization
ALTER TABLE Wo_Comments
ADD INDEX idx_post_id_time (post_id, time DESC),
ADD INDEX idx_user_id (user_id);

-- Comment Replies optimization
ALTER TABLE Wo_CommentsReplies
ADD INDEX idx_comment_id_time (comment_id, time DESC),
ADD INDEX idx_user_id (user_id);

-- Notifications optimization
ALTER TABLE Wo_Notifications
ADD INDEX idx_recipient_id_seen (recipient_id, seen, time DESC),
ADD INDEX idx_notifier_id (notifier_id),
ADD INDEX idx_type (type);

-- Messages optimization
ALTER TABLE Wo_Messages
ADD INDEX idx_from_to_time (from_id, to_id, time DESC),
ADD INDEX idx_seen (seen),
ADD INDEX idx_deleted_fs1 (deleted_fs1),
ADD INDEX idx_deleted_fs2 (deleted_fs2);

-- Followers/Following optimization
ALTER TABLE Wo_Followers
ADD INDEX idx_following_follower (following_id, follower_id),
ADD INDEX idx_active (active),
ADD INDEX idx_time (time DESC);

-- Likes optimization
ALTER TABLE Wo_Likes
ADD INDEX idx_post_id_user (post_id, user_id),
ADD INDEX idx_user_id (user_id);

-- Reactions optimization
ALTER TABLE Wo_Reactions
ADD INDEX idx_post_id_user (post_id, user_id),
ADD INDEX idx_reaction (reaction);

-- Groups Members optimization
ALTER TABLE Wo_GroupMembers
ADD INDEX idx_group_user (group_id, user_id),
ADD INDEX idx_active (active);

-- Pages Likes optimization
ALTER TABLE Wo_PageLikes
ADD INDEX idx_page_user (page_id, user_id);

-- Events Going optimization
ALTER TABLE Wo_EventsGoing
ADD INDEX idx_event_user (event_id, user_id);

-- Recent Searches optimization (for autocomplete)
ALTER TABLE Wo_RecentSearches
ADD INDEX idx_user_id_time (user_id, time DESC);

-- Blocks optimization
ALTER TABLE Wo_Blocks
ADD INDEX idx_blocker_blocked (blocker, blocked);

-- Sessions optimization
ALTER TABLE Wo_Sessions
ADD INDEX idx_user_id_platform (user_id, platform_id),
ADD INDEX idx_time (time DESC);

-- Hashtags optimization
ALTER TABLE Wo_Hashtags
ADD INDEX idx_tag_trend (tag, trend_use_num DESC),
ADD INDEX idx_last_trend_time (last_trend_time DESC);

-- Blog Categories optimization
ALTER TABLE Wo_BlogCategories
ADD INDEX idx_lang_key (lang_key);

-- Forum optimization
ALTER TABLE Wo_Forums
ADD INDEX idx_visibility (visibility);

ALTER TABLE Wo_ForumThreads
ADD INDEX idx_forum_id_time (forum_id, posted DESC),
ADD INDEX idx_views (views DESC);

ALTER TABLE Wo_ForumThreadReplies
ADD INDEX idx_thread_id_time (thread_id, posted DESC),
ADD INDEX idx_user_id (user_id);

-- Products optimization
ALTER TABLE Wo_Products
ADD INDEX idx_user_id_active (user_id, active),
ADD INDEX idx_category_time (category, time DESC),
ADD INDEX idx_price (price);

-- Ads optimization
ALTER TABLE Wo_Ads
ADD INDEX idx_active_appears (active, appears),
ADD INDEX idx_audience (audience);

-- Composite indexes for common query patterns
-- =====================================================

-- Feed query optimization (posts from followed users)
ALTER TABLE Wo_Posts
ADD INDEX idx_feed_query (user_id, time DESC, postPrivacy);

-- Notification count optimization
ALTER TABLE Wo_Notifications
ADD INDEX idx_unread_count (recipient_id, seen);

-- Message count optimization
ALTER TABLE Wo_Messages
ADD INDEX idx_unread_messages (to_id, seen);

-- User search with filters (age, country, gender, online)
ALTER TABLE Wo_Users
ADD INDEX idx_search_filters (country_id, gender, active, lastseen);

-- Comments with post ID and pagination
ALTER TABLE Wo_Comments
ADD INDEX idx_post_pagination (post_id, id DESC);


-- =====================================================
-- Query Optimization Recommendations
-- =====================================================
-- After adding these indexes, update your queries to use:
--
-- 1. FULLTEXT SEARCH instead of LIKE:
--    OLD: WHERE postText LIKE '%keyword%'
--    NEW: WHERE MATCH(postText) AGAINST('keyword' IN NATURAL LANGUAGE MODE)
--
-- 2. Use covered indexes by including only necessary columns in SELECT
--
-- 3. Avoid SELECT * - specify only needed columns
--
-- 4. Use LIMIT for pagination queries
--
-- 5. Use EXPLAIN to analyze query performance
--
-- =====================================================
-- Maintenance
-- =====================================================
-- Run OPTIMIZE TABLE periodically to defragment tables:
-- OPTIMIZE TABLE Wo_Posts, Wo_Users, Wo_Comments, Wo_Notifications;
--
-- Monitor slow queries with:
-- SET GLOBAL slow_query_log = 'ON';
-- SET GLOBAL long_query_time = 2;
--
-- =====================================================
