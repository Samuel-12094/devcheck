<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Tests "Serveur & PHP" : moteur, extensions, reglages ini, contexte web.
 */
final class PhpChecks
{
    private const CAT = 'Serveur & PHP';

    /** Extensions indispensables au developpement courant. */
    private const EXT_CORE = [
        'pdo'       => 'Couche d\'abstraction base de donnees',
        'pdo_mysql' => 'Driver PDO MySQL/MariaDB',
        'mysqli'    => 'API mysqli',
        'mbstring'  => 'Gestion UTF-8',
        'openssl'   => 'HTTPS, TLS, hachage',
        'curl'      => 'Requetes HTTP sortantes',
        'fileinfo'  => 'Detection de type MIME',
        'json'      => 'Serialisation JSON',
        'session'   => 'Sessions',
        'filter'    => 'Validation de donnees',
    ];

    /** Extensions recommandees. */
    private const EXT_RECO = [
        'gd'      => 'Traitement d\'images (captures, vignettes)',
        'zip'     => 'Archives / installation Composer',
        'intl'    => 'Internationalisation, dates, nombres',
        'opcache' => 'Cache d\'opcodes (performance)',
        'exif'    => 'Metadonnees des photos',
        'sodium'  => 'Chiffrement moderne',
        'sqlite3' => 'Base legere / tests',
        'sockets' => 'Sockets serveur',
        'xdebug'  => 'Profilage et breakpoints',
    ];

    /** @param array<string,mixed> $config */
    public static function run(array $config): array
    {
        $out = [];

        $out[] = self::sapi();
        $out[] = self::version();
        $out[] = self::coreExtensions();
        $out[] = self::recoExtensions();
        $out[] = self::iniSettings();
        $out[] = self::upload();
        $out[] = self::session();
        $out[] = self::timezone();
        $out[] = self::webContext();
        $out[] = self::phpHandlerOverHttp($config);
        $out[] = self::security();
        $out[] = self::phpIniConsistency($config);

        return $out;
    }

    private static function sapi(): Result
    {
        $sapi = PHP_SAPI;
        $value = Support::phpServerLabel();

        if ($sapi === 'apache2handler') {
            return new Result(self::CAT, 'Moteur PHP', Result::OK, $value,
                'Apache execute le PHP via mod_php. C\'est la configuration attendue sous XAMPP.');
        }

        if ($sapi === 'cli-server') {
            return new Result(self::CAT, 'Moteur PHP', Result::WARN, $value,
                'Vous utilisez le serveur integre de PHP, pas Apache. C\'est utile pour '
                . 'developper vite, mais cela ne prouve pas que mod_php est configure.',
                'Verifiez mod_php dans C:\\xampp\\apache\\conf\\httpd.conf pour valider la pile complete.');
        }

        return new Result(self::CAT, 'Moteur PHP', Result::WARN, $value,
            'Execution en ligne de commande uniquement : la partie web de votre stack n\'est pas testee ici.',
            'Ouvrez le tableau de bord via votre serveur web (par exemple '
            . 'http://localhost:8000/devcheck/) pour valider la chaine complete, '
            . 'ou lancez `php -S localhost:8080` depuis le dossier du projet.');
    }

    private static function version(): Result
    {
        $v = PHP_VERSION;
        [$major, $minor] = array_map('intval', array_slice(explode('.', $v), 0, 2));
        $supported = ($major === 8 && $minor >= 2) || $major >= 9;

        if ($major < 8) {
            return new Result(self::CAT, 'Version PHP', Result::KO, $v,
                'PHP 7 est en fin de vie : plus de correctifs de securite ni de compatibilite avec les packages actuels.',
                'Installez PHP 8.2+ et faites pointer Apache dessus.');
        }

        return new Result(self::CAT, 'Version PHP', $supported ? Result::OK : Result::WARN, $v,
            $supported
                ? 'Version maintenue, compatible avec les frameworks actuels (Laravel, Symfony, etc.).'
                : 'Version recente mais hors du lot officiellement supporte.');
    }

    private static function coreExtensions(): Result
    {
        $missing = [];
        foreach (self::EXT_CORE as $ext => $why) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        $loaded = count(self::EXT_CORE) - count($missing);

        if ($missing === []) {
            return new Result(self::CAT, 'Extensions critiques', Result::OK,
                "{$loaded}/" . count(self::EXT_CORE) . ' presentes',
                'Toutes les extensions indispensables au developpement web sont chargees.');
        }

        return new Result(self::CAT, 'Extensions critiques', Result::KO,
            count($missing) . ' manquante(s)',
            'Manquant : ' . implode(', ', $missing) . '.',
            'Dans C:\\xampp\\php\\php.ini, decommentez les lignes "extension=" correspondantes '
            . '(pensez a retirer le ";") puis redemarrez Apache.');
    }

    private static function recoExtensions(): Result
    {
        $missing = [];
        $present = [];
        foreach (self::EXT_RECO as $ext => $why) {
            if (Support::extensionActive($ext)) {
                $present[] = $ext;
            } else {
                $missing[] = $ext;
            }
        }

        $value = $present === [] ? 'aucune' : implode(', ', $present);

        if ($missing === []) {
            return new Result(self::CAT, 'Extensions recommandees', Result::OK, $value,
                'Configuration recommandee complete.');
        }

        // xdebug n'est pas livre avec XAMPP : ce n'est pas une erreur d'installation.
        $xdebugOnly = array_diff($missing, ['xdebug']);
        $severity = $xdebugOnly === [] ? Result::INFO : Result::WARN;

        $detail = $xdebugOnly === []
            ? 'Seul xdebug manque. Il n\'est pas livre avec XAMPP : copiez php_xdebug.dll '
              . 'depuis xdebug.org dans C:\\xampp\\php\\ext\\ puis ajoutez zend_extension=php_xdebug.dll.'
            : 'Non chargees : ' . implode(', ', $missing) . '.';

        return new Result(self::CAT, 'Extensions recommandees', $severity, $value, $detail,
            $xdebugOnly === []
                ? null
                : 'Les DLL sont normalement presentes dans C:\\xampp\\php\\ext\\ : decommentez simplement '
                  . 'les lignes "extension=" dans php.ini, puis redemarrez Apache.');
    }

    private static function iniSettings(): Result
    {
        $maxExec = (int) ini_get('max_execution_time');
        $problems = [];

        if ($maxExec > 0 && $maxExec < 30) {
            $problems[] = 'max_execution_time=' . $maxExec . ' (trop court pour des traitements longs)';
        }
        if (Support::iniBytes((string) ini_get('memory_limit')) < 128 * 1024 ** 2) {
            $problems[] = 'memory_limit=' . ini_get('memory_limit') . ' (minimum recommande : 256M)';
        }

        $value = sprintf(
            'max_exec=%s | mem=%s | upload=%s',
            $maxExec === 0 ? 'illimite' : (string) $maxExec,
            ini_get('memory_limit'),
            ini_get('upload_max_filesize')
        );

        if ($problems === []) {
            // zend.assertions=1 est le reglage recommande en developpement
            // (les assertions attrapent des bugs totaux) : on ne le signale pas
            // comme un defaut, seulement comme un rappel pour la mise en ligne.
            $assertNote = (ini_get('zend.assertions') === '1')
                ? ' Rappel : zend.assertions=1 est adapte au dev, passez-le a -1 en production.'
                : '';

            return new Result(self::CAT, 'Reglages php.ini', Result::OK, $value,
                'Valeurs correctes pour le developpement.' . $assertNote);
        }

        return new Result(self::CAT, 'Reglages php.ini', Result::WARN, $value,
            implode(' | ', $problems),
            'Ajustez ces directives dans php.ini.');
    }

    private static function upload(): Result
    {
        $maxFile = Support::iniBytes((string) ini_get('upload_max_filesize'));
        $maxPost = Support::iniBytes((string) ini_get('post_max_size'));
        $enabled = (bool) ini_get('file_uploads');

        $value = Support::humanBytes($maxFile);

        if (!$enabled) {
            return new Result(self::CAT, 'Envoi de fichiers', Result::KO, 'desactive',
                'file_uploads=0 : aucun upload ne peut fonctionner.',
                'Passez file_uploads=On dans php.ini.');
        }
        if ($maxPost > 0 && $maxFile > $maxPost) {
            return new Result(self::CAT, 'Envoi de fichiers', Result::WARN, $value,
                'upload_max_filesize est superieur a post_max_size : les fichiers au-dela de '
                . Support::humanBytes($maxPost) . ' seront rejetes silencieusement.',
                'Alignez post_max_size sur upload_max_filesize (ou plus grand) dans php.ini.');
        }

        return new Result(self::CAT, 'Envoi de fichiers', Result::OK, $value,
            'Upload active, ' . ini_get('max_file_uploads') . ' fichiers maximum par requete.');
    }

    private static function session(): Result
    {
        $handler = ini_get('session.save_handler');
        $path    = (string) ini_get('session.save_path');

        if ($handler === 'files' && ($path === '' || !is_dir($path))) {
            return new Result(self::CAT, 'Sessions', Result::KO, (string) $handler,
                'Dossier de sessions introuvable : ' . ($path ?: '(non defini)'),
                'Creez le dossier et pointez session.save_path dessus dans php.ini.');
        }

        return new Result(self::CAT, 'Sessions', Result::OK, (string) $handler,
            'Stockage : ' . ($path !== '' ? $path : 'repertoire systeme') . ' | cookie httponly='
            . (ini_get('session.cookie_httponly') ? 'oui' : 'non'));
    }

    private static function timezone(): Result
    {
        $tz = (string) ini_get('date.timezone');
        $valid = in_array($tz, timezone_identifiers_list(), true);

        if (!$valid) {
            return new Result(self::CAT, 'Fuseau horaire', Result::WARN, $tz,
                'Fuseau non renseigne : PHP utilise celui du systeme, ce qui rend les dates moins previsibles.',
                'Renseignez date.timezone (ex. Europe/Paris) dans php.ini.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        return new Result(self::CAT, 'Fuseau horaire', Result::OK, $tz,
            'Heure locale : ' . $now->format('d/m/Y H:i:s'));
    }

    private static function webContext(): Result
    {
        if (PHP_SAPI === 'cli') {
            return new Result(self::CAT, 'Contexte web', Result::SKIP, 'CLI',
                'Execute en ligne de commande : $_SERVER n\'est pas peuple. '
                . 'Les variables .htaccess / URL ne sont pas evaluees.');
        }

        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '(inconnu)';
        $writable = is_writable($docRoot);

        $value = 'doc_root=' . $docRoot;

        if (!$writable) {
            return new Result(self::CAT, 'Contexte web', Result::INFO, $value,
                'Le document root n\'est pas inscriptible. C\'est une bonne pratique de securite : '
                . 'stockez les fichiers ecrits hors de htdocs.');
        }

        return new Result(self::CAT, 'Contexte web', Result::OK, $value,
            'URL : ' . ($_SERVER['REQUEST_URI'] ?? '-')
            . ' | rewrite: ' . (Support::inPath('n/a') ? 'oui' : 'inconnu')
            . ' | ' . ($_SERVER['SERVER_SOFTWARE'] ?? '?'));
    }

    /**
     * Verifie que le serveur web EXECUTE le PHP et ne le sert pas en texte brut.
     *
     * Ce test ecrit lui-meme une sonde sans BOM, l'appelle en HTTP et compare la
     * reponse. C'est le seul moyen de distinguer "`mod_php` actif" de "le .php
     * s'affiche tel quel" : lire `php_sapi_name()` ne suffit pas, puisque la page
     * courante peut etre servie par une autre installation de PHP.
     *
     * @param array<string,mixed> $config
     */
    private static function phpHandlerOverHttp(array $config): Result
    {
        if (PHP_SAPI === 'cli') {
            return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::SKIP, 'CLI',
                'Ignore : aucun serveur web implique dans une execution en ligne de commande.');
        }

        if (($config['net']['probe_handler'] ?? true) === false) {
            return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::SKIP, 'desactive',
                'Ignore : test desactive via config.php (net.probe_handler = false).');
        }

        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $storage = rtrim((string) $config['paths']['storage'], '/\\');
        $probe   = $storage . DIRECTORY_SEPARATOR . '_handler_probe.php';
        $canary  = 'devcheck-' . bin2hex(random_bytes(4));

        if ($docRoot === false || !is_dir($storage)) {
            return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::SKIP, 'inconnu',
                'Ignore : document root ou dossier de stockage introuvable.');
        }

        // Chemin web du fichier de sonde (relative au document root).
        $realProbe = realpath($storage);
        $rel = ($realProbe === false) ? false : str_replace('\\', '/', substr((string) $realProbe, strlen($docRoot)));
        if ($realProbe === false || $rel === false || !str_starts_with($rel, '/')) {
            return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::SKIP, 'inconnu',
                'Ignore : le dossier de stockage est hors du document root, la sonde n\'est pas accessible en HTTP.');
        }

        // Ecriture SANS BOM : un BOM UTF-8 devant "<?php" empeche PHP de reconnaitre
        // la balise ouvrante, et donne un faux diagnostic de "PHP non execute".
        $body = "<?php echo '" . $canary . "'; echo PHP_SAPI;";
        if (@file_put_contents($probe, $body) !== strlen($body)) {
            return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::KO, 'sonde non ecrite',
                'Impossible d\'ecrire la sonde dans ' . $storage . '.',
                'Verifiez les droits NTFS du dossier de stockage.');
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $url  = 'http://' . $host . $rel . '/' . basename($probe);

        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $ctx);
        @unlink($probe);

        if ($response === false) {
            return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::WARN, 'requete echouee',
                'Impossible de rejouer la sonde en HTTP (' . $url . ').',
                'Verifiez que le serveur web est bien demarre et que la sonde n\'est pas bloquee par un antivirus.');
        }

        if (str_contains($response, '<?php') || !str_contains($response, $canary)) {
            return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::KO, 'code source servi',
                'Le serveur renvoie le code PHP au lieu de l\'executer : mod_php n\'est pas associe a l\'extension .php.',
                'Dans ' . $config['paths']['httpd'] . ' : verifiez "LoadModule php_module" et '
                . 'dans conf/extra/httpd-xampp.conf le "SetHandler application/x-httpd-php". '
                . 'Redemarrez Apache ensuite.');
        }

        $sapi = str_contains($response, 'apache2handler') ? 'mod_php' : trim($response);

        return new Result(self::CAT, 'Gestionnaire .php du serveur web', Result::OK, 'execute (' . $sapi . ')',
            'Sonde executee par le serveur web en HTTP : le code est bien interprete, pas affiche. '
            . 'Verifie sur ' . $url,
            null,
            ['url' => $url, 'sapi' => $sapi]);
    }

    private static function security(): Result
    {
        $display = (string) filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL);
        $notes = [];

        if (PHP_SAPI === 'apache2handler' || PHP_SAPI === 'fpm-fcgi') {
            $notes[] = 'display_errors=' . ($display ? 'On (ne JAMAIS en production)' : 'Off');
            $notes[] = 'expose_php=' . ini_get('expose_php');
        } else {
            $notes[] = 'display_errors=' . $display . ' (normal en CLI)';
        }

        $notes[] = 'serveur=' . (PHP_SAPI === 'apache2handler' ? 'Apache' : 'CLI');
        $notes[] = 'open_basedir=' . (ini_get('open_basedir') ?: 'non definie');

        return new Result(self::CAT, 'Configuration securite', Result::INFO, implode(' | ', $notes),
            'Verifiez ces valeurs avant toute mise en ligne : sur un poste de dev elles peuvent rester permissives.');
    }

    /** @param array<string,mixed> $config */
    private static function phpIniConsistency(array $config): Result
    {
        $iniPath = $config['paths']['php_ini'];
        $loaded  = php_ini_loaded_file();

        if (!is_file($iniPath)) {
            return new Result(self::CAT, 'Fichier php.ini', Result::WARN, (string) $loaded,
                'Fichier attendu introuvable : ' . $iniPath,
                'Verifiez la variable DEVCHECK_PHP_INI ou le chemin d\'installation de XAMPP.');
        }

        $real = @realpath($iniPath);
        $realLoaded = $loaded ? @realpath($loaded) : false;
        $same = $real && $realLoaded && strcasecmp($real, $realLoaded) === 0;

        if ($same) {
            return new Result(self::CAT, 'Fichier php.ini', Result::OK, $real,
                'Le fichier edite est bien celui charge par le moteur.');
        }

        return new Result(self::CAT, 'Fichier php.ini', Result::WARN, (string) $realLoaded,
            'Analysee : ' . $real . ' | chargee : ' . (string) $realLoaded,
            'Vous editez un fichier different de celui utilise. Redemarrez Apache apres toute modification.');
    }
}
