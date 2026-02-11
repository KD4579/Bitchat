<?php
if ($f == 'upload-blog-image') {
    // CSRF Protection - Prevent unauthorized blog image uploads
    BitchatSecurity::requireCsrfToken();

    reset($_FILES);
    $temp = current($_FILES);

    if (!is_uploaded_file($temp['tmp_name'])) {
        header("HTTP/1.0 400 Bad Request");
        header("Content-Type: application/json");
        echo json_encode(array(
            'error' => 'No file was uploaded or upload failed. Please try again.',
            'status' => 400
        ));
        exit();
    }

    $fileInfo = array(
        'file' => $temp["tmp_name"],
        'name' => $temp['name'],
        'size' => $temp["size"],
        'type' => $temp["type"]
    );

    $media = Wo_ShareFile($fileInfo);

    if (!empty($media)) {
        $mediaFilename = $media['filename'];
        $mediaName     = $media['name'];
    }

    if (!empty($mediaFilename)) {
        $filetowrite = Wo_GetMedia($mediaFilename);
        echo json_encode(array(
            'location' => $filetowrite,
            'status' => 200
        ));
        exit();
    } else {
        header("HTTP/1.0 500 Server Error");
        header("Content-Type: application/json");

        // Determine specific error reason
        $errorMsg = 'File upload failed. ';
        if (!empty($temp['size']) && $temp['size'] > $wo['config']['maxUpload']) {
            $maxMB = round($wo['config']['maxUpload'] / (1024 * 1024));
            $errorMsg .= "File is too large (max: {$maxMB}MB).";
        } elseif (!empty($temp['type'])) {
            $ext = pathinfo($temp['name'], PATHINFO_EXTENSION);
            $allowed = $wo['config']['allowedExtension'] ?? 'jpg,png,jpeg,gif';
            if (!empty($ext) && strpos($allowed, strtolower($ext)) === false) {
                $errorMsg .= "File type '.{$ext}' is not allowed. Allowed: {$allowed}";
            } else {
                $errorMsg .= 'Please check file format and size.';
            }
        } else {
            $errorMsg .= 'Please try again or contact support.';
        }

        echo json_encode(array(
            'error' => $errorMsg,
            'status' => 500
        ));
        exit();
    }
}
