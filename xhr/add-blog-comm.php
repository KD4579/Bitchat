<?php
if ($f == "add-blog-comm") {
    // CSRF Protection - Prevent unauthorized blog comments
    BitchatSecurity::requireCsrfToken();

    $html = "";
    if (isset($_POST['text']) && isset($_POST['blog']) && is_numeric(($_POST['blog'])) && strlen($_POST['text']) > 2) {
        $registration_data = array(
            'blog_id' => Wo_Secure($_POST['blog']),
            'user_id' => $wo['user']['id'],
            'text' => Wo_Secure($_POST['text']),
            'posted' => time()
        );
        $get_blog          = Wo_GetArticle($_POST['blog']);
        if (empty($get_blog)) {
            exit();
        }
        // SECURITY: Prevent blocked users from commenting on each other's blogs
        if (!empty($get_blog['user']) && Wo_IsBlocked($get_blog['user'])) {
            $data = array('status' => 403);
            header("Content-type: application/json");
            echo json_encode($data);
            exit();
        }
        $lastId = Wo_RegisterBlogComment($registration_data);
        if ($lastId && is_numeric($lastId)) {
            $comment = Wo_GetBlogComments(array(
                'id' => $lastId
            ));
            if ($comment && count($comment) > 0) {
                foreach ($comment as $wo['comment']) {
                    $html .= Wo_LoadPage('blog/comment-list');
                }
                $notification_data_array = array(
                    'recipient_id' => $get_blog['user'],
                    'type' => 'blog_commented',
                    'blog_id' => $lastId,
                    'text' => '',
                    'url' => 'index.php?link1=read-blog&id=' . $get_blog['id']
                );
                Wo_RegisterNotification($notification_data_array);
                $data = array(
                    'status' => 200,
                    'html' => $html,
                    'comments' => Wo_GetBlogCommentsCount($_POST['blog']),
                    'user_id' => $get_blog['user']
                );
            }
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
