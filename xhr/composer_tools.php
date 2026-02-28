<?php
if ($f == 'composer_tools') {
    $html = Wo_LoadPage('story/publisher-box-tools');
    header("Content-type: application/json");
    echo json_encode(array(
        'status' => 200,
        'html'   => $html
    ));
    exit();
}
