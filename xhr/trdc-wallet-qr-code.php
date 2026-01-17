<?php 
if ($f == "trdc-wallet-qr-code") {
    if (Wo_IsAdmin() || Wo_IsModerator()) {
        if (!empty($_FILES["file"]["tmp_name"])) {
            if (file_exists($_FILES["file"]["tmp_name"])) {
                $trdc_wallet_qr_code = getimagesize($_FILES["file"]["tmp_name"]);
            }
        }
        if (empty($error)) {
                $update_film = array();
                if (!empty($_FILES["file"]["tmp_name"])) {
                    $fileInfo             = array(
                        'file' => $_FILES["file"]["tmp_name"],
                        'name' => $_FILES['file']['name'],
                        'size' => $_FILES["file"]["size"],
                        'type' => $_FILES["file"]["type"],
                        'types' => 'jpeg,jpg,png,bmp,gif',
                        'compress' => false
                    );
                    $media                = Wo_ShareFile($fileInfo);
                    $update_film['trdc-wallet-qr-code'] = $media['filename'];
                    Wo_SaveConfig('trdc-wallet-qr-code', $media['filename']);
                }
               
                $data = array(
                    'status' => 200,
                    'message' => $success_icon . ' The QR Code uploaded successfully updated'
                );
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
