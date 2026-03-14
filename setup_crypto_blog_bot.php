<?php
/**
 * One-time setup script: Create the "Crypto Trading Views" bot account.
 *
 * Run once via CLI: php setup_crypto_blog_bot.php
 * Or visit in browser: https://bitchat.live/setup_crypto_blog_bot.php
 *
 * After running, delete this file for security.
 */

// Load the app
require_once(__DIR__ . '/assets/init.php');

// Check if already exists
$existing = mysqli_query($sqlConnect, "SELECT user_id FROM " . T_USERS . " WHERE username = 'cryptotradingviews' LIMIT 1");
if ($existing && mysqli_num_rows($existing) > 0) {
    die("Bot account 'cryptotradingviews' already exists. No action taken.\n");
}

// Create user account
$name = 'Crypto Trading Views';
$username = 'cryptotradingviews';
$email = $username . '@bot.bitchat.local';
$password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$now = time();
$registered = date('n') . '/' . date('Y');
$lang = $wo['config']['defualtLang'] ?? 'english';
$orderBy = $wo['config']['order_posts_by'] ?? 0;

$insertUser = mysqli_query($sqlConnect, "INSERT INTO " . T_USERS . " (
    username, email, password, first_name, last_name, active, verified,
    start_up, startup_image, start_up_info, startup_follow,
    joined, registered, language, order_posts_by, about
) VALUES (
    '" . Wo_Secure($username) . "',
    '" . Wo_Secure($email) . "',
    '" . Wo_Secure($password) . "',
    'Crypto',
    'Trading Views',
    '1', '1', '1', '1', '1', '1',
    '{$now}',
    '{$registered}',
    '" . Wo_Secure($lang) . "',
    '" . Wo_Secure($orderBy) . "',
    '" . Wo_Secure('Cryptocurrency news, market analysis, and trading insights from TradingView and top crypto sources.') . "'
)");

if (!$insertUser) {
    die("Failed to create user account: " . mysqli_error($sqlConnect) . "\n");
}

$userId = mysqli_insert_id($sqlConnect);

// Create user fields row
mysqli_query($sqlConnect, "INSERT INTO " . T_USERS_FIELDS . " (user_id) VALUES ({$userId})");

// Create bot account record
$db = new MysqliDb($sqlConnect);
$botId = $db->insert('Wo_Bot_Accounts', [
    'user_id' => $userId,
    'name' => $name,
    'username' => $username,
    'category' => 'crypto',
    'news_sources' => "https://cointelegraph.com/rss\nhttps://www.newsbtc.com/feed/\nhttps://coinpedia.org/feed/\nhttps://www.theblock.co/rss.xml",
    'post_frequency' => 60,         // Post every 60 minutes
    'max_posts_per_day' => 10,      // Max 10 blog posts per day
    'include_thumbnail' => 1,
    'enabled' => 1,
    'content_type' => 'blog_scraper'
]);

if ($botId) {
    echo "SUCCESS! Bot account created:\n";
    echo "  Name: {$name}\n";
    echo "  Username: {$username}\n";
    echo "  User ID: {$userId}\n";
    echo "  Bot ID: {$botId}\n";
    echo "  Frequency: Every 60 minutes\n";
    echo "  Max posts/day: 10\n";
    echo "\nThe bot will auto-post crypto news as blog posts via cron-job.php.\n";
    echo "\nIMPORTANT: Delete this setup script now for security:\n";
    echo "  rm setup_crypto_blog_bot.php\n";
} else {
    echo "User created (ID: {$userId}) but bot record failed: " . mysqli_error($sqlConnect) . "\n";
}
