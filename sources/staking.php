<?php
if ($wo['loggedin'] == false) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'staking';
$wo['title']       = 'TRDC Staking';

// User balance for offchain staking
$wo['staking_balance'] = floatval($wo['user']['wallet'] ?? 0);

// Load active stakes for this user
$wo['active_stakes'] = array();
$_stakes_q = @mysqli_query($sqlConnect, "SELECT * FROM Wo_Staking WHERE user_id = " . intval($wo['user']['user_id']) . " AND status = 'active' ORDER BY created_at DESC");
if ($_stakes_q) {
    while ($row = mysqli_fetch_assoc($_stakes_q)) {
        $wo['active_stakes'][] = $row;
    }
}

// Load stake history
$wo['stake_history'] = array();
$_hist_q = @mysqli_query($sqlConnect, "SELECT * FROM Wo_Staking WHERE user_id = " . intval($wo['user']['user_id']) . " ORDER BY created_at DESC LIMIT 20");
if ($_hist_q) {
    while ($row = mysqli_fetch_assoc($_hist_q)) {
        $wo['stake_history'][] = $row;
    }
}

$wo['content'] = Wo_LoadPage('staking/content');
?>
