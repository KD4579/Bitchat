<?php
/**
 * One-time setup script: Create 5 character bot user accounts.
 * Run on server: php setup_character_bots.php
 * Delete after use.
 */
require_once('assets/init.php');

$bots = [
    [
        'username'     => 'cryptosage',
        'first_name'   => 'Crypto',
        'last_name'    => 'Sage',
        'display_name' => 'CryptoSage',
        'category'     => 'crypto',
        'content_file' => 'assets/bot_content/crypto_sage.json',
        'frequency'    => 120,
        'max_daily'    => 6,
    ],
    [
        'username'     => 'blockchainbuzz',
        'first_name'   => 'Blockchain',
        'last_name'    => 'Buzz',
        'display_name' => 'BlockchainBuzz',
        'category'     => 'crypto',
        'content_file' => 'assets/bot_content/blockchain_buzz.json',
        'frequency'    => 90,
        'max_daily'    => 6,
    ],
    [
        'username'     => 'tradingvibes',
        'first_name'   => 'Trading',
        'last_name'    => 'Vibes',
        'display_name' => 'TradingVibes',
        'category'     => 'crypto',
        'content_file' => 'assets/bot_content/trading_vibes.json',
        'frequency'    => 60,
        'max_daily'    => 8,
    ],
    [
        'username'     => 'web3explorer',
        'first_name'   => 'Web3',
        'last_name'    => 'Explorer',
        'display_name' => 'Web3Explorer',
        'category'     => 'technology',
        'content_file' => 'assets/bot_content/web3_explorer.json',
        'frequency'    => 150,
        'max_daily'    => 5,
    ],
    [
        'username'     => 'dailydigest',
        'first_name'   => 'Daily',
        'last_name'    => 'Digest',
        'display_name' => 'DailyDigest',
        'category'     => 'general',
        'content_file' => 'assets/bot_content/daily_digest.json',
        'frequency'    => 180,
        'max_daily'    => 4,
    ],
];

foreach ($bots as $bot) {
    $username = $bot['username'];

    // Check if user already exists
    $check = mysqli_query($sqlConnect, "SELECT user_id FROM Wo_Users WHERE username = '{$username}'");
    if (mysqli_num_rows($check) > 0) {
        $row = mysqli_fetch_assoc($check);
        echo "[SKIP] @{$username} already exists (user_id: {$row['user_id']})\n";
        continue;
    }

    // Download avatar from UI Avatars (generates letter-based avatars)
    $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($bot['display_name']) . "&size=200&background=random&color=fff&bold=true&format=png";
    $avatarDir = 'upload/photos/' . date('Y/m');
    if (!is_dir($avatarDir)) {
        mkdir($avatarDir, 0755, true);
    }
    $avatarFile = $avatarDir . '/bot_avatar_' . $username . '.png';

    $ch = curl_init($avatarUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $imgData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 && !empty($imgData)) {
        file_put_contents($avatarFile, $imgData);
        echo "  Avatar saved: {$avatarFile}\n";
    } else {
        $avatarFile = '';
        echo "  Avatar download failed (HTTP {$httpCode}), using default\n";
    }

    // Create user account
    $password = md5('bot_' . $username . '_' . time());
    $email = $username . '@bot.bitchat.live';
    $now = time();
    $joined = $now;

    $sql = "INSERT INTO Wo_Users (
        username, password, email, first_name, last_name, avatar,
        verified, active, joined, language, src, about
    ) VALUES (
        '{$username}',
        '{$password}',
        '{$email}',
        '" . mysqli_real_escape_string($sqlConnect, $bot['first_name']) . "',
        '" . mysqli_real_escape_string($sqlConnect, $bot['last_name']) . "',
        '{$avatarFile}',
        '1', '1', {$joined}, 'english', 'Developer',
        '" . mysqli_real_escape_string($sqlConnect, $bot['display_name'] . ' - Bitchat community bot') . "'
    )";

    $result = mysqli_query($sqlConnect, $sql);
    if (!$result) {
        echo "[ERROR] Failed to create @{$username}: " . mysqli_error($sqlConnect) . "\n";
        continue;
    }

    $userId = mysqli_insert_id($sqlConnect);
    echo "[OK] Created @{$username} (user_id: {$userId})\n";

    // Create bot account entry
    $sql2 = "INSERT INTO Wo_Bot_Accounts (
        user_id, name, username, category, content_type, content_file,
        news_sources, post_frequency, max_posts_per_day, include_thumbnail, enabled
    ) VALUES (
        {$userId},
        '" . mysqli_real_escape_string($sqlConnect, $bot['display_name']) . "',
        '{$username}',
        '{$bot['category']}',
        'template',
        '{$bot['content_file']}',
        '',
        {$bot['frequency']},
        {$bot['max_daily']},
        0,
        1
    )";

    $result2 = mysqli_query($sqlConnect, $sql2);
    if (!$result2) {
        echo "[ERROR] Failed to create bot entry for @{$username}: " . mysqli_error($sqlConnect) . "\n";
        continue;
    }
    echo "  Bot entry created (frequency: {$bot['frequency']}min, max: {$bot['max_daily']}/day)\n";

    // Auto-follow: make all existing active users follow this bot
    $followSql = "INSERT IGNORE INTO Wo_Followers (follower_id, following_id, active)
        SELECT user_id, {$userId}, '1' FROM Wo_Users WHERE active = '1' AND user_id != {$userId}";
    $followResult = mysqli_query($sqlConnect, $followSql);
    $followCount = mysqli_affected_rows($sqlConnect);
    echo "  Auto-followed by {$followCount} users\n";
}

echo "\nDone! Delete this file after use.\n";
