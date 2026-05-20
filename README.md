# Bot telegram antispam in PHP
Un bot in php per Telegram per la moderazione automatica dello spam tramite webhook. Pensato per gruppi di lingua italiana ma adattabile per altre lingue mediante espansione o riscrittura dei filtri preimpostati. Filtra:
* Messaggi con tag utenti esterni e link t.me.
* Messaggi con caratteri latini russi, cinesi e di alfabeti usati per obfuscare i messaggi di spam. 
* Messaggi che triggerano un determinato numero di parole chiave definite in un file .json

Il bot inizia cancellando solamente i messaggi per le prime due settimane, poi inizia a bannare, sempre che abbia i permessi per farlo. Gli utenti che riescono a raggiungere un determinato numero di messaggi vengono salvati in una whitelist sia come esenti dai controlli che "sicuri" da taggare in chat.
