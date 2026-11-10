<?php 
defined('MAIN_SCRIPT') or die("richiamo diretto non permesso.");
// ================= RICHIESTE HTTP CON RETRY =================

function apiRequest($url, $post_data = null, $retries = 2, $timeout = 5) {
    for ($tentativo = 0; $tentativo <= $retries; $tentativo++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }

        $response = curl_exec($ch);
        $errore   = curl_error($ch);
        curl_close($ch);

        if ($response !== false) {
            return $response;
        }

        // Log del tentativo fallito, poi breve pausa prima del retry
        error_log("apiRequest tentativo $tentativo fallito su $url: $errore");
        if ($tentativo < $retries) {
            usleep(300000); // 300ms
        }
    }

    return false;
}
// ================= API TELEGRAM =================
function sendMessage($chatID, $text, $parse_mode = "HTML") {
    $payload = json_encode([
        'chat_id'    => $chatID,
        'text'       => $text,
        'parse_mode' => $parse_mode,
		'disable_notification' => true,
    ]);
    return apiRequest(API_URL . "sendMessage", $payload);
}

function deleteMessages($chatID, $message_id) {
    $response = apiRequest(API_URL . "deleteMessage?chat_id=$chatID&message_id=$message_id");
    if (!$response) return false;
    $data = json_decode($response, true);
    return isset($data["ok"]) && $data["ok"];
}

function banChatMember($chatID, $user_id, $until_date = false, $revoke_messages = false) {
    apiRequest(API_URL . "banChatMember?chat_id=$chatID&user_id=$user_id&until_date=$until_date&revoke_messages=$revoke_messages");
}
// ================= UTILITY =================
function ensureDir($path) {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}
function loadCache($path, $max_age_minutes = 60) {
    if (!file_exists($path)) return null;
    $age_minutes = (time() - filemtime($path)) / 60;
    if ($age_minutes >= $max_age_minutes) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function isTelegramCommand(array $message) {
    if (empty($message['entities'])) {
        return false;
    }
    foreach ($message['entities'] as $entity) {
        if (
            $entity['type'] === 'bot_command' &&
            $entity['offset'] === 0
        ) {
            return true;
        }
    }
    return false;
}
// ================= FUNZIONI FILTRO =================
function contaCorrispondenze($testo, $arrayDiStringhe) {
    $conteggio = 0;
    $testo = strtoupper($testo);

    foreach ($arrayDiStringhe as $stringa) {
        $stringa = trim(strtoupper($stringa));
        if ($stringa === "") continue;

        // Conta le occorrenze rispettando i boundary di parola
        $pattern = '/(?<!\w)' . preg_quote($stringa, '/') . '(?!\w)/u';
        $conteggio += preg_match_all($pattern, $testo);
    }

    return $conteggio;
}
//costruisco regex emoji
function build_emoji_regex() {
    $txt = file_get_contents('https://unicode.org/Public/emoji/15.1/emoji-test.txt');
	if ($txt === false) return false;
    $emojis = [];

    foreach (explode("\n", $txt) as $line) {
        if (str_starts_with(trim($line), '#') || trim($line) === '') continue;
        if (!str_contains($line, 'fully-qualified')) continue;

        $parts = explode(';', $line);
        $codepoints = array_map('trim', explode(' ', trim($parts[0])));

        // Costruisce la sequenza di caratteri
        $seq = '';
        foreach ($codepoints as $cp) {
            $seq .= mb_chr(hexdec($cp), 'UTF-8');
        }
        $emojis[] = preg_quote($seq, '/');
    }

    // Ordina dal più lungo al più corto (le sequenze ZWJ prima)
    usort($emojis, fn($a, $b) => strlen($b) - strlen($a));
	ensureDir("./bot_data");
	file_put_contents("./bot_data/emoji_regex.txt",'/(' . implode('|', $emojis) . ')/u', LOCK_EX);
	return true;
}
function hasSuspiciousUnicode($text) {
	//carica pattern emoji, lo ricrea se non esiste il file
	ensureDir("./bot_data");	
    if (!file_exists("./bot_data/emoji_regex.txt")) {
        if (!build_emoji_regex()) return null;
    }
	$pattern_emoji = file_get_contents("./bot_data/emoji_regex.txt");
	if (@preg_match($pattern_emoji, '') === false) return null;
	//rimuove le emoji per evitare falsi positivi
	$text_no_emoji = preg_replace($pattern_emoji, '', $text);
    $patterns = [
        '/[\x{0400}-\x{04FF}]/u',                  // Cirillico
        '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}]/u', // Cinese
        '/[\x{3040}-\x{30FF}]/u',                  // Giapponese
        '/[\x{1D400}-\x{1D7FF}]/u',                // Simboli matematici Unicode (bold/italic/script)
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text_no_emoji)) {
            return true;
        }
    }
	// Mix sospetto: ASCII + non-ASCII che NON siano lettere latine estese
    // Lettere latine estese (es. è, à, ù, ò, ç) sono nel range U+00C0–U+024F: escluse
    if (
        preg_match('/[a-zA-Z]/', $text_no_emoji) &&
        preg_match('/[^\x00-\x7F]/', $text_no_emoji) &&
        preg_match('/[\x{0250}-\x{1CFF}]/u', $text_no_emoji)
    ) {
        return true;
    }
    return false;
}
// ================= FILTRI SU TIPO DI MESSAGGIO =================
// Ritorna true se il messaggio è l'inoltro di una storia (campo "story" dell'API Telegram)
function isForwardedStory(array $msg) {
    return isset($msg['story']);
}
// Ritorna true se il messaggio è la condivisione di un contatto telefonico (campo "contact")
function isContactShare(array $msg) {
    return isset($msg['contact']);
}

// ================= TESTO NASCOSTO NEI MESSAGGI =================
// Estrae in modo ricorsivo il testo da un campo "rich_message" (blocchi annidati con stringhe e menzioni).
// La struttura è nota solo da esempi reali, quindi la lettura è tollerante: si prendono le stringhe nelle
// liste e sotto le chiavi text/title/url, si ignorano type/size/username. Profondità limitata.
function estraiTestoRicco($nodo, $profondita = 0) {
    if ($profondita > 8) return "";
    if (is_string($nodo)) return $nodo;
    if (!is_array($nodo)) return "";
    $parti = [];
    // menzione senza testo: uso lo username con la @
    if (($nodo['type'] ?? '') === 'mention' && empty($nodo['text']) && !empty($nodo['username'])) {
        $parti[] = '@' . ltrim((string)$nodo['username'], '@');
    }
    foreach ($nodo as $chiave => $valore) {
        if (is_int($chiave) || in_array($chiave, ['text', 'title', 'url', 'blocks', 'items', 'children'], true)) {
            $t = estraiTestoRicco($valore, $profondita + 1);
            if ($t !== "") $parti[] = $t;
        }
    }
    return trim(implode(" ", $parti));
}
// Estrae testi e URL dei bottoni inline (reply_markup.inline_keyboard) per poterli analizzare come testo.
function estraiTestoBottoni($reply_markup) {
    if (!is_array($reply_markup) || empty($reply_markup['inline_keyboard']) || !is_array($reply_markup['inline_keyboard'])) {
        return "";
    }
    $parti = [];
    foreach ($reply_markup['inline_keyboard'] as $riga) {
        if (!is_array($riga)) continue;
        foreach ($riga as $bottone) {
            if (!is_array($bottone)) continue;
            foreach ([$bottone['text'] ?? null, $bottone['url'] ?? null,
                      $bottone['login_url']['url'] ?? null, $bottone['web_app']['url'] ?? null] as $v) {
                if (is_string($v) && $v !== "") $parti[] = $v;
            }
        }
    }
    return implode(" ", $parti);
}

// ================= FILTRO LINK TELEGRAM =================
function hasTelegramLinks($text, $entities = [], $chat_id = false, $chat_username = false, $is_reply = false, $user_count = 0, $json_update_from_api = false) {
	if (isTelegramCommand($json_update_from_api)) { return false;}
    $bot_username = strtolower(str_replace("@", "", BOT_ID));
    $whitelist_mentions = [$bot_username, "admin"];
    if ($chat_id) {
        $whitelist_mentions = array_merge(
            $whitelist_mentions,
            loadMentionable($chat_id),
            fetchAndCacheAdmins($chat_id)
        );
    }
    // 1. Link diretti t.me / telegram.me — bloccati sempre, anche in reply
    if (preg_match_all('/(https?:\/\/)?(t\.me|telegram\.me)\/([a-zA-Z0-9_+-]+)/i', $text, $matches)) {
        foreach ($matches[3] as $linked_slug) {
            // Se il link punta allo stesso gruppo, lo ignora
            if ($chat_username && strtolower($linked_slug) === strtolower($chat_username)) {
                continue;
            }
            return true;
        }
	}
    // 2. Mention nel testo — in reply da utente con storico si ignorano
    $skip_mention_check = $is_reply && $user_count >= MIN_MSG_FOR_REPLY_WITH_TAG;
    if (!$skip_mention_check && preg_match_all('/(?<!\w)@([a-zA-Z0-9_]{3,})/', $text, $matches)) {
        foreach ($matches[1] as $mentioned) {
            if (!in_array(strtolower($mentioned), $whitelist_mentions)) {
                return true;
            }
        }
    }
    // 3. Entities (link mascherati e mention cliccabili)
    foreach ($entities as $e) {
        if (!isset($e['type'])) continue;
        // Link cliccabile che punta a Telegram — bloccato sempre
		if ($e['type'] === 'text_link' && isset($e['url'])) {
			if (preg_match('/(t\.me|telegram\.me)\/([a-zA-Z0-9_+-]+)/i', $e['url'], $m)) {
				if (!$chat_username || strtolower($m[2]) !== strtolower($chat_username)) {
					return true;
				}
			}
		}
        // Mention cliccabile — in reply da utente con storico si ignora
        if ($e['type'] === 'mention' && !$skip_mention_check && isset($e['offset'], $e['length'])) {
            $mentioned = mb_substr($text, $e['offset'] + 1, $e['length'] - 1);
            if (!in_array(strtolower($mentioned), $whitelist_mentions)) {
                return true;
            }
        }
    }
    return false;
}
// ================= LOG BAN =================
function logCS($chat_id, $chat_title, $user_id, $nome_user, $filtro, $message, $ban) {
    $log_dir  = __DIR__ . "/logs/$chat_id";
    $log_file = "$log_dir/counter_spam_action_log.json";

    ensureDir($log_dir);

    $log = [];
    if (file_exists($log_file)) {
        $data = json_decode(file_get_contents($log_file), true);
        if (is_array($data)) {
            $log = $data;
        }
    }
    $text_ban = $ban ? "Utente Bannato" : "Messaggio Cancellato";
    $log[] = [
        "data"       => date("d/m/Y H:i:s"),
        "chat_id"    => $chat_id,
        "chat_title" => $chat_title,
        "user_id"    => $user_id,
        "user_name"  => $nome_user,
        "filtro"     => $filtro,
        "messaggio"  => $message,
        "azione"     => $text_ban,
    ];
    file_put_contents($log_file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
// ================= ADMIN AUTOMATICI =================
function fetchAndCacheAdmins($chat_id, $max_age_minutes = 60) {
    $cache_file = __DIR__ . "/group_data/$chat_id/admins.json";
    $cached = loadCache($cache_file, $max_age_minutes);
    if ($cached !== null) return $cached;

    $response = apiRequest(API_URL . "getChatAdministrators?chat_id=$chat_id&return_bots=true");
    if (!$response) return [];
    $data = json_decode($response, true);
    if (!isset($data["ok"]) || !$data["ok"]) return [];

    $usernames = [];
    foreach ($data["result"] as $member) {
        $username = $member["user"]["username"] ?? false;
        if ($username) {
            $usernames[] = strtolower($username);
        }
    }

    ensureDir(__DIR__ . "/group_data/$chat_id");
    file_put_contents($cache_file, json_encode($usernames, JSON_PRETTY_PRINT), LOCK_EX);
    return $usernames;
}
// ================= NUOVI MEMBRI DEL GRUPPO =================
function getNewMembersFile($chat_id) {
    return __DIR__ . "/group_data/$chat_id/new_members.json";
}
function loadNewMembers($chat_id) {
    $file = getNewMembersFile($chat_id);
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
// Salva id => timestamp di ingresso per ogni utente in new_chat_members.
// Lettura+scrittura sotto un unico lock per evitare race tra update concorrenti.
// Se un utente rientra, il timestamp viene sovrascritto con l'ultimo ingresso.
function saveNewMembers($chat_id, array $members, $timestamp) {
    $ids = [];
    foreach ($members as $m) {
        if (!empty($m['id']) && empty($m['is_bot'])) $ids[] = (int)$m['id'];
    }
    if (!$ids) return;

    ensureDir(__DIR__ . "/group_data/$chat_id");
    $fp = fopen(getNewMembersFile($chat_id), 'c+');
    if (!$fp) return;
    if (flock($fp, LOCK_EX)) {
        $raw  = stream_get_contents($fp);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];
        foreach ($ids as $id) {
            $data[$id] = (int)$timestamp;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
// Rimuove un utente dal file dei nuovi membri. Ritorna true se era presente.
function removeNewMember($chat_id, $user_id) {
    $file = getNewMembersFile($chat_id);
    if (!file_exists($file)) return false;
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    $trovato = false;
    if (flock($fp, LOCK_EX)) {
        $data = json_decode(stream_get_contents($fp), true);
        if (is_array($data) && isset($data[$user_id])) {
            unset($data[$user_id]);
            $trovato = true;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $trovato;
}
// ================= VERIFICA NUOVI UTENTI =================
function isVerificaAttiva($chat_id) {
    return in_array((int)$chat_id, array_map('intval', GRUPPI_VERIFICA), true);
}
// Log di diagnosi della verifica: logs/<chat>/verifica_debug.txt (non contiene token)
function logVerifica($chat_id, $testo) {
    $dir = __DIR__ . "/logs/$chat_id";
    ensureDir($dir);
    file_put_contents("$dir/verifica_debug.txt", date('d/m/Y H:i:s') . " " . $testo . "\n", FILE_APPEND | LOCK_EX);
}
// Chiama restrictChatMember con i permessi indicati. Ritorna true se Telegram risponde ok.
function setMemberPermissions($chat_id, $user_id, array $permissions) {
    $payload = json_encode([
        'chat_id'                         => $chat_id,
        'user_id'                         => $user_id,
        'permissions'                     => $permissions,
        'use_independent_chat_permissions' => true,
    ]);
    $response = apiRequest(API_URL . "restrictChatMember", $payload);
    if (!$response) {
        logVerifica($chat_id, "restrictChatMember user $user_id: nessuna risposta da Telegram");
        return false;
    }
    $data = json_decode($response, true);
    if (empty($data["ok"])) {
        error_log("restrictChatMember fallita ($chat_id / $user_id): " . $response);
        logVerifica($chat_id, "restrictChatMember user $user_id FALLITA: " . $response);
        return false;
    }
    logVerifica($chat_id, "restrictChatMember user $user_id ok");
    return true;
}
function restrictMember($chat_id, $user_id) {
    $nomi = ['can_send_messages', 'can_send_audios', 'can_send_documents', 'can_send_photos',
             'can_send_videos', 'can_send_video_notes', 'can_send_voice_notes', 'can_send_polls',
             'can_send_other_messages', 'can_add_web_page_previews', 'can_change_info',
             'can_invite_users', 'can_pin_messages', 'can_manage_topics'];
    return setMemberPermissions($chat_id, $user_id, array_fill_keys($nomi, false));
}
// Ripristina i permessi di default del gruppo (non "tutto permesso", per rispettare le impostazioni del gruppo)
function unrestrictMember($chat_id, $user_id) {
    $permissions = null;
    $response = apiRequest(API_URL . "getChat?chat_id=$chat_id");
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data["ok"]) && !empty($data["result"]["permissions"])) {
            $permissions = $data["result"]["permissions"];
        }
    }
    if (!$permissions) {
        $permissions = [
            'can_send_messages' => true, 'can_send_audios' => true, 'can_send_documents' => true,
            'can_send_photos' => true, 'can_send_videos' => true, 'can_send_video_notes' => true,
            'can_send_voice_notes' => true, 'can_send_polls' => true, 'can_send_other_messages' => true,
            'can_add_web_page_previews' => true, 'can_invite_users' => true,
        ];
    }
    return setMemberPermissions($chat_id, $user_id, $permissions);
}
// Link alla verifica: Mini App aperto direttamente in Telegram se MINIAPP_NOME è impostato, altrimenti link alla pagina web
function linkVerifica($chat_id) {
    if (MINIAPP_NOME !== '') {
        return "https://t.me/" . str_replace("@", "", BOT_ID) . "/" . MINIAPP_NOME . "?startapp=" . $chat_id;
    }
    return VERIFICA_URL . "?idgruppo=" . $chat_id;
}
// Messaggio di benvenuto unico per gruppo: lo stato (id dell'ultimo messaggio) sta in group_data/<chat>/welcome_message.json
function getWelcomeFile($chat_id) {
    return __DIR__ . "/group_data/$chat_id/welcome_message.json";
}
function loadWelcomeState($chat_id) {
    $file = getWelcomeFile($chat_id);
    if (!file_exists($file)) return ['message_id' => 0];
    $d = json_decode(file_get_contents($file), true);
    if (!is_array($d)) return ['message_id' => 0];
    return ['message_id' => (int)($d['message_id'] ?? 0)];
}
// Restringe i nuovi utenti e manda un unico messaggio di benvenuto col link alla verifica.
// Il messaggio precedente del gruppo viene cancellato; il nuovo cita solo gli utenti appena entrati.
function avviaVerificaNuoviMembri($chat_id, array $members) {
    $nuovi = []; // id => nome
    foreach ($members as $m) {
        if (empty($m['id']) || !empty($m['is_bot'])) {
            logVerifica($chat_id, "membro saltato (bot o id mancante): " . ($m['id'] ?? '?'));
            continue;
        }
        if (!restrictMember($chat_id, $m['id'])) continue; // motivo già loggato in setMemberPermissions
        $nuovi[(int)$m['id']] = (string)($m['first_name'] ?? 'utente');
    }
    if (!$nuovi) {
        logVerifica($chat_id, "nessun utente ristretto: messaggio di benvenuto non inviato");
        return;
    }
    ensureDir(__DIR__ . "/group_data/$chat_id");
    // Lock per gruppo: ingressi ravvicinati non devono lasciare due messaggi o perderne il riferimento
    $lock = fopen(__DIR__ . "/group_data/$chat_id/welcome.lock", 'c');
    if ($lock) flock($lock, LOCK_EX);

    $stato    = loadWelcomeState($chat_id);
    $menzioni = [];
    foreach ($nuovi as $id => $nome) {
        $menzioni[] = "<a href='tg://user?id=$id'>" . htmlspecialchars($nome, ENT_QUOTES) . "</a>";
    }
    $url   = linkVerifica($chat_id);
    $testo = "Benvenuto " . implode(", ", $menzioni) . "!\n"
           . "Per poter scrivere in questo gruppo completa la verifica anti-spam: "
           . "<a href='$url'>clicca qui</a>.";
    $risposta = sendMessage($chat_id, $testo);
    $esito    = $risposta ? json_decode($risposta, true) : null;
    $nuovo_id = (int)($esito['result']['message_id'] ?? 0);

    if (empty($esito['ok']) || !$nuovo_id) {
        // Invio fallito: lascio intatti vecchio messaggio e stato
        logVerifica($chat_id, "sendMessage FALLITO: " . ($risposta ?: 'nessuna risposta'));
    } else {
        logVerifica($chat_id, "messaggio di benvenuto inviato (id $nuovo_id)");
        if ($stato['message_id']) {
            $ok = deleteMessages($chat_id, $stato['message_id']);
            logVerifica($chat_id, "cancellazione benvenuto precedente {$stato['message_id']}: " . ($ok ? "ok" : "non riuscita (già cancellato o più vecchio di 48h)"));
        }
        file_put_contents(getWelcomeFile($chat_id),
            json_encode(['message_id' => $nuovo_id], JSON_PRETTY_PRINT), LOCK_EX);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}
// Stato di un utente nel gruppo: ritorna il "result" di getChatMember, oppure null se la chiamata fallisce
function getMemberInfo($chat_id, $user_id) {
    $response = apiRequest(API_URL . "getChatMember?chat_id=$chat_id&user_id=$user_id");
    $data = $response ? json_decode($response, true) : null;
    return !empty($data["ok"]) ? $data["result"] : null;
}
// Rimuove l'utente dal gruppo senza bannarlo: ban + subito unban, così può rientrare con il link di invito.
// Se l'unban fallisce lo ritenta, perché altrimenti l'utente resterebbe bannato. Ritorna true se la rimozione è completa.
function kickMember($chat_id, $user_id) {
    $risposta = apiRequest(API_URL . "banChatMember", json_encode(['chat_id' => $chat_id, 'user_id' => $user_id]));
    $data = $risposta ? json_decode($risposta, true) : null;
    if (empty($data["ok"])) {
        logVerifica($chat_id, "banChatMember (rimozione) user $user_id FALLITO: " . ($risposta ?: 'nessuna risposta'));
        return false;
    }
    for ($i = 0; $i < 3; $i++) {
        $risposta = apiRequest(API_URL . "unbanChatMember", json_encode(['chat_id' => $chat_id, 'user_id' => $user_id, 'only_if_banned' => true]));
        $data = $risposta ? json_decode($risposta, true) : null;
        if (!empty($data["ok"])) return true;
        usleep(500000);
    }
    logVerifica($chat_id, "ATTENZIONE: user $user_id rimosso ma unbanChatMember FALLITO, resta bannato: " . ($risposta ?: 'nessuna risposta'));
    return true; // l'utente è stato rimosso comunque; non va riprovata la rimozione
}
// ================= PERMESSI BOT =================
function getBotPermissions($chat_id, $max_age_minutes = 60) {
    $cache_file = __DIR__ . "/group_data/$chat_id/bot_permissions.json";
    $cached = loadCache($cache_file, $max_age_minutes);
    if ($cached !== null) return $cached;

    $bot_id   = explode(":", BOT_TOKEN)[0];
    $response = apiRequest(API_URL . "getChatMember?chat_id=$chat_id&user_id=$bot_id");
    if (!$response) return ['can_delete' => false, 'can_ban' => false];
    $data = json_decode($response, true);
    if (!isset($data["ok"]) || !$data["ok"]) return ['can_delete' => false, 'can_ban' => false];

    $member = $data["result"];
    $permissions = [
        'can_delete' => $member["can_delete_messages"] ?? false,
        'can_ban'    => $member["can_restrict_members"] ?? false,
    ];

    ensureDir(__DIR__ . "/group_data/$chat_id");
    file_put_contents($cache_file, json_encode($permissions, JSON_PRETTY_PRINT), LOCK_EX);
    return $permissions;
}
// ================= GESTIONE UTENTI MENZIONABILI =================
function getMentionableFile($chat_id) {
    return __DIR__ . "/group_data/$chat_id/mentionable.json";
}
function loadMentionable($chat_id) {
    $file = getMentionableFile($chat_id);
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function addMentionable($chat_id, $username) {
    if (empty($username)) return;
    $username = strtolower(str_replace("@", "", $username));
    $list = loadMentionable($chat_id);
    if (in_array($username, $list)) return;
    $list[] = $username;
    ensureDir(__DIR__ . "/group_data/$chat_id");
    file_put_contents(getMentionableFile($chat_id), json_encode($list, JSON_PRETTY_PRINT), LOCK_EX);
}
function isMentionable($chat_id, $username) {
    $username = strtolower(str_replace("@", "", $username));
    return in_array($username, loadMentionable($chat_id));
}
// ================= GESTIONE WHITELIST =================
function getWhitelistFile($chat_id) {
    return __DIR__ . "/group_data/$chat_id/whitelist.json";
}
function loadWhitelist($chat_id) {
    $file = getWhitelistFile($chat_id);
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function saveWhitelist($chat_id, $data) {
    ensureDir(__DIR__ . "/group_data/$chat_id");
    file_put_contents(getWhitelistFile($chat_id), json_encode($data), LOCK_EX);
}
function updateUserCountSmart($chat_id, $user_id, $threshold = MIN_MSG_FOR_WHITELIST) {
    $whitelist = loadWhitelist($chat_id);
    // Già whitelistato → non scrivere più
    if (isset($whitelist[$user_id]) && $whitelist[$user_id] >= $threshold) {
        return $whitelist[$user_id];
    }
    $whitelist[$user_id] = ($whitelist[$user_id] ?? 0) + 1;
    saveWhitelist($chat_id, $whitelist);
    return $whitelist[$user_id];
}
function isWhitelisted($chat_id, $user_id, $threshold = MIN_MSG_FOR_WHITELIST) {
    $whitelist = loadWhitelist($chat_id);
    return isset($whitelist[$user_id]) && $whitelist[$user_id] >= $threshold;
}
// ================= HELPER: ban + log + notifica =================
function handleSpam($id_chat, $id_message, $id_user, $title_chat, $nome_user, $filtro, $message, $enable_ban = true) {
    $deleted = deleteMessages($id_chat, $id_message);
	
    // Se il messaggio non esiste più, un moderatore ha già agito — non notificare
    if (!$deleted) return;
	
    if ($enable_ban) {
        $testo = "Messaggio cancellato e utente bannato.\n Utente <a href='tg://user?id=$id_user'>$nome_user ($id_user)</a>.\nFiltro: $filtro";
        banChatMember($id_chat, $id_user);
    } else {
        $testo = "Messaggio cancellato.\n Utente <a href='tg://user?id=$id_user'>$nome_user ($id_user)</a>.\nFiltro: $filtro";
    }
    logCS($id_chat, $title_chat, $id_user, $nome_user, $filtro, $message, $enable_ban);
    sendMessage($id_chat, $testo);
    exit;
}
?>