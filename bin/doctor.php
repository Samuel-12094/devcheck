<?php
/**
 * DevEnv Doctor - rapport en ligne de commande.
 *
 *   php bin/doctor.php              rapport complet
 *   php bin/doctor.php --json       sortie JSON
 *   php bin/doctor.php --only=db    une categorie : web|db|fs|tools|net
 *   php bin/doctor.php --fail=1     code de sortie 1 si au moins un echec (CI)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script est reserve a la ligne de commande.\n");
}

require dirname(__DIR__) . '/src/autoload.php';

use DevCheck\Doctor;
use DevCheck\Report;
use DevCheck\Result;
use DevCheck\Support;

$opts = getopt('', ['json', 'only:', 'fail', 'no-color', 'help']);
if (isset($opts['help'])) {
    echo <<<TXT
    DevEnv Doctor - diagnostic de l'environnement de developpement

      --json        affiche le rapport au format JSON
      --only=cat    limite a une categorie (web, db, fs, tools, net)
      --fail        retourne le code 1 s'il y a au moins un echec
      --no-color    desactive les couleurs ANSI

    TXT;
    exit(0);
}

$color = !isset($opts['no-color']) && function_exists('posix_isatty')
    ? @posix_isatty(STDOUT)
    : !isset($opts['no-color']) && getenv('WT_SESSION') !== false;
$color = $color && !isset($opts['no-color']);

$c = static fn (string $s): string => $color ? $s : '';
$paint = static function (string $text, string $color) use ($c): string {
    return $c($color) . $text . $c("\033[0m");
};

$map = [
    Result::OK   => $paint(' OK  ', "\033[42;30m"),
    Result::WARN => $paint('WARN ', "\033[43;30m"),
    Result::KO   => $paint('FAIL ', "\033[41;97m"),
    Result::SKIP => $paint('SKIP ', "\033[100;97m"),
    Result::INFO => $paint('INFO ', "\033[44;97m"),
];

$only  = $opts['only'] ?? null;
$keys  = ['web' => 'Serveur & PHP', 'db' => 'Base de donnees', 'fs' => 'Fichiers & droits',
          'tools' => 'Outils CLI', 'net' => 'Reseau'];
$wantCat = is_string($only) && isset($keys[$only]) ? $keys[$only] : null;

$report = Doctor::fromProjectRoot()->run();

if (isset($opts['json'])) {
    echo json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(isset($opts['fail']) && $report->failures() !== [] ? 1 : 0);
}

$verdictColor = $report->summary()['ko'] > 0 ? "\033[41;97m"
    : ($report->summary()['warn'] > 0 ? "\033[43;30m" : "\033[42;30m");

$line = str_repeat('=', 74);
echo PHP_EOL, $c($verdictColor), ' DevEnv Doctor ', $c("\033[0m"), PHP_EOL;
echo $c($line), PHP_EOL;

// Categorie affichee => cle de suite (pour recuperer son temps d'execution).
$catKey = array_combine(
    ['Serveur & PHP', 'Base de donnees', 'Fichiers & droits', 'Outils CLI', 'Reseau'],
    ['web', 'db', 'fs', 'tools', 'net']
);
$timings = $report->timings();

foreach ($report->byCategory() as $cat => $items) {
    if ($wantCat !== null && $cat !== $wantCat) {
        continue;
    }
    $tk = $catKey[$cat] ?? null;
    $ms = ($tk !== null && isset($timings[$tk])) ? $timings[$tk] : null;
    echo PHP_EOL, $c("\033[1m"), $cat, $c("\033[0m"),
         $ms !== null ? $c("\033[90m") . '  (' . number_format($ms, 0, ',', ' ') . ' ms)' . $c("\033[0m") : '',
         PHP_EOL;

    foreach ($items as $r) {
        $label = str_pad($r->label, 34, '.');
        echo '  ', $map[$r->status] ?? '  ?   ', ' ', $label, ' ',
             $c("\033[36m"), $r->value, $c("\033[0m"), PHP_EOL;

        if ($r->detail !== '') {
            echo '        ', $c("\033[90m"), wordwrap($r->detail, 64, PHP_EOL . '        ', false), $c("\033[0m"), PHP_EOL;
        }
        if ($r->fix !== null) {
            echo '        ', $c("\033[33m"), '-> ', wordwrap($r->fix, 60, PHP_EOL . '           ', false), $c("\033[0m"), PHP_EOL;
        }
        if (!empty($r->meta['steps']) && $r->isFailure()) {
            echo '        ', $c("\033[90m"), json_encode($r->meta['steps'], JSON_UNESCAPED_UNICODE), $c("\033[0m"), PHP_EOL;
        }
    }
}

$s = $report->summary();
echo PHP_EOL, $c($line), PHP_EOL;
printf("  Sante %d/100   |   %d OK  %d warn  %d echec  %d ignore  |  %.0f ms%s",
    $report->score(), $s[Result::OK], $s[Result::WARN], $s[Result::KO], $s[Result::SKIP], $report->durationMs(), PHP_EOL);
echo PHP_EOL;

if ($report->failures() !== []) {
    echo PHP_EOL, $c("\033[1;41;97m"), ' A CORRIGER ', $c("\033[0m"), PHP_EOL;
    foreach ($report->failures() as $i => $f) {
        echo '  ', $i + 1, '. ', $f->label, PHP_EOL;
        if ($f->fix !== null) {
            echo '     ', $c("\033[33m"), wordwrap($f->fix, 66, PHP_EOL . '     ', false), $c("\033[0m"), PHP_EOL;
        }
    }
    echo PHP_EOL;
}

exit(isset($opts['fail']) && $report->failures() !== [] ? 1 : 0);
