<?php 
if ($f == 'clearChat') {
    $clear = Wo_ClearRecent();
    if ($clear === true) {
        $data = array(
            'status' => 200,
            'message' => 'Chat history cleared successfully.'
        );
    } else {
        $data = array(
            'status' => 500,
            'error' => 'Failed to clear chat history. Please try again.'
        );
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
