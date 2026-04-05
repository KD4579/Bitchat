<?php
if ($f == 'onboarding') {
    if (!empty($_POST['action']) && $_POST['action'] == 'complete' && $wo['loggedin'] == true) {
        if (Wo_CheckSession($hash_id) === true) {
            $userId = intval($wo['user']['user_id']);

            // Auto-migrate: ensure onboarding_completed column exists
            $col_check = mysqli_query($sqlConnect,
                "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . T_USERS . "'
                 AND COLUMN_NAME = 'onboarding_completed'"
            );
            if ($col_check) {
                $col_row = mysqli_fetch_assoc($col_check);
                if ((int)$col_row['cnt'] === 0) {
                    mysqli_query($sqlConnect,
                        "ALTER TABLE `" . T_USERS . "` ADD COLUMN `onboarding_completed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `verified`"
                    );
                }
            }

            $ok = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET onboarding_completed = 1, start_up = '1', startup_image = '1', start_up_info = '1', startup_follow = '1' WHERE user_id = {$userId}");
            if ($ok && mysqli_affected_rows($sqlConnect) >= 0) {
                cache($userId, 'users', 'delete');
                echo json_encode(array('status' => 200));
                exit();
            }
        }
    }
    echo json_encode(array('status' => 400));
    exit();
}
