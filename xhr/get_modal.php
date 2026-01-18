<?php
// +------------------------------------------------------------------------+
// | Lazy Modal Loader for Bitchat
// | Returns modal HTML on demand to improve initial page load
// +------------------------------------------------------------------------+

if ($f == 'get_modal') {
    $modal_id = Wo_Secure($_GET['modal_id'] ?? '');

    // Define allowed modals and their template paths
    $allowed_modals = array(
        'edit-post' => 'modals/edit-post',
        'delete-post' => 'modals/delete-post',
        'delete-comment' => 'modals/delete-comment',
        'delete-comment-reply' => 'modals/delete-comment-reply',
        'report-user' => 'modals/report-user',
        'unfriend' => 'modals/unfriend',
        'cover-image' => 'modals/cover-image',
        'profile-picture' => 'modals/profile-picture',
        'apply_job' => 'modals/apply_job',
        'edit_job' => 'modals/edit_job',
        'edit_offer' => 'modals/edit_offer',
        'pay-go-pro' => 'modals/pay-go-pro',
        'calling' => 'modals/calling',
        'calling-audio' => 'modals/calling-audio',
        'in_call' => 'modals/in_call',
        'in_audio_call' => 'modals/in_audio_call',
        'ai_images' => 'modals/ai_images',
        'ai_post' => 'modals/ai_post',
        'ai_blog' => 'modals/ai_blog'
    );

    // Validate modal ID
    if (empty($modal_id) || !isset($allowed_modals[$modal_id])) {
        http_response_code(404);
        echo json_encode(array('status' => 404, 'error' => 'Modal not found'));
        exit();
    }

    // Check if user is logged in for protected modals
    $protected_modals = array('edit-post', 'delete-post', 'report-user', 'unfriend', 'cover-image', 'profile-picture', 'pay-go-pro', 'ai_images', 'ai_post', 'ai_blog');

    if (in_array($modal_id, $protected_modals) && !$wo['loggedin']) {
        http_response_code(403);
        echo json_encode(array('status' => 403, 'error' => 'Authentication required'));
        exit();
    }

    // Load and return modal HTML
    $template_path = $allowed_modals[$modal_id];
    $modal_html = Wo_LoadPage($template_path);

    if (empty($modal_html)) {
        http_response_code(500);
        echo json_encode(array('status' => 500, 'error' => 'Failed to load modal'));
        exit();
    }

    // Return raw HTML
    header('Content-Type: text/html; charset=utf-8');
    echo $modal_html;
    exit();
}
