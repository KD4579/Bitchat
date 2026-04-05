<?php
if ($f == 'save_loc_permission' && $wo['loggedin'] == true) {
    $data = array('status' => 400);

    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';

    if ($action === 'grant' || $action === 'revoke') {
        // Auto-migrate: add loc_permission column if it doesn't exist yet
        $col_check = mysqli_query(
            $sqlConnect,
            "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '" . T_USERS . "'
               AND COLUMN_NAME  = 'loc_permission'"
        );
        if ($col_check) {
            $col_row = mysqli_fetch_assoc($col_check);
            if ((int)$col_row['cnt'] === 0) {
                mysqli_query($sqlConnect,
                    "ALTER TABLE `" . T_USERS . "` ADD COLUMN `loc_permission` TINYINT(1) NOT NULL DEFAULT 0"
                );
            }
        }

        $perm = ($action === 'grant') ? 1 : 0;
        if (Wo_UpdateUserData($wo['user']['user_id'], array('loc_permission' => $perm))) {
            $data['status'] = 200;
            $data['action'] = $action;
        }
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
