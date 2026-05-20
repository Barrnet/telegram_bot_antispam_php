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
?>