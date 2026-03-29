<?php
/**
 * Bitchat OAuth2 Authorization Endpoint
 *
 * GET /api/oauth/authorize.php
 *   ?client_id=<app_id>
 *   &redirect_uri=<url>          (must match registered app_callback_url)
 *   &response_type=code
 *   &state=<random>              (CSRF token, echoed back on redirect)
 *   &scope=basic                 (optional, defaults to 'basic')
 *
 * Flow:
 *   1. Validate client_id and redirect_uri against Wo_Apps.
 *   2. If user is not logged in, redirect to login with return URL.
 *   3. If user already granted permission, issue code and redirect immediately.
 *   4. Otherwise, show consent page (graph/data-request template).
 */

require_once('../../assets/init.php');

// ── Only allow GET ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Collect & sanitize params ────────────────────────────────────────────────
$client_id     = isset($_GET['client_id'])     ? Wo_Secure($_GET['client_id'])     : '';
$redirect_uri  = isset($_GET['redirect_uri'])  ? trim($_GET['redirect_uri'])        : '';
$response_type = isset($_GET['response_type']) ? trim($_GET['response_type'])       : '';
$state         = isset($_GET['state'])         ? Wo_Secure($_GET['state'])          : '';
$scope         = isset($_GET['scope'])         ? Wo_Secure($_GET['scope'])          : 'basic';

// ── Validate required params ─────────────────────────────────────────────────
if (empty($client_id) || empty($redirect_uri) || $response_type !== 'code') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'Missing or invalid parameters. Required: client_id, redirect_uri, response_type=code']);
    exit();
}

// ── Validate redirect_uri format ─────────────────────────────────────────────
if (!filter_var($redirect_uri, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'redirect_uri is not a valid URL']);
    exit();
}

// ── Validate client_id exists and redirect_uri matches ───────────────────────
$client_id_escaped = Wo_Secure($client_id);
$app_row = null;

$app_query = mysqli_query($sqlConnect,
    "SELECT `id`, `app_id`, `app_callback_url`, `app_website_url`, `active`
     FROM " . T_APPS . "
     WHERE `app_id` = '{$client_id_escaped}'
     LIMIT 1"
);

if (!$app_query || mysqli_num_rows($app_query) === 0) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_client', 'error_description' => 'Unknown client_id']);
    exit();
}

$app_row = mysqli_fetch_assoc($app_query);

if ($app_row['active'] != '1') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized_client', 'error_description' => 'Application is disabled']);
    exit();
}

// Validate redirect_uri matches the registered callback URL (exact match)
$registered_callback = $app_row['app_callback_url'];
if (empty($registered_callback)) {
    $registered_callback = $app_row['app_website_url'];
}

if (rtrim($redirect_uri, '/') !== rtrim($registered_callback, '/')) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'redirect_uri does not match registered callback']);
    exit();
}

// ── Require user to be logged in ─────────────────────────────────────────────
if ($wo['loggedin'] == false) {
    $actual_link = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . Wo_SeoLink('index.php?link1=welcome') . '?last_url=' . urlencode($actual_link));
    exit();
}

// ── Store state in session for later verification ────────────────────────────
if (!empty($state)) {
    $_SESSION['oauth2_state_' . $client_id] = $state;
    $_SESSION['oauth2_state_time_' . $client_id] = time();
}

// ── Store scope in session ───────────────────────────────────────────────────
$_SESSION['oauth2_scope_' . $client_id] = $scope;

// ── If user already approved this app, skip consent and issue code ───────────
$numeric_app_id = intval($app_row['id']);
$user_id        = intval($wo['user']['user_id']);

$has_perm = Wo_AppHasPermission($user_id, $numeric_app_id);

if ($has_perm === true) {
    $code = Wo_GenrateCode($user_id, $numeric_app_id);

    // Persist state + scope on the code row if columns exist
    if (!empty($code)) {
        $safe_state = Wo_Secure($state);
        $safe_scope = Wo_Secure($scope);
        mysqli_query($sqlConnect,
            "UPDATE " . T_CODES . "
             SET `state` = '{$safe_state}', `scope` = '{$safe_scope}'
             WHERE `code` = '{$code}'"
        );
    }

    $redirect = $registered_callback . '?code=' . urlencode($code);
    if (!empty($state)) {
        $redirect .= '&state=' . urlencode($state);
    }
    header('Location: ' . $redirect);
    exit();
}

// ── Show consent page ────────────────────────────────────────────────────────
// We reuse the existing sources/oauth.php rendering logic by setting $wo['app']
// then loading the template directly.

$wo['app'] = array();

// Populate app data without requiring loggedin (we already verified above)
$full_app_query = mysqli_query($sqlConnect,
    "SELECT * FROM " . T_APPS . " WHERE `id` = {$numeric_app_id}"
);
if ($full_app_query && mysqli_num_rows($full_app_query) === 1) {
    $wo['app'] = mysqli_fetch_assoc($full_app_query);
    $wo['app']['app_avatar'] = Wo_GetMedia($wo['app']['app_avatar']);
}

// Pass state and scope to the template via a session variable the template JS can read
$_SESSION['oauth2_pending_state']  = $state;
$_SESSION['oauth2_pending_scope']  = $scope;
$_SESSION['oauth2_pending_app_id'] = $numeric_app_id;

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'graph';
$wo['title']       = $wo['config']['siteTitle'];
$wo['content']     = Wo_LoadPage('graph/data-request');
