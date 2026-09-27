<?php
/**
 * DevCheck - tableau de bord web.
 *
 * Lancement rapide sans Apache :   php -S localhost:8080 -t C:\xampp\htdocs\devcheck
 * Puis ouvrir http://localhost:8080
 */

declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use DevCheck\Doctor;
use DevCheck\Report;
use DevCheck\Result;
use DevCheck\Support;

// Tampon de sortie : garantit un JSON valide meme si une extension PHP affiche
// un warning avant le premier echo (display_errors est souvent a On en local).
ob_start();

$config = require __DIR__ . '/config.php';
$app    = $config['app'];

/** Export JSON brut (utile pour CI ou scripts). */
if (($_GET['export'] ?? '') === 'json') {
    $report = Doctor::fromProjectRoot()->run();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: inline; filename="devcheck.json"');
    echo json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$report = Doctor::fromProjectRoot()->run();
$summary = $report->summary();
$score = $report->score();
$byCat = $report->byCategory();
$timings = $report->timings();

// Categorie affichee => cle de suite (pour recuperer son temps d'execution).
$catKey = array_combine(
    ['Serveur & PHP', 'Base de donnees', 'Fichiers & droits', 'Outils CLI', 'Reseau'],
    ['web', 'db', 'fs', 'tools', 'net']
);

$statusLabel = [
    Result::OK   => 'OK',
    Result::WARN => 'Avertissement',
    Result::KO   => 'Echec',
    Result::SKIP => 'Ignore',
    Result::INFO => 'Info',
];

$verdict = $summary['ko'] > 0
    ? ['KO',  $summary['ko'] . ' test(s) en echec : votre stack ne compiles pas encore comme attendu.']
    : ($summary['warn'] > 0
        ? ['WARN', 'Aucun blocage, mais ' . $summary['warn'] . ' point(s) a corriger avant de commencer.']
        : ['OK', 'Installation fonctionnelle sur tous les points testes.']);

?><!DOCTYPE html>
<html lang="fr" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🩺</text></svg>">
<title><?= Support::escape($app['name']) ?> — diagnostic d'environnement</title>
<style>
  :root {
    --bg:#0f1216; --panel:#171b21; --panel2:#1e232b; --line:#2a313b;
    --fg:#e6e9ee; --muted:#98a2b0; --accent:#4c9aff;
    --ok:#3fb950; --warn:#d29922; --ko:#f85149; --skip:#7d8590; --info:#58a6ff;
  }
  @media (prefers-color-scheme: light) {
    :root:not(.dark) {
      --bg:#f4f6f9; --panel:#fff; --panel2:#f0f3f7; --line:#dde3ea;
      --fg:#1b1f24; --muted:#5c6773; --accent:#0a58ca;
    }
  }
  * { box-sizing:border-box }
  body { margin:0; background:var(--bg); color:var(--fg);
         font:14px/1.55 ui-sans-serif,system-ui,"Segoe UI",Roboto,sans-serif;
         transition:background .25s ease,color .25s ease; }
  code,kbd { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:.92em; }
  a { color:var(--accent) }
  .wrap { max-width:1080px; margin:0 auto; padding:28px 20px 72px }
  header { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start; justify-content:space-between }
  h1 { margin:0; font-size:22px; letter-spacing:-.01em }
  .sub { color:var(--muted); margin-top:4px }
  .hero { background:var(--panel); border:1px solid var(--line); border-radius:14px;
          padding:22px; margin:22px 0; display:flex; gap:26px; flex-wrap:wrap; align-items:center }
  .score-ring { position:relative; width:120px; height:120px; flex-shrink:0 }
  .score-ring svg { transform:rotate(-90deg); width:100%; height:100% }
  .score-ring .track { fill:none; stroke:var(--line); stroke-width:8 }
  .score-ring .bar { fill:none; stroke-width:8; stroke-linecap:round;
                     transition:stroke-dashoffset 1s ease, stroke .3s ease }
  .score-label { position:absolute; inset:0; display:flex; flex-direction:column;
                 align-items:center; justify-content:center }
  .score-label .num { font-size:32px; font-weight:700; line-height:1 }
  .score-label .cap { font-size:10px; font-weight:600; letter-spacing:.08em;
                      text-transform:uppercase; color:var(--muted); margin-top:4px }
  .counts { display:flex; gap:8px; flex-wrap:wrap }
  .chip { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--line);
          background:var(--panel2); border-radius:999px; padding:5px 12px; font-size:12.5px }
  .dot { width:8px; height:8px; border-radius:50%; display:inline-block }
  .verdict { flex:1 1 320px; min-width:260px; border-left:3px solid var(--line); padding-left:16px }
  .verdict b { display:block; margin-bottom:2px }
  .verdict[data-s="KO"] { border-color:var(--ko) } .verdict[data-s="WARN"] { border-color:var(--warn) }
  .verdict[data-s="OK"] { border-color:var(--ok) }
  .toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:18px 0 }
  .toolbar input[type=search] { flex:1 1 220px; min-width:180px; background:var(--panel); color:var(--fg);
      border:1px solid var(--line); border-radius:9px; padding:9px 12px; font:inherit }
  button, .btn { background:var(--panel2); color:var(--fg); border:1px solid var(--line);
      border-radius:9px; padding:8px 13px; font:inherit; cursor:pointer; text-decoration:none }
  button:hover, .btn:hover { border-color:var(--accent); transform:translateY(-1px);
      box-shadow:0 2px 8px rgba(0,0,0,.2); }
  button.on { background:var(--accent); border-color:var(--accent); color:#fff }
  button:focus-visible, .btn:focus-visible, input:focus-visible { outline:2px solid var(--accent); outline-offset:2px }
  section { margin:26px 0 }
  section > h2 { font-size:13px; text-transform:uppercase; letter-spacing:.09em;
                 color:var(--muted); margin:0 0 10px }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:11px;
          margin-bottom:8px; overflow:hidden; transition:border-color .2s ease, box-shadow .2s ease; }
  .card:hover { border-color:var(--accent); box-shadow:0 2px 12px rgba(0,0,0,.15); }
  .row { display:grid; grid-template-columns:76px 1fr; gap:14px; padding:13px 15px; align-items:start }
  .row + .row { border-top:1px solid var(--line) }
  .badge { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
           padding:4px 7px; border-radius:5px; text-align:center; margin-top:1px }
  .badge[data-s="ok"]   { background:color-mix(in srgb,var(--ok) 18%,transparent);   color:var(--ok) }
  .badge[data-s="warn"] { background:color-mix(in srgb,var(--warn) 20%,transparent); color:var(--warn) }
  .badge[data-s="ko"]   { background:color-mix(in srgb,var(--ko) 18%,transparent);   color:var(--ko) }
  .badge[data-s="skip"] { background:color-mix(in srgb,var(--skip) 20%,transparent); color:var(--skip) }
  .badge[data-s="info"] { background:color-mix(in srgb,var(--info) 18%,transparent); color:var(--info) }
  .label { font-weight:600 }
  .value { color:var(--accent); font-family:ui-monospace,Consolas,monospace; font-size:12.5px;
           margin-left:8px; font-weight:500 }
  .detail { color:var(--muted); margin-top:3px }
  .fix { margin-top:9px; padding:9px 12px; border-left:3px solid var(--warn);
         background:var(--panel2); border-radius:0 7px 7px 0; font-size:13px;
         overflow-wrap:break-word; word-break:break-word; overflow:hidden; }
  .fix b { color:var(--warn) }
  .detail { overflow-wrap:break-word; word-break:break-word; }
  .value { overflow-wrap:anywhere; word-break:break-word; }
  details.steps { margin-top:9px }
  details.steps summary { cursor:pointer; color:var(--muted); font-size:12.5px; user-select:none }
  details.steps pre { background:var(--panel2); border:1px solid var(--line); border-radius:8px;
        padding:11px; overflow:auto; font-size:12px; margin:8px 0 0; max-height:260px }
  .hide { display:none !important }
  footer { margin-top:36px; color:var(--muted); font-size:12.5px; border-top:1px solid var(--line);
           padding-top:16px }
  .spin { display:inline-block; animation:sp 1s linear infinite }
  @keyframes sp { to { transform:rotate(360deg) } }

  /* Barre de progression du chargement */
  .progress-bar { position:fixed; top:0; left:0; height:3px; background:var(--accent);
                  z-index:999; transition:width .3s ease; opacity:0; }

  /* Mode contraste élevé */
  @media (prefers-contrast: high) {
    :root { --line:#666; --muted:#ccc; }
    .card:hover, button:hover { outline:2px solid var(--fg); }
  }

  /* Réduction des animations */
  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { transition:none !important; animation:none !important; }
  }

  /* Bascule theme — MUST be last to win the cascade */
  html[class].dark {
    --bg:#0f1216 !important; --panel:#171b21 !important; --panel2:#1e232b !important;
    --line:#2a313b !important; --fg:#e6e9ee !important; --muted:#98a2b0 !important;
    --accent:#4c9aff !important; --ok:#3fb950 !important; --warn:#d29922 !important;
    --ko:#f85149 !important; --skip:#7d8590 !important; --info:#58a6ff !important;
  }
  html[class].light {
    --bg:#f4f6f9 !important; --panel:#fff !important; --panel2:#f0f3f7 !important;
    --line:#dde3ea !important; --fg:#1b1f24 !important; --muted:#5c6773 !important;
    --accent:#0a58ca !important; --ok:#3fb950 !important; --warn:#d29922 !important;
    --ko:#f85149 !important; --skip:#7d8590 !important; --info:#58a6ff !important;
  }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1><?= Support::escape($app['name']) ?> <span class="sub" style="font-weight:400">v<?= Support::escape($app['version']) ?></span></h1>
      <div class="sub">
        Diagnostic de la chaine de developpement &middot; execute en <?= number_format($report->durationMs(), 0, ',', ' ') ?> ms
        &middot; <?= Support::escape(Support::phpServerLabel()) ?>
      </div>    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button id="themeBtn" onclick="toggleTheme()" title="Basculer clair/sombre">🌓 Theme</button>
      <a class="btn" href="?export=json">Exporter JSON</a>
      <button onclick="location.reload()">Relancer</button>
    </div>
  </header>

  <div class="hero">
    <div class="score-ring" data-score="<?= $score ?>">
      <svg viewBox="0 0 120 120">
        <circle class="track" cx="60" cy="60" r="52"/>
        <circle class="bar" cx="60" cy="60" r="52" id="scoreBar"/>
      </svg>
      <div class="score-label">
        <span class="num" style="color:var(--<?= $verdict[0] === 'KO' ? 'ko' : ($verdict[0] === 'WARN' ? 'warn' : 'ok') ?>)"><?= $score ?></span>
        <span class="cap">/ 100 sante</span>
      </div>
    </div>
    <div class="counts">
      <?php foreach ([Result::OK, Result::WARN, Result::KO, Result::SKIP, Result::INFO] as $st): ?>
        <span class="chip" data-status="<?= $st ?>">
          <span class="dot" style="background:var(--<?= $st ?>)"></span>
          <?= $summary[$st] ?> <?= Support::escape($statusLabel[$st]) ?>
        </span>
      <?php endforeach; ?>
    </div>
    <div class="verdict" data-s="<?= $verdict[0] ?>">
      <b>Verdict</b><?= Support::escape($verdict[1]) ?>
    </div>
  </div>

  <div class="toolbar">
    <input type="search" id="q" placeholder="Filtrer (ex: mysql, extension, node, apache)…" autocomplete="off">
    <button data-f="all" class="on">Tout</button>
    <button data-f="ko">Echecs</button>
    <button data-f="warn">Avertissements</button>
    <label class="chip" style="cursor:pointer"><input type="checkbox" id="hideok"> Masquer les OK</label>
  </div>

<?php foreach ($byCat as $cat => $items): ?>
  <section data-cat="<?= Support::escape($cat) ?>">
    <h2><?= Support::escape($cat) ?> &mdash; <?= count($items) ?> test(s)
      <?php $tk = $catKey[$cat] ?? null; if ($tk !== null && isset($timings[$tk])): ?>
        &middot; <?= number_format($timings[$tk], 0, ',', ' ') ?> ms
      <?php endif; ?>
    </h2>
    <?php foreach ($items as $r): ?>
      <div class="card" data-status="<?= $r->status ?>"
           data-text="<?= Support::escape(strtolower($r->label . ' ' . $r->value . ' ' . $r->detail)) ?>">
        <div class="row">
          <span class="badge" data-s="<?= $r->status ?>"><?= Support::escape($statusLabel[$r->status]) ?></span>
          <div>
            <span class="label"><?= Support::escape($r->label) ?></span>
            <?php if ($r->value !== ''): ?><span class="value"><?= Support::escape($r->value) ?></span><?php endif; ?>
            <?php if ($r->detail !== ''): ?><div class="detail"><?= Support::escape($r->detail) ?></div><?php endif; ?>
            <?php if ($r->fix !== null): ?>
              <div class="fix"><b>Correctif :</b> <?= Support::escape($r->fix) ?></div>
            <?php endif; ?>
            <?php if (!empty($r->meta['steps'])): ?>
              <details class="steps">
                <summary>Detail des etapes</summary>
                <pre><?= Support::escape(json_encode($r->meta['steps'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
              </details>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

  <footer>
    <p>
      <strong>Comment lire ce rapport</strong> &mdash; <b style="color:var(--ko)">Echec</b> : vous ne pourrez pas
      developper correctement tant que ce n'est pas corrige. <b style="color:var(--warn)">Avertissement</b> :
        facultatif mais fortement recommande. <b>Ignore</b> : test non applicable a votre configuration.
        <b>Info</b> : donnee de contexte.
    </p>
    <p>
      Le test base de donnees cree et supprime une base <code><?= Support::escape($config['db']['database']) ?></code>
      et une table temporaire. Rien d'existant n'est touche.
      Identifiants configurables dans <code>config.php</code> ou via les variables
      <code>DEVCHECK_DB_HOST</code>, <code>DEVCHECK_DB_USER</code>, <code>DEVCHECK_DB_PASS</code>.
    </p>
  </footer>
</div>

<div class="progress-bar" id="progressBar"></div>
<script>
(function () {
  var q = document.getElementById('q');
  var hideOk = document.getElementById('hideok');
  var filter = 'all';

  function apply() {
    var needle = q.value.trim().toLowerCase();
    document.querySelectorAll('.card').forEach(function (card) {
      var st = card.dataset.status;
      var okFilter = filter === 'all' || st === filter;
      var okText = !needle || card.dataset.text.indexOf(needle) !== -1;
      var okHide = !(hideOk.checked && st === 'ok');
      card.classList.toggle('hide', !(okFilter && okText && okHide));
    });
    document.querySelectorAll('section').forEach(function (sec) {
      var visible = sec.querySelectorAll('.card:not(.hide)').length;
      sec.classList.toggle('hide', visible === 0);
    });
  }

  q.addEventListener('input', apply);
  hideOk.addEventListener('change', apply);
  document.querySelectorAll('button[data-f]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('button[data-f]').forEach(function (o) { o.classList.remove('on'); });
      b.classList.add('on');
      filter = b.dataset.f;
      apply();
    });
  });
  apply();

  /* Anneau de score anime */
  var scoreBar = document.getElementById('scoreBar');
  if (scoreBar) {
    var score = parseInt(document.querySelector('.score-ring').dataset.score, 10);
    var circumference = 2 * Math.PI * 52;
    scoreBar.style.strokeDasharray = circumference;
    scoreBar.style.strokeDashoffset = circumference;
    var color = score >= 90 ? 'var(--ok)' : score >= 70 ? 'var(--warn)' : 'var(--ko)';
    scoreBar.style.stroke = color;
    setTimeout(function () {
      scoreBar.style.strokeDashoffset = circumference * (1 - score / 100);
    }, 100);
  }

  /* Barre de progression simulee */
  var progressBar = document.getElementById('progressBar');
  if (progressBar) {
    progressBar.style.opacity = '1';
    progressBar.style.width = '30%';
    setTimeout(function () { progressBar.style.width = '70%'; }, 500);
    setTimeout(function () { progressBar.style.width = '100%'; }, 1200);
    setTimeout(function () { progressBar.style.opacity = '0'; progressBar.style.width = '0'; }, 1800);
  }
})();

/* Bascule theme clair/sombre */
function toggleTheme() {
  var root = document.documentElement;
  root.classList.toggle('light');
  root.classList.toggle('dark');
}
</body>
</html>
