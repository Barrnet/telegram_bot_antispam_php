<?php 
define('MAIN_SCRIPT', true);
include_once("config.php");
include_once("funzioni.php");

$risultati  = [];
$testo      = $_POST['testo'] ?? '';
$array_filtro = json_decode(file_get_contents("./bot_data/filtro_spam.json"), true) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $testo !== '') {
    $conteggio_caratteri  = strlen($testo);
    $moltiplicatore       = max(1, $conteggio_caratteri / FILTER_TRIGGER_MULTIPLIER);

    foreach ($array_filtro as $nome_filtro => $filtro) {
        $parole           = explode(",", $filtro["array_trigger"]);
        $soglia_base      = $filtro["conteggio_trigger"];
        $soglia_effettiva = $soglia_base * $moltiplicatore;

        // Trova le parole triggerate per l'evidenziazione — logica locale alla UI
        $testo_up   = strtoupper($testo);
        $triggerate = [];
        foreach ($parole as $parola) {
            $parola  = trim(strtoupper($parola));
            if ($parola === "") continue;
            $pattern = '/(?<!\w)' . preg_quote($parola, '/') . '(?!\w)/u';
            if (preg_match($pattern, $testo_up)) {
                $triggerate[] = trim($parola);
            }
        }

        // Conteggio ufficiale via funzione condivisa con il bot
        $conteggio = contaCorrispondenze($testo, $parole);

        $risultati[$nome_filtro] = [
            'triggerate'       => $triggerate,
            'conteggio'        => $conteggio,
            'soglia_base'      => $soglia_base,
            'soglia_effettiva' => $soglia_effettiva,
            'scattato'         => $conteggio >= $soglia_effettiva,
        ];
    }

    // Evidenzia le parole nel testo
    $testo_evidenziato = htmlspecialchars($testo);
    $tutte_le_parole = [];
    foreach ($risultati as $r) {
        foreach ($r['triggerate'] as $p) {
            $tutte_le_parole[] = $p;
        }
    }
    $tutte_le_parole = array_unique($tutte_le_parole);
    usort($tutte_le_parole, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($tutte_le_parole as $parola) {
        $testo_evidenziato = preg_replace(
            '/(' . preg_quote(htmlspecialchars($parola), '/') . ')/iu',
            '<mark>$1</mark>',
            $testo_evidenziato
        );
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test Filtro Spam</title>
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
        margin-bottom: 2rem;
        border-bottom: 1px solid #222;
        padding-bottom: 1rem;
    }

    h1 span { color: #e0e0e0; }

    .layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        max-width: 1200px;
    }

    @media (max-width: 800px) {
        .layout { grid-template-columns: 1fr; }
    }

    label {
        display: block;
        font-size: 0.7rem;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: #555;
        margin-bottom: 0.5rem;
    }

    textarea {
        width: 100%;
        height: 180px;
        background: #161616;
        border: 1px solid #2a2a2a;
        color: #e0e0e0;
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        padding: 1rem;
        resize: vertical;
        outline: none;
        transition: border-color 0.2s;
    }

    textarea:focus { border-color: #444; }

    button {
        margin-top: 1rem;
        background: #e0e0e0;
        color: #0f0f0f;
        border: none;
        padding: 0.6rem 1.8rem;
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        cursor: pointer;
        transition: background 0.2s;
    }

    button:hover { background: #fff; }

    .testo-box {
        background: #161616;
        border: 1px solid #2a2a2a;
        padding: 1rem;
        font-size: 0.9rem;
        line-height: 1.6;
        min-height: 180px;
        word-break: break-word;
    }

    mark {
        background: #ff4444;
        color: #fff;
        padding: 0 2px;
        border-radius: 2px;
    }

    .meta {
        margin-top: 1rem;
        font-size: 0.75rem;
        color: #555;
        display: flex;
        gap: 2rem;
    }

    .meta span { color: #888; }

    .filtri { margin-top: 2rem; display: flex; flex-direction: column; gap: 0.8rem; }

    .filtro-card {
        border: 1px solid #2a2a2a;
        padding: 0.8rem 1rem;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }

    .filtro-card.scattato { border-color: #ff4444; }
    .filtro-card.warning  { border-color: #ff8800; }

    .stato {
        font-size: 0.7rem;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        white-space: nowrap;
        padding-top: 2px;
        min-width: 60px;
    }

    .stato.ok     { color: #444; }
    .stato.warn   { color: #ff8800; }
    .stato.banned { color: #ff4444; }

    .filtro-nome {
        font-size: 0.85rem;
        color: #aaa;
        flex: 1;
    }

    .filtro-nome strong { color: #e0e0e0; display: block; margin-bottom: 0.2rem; }

    .badge-parole {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        margin-top: 0.4rem;
    }

    .badge.hit { background: #3a1a1a; color: #ff6666; font-size: 0.7rem; padding: 2px 6px; border-radius: 2px; }

    .contatore {
        font-size: 0.75rem;
        color: #555;
        white-space: nowrap;
        padding-top: 2px;
        text-align: right;
        line-height: 1.8;
    }

    .contatore .soglia-effettiva { color: #aaa; }
    .contatore .soglia-base      { color: #444; font-size: 0.65rem; }
</style>
</head>
<body>

<h1>// <span>test filtro spam</span></h1>

<form method="POST">
<div class="layout">
    <div>
        <label>Testo da analizzare</label>
        <textarea name="testo"><?= htmlspecialchars($testo) ?></textarea>
        <button type="submit">Analizza</button>
    </div>

    <div>
        <label>Parole triggerate (evidenziate in rosso)</label>
        <div class="testo-box">
            <?php if ($testo !== ''): ?>
                <?= $testo_evidenziato ?>
            <?php else: ?>
                <span style="color:#333">Il testo apparirà qui...</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($risultati)): ?>
<div class="filtri">
    <label style="margin-top:1rem">Risultati per filtro</label>

    <div class="meta">
        Caratteri: <span><?= $conteggio_caratteri ?></span>
        Moltiplicatore soglia: <span><?= number_format($moltiplicatore, 2) ?>x</span>
        (1x ogni <?= FILTER_TRIGGER_MULTIPLIER ?> caratteri)
    </div>

    <?php foreach ($risultati as $nome => $r):
        $classe      = $r['scattato'] ? 'scattato' : ($r['conteggio'] > 0 ? 'warning' : '');
        $stato_label = $r['scattato'] ? 'banned'   : ($r['conteggio'] > 0 ? 'warn'    : 'ok');
        $stato_testo = $r['scattato'] ? 'SCATTATO' : ($r['conteggio'] > 0 ? 'PARZIALE': 'ok');
    ?>
    <div class="filtro-card <?= $classe ?>">
        <div class="stato <?= $stato_label ?>"><?= $stato_testo ?></div>
        <div class="filtro-nome">
            <strong><?= htmlspecialchars($nome) ?></strong>
            <?php if (!empty($r['triggerate'])): ?>
            <div class="badge-parole">
                <?php foreach ($r['triggerate'] as $p): ?>
                <span class="badge hit"><?= htmlspecialchars($p) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="contatore">
            <div class="soglia-effettiva"><?= $r['conteggio'] ?> / <?= ceil($r['soglia_effettiva']) ?></div>
            <div class="soglia-base">soglia base: <?= $r['soglia_base'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</form>
</body>
</html>