<?php
if ($f == 'upload_image') {
    // CSRF Protection - Prevent unauthorized file uploads
    BitchatSecurity::requireCsrfToken();

    if (isset($_FILES['image']['name'])) {
        $fileInfo = array(
            'file' => $_FILES["image"]["tmp_name"],
            'name' => $_FILES['image']['name'],
            'size' => $_FILES["image"]["size"],
            'type' => $_FILES["image"]["type"]
        );
        $media    = Wo_ShareFile($fileInfo);
        if (!empty($media)) {
            $mediaFilename    = $media['filename'];
            $mediaName        = $media['name'];
            $_SESSION['file'] = $mediaFilename;
            $data             = array(
                'status' => 200,
                'image' => Wo_GetMedia($mediaFilename),
                'image_src' => $mediaFilename
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}

$user = Wo_UserData($wo['user']['user_id']);
$pro_type = $user['pro_type']; // 1 = weekly plan
$pro_packages = $wo['pro_packages'];
$max_upload = 0;

// Get max_upload for the user's plan
if ($pro_type == 1) {
    $max_upload = $pro_packages['star']['max_upload']; // weekly plan
} elseif ($pro_type == 2) {
    $max_upload = $pro_packages['hot']['max_upload'];
} elseif ($pro_type == 3) {
    $max_upload = $pro_packages['ultima']['max_upload'];
} elseif ($pro_type == 4) {
    $max_upload = $pro_packages['vip']['max_upload'];
}

// If max_upload is set, check file size
if (!empty($max_upload) && isset($_FILES['file'])) {
    if ($_FILES['file']['size'] > $max_upload) {
        $data = [
            'status' => 403,
            'message' => 'Your plan only allows uploads up to ' . ($max_upload / 1000000) . ' MB. Please upgrade your plan for larger uploads.'
        ];
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
}
