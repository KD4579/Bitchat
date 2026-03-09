-- 006_feed_composite_indexes.sql
-- Composite indexes for feed query performance optimization
-- Safe to re-run: uses IF NOT EXISTS pattern via stored procedure

DELIMITER //
CREATE PROCEDURE IF NOT EXISTS bc_add_index_if_missing(
    IN tbl VARCHAR(100),
    IN idx VARCHAR(100),
    IN cols VARCHAR(500)
)
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO idx_exists
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx;
    IF idx_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (', cols, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- RANKED FEED: time-based filtering with common WHERE columns
CALL bc_add_index_if_missing('Wo_Posts', 'idx_ranked_feed', '`time` DESC, `boosted`, `postType`, `multi_image_post`, `id`');

-- USER POSTS: per-user time ordering (frequency penalty, profile feed)
CALL bc_add_index_if_missing('Wo_Posts', 'idx_user_posts_time', '`user_id`, `time` DESC, `id`');

-- CHRONOLOGICAL PAGINATION: core feed pagination
CALL bc_add_index_if_missing('Wo_Posts', 'idx_pagination_active', '`id`, `active`, `postShare`, `multi_image_post`');

-- SHARE COUNT: engagement scoring subquery
CALL bc_add_index_if_missing('Wo_Posts', 'idx_shares', '`parent_id`, `postShare`, `id`');

-- PAGE POSTS: page-specific feed
CALL bc_add_index_if_missing('Wo_Posts', 'idx_page_posts', '`page_id`, `id` DESC');

-- GROUP POSTS: group-specific feed
CALL bc_add_index_if_missing('Wo_Posts', 'idx_group_posts', '`group_id`, `id` DESC');

-- FOLLOWERS: used in every home feed query
CALL bc_add_index_if_missing('Wo_Followers', 'idx_follower_active', '`follower_id`, `active`, `following_id`');

-- GROUP MEMBERS: group membership check
CALL bc_add_index_if_missing('Wo_GroupMembers', 'idx_user_group_active', '`user_id`, `active`, `group_id`');

-- PAGE LIKES: page like check
CALL bc_add_index_if_missing('Wo_PageLikes', 'idx_user_page_active', '`user_id`, `active`, `page_id`');

-- REACTIONS: engagement count
CALL bc_add_index_if_missing('Wo_Reactions', 'idx_post_reactions', '`post_id`, `id`');

-- COMMENTS: engagement count
CALL bc_add_index_if_missing('Wo_Comments', 'idx_post_comments', '`post_id`, `id`');

-- BOT ACCOUNTS: fast lookup
CALL bc_add_index_if_missing('Wo_Bot_Accounts', 'idx_enabled_user', '`enabled`, `user_id`');

-- Cleanup helper procedure
DROP PROCEDURE IF EXISTS bc_add_index_if_missing;
