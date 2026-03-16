<?php
// Complete Profile — collect missing email (phone signup) or phone (email signup)
if (!$wo['loggedin']) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}

$userId = intval($wo['user']['user_id']);
$src = $wo['user']['src'] ?? '';

// Determine what's missing
$wo['complete_profile_need'] = '';
if ($src === 'phone_signup' && (empty($wo['user']['email']) || strpos($wo['user']['email'], '@placeholder.bitchat.live') !== false)) {
    $wo['complete_profile_need'] = 'email';
} elseif ($src === 'email_signup' && empty($wo['user']['phone_number'])) {
    $wo['complete_profile_need'] = 'phone';
} else {
    // Nothing missing — redirect to home
    header("Location: " . $wo['config']['site_url']);
    exit();
}

$wo['description'] = 'Complete your profile';
$wo['keywords'] = '';
$wo['page'] = 'complete-profile';
$wo['title'] = 'Complete Your Profile';
$wo['content'] = Wo_LoadPage('welcome/complete-profile');
