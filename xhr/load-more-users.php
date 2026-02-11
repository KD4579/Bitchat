<?php 
if ($f == 'load-more-users') {
    // Accept from both GET and POST for compatibility
    $offset = (isset($_POST['offset']) && is_numeric($_POST['offset'])) ? $_POST['offset'] :
              ((isset($_GET['offset']) && is_numeric($_GET['offset'])) ? $_GET['offset'] : false);
    $query  = (isset($_POST['query'])) ? $_POST['query'] :
              ((isset($_GET['query'])) ? $_GET['query'] : '');
    $html   = "";
    $data   = array(
        "status" => 404,
        "html" => $html
    );

    if ($offset) {
        // Merge POST and GET parameters for filter compatibility
        $search_params = array_merge($_GET, $_POST);
        $groups = Wo_GetSearchFilter(
            $search_params
        , 10, $offset);
        if (count($groups) > 0) {
            foreach ($groups as $wo['result']) {
                if ($wo['config']['theme'] == 'sunshine') {
                    $html .= Wo_LoadPage('search/user-result');
                }
                else{
                    $html .= Wo_LoadPage('search/user-result');
                }
            }
            $data['status'] = 200;
            $data['html']   = $html;
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
