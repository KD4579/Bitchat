<?php
/**
 * Database Performance Index Migration Script
 * Safely applies recommended indexes to improve Bitchat performance
 *
 * IMPORTANT: Backup your database before running this script!
 *
 * Usage: php apply_performance_indexes.php
 * Or access via browser: https://yourdomain.com/database/apply_performance_indexes.php
 */

// Require admin authentication if accessed via web
if (php_sapi_name() !== 'cli') {
    // Load Bitchat config
    require_once('../assets/init.php');

    // Verify admin access
    if (!$wo['loggedin'] || $wo['user']['admin'] != 1) {
        die('Access denied. Admin privileges required.');
    }
}

set_time_limit(0); // No time limit for this operation

echo "==============================================\n";
echo "Bitchat Database Performance Index Migration\n";
echo "==============================================\n\n";

// Connect to database
if (php_sapi_name() === 'cli') {
    // CLI mode - load config manually
    require_once('../assets/init.php');
}

$conn = mysqli_connect($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error() . "\n");
}

echo "✓ Connected to database: {$sql_db_name}\n\n";

// Index definitions grouped by table
$indexes = [
    // FULLTEXT Indexes
    'Wo_Posts' => [
        ['name' => 'ft_postText', 'type' => 'FULLTEXT', 'columns' => 'postText', 'description' => 'Post text search'],
        ['name' => 'idx_user_id_time', 'type' => 'INDEX', 'columns' => 'user_id, time DESC', 'description' => 'User posts by time'],
        ['name' => 'idx_recipient_id', 'type' => 'INDEX', 'columns' => 'recipient_id', 'description' => 'Recipient posts'],
        ['name' => 'idx_time', 'type' => 'INDEX', 'columns' => 'time DESC', 'description' => 'Posts by time'],
        ['name' => 'idx_postType', 'type' => 'INDEX', 'columns' => 'postType', 'description' => 'Filter by post type'],
    ],

    'Wo_Users' => [
        ['name' => 'ft_username_name', 'type' => 'FULLTEXT', 'columns' => 'username, first_name, last_name', 'description' => 'User search'],
        ['name' => 'idx_email', 'type' => 'INDEX', 'columns' => 'email', 'description' => 'Email lookup'],
        ['name' => 'idx_active', 'type' => 'INDEX', 'columns' => 'active', 'description' => 'Active users'],
        ['name' => 'idx_lastseen', 'type' => 'INDEX', 'columns' => 'lastseen', 'description' => 'Online status'],
        ['name' => 'idx_verified', 'type' => 'INDEX', 'columns' => 'verified', 'description' => 'Verified users'],
        ['name' => 'idx_search_filters', 'type' => 'INDEX', 'columns' => 'country_id, gender, active, lastseen', 'description' => 'Search filters'],
    ],

    'Wo_Pages' => [
        ['name' => 'ft_page_name_title', 'type' => 'FULLTEXT', 'columns' => 'page_name, page_title', 'description' => 'Page search'],
    ],

    'Wo_Groups' => [
        ['name' => 'ft_group_name_title', 'type' => 'FULLTEXT', 'columns' => 'group_name, group_title', 'description' => 'Group search'],
    ],

    'Wo_Hashtags' => [
        ['name' => 'ft_tag', 'type' => 'FULLTEXT', 'columns' => 'tag', 'description' => 'Hashtag search'],
        ['name' => 'idx_tag_trend', 'type' => 'INDEX', 'columns' => 'tag, trend_use_num DESC', 'description' => 'Trending hashtags'],
    ],

    'Wo_Notifications' => [
        ['name' => 'idx_recipient_id_seen', 'type' => 'INDEX', 'columns' => 'recipient_id, seen, time DESC', 'description' => 'Unread notifications'],
        ['name' => 'idx_notifier_id', 'type' => 'INDEX', 'columns' => 'notifier_id', 'description' => 'Notification sender'],
        ['name' => 'idx_unread_count', 'type' => 'INDEX', 'columns' => 'recipient_id, seen', 'description' => 'Count unread'],
    ],

    'Wo_Messages' => [
        ['name' => 'idx_from_to_time', 'type' => 'INDEX', 'columns' => 'from_id, to_id, time DESC', 'description' => 'Conversation messages'],
        ['name' => 'idx_seen', 'type' => 'INDEX', 'columns' => 'seen', 'description' => 'Unread messages'],
        ['name' => 'idx_unread_messages', 'type' => 'INDEX', 'columns' => 'to_id, seen', 'description' => 'Count unread'],
    ],

    'Wo_Comments' => [
        ['name' => 'idx_post_id_time', 'type' => 'INDEX', 'columns' => 'post_id, time DESC', 'description' => 'Post comments'],
        ['name' => 'idx_user_id', 'type' => 'INDEX', 'columns' => 'user_id', 'description' => 'User comments'],
        ['name' => 'idx_post_pagination', 'type' => 'INDEX', 'columns' => 'post_id, id DESC', 'description' => 'Comment pagination'],
    ],

    'Wo_Followers' => [
        ['name' => 'idx_following_follower', 'type' => 'INDEX', 'columns' => 'following_id, follower_id', 'description' => 'Follow relationships'],
        ['name' => 'idx_active', 'type' => 'INDEX', 'columns' => 'active', 'description' => 'Active follows'],
    ],

    'Wo_Likes' => [
        ['name' => 'idx_post_id_user', 'type' => 'INDEX', 'columns' => 'post_id, user_id', 'description' => 'Post likes'],
    ],
];

$stats = [
    'total' => 0,
    'added' => 0,
    'skipped' => 0,
    'errors' => 0
];

// Count total indexes
foreach ($indexes as $table => $tableIndexes) {
    $stats['total'] += count($tableIndexes);
}

echo "Found {$stats['total']} indexes to process\n\n";
echo "Starting migration...\n";
echo str_repeat('-', 60) . "\n\n";

// Process each table
foreach ($indexes as $table => $tableIndexes) {
    echo "Processing table: {$table}\n";

    // Check if table exists
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
    if (mysqli_num_rows($result) == 0) {
        echo "  ⚠ Table not found, skipping\n\n";
        $stats['skipped'] += count($tableIndexes);
        continue;
    }

    foreach ($tableIndexes as $index) {
        $indexName = $index['name'];
        $indexType = $index['type'];
        $columns = $index['columns'];
        $description = $index['description'];

        // Check if index already exists
        $result = mysqli_query($conn, "SHOW INDEX FROM {$table} WHERE Key_name = '{$indexName}'");

        if (mysqli_num_rows($result) > 0) {
            echo "  ⊙ {$indexName} - Already exists\n";
            $stats['skipped']++;
            continue;
        }

        // Create index
        if ($indexType === 'FULLTEXT') {
            $sql = "ALTER TABLE {$table} ADD FULLTEXT INDEX {$indexName} ({$columns})";
        } else {
            $sql = "ALTER TABLE {$table} ADD INDEX {$indexName} ({$columns})";
        }

        echo "  + Adding {$indexName} ({$description})... ";
        flush();

        if (mysqli_query($conn, $sql)) {
            echo "✓ Success\n";
            $stats['added']++;
        } else {
            echo "✗ Error: " . mysqli_error($conn) . "\n";
            $stats['errors']++;
        }
    }

    echo "\n";
}

echo str_repeat('-', 60) . "\n";
echo "\nMigration Summary:\n";
echo "  Total indexes:   {$stats['total']}\n";
echo "  Added:           {$stats['added']}\n";
echo "  Already existed: {$stats['skipped']}\n";
echo "  Errors:          {$stats['errors']}\n\n";

if ($stats['errors'] > 0) {
    echo "⚠ Some indexes failed to create. Check the errors above.\n\n";
} else if ($stats['added'] > 0) {
    echo "✓ Migration completed successfully!\n\n";
    echo "Recommendations:\n";
    echo "  1. Update search queries to use FULLTEXT MATCH() AGAINST()\n";
    echo "  2. Run OPTIMIZE TABLE on large tables to defragment\n";
    echo "  3. Monitor query performance with EXPLAIN\n";
    echo "  4. Consider enabling MySQL slow query log\n\n";
} else {
    echo "✓ All indexes already exist. No changes made.\n\n";
}

mysqli_close($conn);

echo "==============================================\n";
echo "Done!\n";
echo "==============================================\n";
