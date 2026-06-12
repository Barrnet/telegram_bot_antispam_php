<?php
define('MAIN_SCRIPT', true);
include_once("config.php");
include_once("funzioni.php");

// ==============================
// CONFIGURAZIONE
// ==============================
$json_file    = "./result.json"; // file esportato da Telegram (rinominalo così)
$filtro_file  = "./bot_data/filtro_spam.json";
$output_html  = true; // true = pagina HTML, false = output testo nel terminale

// ==============================
// CARICAMENTO
// ==============================
if (!file_exists($json_file)) {
    die("File $json_file non trovato. Esporta la chat da Telegram e rinomina il file result.json in questa cartella.\n");
}
if (!file_exists($filtro_file)) {
    die("File $filtro_file non trovato.\n");
}

$chat     = json_decode(file_get_contents($json_file), true);
$filtri   = json_decode(file_get_contents($filtro_file), true);

if (!$chat || !isset($chat['messages'])) {
    die("Formato JSON non valido o file corrotto.\n");
}
if (!$filtri) {
    die("Filtro spam non valido.\n");
}

// ==============================
// ANALISI
// ==============================
$risultati   = [];
$totale_msg  = 0;

foreach ($chat['messages'] as $msg) {
    // Estrai testo (testo normale o caption foto/video)
    if (isset($msg['text'])) {
        if (is_array($msg['text'])) {
            // Telegram a volte mette il testo come array di segmenti
            $testo = implode('', array_map(fn($s) => is_string($s) ? $s : ($s['text'] ?? ''), $msg['text']));
        } else {
            $testo = $msg['text'];
        }
    } else {
        continue;
    }

    if (empty(trim($testo))) continue;
    $totale_msg++;

    $conteggio_caratteri  = strlen($testo);
    $moltiplicatore       = max(1, $conteggio_caratteri / FILTER_TRIGGER_MULTIPLIER);
    $filtri_scattati      = [];

    foreach ($filtri as $nome_filtro => $filtro) {
        $parole           = explode(",", $filtro["array_trigger"]);
        $soglia_base      = $filtro["conteggio_trigger"];
        $soglia_effettiva = $soglia_base * $moltiplicatore;
        $conteggio        = contaCorrispondenze($testo, $parole);

        if ($conteggio >= $soglia_effettiva) {
            // Trova le parole triggerate per il report
            $triggerate = [];
            $testo_up   = strtoupper($testo);
            foreach ($parole as $parola) {
                $parola  = trim(strtoupper($parola));
                if ($parola === "") continue;
                $pattern = '/(?<!\w)' . preg_quote($parola, '/') . '(?!\w)/u';
                if (preg_match($pattern, $testo_up)) {
                    $triggerate[] = trim($parola);
                }
            }
            $filtri_scattati[$nome_filtro] = [
                'conteggio'        => $conteggio,
                'soglia_effettiva' => $soglia_effettiva,
                'soglia_base'      => $soglia_base,
                'triggerate'       => $triggerate,
            ];
        }
    }

    if (!empty($filtri_scattati)) {
        $risultati[] = [
            'id'             => $msg['id'] ?? '?',
            'data'           => $msg['date'] ?? '',
            'mittente'       => $msg['from'] ?? 'sconosciuto',
            'testo'          => $testo,
            'caratteri'      => $conteggio_caratteri,
            'moltiplicatore' => $moltiplicatore,
            'filtri'         => $filtri_scattati,
        ];
    }
}

$totale_flag = count($risultati);

// ==============================
// OUTPUT HTML
// ==============================
if (!$output_html) {
    // Output testo semplice per CLI
    echo "Messaggi analizzati: $totale_msg\n";
    echo "Messaggi che avrebbero triggerato: $totale_flag\n\n";
    foreach ($risultati as $r) {
        echo "---\n";
        echo "ID: {$r['id']} | Data: {$r['data']} | Da: {$r['mittente']}\n";
        echo "Testo: " . mb_substr($r['testo'], 0, 100) . (mb_strlen($r['testo']) > 100 ? '...' : '') . "\n";
        foreach ($r['filtri'] as $nome => $f) {
            echo "  Filtro: $nome ({$f['conteggio']} / " . number_format($f['soglia_effettiva'], 2) . ")\n";
            echo "  Trigger: " . implode(', ', $f['triggerate']) . "\n";
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test Cronologia</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        background: #0f0f0f;
        color: #e0e0e0;
        font-family: 'Courier New', monospace;
        min-height: 100vh;
        padding: 2rem;
    }

    h1 {
        font-size: 1.1rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: #555;
        margin-bottom: 1.5rem;
        border-bottom: 1px solid #222;
        padding-bottom: 1rem;
    }

    h1 span { color: #e0e0e0; }

    .sommario {
        display: flex;
        gap: 3rem;
        margin-bottom: 2rem;
        font-size: 0.8rem;
        color: #555;
    }

    .sommario .num { font-size: 1.8rem; color: #e0e0e0; display: block; }
    .sommario .num.red { color: #ff4444; }

    .msg-card {
        border: 1px solid #2a2a2a;
        margin-bottom: 1rem;
        padding: 1rem;
    }

    .msg-header {
        display: flex;
        gap: 1.5rem;
        font-size: 0.7rem;
        color: #555;
        margin-bottom: 0.8rem;
        flex-wrap: wrap;
    }

    .msg-header span { color: #888; }

    .msg-testo {
        font-size: 0.85rem;
        line-height: 1.6;
        color: #bbb;
        margin-bottom: 0.8rem;
        word-break: break-word;
        border-left: 2px solid #222;
        padding-left: 0.8rem;
    }

    mark {
        background: #ff4444;
        color: #fff;
        padding: 0 2px;
        border-radius: 2px;
    }

    .filtro-riga {
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-top: 0.4rem;
        flex-wrap: wrap;
    }

    .filtro-nome { color: #ff6666; min-width: 200px; }
    .filtro-score { color: #555; white-space: nowrap; }
    .filtro-score span { color: #aaa; }

    .badge {
        background: #3a1a1a;
        color: #ff8888;
        font-size: 0.65rem;
        padding: 2px 6px;
        border-radius: 2px;
    }

    .badge-parole {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
    }

    .nessun-risultato {
        color: #444;
        font-size: 0.85rem;
        padding: 2rem 0;
    }
</style>
</head>
<body>

<h1>// <span>test cronologia</span></h1>

<div class="sommario">
    <div>
        <span class="num"><?= number_format($totale_msg) ?></span>
        messaggi analizzati
    </div>
    <div>
        <span class="num <?= $totale_flag > 0 ? 'red' : '' ?>"><?= $totale_flag ?></span>
        avrebbero triggerato
    </div>
    <div>
        <span class="num"><?= $totale_msg > 0 ? number_format($totale_flag / $totale_msg * 100, 2) : 0 ?>%</span>
        tasso di intercettazione
    </div>
</div>

<?php if (empty($risultati)): ?>
    <div class="nessun-risultato">Nessun messaggio avrebbe triggerato i filtri.</div>
<?php else: ?>
    <?php foreach ($risultati as $r):
        // Evidenzia tutte le parole triggerate nel testo
        $testo_ev = htmlspecialchars($r['testo']);
        $tutte = [];
        foreach ($r['filtri'] as $f) {
            foreach ($f['triggerate'] as $p) $tutte[] = $p;
        }
        $tutte = array_unique($tutte);
        usort($tutte, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($tutte as $parola) {
            $testo_ev = preg_replace(
                '/(?<!\w)(' . preg_quote(htmlspecialchars($parola), '/') . ')(?!\w)/iu',
                '<mark>$1</mark>',
                $testo_ev
            );
        }
    ?>
    <div class="msg-card">
        <div class="msg-header">
            ID: <span><?= htmlspecialchars((string)$r['id']) ?></span>
            Data: <span><?= htmlspecialchars($r['data']) ?></span>
            Da: <span><?= htmlspecialchars($r['mittente']) ?></span>
            Caratteri: <span><?= $r['caratteri'] ?></span>
            Moltiplicatore: <span><?= number_format($r['moltiplicatore'], 2) ?>x</span>
        </div>
        <div class="msg-testo"><?= $testo_ev ?></div>
        <?php foreach ($r['filtri'] as $nome => $f): ?>
        <div class="filtro-riga">
            <div class="filtro-nome"><?= htmlspecialchars($nome) ?></div>
            <div class="filtro-score"><?= $f['conteggio'] ?> / <span><?= ceil($f['soglia_effettiva']) ?></span> <span style="color:#333">(base <?= $f['soglia_base'] ?>)</span></div>
            <div class="badge-parole">
                <?php foreach ($f['triggerate'] as $p): ?>
                <span class="badge"><?= htmlspecialchars($p) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>