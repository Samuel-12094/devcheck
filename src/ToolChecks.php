<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Tests "Outils CLI" : ce que vous utilisez en developpement hors navigateur.
 */
final class ToolChecks
{
    private const CAT = 'Outils CLI';

    /** Outil => [commande de version, chemin attendu, critique ?] */
    private const TOOLS = [
        'node'     => ['node -v',            'Node.js (npm, Vite, Next...)', true],
        'npm'      => ['npm -v',             'Gestionnaire de paquets Node', true],
        'git'      => ['git --version',      'Gestion de versions', true],
        'composer' => ['composer -V',        'Dependances PHP', false],
        'php'      => ['php -v',             'CLI PHP', true],
        'code'     => ['code --version',     'VS Code (editeur)', false],
        'python'   => ['python --version',   'Scripting / tooling', false],
        'mysql'    => ['mysql --version',    'Client MySQL en ligne de commande', false],
    ];

    /** @param array<string,mixed> $config */
    public static function run(array $config): array
    {
        $out = [self::path()];

        foreach (self::TOOLS as $tool => [$probe, $why, $critical]) {
            $out[] = self::tool((string) $tool, $probe, $why, (bool) $critical);
        }

        $out[] = self::composerPlatform();
        $out[] = self::xamppTools($config);

        return $out;
    }

    private static function path(): Result
    {
        $dirs = Support::pathDirs();
        $missing = 0;
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                $missing++;
            }
        }

        $unique = count(array_unique($dirs));

        if ($missing > 3) {
            return new Result(self::CAT, 'Variable PATH', Result::WARN,
                $unique . ' entrees',
                $missing . ' entrees du PATH pointent vers des dossiers inexistants '
                . '(logiciels desinstalles).',
                'Nettoyez les variables d\'environnement : ce sont des residus qui ralentissent le shell.');
        }

        return new Result(self::CAT, 'Variable PATH', Result::OK, $unique . ' entrees',
            'Toutes les entrees existent.');
    }

    private static function tool(string $tool, string $probe, string $why, bool $critical): Result
    {
        $exe = Support::findExecutable($tool);

        if ($exe === null) {
            // Windows 11 installe un alias vide (App Execution Alias) qui occupe le nom
            // sans aucune installation derriere : tres frequent pour python et node.
            $alias = Support::pathHasEntry($tool);

            if ($alias !== null) {
                return new Result(self::CAT, $why, Result::WARN, 'alias vide',
                    'Un alias Microsoft Store occupe le nom dans le PATH (' . $alias . ') '
                    . 'mais aucune installation n\'est derriere. La commande ouvre le Store au lieu de s\'executer.',
                    'Desinstallez Python / Node puis reinstallez depuis python.org ou nodejs.org : '
                    . 'pensez a decocher "Add to PATH" pendant l\'installation.');
            }

            return new Result(self::CAT, $why, $critical ? Result::KO : Result::WARN, 'absent',
                'Executable introuvable dans le PATH. Sans cet outil : ' . strtolower($why) . ' non utilisable.',
                $critical
                    ? 'Installez ' . $tool . ' puis fermez et rouvrez le terminal pour recharger le PATH.'
                    : 'Optionnel : installez ' . $tool . ' si vous en avez besoin.');
        }

        $out = Support::runCommand($probe . ' 2>&1', 6);
        $version = $out !== null && $out !== '' ? $out : 'version non lisible';

        $inPath = Support::inPath($exe);

        return new Result(self::CAT, $why, Result::OK, $version,
            'Chemin : ' . $exe . ($inPath ? '' : ' (hors PATH, execution directe uniquement)'),
            null,
            ['path' => $exe, 'in_path' => $inPath]);
    }

    private static function composerPlatform(): Result
    {
        $exe = Support::findExecutable('composer');
        if ($exe === null) {
            return new Result(self::CAT, 'Composer / PHP', Result::SKIP, 'absent',
                'Ignore : Composer n\'est pas installe.');
        }

        $out = Support::runCommand('composer diagnose 2>&1', 15) ?? '';
        $ok = stripos($out, 'checks are failing') === false && stripos($out, 'Unable to diagnose') === false;

        return new Result(self::CAT, 'Composer / PHP', $ok ? Result::OK : Result::WARN,
            $ok ? 'diagnostic OK' : 'anomalies detectees',
            $ok
                ? 'Composer est correctement aligne sur votre PHP CLI.'
                : 'Lancez `composer diagnose` pour le detail des anomalies.',
            $ok ? null : 'Verifiez que la variable COMPOSER_HOME et le cache sont accessibles.',
            ['raw' => mb_substr($out, 0, 4000)]);
    }

    /** @param array<string,mixed> $config */
    private static function xamppTools(array $config): Result
    {
        $root = $config['paths']['xampp'];
        $wanted = [
            'Apache'     => $root . '/apache/bin/httpd.exe',
            'MySQL'      => $root . '/mysql/bin/mysqld.exe',
            'PHP CLI'    => $root . '/php/php.exe',
            'phpMyAdmin' => $root . '/phpMyAdmin/index.php',
            'Tomcat'     => $root . '/tomcat/bin/catalina.bat',
        ];

        $present = [];
        foreach ($wanted as $label => $path) {
            $present[$label] = is_file($path);
        }

        $count = count(array_filter($present));

        return new Result(self::CAT, 'Composants XAMPP', Result::OK, "{$count}/" . count($wanted) . ' installes',
            implode(', ', array_keys(array_filter($present)))
            . ' | racine : ' . $root);
    }
}
