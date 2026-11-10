<?php
// Script da cron (solo CLI): rimuove dai gruppi con verifica abilitata gli utenti che non hanno completato
// la verifica entro VERIFICA_TIMEOUT_ORE ore (default 6). Rimozione senza ban: possono rientrare e ripetere la verifica.
// Esempio crontab (ogni 15 minuti):  */15 * * * * php /percorso/public_html/tgbot/oti_cspam_bot/pulizia_non_verificati.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('MAIN_SCRIPT', true);
chdir(__DIR__);
include_once(__DIR__ . "/config.php");
include_once(__DIR__ . "/funzioni.php");

$timeout_ore = defined('VERIFICA_TIMEOUT_ORE') ? (int)VERIFICA_TIMEOUT_ORE : 6;
$scadenza    = time() - $timeout_ore * 3600;

// Evita esecuzioni sovrapposte se un giro dura più dell'intervallo del cron
ensureDir(__DIR__ . "/logs");
$lock = fopen(__DIR__ . "/logs/pulizia.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

foreach (glob(__DIR__ . "/group_data/*", GLOB_ONLYDIR) as $cartella) {
    $chat_id = basename($cartella);
    if (!preg_match('/^-?[1-9][0-9]{0,18}$/', $chat_id)) continue; // non è una cartella di gruppo
    if (!isVerificaAttiva($chat_id)) continue;                      // niente rimozioni dove la verifica non è abilitata

    $scaduti = array_filter(loadNewMembers($chat_id), fn($ts) => (int)$ts <= $scadenza);
    if (!$scaduti) continue;

    // Servono i permessi di ban; con la cache vecchia il giro successivo riprova
    if (empty(getBotPermissions($chat_id)['can_ban'])) {
        logVerifica($chat_id, "pulizia: il bot non ha can_restrict_members, " . count($scaduti) . " utenti scaduti non rimossi");
        continue;
    }

    foreach ($scaduti as $user_id => $ts) {
        $user_id = (int)$user_id;
        $info = getMemberInfo($chat_id, $user_id);
        if ($info === null) { // errore API: lascio la voce e riprovo al prossimo giro
            logVerifica($chat_id, "pulizia: getChatMember user $user_id fallita, riprovo al prossimo giro");
            continue;
        }
        $stato = $info['status'] ?? '';
        // Solo chi è davvero rimasto bloccato (ristretto senza poter scrivere) va rimosso.
        // Chi è uscito, già bannato, admin o mai ristretto (es. entrato quando il bot non poteva restringere) esce solo dalla lista.
        $da_rimuovere = ($stato === 'restricted' && empty($info['can_send_messages']));

        // Claim atomico: se nel frattempo l'utente si è verificato la voce non c'è più e non si fa nulla
        if (!removeNewMember($chat_id, $user_id)) continue;

        if (!$da_rimuovere) {
            logVerifica($chat_id, "pulizia: user $user_id (stato $stato) tolto dalla lista, nessuna rimozione");
            continue;
        }
        if (kickMember($chat_id, $user_id)) {
            logVerifica($chat_id, "pulizia: user $user_id non verificato da oltre $timeout_ore h (ingresso " . date('d/m/Y H:i', (int)$ts) . "), rimosso senza ban");
        } else {
            saveNewMembers($chat_id, [['id' => $user_id]], (int)$ts); // rimozione fallita: ripristino la voce col timestamp originale
        }
        usleep(100000); // resto sotto i limiti di Telegram
    }
}
flock($lock, LOCK_UN);