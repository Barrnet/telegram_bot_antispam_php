<?php 
define('MAIN_SCRIPT', true);
include_once("config.php");
include_once("funzioni.php");
// Ricezione update
$opts    = ['http' => ['header' => 'Accept-Charset: UTF-8, *;q=0']];
$context = stream_context_create($opts);
$content = file_get_contents("php://input", false, $context);
file_put_contents(__DIR__ . "/logs/log_general.txt", $content . "\n", FILE_APPEND);
$update = json_decode($content, true);
if (!$update) exit;
// Variabili
include_once("definizione_variabili.php");
// Testo completo da analizzare (messaggio + eventuale citazione)
$testo_analisi = implode(" ", array_filter([$message, $quote_text]));
// Carico filtri spam
$array_filtro = json_decode(file_get_contents("./filtro_spam.json"), true);
// ==========================
// CONTROLLO SE ABILITARE I BAN
// ==========================
$file_data_primo_messaggio = __DIR__ . "/group_data/$id_chat/first_message_date.txt";
if (!file_exists($file_data_primo_messaggio)) {
	ensureDir(__DIR__ . "/group_data/$id_chat");
    file_put_contents($file_data_primo_messaggio, $date_message, LOCK_EX);
}
$bot_permissions = getBotPermissions($id_chat);
//se non può cancellare messaggi, skippa tutti i controlli
if (!$bot_permissions['can_delete']) exit;
//abilito ban dopo due settimane di attesa dal primo messaggio loggato e solo se il bot ha il permesso per bannare
$data_primo_messaggio = (int) file_get_contents($file_data_primo_messaggio);
if ($bot_permissions['can_ban']) {
	$enable_ban = (time() - $data_primo_messaggio) > 1209600;
}else {
	$enable_ban = false;
}
// ==========================
// WHITELIST SMART
// ==========================
if ($message && strlen($message) > 3) {
    $user_count = updateUserCountSmart($id_chat, $id_user, MIN_MSG_FOR_WHITELIST);
} else {
    $whitelist  = loadWhitelist($id_chat);
    $user_count = $whitelist[$id_user] ?? 0;
}
// Utente fidato → nessun controllo necessario
if ($user_count >= MIN_MSG_FOR_WHITELIST) exit;
// Messaggio vuoto → nessun filtro da applicare
if (!$message) exit;
// ==========================
// UNICODE SOSPETTO
// ==========================
if (hasSuspiciousUnicode($testo_analisi)) {
    handleSpam($id_chat, $id_message, $id_user, $title_chat, $nome_user, "unicode_sospetto", $testo_analisi, $enable_ban);
}
// ==========================
// FILTRO LINK TELEGRAM
// ==========================
$all_entities = array_merge($message_entities, $caption_entities);
$is_reply     = isset($update["message"]["reply_to_message"]);
if (hasTelegramLinks($testo_analisi, $all_entities, $id_chat, $is_reply, $user_count)) {
    handleSpam($id_chat, $id_message, $id_user, $title_chat, $nome_user, "link_telegram", $testo_analisi, $enable_ban);
}
// ==========================
// FILTRI SPAM
// ==========================
if ($array_filtro) {
    foreach ($array_filtro as $nome_filtro => $filtro) {
        $conteggio = contaCorrispondenze($testo_analisi, explode(",", $filtro["array_trigger"]));
        if ($conteggio >= $filtro["conteggio_trigger"]) {
            handleSpam($id_chat, $id_message, $id_user, $title_chat, $nome_user, $nome_filtro, $testo_analisi, $enable_ban);
        }
    }
}
// ==========================
// UTENTE PULITO — registra username come menzionabile
// ==========================
$username_user = $update["message"]["from"]["username"] ?? false;
if ($username_user) {
    addMentionable($id_chat, $username_user);
}
?>
