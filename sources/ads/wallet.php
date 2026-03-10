<?php 
if ($wo['loggedin'] == false) {
  header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
  exit();

}

if (isset($_SESSION['replenished_amount']) && $_SESSION['replenished_amount'] > 0){
	$wo['replenishment_notif']  = $wo['lang']['replenishment_notif'] . ' ' . Wo_GetCurrency($wo['config']['currency']) .  $_SESSION['replenished_amount'];
	unset($_SESSION['replenished_amount']);
}

// print_r( $wo['config']['theme']);
// exit();
$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords']    = $wo['config']['siteKeywords'];
$wo['page']        = 'ads';
$wo['ap']          = 'wallet';
$wo['title']       = $wo['lang']['wallet'];
$wo['ads']         = Wo_GetMyAds();

// Load data needed for Earn & Rewards section (merged from my_points)
if (!isset($wo['setting']) || !is_array($wo['setting'])) {
    $wo['setting'] = array();
}
$wo['setting']['balance'] = floatval($wo['user']['wallet'] ?? 0);
$wo['setting']['points']  = intval($wo['user']['points'] ?? 0);

$wo['content']     = Wo_LoadPage('ads/wallet');
 ?>