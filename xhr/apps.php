<?php 
if ($f == 'apps') {
    if ($s == 'create_app') {
        if (empty($_POST['app_name']) || empty($_POST['app_website_url']) || empty($_POST['app_description'])) {
            $errors[] = $error_icon . $wo['lang']['please_check_details'];
        }
        if (!filter_var($_POST['app_website_url'], FILTER_VALIDATE_URL)) {
            $errors[] = $error_icon . $wo['lang']['website_invalid_characters'];
        }
        if (empty($errors)) {
            $app_callback_url = '';
            if (!empty($_POST['app_callback_url'])) {
                if (!filter_var($_POST['app_callback_url'], FILTER_VALIDATE_URL)) {
                    $errors[] = $error_icon . $wo['lang']['website_invalid_characters'];
                } else {
                    $app_callback_url = $_POST['app_callback_url'];
                }
            }
            $re_app_data = array(
                'app_user_id' => Wo_Secure($wo['user']['user_id']),
                'app_name' => Wo_Secure($_POST['app_name']),
                'app_website_url' => Wo_Secure($_POST['app_website_url']),
                'app_description' => Wo_Secure($_POST['app_description']),
                'app_callback_url' => Wo_Secure($app_callback_url)
            );
            $app_id      = Wo_RegisterApp($re_app_data);
            if ($app_id != '') {
                if (!empty($_FILES["app_avatar"]["name"])) {
                    Wo_UploadImage($_FILES["app_avatar"]["tmp_name"], $_FILES['app_avatar']['name'], 'avatar', $_FILES['app_avatar']['type'], $app_id, 'app');
                }
                $data = array(
                    'status' => 200,
                    'location' => Wo_SeoLink('index.php?link1=app&app_id=' . $app_id)
                );
            }
        }
        header("Content-type: application/json");
        if (isset($errors)) {
            echo json_encode(array(
                'errors' => $errors
            ));
        } else {
            echo json_encode($data);
        }
        exit();
    }
    if ($s == 'update_app') {
        if (empty($_POST['app_name']) || empty($_POST['app_website_url']) || empty($_POST['app_description'])) {
            $errors[] = $error_icon . $wo['lang']['please_check_details'];
        }
        if (!filter_var($_POST['app_website_url'], FILTER_VALIDATE_URL)) {
            $errors[] = $error_icon . $wo['lang']['website_invalid_characters'];
        }
        if (!filter_var($_POST['app_callback_url'], FILTER_VALIDATE_URL)) {
            $errors[] = $error_icon . $wo['lang']['website_invalid_characters'];
        }
        if (empty($errors)) {
            $app_id      = $_POST['app_id'];
            $re_app_data = array(
                'app_user_id' => Wo_Secure($wo['user']['user_id']),
                'app_name' => Wo_Secure($_POST['app_name']),
                'app_website_url' => Wo_Secure($_POST['app_website_url']),
                'app_callback_url' => Wo_Secure($_POST['app_callback_url']),
                'app_description' => Wo_Secure($_POST['app_description'])
            );
            if (Wo_UpdateAppData($app_id, $re_app_data) === true) {
                if (!empty($_FILES["app_avatar"]["name"])) {
                    Wo_UploadImage($_FILES["app_avatar"]["tmp_name"], $_FILES['app_avatar']['name'], 'avatar', $_FILES['app_avatar']['type'], $app_id, 'app');
                }
                $img  = Wo_GetApp($app_id);
                $data = array(
                    'status' => 200,
                    'message' => $wo['lang']['setting_updated'],
                    'name' => $_POST['app_name'],
                    'image' => $img['app_avatar']
                );
            }
        }
        header("Content-type: application/json");
        if (isset($errors)) {
            echo json_encode(array(
                'errors' => $errors
            ));
        } else {
            echo json_encode($data);
        }
        exit();
    }
    if ($s == 'acceptPermissions') {
        // SECURITY: never trust $_POST['url'] for the redirect destination —
        // always look up the registered callback URL from the database by app id.
        $raw_app_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($raw_app_id < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_app_id']);
            exit();
        }

        // Fetch the registered callback URL for this app
        $safe_app_id   = Wo_Secure($raw_app_id);
        $app_row_query = mysqli_query($sqlConnect,
            "SELECT `id`, `app_callback_url`, `app_website_url`
             FROM " . T_APPS . "
             WHERE `id` = {$safe_app_id} AND `active` = '1'
             LIMIT 1"
        );

        if (!$app_row_query || mysqli_num_rows($app_row_query) === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'unknown_app']);
            exit();
        }

        $app_row              = mysqli_fetch_assoc($app_row_query);
        $registered_callback  = !empty($app_row['app_callback_url'])
                                    ? $app_row['app_callback_url']
                                    : $app_row['app_website_url'];

        $acceptPermissions = Wo_AcceptPermissions($raw_app_id);
        if ($acceptPermissions === true) {
            $import = Wo_GenrateCode($wo['user']['user_id'], $raw_app_id);

            // Retrieve state from session (set by api/oauth/authorize.php)
            $state     = isset($_SESSION['oauth2_pending_state'])  ? $_SESSION['oauth2_pending_state']  : '';
            $scope     = isset($_SESSION['oauth2_pending_scope'])   ? $_SESSION['oauth2_pending_scope']  : 'basic';

            // Persist state + scope on the code row if migration 010 has been applied
            if (!empty($import)) {
                $safe_state = Wo_Secure($state);
                $safe_scope = Wo_Secure($scope);
                mysqli_query($sqlConnect,
                    "UPDATE " . T_CODES . "
                     SET `state` = '{$safe_state}', `scope` = '{$safe_scope}'
                     WHERE `code` = '{$import}'"
                );
            }

            // Clear one-time session state
            unset($_SESSION['oauth2_pending_state'], $_SESSION['oauth2_pending_scope'], $_SESSION['oauth2_pending_app_id']);

            $location = $registered_callback . '?code=' . urlencode($import);
            if (!empty($state)) {
                $location .= '&state=' . urlencode($state);
            }

            $data = array(
                'status'   => 200,
                'location' => $location,
            );
        }
        header("Content-type: application/json");
        echo json_encode($data ?? ['error' => 'permission_denied']);
        exit();
    }
}
