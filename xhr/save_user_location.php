<?php
if ($f == 'save_user_location' && isset($_POST['lat']) && isset($_POST['lng'])) {
    $lat     = $_POST['lat'];
    $lng     = $_POST['lng'];
    $context = isset($_POST['context']) ? Wo_Secure($_POST['context']) : 'general';

    // 1-hour refresh on nearby page, 7-day default otherwise
    $next_update = ($context === 'nearby_page')
        ? strtotime("+1 hour")
        : strtotime("+1 week");

    $safe_lat = (is_numeric($lat)) ? $lat : 0;
    $safe_lng = (is_numeric($lng)) ? $lng : 0;

    $update_array = array(
        'lat' => $safe_lat,
        'lng' => $safe_lng,
        'last_location_update' => $next_update,
        'loc_permission' => 1
    );
    $data = array('status' => 304);
    if (Wo_UpdateUserData($wo['user']['user_id'], $update_array)) {
        $data['status'] = 200;
        $data['lat']    = floatval($safe_lat);
        $data['lng']    = floatval($safe_lng);
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
