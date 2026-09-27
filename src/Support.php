<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Petites aides transverses (Detection d'outils, ports, formats).
 */
final class Support
{
    /** Cherche un executable dans le PATH sans passer par un shell (plus fiable sous Windows). */
    public static function findExecutable(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $exts = $isWindows
            ? explode(';', (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD'))
            : [''];

        $pathEnv = (string) (getenv('PATH') ?: '');
        $dirs = $isWindows ? explode(';', $pathEnv) : explode(':', $pathEnv);

        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($exts as $ext) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                if (@is_file($candidate)) {
                    return $candidate;
                }
            }
            // Cas *nix : fichier sans extension mais executable
            if (!$isWindows && @is_file(rtrim($dir, '/') . '/' . $name) && @is_executable(rtrim($dir, '/') . '/' . $name)) {
                return rtrim($dir, '/') . '/' . $name;
            }
        }

        return null;
    }

    /** Execute une commande et renvoie sa sortie (stdout+stderr), avec timeout. */
    public static function runCommand(string $cmd, int $timeoutSec = 5): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        if ($isWindows) {
            $cmd = 'cmd /V:ON /C ' . escapeshellarg($cmd);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Environnement herite tel quel : sur Windows, remplacer l'env complet casse cmd.exe
        // (ComSpec/SystemRoot manquants) et donc tous les scripts .cmd / .bat.
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
        if (!is_resource($proc)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(50_000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($proc);

        if ($exit === -1 && $out === '') {
            return null; // timeout
        }

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }

    /** true = le port accepte une connexion TCP. */
    public static function isPortOpen(string $host, int $port, float $timeout = 1.0): bool
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (is_resource($sock)) {
            fclose($sock);
            return true;
        }
        return false;
    }

    public static function humanBytes(int|float $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    /** Convertit une valeur php.ini en octets ("8M" -> 8388608). */
    public static function iniBytes(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        $unit = strtolower(substr($raw, -1));
        $num  = (int) $raw;
        return match ($unit) {
            'g'     => $num * 1024 ** 3,
            'm'     => $num * 1024 ** 2,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    /**
     * Verifie qu'une extension est activee.
     *
     * extension_loaded() ne fonctionne pas pour les Zend extensions (opcache) :
     * le module s'appelle "Zend OPcache" dans get_loaded_extensions(true), donc
     * il faut passer par un alias.
     */
    public static function extensionActive(string $name): bool
    {
        if (extension_loaded($name)) {
            return true;
        }

        $aliases = ['opcache' => 'Zend OPcache', 'xdebug' => 'Xdebug'];
        $target = strtolower(str_replace(' ', '', $aliases[strtolower($name)] ?? $name));

        foreach (get_loaded_extensions(true) as $ext) {
            if (strtolower(str_replace(' ', '', $ext)) === $target) {
                return true;
            }
        }

        return false;
    }

    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Decoupe le PATH : separateur ";" sous Windows, ":" ailleurs (le "C:" de Windows n'est pas un separateur). */
    public static function pathDirs(): array
    {
        $pathEnv = (string) (getenv('PATH') ?: '');
        if ($pathEnv === '') {
            return [];
        }
        $sep = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        return array_values(array_filter(preg_split('/' . preg_quote($sep, '/') . '/', $pathEnv) ?: []));
    }

    /**
     * Cherche un nom d'executable dans le PATH en listant les entrees de repertoire.
     *
     * is_file() echoue sur les "App Execution Alias" de Windows 11 (points de
     * reparse 0 octet) : scandir() les voit, ce qui permet de les signaler
     * comme alias plutot que comme "absent".
     */
    public static function pathHasEntry(string $name): ?string
    {
        $exts = DIRECTORY_SEPARATOR === '\\'
            ? explode(';', (string) (getenv('PATHEXT') ?: '.EXE'))
            : [''];

        foreach (self::pathDirs() as $dir) {
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                foreach ($exts as $ext) {
                    if (strcasecmp($entry, $name . $ext) === 0) {
                        return $dir . DIRECTORY_SEPARATOR . $entry;
                    }
                }
            }
        }

        return null;
    }

    /** Est-ce qu'un chemin est dans le PATH de l'utilisateur courant ? */
    public static function inPath(string $path): bool
    {
        $needle = strtolower(str_replace('\\', '/', $path));
        foreach (self::pathDirs() as $dir) {
            $hay = strtolower(str_replace('\\', '/', $dir));
            if ($hay !== '' && str_starts_with($needle, rtrim($hay, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    public static function phpServerLabel(): string
    {
        $sapi = PHP_SAPI;
        return match ($sapi) {
            'apache2handler' => 'Apache + mod_php',
            'cli-server'     => 'Serveur integre PHP (cli -S)',
            'cli'            => 'Ligne de commande (CLI)',
            'fpm-fcgi'       => 'PHP-FPM / FastCGI',
            default          => $sapi,
        };
    }
}
