<?php 
defined('MAIN_SCRIPT') or die("richiamo diretto non permesso.");
//Api Telegram, replace "XXXXXXX:XXXXXXXXXXXXXXXXXXXXX" with your API KEY
define('BOT_TOKEN', 'XXXXXXX:XXXXXXXXXXXXXXXXXXXXX');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
//Bot id, change with your bot id
define('BOT_ID', '@name_bot');
//conteggio messaggi per whitelist
define('MIN_MSG_FOR_WHITELIST', 10);
define('MIN_MSG_FOR_REPLY_WITH_TAG', 3);
define('FILTER_TRIGGER_MULTIPLIER',450);
//verifica nuovi utenti: id dei gruppi dove è abilitata (es. -1001234567890)
define('GRUPPI_VERIFICA', []);
//pagina di verifica (https). È l'URL da registrare nel Mini App su BotFather; l'host deve coincidere con quello da cui è servita (controllo Turnstile)
define('VERIFICA_URL', 'https://www.sito.com/verifica_antispam/verificabot.php');
//Mini App: nome breve scelto in BotFather con /newapp. Il link nel gruppo diventa https://t.me/<bot>/<nome>?startapp=<id gruppo>.
//Lasciare vuoto ('') per usare il link diretto alla pagina (senza Mini App, serve il login di ripiego qui sotto).
define('MINIAPP_NOME', 'verifica');
//true = il pulsante di verifica funziona solo per il nuovo utente (pulsante callback; chi altro lo preme riceve un avviso).
//Si appoggia ad answerCallbackQuery con link al Mini App. Se il client non lo apre, impostare false: pulsante con link diretto, apribile da chiunque.
define('VERIFICA_BOTTONE_RISERVATO', true);
//validità massima (secondi) dei dati di avvio del Mini App (auth_date), contata da quando l'utente apre il Mini App
define('MINIAPP_MAX_AGE', 900);
//Login di ripiego (OIDC) per chi apre la pagina fuori da Telegram: Client ID del Login Widget (BotFather > mini app > bot > Login Widget).
//0 = disattivato. Il Client Secret non serve.
define('LOGIN_CLIENT_ID', 0);
//Cloudflare Turnstile
define('TURNSTILE_SITE_KEY', 'XXXXXXXXXXXX');
define('TURNSTILE_SECRET_KEY', 'XXXXXXXXXXXX');
//validità massima (secondi) dell'id_token di login Telegram, calcolata dal campo iat
define('LOGIN_MAX_AGE', 300);
define('VERIFICA_TIMEOUT_ORE', 6);
?>