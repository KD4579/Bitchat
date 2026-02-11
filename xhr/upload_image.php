<?php
if ($f == 'upload_image') {
    // CSRF Protection - Prevent unauthorized file uploads
    BitchatSecurity::requireCsrfToken();

    $data = array('status' => 500, 'error' => 'Upload failed');

    if (isset($_FILES['image']['name']) && isset($_FILES['image']['tmp_name'])) {
        if (!is_uploaded_file($_FILES['image']['tmp_name'])) {
            $data = array(
                'status' => 400,
                'error' => 'No file was uploaded or upload failed. Please try again.'
            );
        } else {
            $fileInfo = array(
                'file' => $_FILES["image"]["tmp_name"],
                'name' => $_FILES['image']['name'],
                'size' => $_FILES["image"]["size"],
                'type' => $_FILES["image"]["type"]
            );
            $media = Wo_ShareFile($fileInfo);

            if (!empty($media)) {
                $mediaFilename    = $media['filename'];
                $mediaName        = $media['name'];
                $_SESSION['file'] = $mediaFilename;
                $data             = array(
                    'status' => 200,
                    'image' => Wo_GetMedia($mediaFilename),
                    'image_src' => $mediaFilename
                );
            } else {
                // Determine specific error
                $errorMsg = 'File upload failed. ';
                if (!empty($_FILES['image']['size']) && $_FILES['image']['size'] > $wo['config']['maxUpload']) {
                    $maxMB = round($wo['config']['maxUpload'] / (1024 * 1024));
                    $errorMsg .= "File is too large (max: {$maxMB}MB).";
                } else {
                    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $allowed = $wo['config']['allowedExtension'] ?? 'jpg,png,jpeg,gif';
                    if (!empty($ext) && strpos($allowed, strtolower($ext)) === false) {
                        $errorMsg .= "File type '.{$ext}' is not allowed. Allowed: {$allowed}";
                    } else {
                        $errorMsg .= 'Please check file format and size.';
                    }
                }

                $data = array(
                    'status' => 500,
                    'error' => $errorMsg
                );
            }
        }
    } else {
        $data = array(
            'status' => 400,
            'error' => 'No file provided. Please select a file to upload.'
        );
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
