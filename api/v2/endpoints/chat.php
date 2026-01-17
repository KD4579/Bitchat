<?php

if ($_POST['type'] == 'search') {

	try {

		chatSearchValidation();

		$text = Wo_Secure($_POST['text']);

		if (!empty($_POST['user_id'])) {
			$user_id = Wo_Secure($_POST['user_id']);
			$db->where("((from_id = '".$wo['user']['user_id']."' AND to_id = '".$user_id."') OR (from_id = '".$user_id."' AND to_id = '".$wo['user']['user_id']."'))");
		}
		elseif (!empty($_POST['page_id'])) {
			$page_id = Wo_Secure($_POST['page_id']);

			$db->where("page_id",$page_id);
		}
		elseif (!empty($_POST['group_id'])) {
			$group_id = Wo_Secure($_POST['group_id']);

			$db->where("group_id",$group_id);
		}

		$messages = $db->where("text","%".$text."%","like")->get(T_MESSAGES);
		$search = array_map(function ($message)
		{
			return GetMessageById($message->id);
		}, $messages);

		$response_data = array(
            'api_status' => 200,
            'data' => $search
        );

	} catch (Exception $e) {
		$error_code    = 5;
    	$error_message = $e->getMessage();
	}
}