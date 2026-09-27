<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Tests "Fichiers & droits".
 */
final class FsChecks
{
    private const CAT = 'Fichiers & droits';

    /** @param array<string,mixed> $config */
    public static function run(array $config): array
    {
        $storage = $config['paths']['storage'];

        return [
            self::directory($storage, 'Stockage du projet'),
            self::writeCycle($storage, 'Ecriture / lecture / suppression'),
            self::largeFile($storage, 'Fichier volumineux (2 Mo)'),
            self::htdocs(),
            self::temp(),
            self::configFiles($config),
        ];
    }

    private static function directory(string $path, string $label): Result
    {
        if (!is_dir($path)) {
            $made = @mkdir($path, 0o775, true);
            if (!$made) {
                return new Result(self::CAT, $label, Result::KO, $path,
                    'Repertoire absent et creation impossible.',
                    'Creez manuellement le dossier : ' . $path);
            }
        }

        return new Result(self::CAT, $label, Result::OK, $path,
            'Repertoire present' . (is_writable($path) ? ' et inscriptible.' : ' (lecture seule).'));
    }

    private static function writeCycle(string $dir, string $label): Result
    {
        $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.probe.tmp';
        $payload = str_repeat('devcheck', 64);

        if (@file_put_contents($file, $payload) === false) {
            return new Result(self::CAT, $label, Result::KO, $dir,
                'Ecriture impossible dans ce dossier (permissions NTFS ou antivirus).',
                'Clic droit > Proprietes > Securite > accordez "Modifier" a votre utilisateur, '
                . 'ou excluez le dossier de la protection en temps reel de l\'antivirus.');
        }

        $back  = @file_get_contents($file);
        $size  = @filesize($file) ?: 0;
        $unlinked = @unlink($file);

        $consistent = $back === $payload;

        if (!$consistent || !$unlinked) {
            return new Result(self::CAT, $label, Result::KO, $dir,
                'Le contenu relu ne correspond pas' . ($unlinked ? '' : ' et/ou la suppression a echoue.'),
                'Anomalie d\'ecriture : verifiez l\'antivirus, les quotas de disque et l\'etat du systeme de fichiers.');
        }

        return new Result(self::CAT, $label, Result::OK, Support::humanBytes($size) . ' ecrits',
            'Cycle ecriture -> lecture -> suppression valide.');
    }

    private static function largeFile(string $dir, string $label): Result
    {
        $file  = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.probe.big';
        $chunk = random_bytes(256 * 1024);
        $want  = 2 * 1024 * 1024;

        $fh = @fopen($file, 'wb');
        if (!$fh) {
            return new Result(self::CAT, $label, Result::KO, $dir, 'Ouverture en ecriture impossible.', null);
        }
        for ($i = 0; $i < $want; $i += strlen($chunk)) {
            fwrite($fh, $chunk);
        }
        fclose($fh);

        $got = @filesize($file) ?: 0;
        @unlink($file);

        if ($got !== $want) {
            return new Result(self::CAT, $label, Result::WARN, Support::humanBytes($got) . ' ecrits',
                'Ecriture partielle (attendu ' . Support::humanBytes($want) . '). Espace disque insuffisant ?',
                'Verifiez l\'esace disque disponible sur la partition.');
        }

        return new Result(self::CAT, $label, Result::OK, Support::humanBytes($got),
            'Ecriture volumineuse sans saturation de memoire.');
    }

    private static function htdocs(): Result
    {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (!$docRoot) {
            $docRoot = dirname(__DIR__, 1);
        }
        $writable = is_writable($docRoot);
        $isHtdocs = stripos((string) $docRoot, 'htdocs') !== false;

        if ($isHtdocs && $writable) {
            return new Result(self::CAT, 'Document root', Result::WARN, (string) $docRoot,
                'htdocs est inscriptible : un bug applicatif pourrait y ecrire du code executable.',
                'Recommandation : mettre DocumentRoot sur un sous-dossier, ou interdire l\'ecriture sur htdocs.');
        }

        return new Result(self::CAT, 'Document root', $isHtdocs ? Result::OK : Result::INFO, (string) $docRoot,
            $writable ? 'Inscriptible.' : 'Non inscriptible (bonnes pratiques).');
    }

    private static function temp(): Result
    {
        $tmp = sys_get_temp_dir();
        $file = rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . 'devcheck_probe.tmp';

        $ok = @file_put_contents($file, 'x') !== false;
        if ($ok) {
            @unlink($file);
        }

        return new Result(self::CAT, 'Dossier temporaire', $ok ? Result::OK : Result::KO, $tmp,
            $ok ? 'Accessible en ecriture.' : 'Dossier temporaire non inscriptible (upload_tmp_dir, TMPDIR, antivirus).',
            $ok ? null : 'Creez le dossier ou changez upload_tmp_dir / sys_temp_dir dans php.ini.');
    }

    /** @param array<string,mixed> $config */
    private static function configFiles(array $config): Result
    {
        $ini  = $config['paths']['php_ini'];
        $httpd = $config['paths']['httpd'];

        $found = [];
        foreach (['php.ini' => $ini, 'httpd.conf' => $httpd] as $label => $path) {
            $found[$label] = is_file($path) ? $path : null;
        }

        $missing = array_keys(array_filter($found, static fn ($v) => $v === null));

        if ($missing === []) {
            return new Result(self::CAT, 'Fichiers de configuration', Result::OK,
                count($found) . ' fichiers',
                'php.ini et httpd.conf sont accessibles en lecture : vous pourrez les editer.');
        }

        return new Result(self::CAT, 'Fichiers de configuration', Result::INFO,
            (string) $ini,
            'Introuvables : ' . implode(', ', $missing) . '. Definissez DEVCHECK_XAMPP_ROOT / DEVCHECK_PHP_INI '
            . 'pour pointer vers votre installation.',
            null);
    }
}
