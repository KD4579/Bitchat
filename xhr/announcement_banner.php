<?php
if ($f == 'announcement_banner') {
    if (!Wo_IsAdmin()) {
        $data = array('status' => 403, 'message' => 'Unauthorized');
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }

    $data = array('status' => 200);

    if (isset($_POST['announcement_banner_enabled'])) {
        Wo_SaveConfig('announcement_banner_enabled', ($_POST['announcement_banner_enabled'] == '1') ? '1' : '0');
    }
    if (isset($_POST['announcement_banner_text'])) {
        Wo_SaveConfig('announcement_banner_text', Wo_Secure($_POST['announcement_banner_text']));
    }
    if (isset($_POST['announcement_banner_url'])) {
        $url = trim($_POST['announcement_banner_url']);
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            $data = array('status' => 400, 'message' => 'Invalid URL');
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
        Wo_SaveConfig('announcement_banner_url', $url);
    }
    if (isset($_POST['announcement_banner_bg'])) {
        $bg = preg_replace('/[^#a-fA-F0-9]/', '', $_POST['announcement_banner_bg']);
        Wo_SaveConfig('announcement_banner_bg', $bg);
    }
    if (isset($_POST['announcement_banner_color'])) {
        $color = preg_replace('/[^#a-fA-F0-9]/', '', $_POST['announcement_banner_color']);
        Wo_SaveConfig('announcement_banner_color', $color);
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
