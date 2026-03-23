<?php
if ($f == 'share_post_on') {
    // CSRF Protection - Prevent unauthorized post sharing
    BitchatSecurity::requireCsrfToken();

    $data_info = array();
    $data['status'] = 400;
    $result = false;
    $user_id = 0;

    // SECURITY: Helper to check if the current user can share a post based on its privacy setting
    // postPrivacy: 0 = everyone, 1 = people I follow, 2 = people who follow me, 3 = only me
    $canSharePost = function($post) use ($wo) {
        if (empty($post) || empty($post['id'])) return false;
        $privacy = isset($post['postPrivacy']) ? (int)$post['postPrivacy'] : 0;
        $publisherId = !empty($post['user_id']) ? $post['user_id'] : 0;
        if (!empty($post['page_id'])) {
            // Page posts: check page owner
            $page = Wo_PageData($post['page_id']);
            $publisherId = !empty($page['user_id']) ? $page['user_id'] : 0;
        }
        // Only-me posts cannot be shared by anyone except the owner
        if ($privacy == 3 && $publisherId != $wo['user']['user_id']) return false;
        // People I follow (publisher's followers only)
        if ($privacy == 1 && $publisherId != $wo['user']['user_id']) {
            if (Wo_IsFollowing($wo['user']['user_id'], $publisherId) === false) return false;
        }
        // People who follow me (mutual followers)
        if ($privacy == 2 && $publisherId != $wo['user']['user_id']) {
            if (Wo_IsFollowing($publisherId, $wo['user']['user_id']) === false) return false;
        }
        return true;
    };

    if ($s == 'group' && !empty($_GET['type_id']) && is_numeric($_GET['type_id']) && $_GET['type_id'] > 0 && !empty($_GET['post_id']) && is_numeric($_GET['post_id']) && $_GET['post_id'] > 0) {
        $group = Wo_GroupData(Wo_Secure($_GET['type_id']));
        $post = Wo_PostData(Wo_Secure($_GET['post_id']));
        $user_id = $post['user_id'];
        if (!empty($post) && !empty($group) && $group['user_id'] == $wo['user']['user_id'] && $canSharePost($post)) {
            $result = Wo_SharePostOn($post['id'],$group['id'],'group');
        }
    }
    elseif ($s == 'page' && !empty($_GET['type_id']) && is_numeric($_GET['type_id']) && $_GET['type_id'] > 0 && !empty($_GET['post_id']) && is_numeric($_GET['post_id']) && $_GET['post_id'] > 0) {
        $page = Wo_PageData(Wo_Secure($_GET['type_id']));
        $post = Wo_PostData(Wo_Secure($_GET['post_id']));
        $user_id = $post['user_id'];
        if (empty($post['user_id'])) {
            $user_id = $page['user_id'];
        }
        if (!empty($post) && !empty($page) && $page['user_id'] == $wo['user']['user_id'] && $canSharePost($post)) {
            $result = Wo_SharePostOn($post['id'],$page['id'],'page');
        }
    }
    elseif ($s == 'user' && !empty($_GET['type_id']) && is_numeric($_GET['type_id']) && $_GET['type_id'] > 0 && !empty($_GET['post_id']) && is_numeric($_GET['post_id']) && $_GET['post_id'] > 0) {
        $user = Wo_UserData(Wo_Secure($_GET['type_id']));
        $post = Wo_PostData(Wo_Secure($_GET['post_id']));
        $user_id = $post['user_id'];
        if (!empty($post['page_id'])) {
            $page = Wo_PageData($post['page_id']);
            $user_id = $page['user_id'];
        }
        if (!empty($post) && !empty($user) && $canSharePost($post)) {
            $result = Wo_SharePostOn($post['id'],$user['id'],'user');
        }
    }
    elseif ($s == 'timeline' && !empty($_GET['post_id']) && is_numeric($_GET['post_id']) && $_GET['post_id'] > 0) {
        $post = Wo_PostData(Wo_Secure($_GET['post_id']));
        $user_id = $post['user_id'];
        if (empty($post['user_id']) && !empty($post['page_id'])) {
            $page = Wo_PageData($post['page_id']);
            $user_id = $page['user_id'];
        }
        if (!empty($post) && $canSharePost($post)) {
            $result = Wo_SharePostOn($post['id'],$wo['user']['user_id'],'user');
        }
    }
    if ($result) {
        if (!empty($_GET['text'])) {
            $updatePost = Wo_UpdatePost(array(
                'post_id' => $result,
                'text' => $_GET['text']
            ));
        }
        $notification_data_array = array(
            'recipient_id' => $user_id,
            'post_id' => $post['id'],
            'type' => 'shared_your_post',
            'url' => 'index.php?link1=post&id=' . $result
        );
        Wo_RegisterNotification($notification_data_array);
        if ($s == 'user') {
            $notification_data_array = array(
                'recipient_id' => $user['id'],
                'post_id' => $post['id'],
                'type' => 'shared_a_post_in_timeline',
                'url' => 'index.php?link1=post&id=' . $result
            );
            Wo_RegisterNotification($notification_data_array);
        }
        $data['status'] = 200;
    }
    else{
        $data['status'] = 400;
        $data['message'] = $wo['lang']['cant_share_own'];
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}

