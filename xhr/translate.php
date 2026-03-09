<?php
if ($f == 'translate') {
    $data = array('status' => 400);

    if (Wo_CheckMainSession($hash_id) !== true) {
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $text = isset($_POST['text']) ? trim($_POST['text']) : '';
    $target = isset($_POST['lang']) ? trim($_POST['lang']) : 'en';

    if (empty($text) || mb_strlen($text) < 2 || mb_strlen($text) > 1000) {
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    // Sanitize target language (2-letter ISO code)
    if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $target)) {
        $target = 'en';
    }

    // Check Redis cache first
    $cacheKey = 'translate:' . md5($text . '|' . $target);
    $cached = BitchatCache::get($cacheKey);
    if ($cached !== false && !empty($cached['translated'])) {
        header("Content-type: application/json");
        echo json_encode(array('status' => 200, 'translated' => $cached['translated'], 'cached' => true));
        exit();
    }

    // Call MyMemory API
    $langPair = 'autodetect|' . $target;
    $apiUrl = 'https://api.mymemory.translated.net/get?' . http_build_query(array(
        'q' => mb_substr($text, 0, 500),
        'langpair' => $langPair
    ));

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Bitchat/1.0'
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 && !empty($response)) {
        $result = json_decode($response, true);
        if (!empty($result['responseStatus']) && $result['responseStatus'] == 200
            && !empty($result['responseData']['translatedText'])) {
            $translated = $result['responseData']['translatedText'];
            if ($translated && $translated !== 'NO QUERY SPECIFIED') {
                // Cache for 24 hours
                BitchatCache::set($cacheKey, array('translated' => $translated), 86400);
                $data = array('status' => 200, 'translated' => $translated, 'cached' => false);
            }
        }
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
