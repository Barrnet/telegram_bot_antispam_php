<?php 
defined('MAIN_SCRIPT') or die("richiamo diretto non permesso.");
// ================= API TELEGRAM =================
function sendMessage($chatID, $text, $parse_mode = "HTML") {
    $payload = json_encode([
        'chat_id'    => $chatID,
        'text'       => $text,
        'parse_mode' => $parse_mode,
    ]);
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
        ],
    ]);
    file_get_contents(API_URL . "sendMessage", false, $context);
}
function deleteMessages($chatID, $message_id) {
    file_get_contents(API_URL . "deleteMessage?chat_id=$chatID&message_id=$message_id");
}
function banChatMember($chatID, $user_id, $until_date = false, $revoke_messages = false) {
    file_get_contents(API_URL . "banChatMember?chat_id=$chatID&user_id=$user_id&until_date=$until_date&revoke_messages=$revoke_messages");
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
// ================= FUNZIONI FILTRO =================
function contaCorrispondenze($testo, $arrayDiStringhe) {
    $conteggio = 0;
    $testo = strtoupper($testo);
    foreach ($arrayDiStringhe as $stringa) {
        $stringa = trim(strtoupper($stringa));
        if ($stringa !== "") {
            $conteggio += substr_count($testo, $stringa);
        }
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
		preg_match('/[\x{0250}-\x{1CFF}\x{1E00}-\x{FFFF}]/u', $text_no_emoji)
	) {
        return true;
    }
    return false;
}
// ================= FILTRO LINK TELEGRAM =================
function hasTelegramLinks($text, $entities = [], $chat_id = false, $chat_username = false, $is_reply = false, $user_count = 0) {
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
    if (preg_match_all('/(https?:\/\/)?(t\.me|telegram\.me)\/([a-zA-Z0-9_]+)/i', $text, $matches)) {
        foreach ($matches[3] as $linked_slug) {
            // Se il link punta allo stesso gruppo, lo ignora
            if ($chat_username && strtolower($linked_slug) === strtolower($chat_username)) {
                continue;
            }
            return true;
        }
    // 2. Mention nel testo — in reply da utente con storico si ignorano
    $skip_mention_check = $is_reply && $user_count >= MIN_MSG_FOR_REPLY_WITH_TAG;
    if (!$skip_mention_check && preg_match_all('/@([a-zA-Z0-9_]{3,})/', $text, $matches)) {
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
			if (preg_match('/(t\.me|telegram\.me)\/([a-zA-Z0-9_]+)/i', $e['url'], $m)) {
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

    $response = file_get_contents(API_URL . "getChatAdministrators?chat_id=$chat_id");
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
// ================= PERMESSI BOT =================
function getBotPermissions($chat_id, $max_age_minutes = 60) {
    $cache_file = __DIR__ . "/group_data/$chat_id/bot_permissions.json";
    $cached = loadCache($cache_file, $max_age_minutes);
    if ($cached !== null) return $cached;

    $bot_id   = explode(":", BOT_TOKEN)[0];
    $response = file_get_contents(API_URL . "getChatMember?chat_id=$chat_id&user_id=$bot_id");
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
    deleteMessages($id_chat, $id_message);
    if ($enable_ban) {
        $testo = "Messaggio cancellato, <a href='tg://user?id=$id_user'>$nome_user</a> bannato.\nFiltro: $filtro";
        banChatMember($id_chat, $id_user);
    } else {
        $testo = "Messaggio cancellato.\nFiltro: $filtro";
    }
    logCS($id_chat, $title_chat, $id_user, $nome_user, $filtro, $message, $enable_ban);
    sendMessage($id_chat, $testo);
    exit;
}
?>