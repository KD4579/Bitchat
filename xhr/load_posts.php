<?php
if ($f == 'load_posts') {
    $wo['page'] = 'home';
    $filterBy = !empty($_GET['filter_by']) ? Wo_Secure($_GET['filter_by']) : '';

    if ($filterBy === 'trading_signals') {
        // Trading tab: show only trading signal posts
        $wo['feed_filter'] = 'trading_signals';
    } elseif ($filterBy === 'following') {
        $wo['feed_filter'] = 'following';
    } elseif ($filterBy === 'creators') {
        $wo['feed_filter'] = 'creators';
    }

    $load = sanitize_output(Wo_LoadPage('home/load-posts'));
    echo $load;
    exit();
}
