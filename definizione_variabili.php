<?php 
defined('MAIN_SCRIPT') or die("richiamo diretto non permesso.");
/* BOT */
$username_bot = str_replace("@","",BOT_ID);
/* CHAT */
$id_chat = $update["message"]["chat"]["id"] ?? false;
$title_chat = $update["message"]["chat"]["title"] ?? false;
$tipo_chat = $update["message"]["chat"]["type"] ?? false;
/* UTENTE */
$id_user = $update["message"]["from"]["id"] ?? false;
$nome_user = $update["message"]["from"]["first_name"] ?? false;
/* FORWARD */
$forward_user_id = $update["message"]["forward_origin"]["sender_user"]["id"] ?? false;
/* MESSAGGIO (FIX TEXT + CAPTION) */
if (isset($update["message"]["text"])) {
    $message = $update["message"]["text"];
} elseif (isset($update["message"]["caption"])) {
    $message = $update["message"]["caption"];
} else {
    $message = false;
}
$message_entities = $update["message"]["entities"] ?? [];
$caption_entities = $update["message"]["caption_entities"] ?? [];
$id_message = $update["message"]["message_id"] ?? false;
$date_message = $update["message"]["date"] ?? false;
/* FORWARD (FIX COMPLETO) */
$is_forwarded = false;
if (
    isset($update["message"]["forward_from"]) ||
    isset($update["message"]["forward_from_chat"]) ||
    isset($update["message"]["forward_sender_name"])
) {
    $is_forwarded = true;
}
/* QUOTE */
$quote_text = $update["message"]["quote"]["text"] ?? false;
/* REPLY */
$nome_quotato = $update["message"]["reply_to_message"]["from"]["first_name"] ?? false;
$id_quotato = $update["message"]["reply_to_message"]["from"]["id"] ?? false;
$id_msg_quotato = $update["message"]["reply_to_message"]["message_id"] ?? false;
/* CALLBACK */
$callback_query_user_id = $update["callback_query"]["from"]["id"] ?? false;
$callback_query_first_name = $update["callback_query"]["from"]["first_name"] ?? false;
$callback_query_username = $update["callback_query"]["from"]["username"] ?? false;
?>