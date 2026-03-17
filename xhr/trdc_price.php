<?php
if ($f == 'trdc_price') {
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=30');

    $geckoUrl = 'https://api.geckoterminal.com/api/v2/networks/bsc/tokens/0x39006641dB2d9C3618523a1778974c0D7e98e39d/pools?page=1';

    $ch = curl_init($geckoUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Bitchat/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = ['price' => 0, 'change24h' => 0];

    if ($httpCode === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (!empty($data['data'][0]['attributes'])) {
            $pool = $data['data'][0]['attributes'];
            $price = floatval($pool['base_token_price_usd'] ?? 0);
            $change = 0;
            if (!empty($pool['price_change_percentage']['h24'])) {
                $change = floatval($pool['price_change_percentage']['h24']);
            }
            if ($price > 0) {
                $result = ['price' => $price, 'change24h' => $change];
            }
        }
    }

    echo json_encode($result);
    exit();
}
