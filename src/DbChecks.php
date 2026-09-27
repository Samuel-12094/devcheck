<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Tests "Base de donnees" : connectabilite, self-test CRUD, transactions.
 *
 * Tous les timeouts sont courts : un serveur MySQL bloque ne doit jamais
 * figer la page de diagnostic.
 */
final class DbChecks
{
    private const CAT = 'Base de donnees';

    /** @param array<string,mixed> $config */
    public static function run(array $config): array
    {
        $c = $config['db'];

        return [
            self::port($c),
            self::connect($c),
            self::crud($c),
            self::transactions($c),
        ];
    }

    /** Memorise le resultat de la premiere tentative de connexion. */
    private static ?bool $linkOk = null;

    /** @param array<string,mixed> $c */
    private static function port(array $c): Result
    {
        $host = (string) $c['host'];
        $port = (int) $c['port'];
        $open = Support::isPortOpen($host, $port, 1.5);

        if (!$open) {
            return new Result(self::CAT, 'Port MySQL', Result::KO, "{$host}:{$port} ferme",
                'Aucun service ne repond sur ce port. Soit MySQL n\'est pas demarre, soit il ecoute ailleurs.',
                'Lancez MySQL via le Control Panel XAMPP, ou ajustez DEVCHECK_DB_PORT dans config.php.');
        }

        return new Result(self::CAT, 'Port MySQL', Result::OK, "{$host}:{$port} ouvert",
            'Une connexion TCP est acceptee. Cela ne garantit pas que le serveur repond correctement '
            . '(voir les tests suivants).');
    }

    /**
     * Applique des timeouts courts a une session mysqli.
     * Indispensable : un serveur bloque ne doit jamais figer la page de diagnostic.
     */
    private static function hardTimeout(\mysqli $link, int $seconds): void
    {
        @mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, $seconds);
        @mysqli_options($link, MYSQLI_OPT_READ_TIMEOUT, $seconds);
        if (defined('MYSQLI_OPT_WRITE_TIMEOUT')) {
            @mysqli_options($link, constant('MYSQLI_OPT_WRITE_TIMEOUT'), $seconds);
        }
    }

    /**
     * Connexion mysqli + lecture des metadonnees serveur.
     *
     * @param array<string,mixed> $c
     */
    private static function connect(array $c): Result
    {
        $timeout = (int) ($c['connect_timeout'] ?? 3);
        $name = sprintf('%s@%s:%d', $c['user'], $c['host'], $c['port']);

        mysqli_report(MYSQLI_REPORT_OFF);
        $link = mysqli_init();
        if (!$link) {
            return new Result(self::CAT, 'Connexion mysqli', Result::KO, $name,
                'Impossible d\'initialiser la session mysqli.', null, ['steps' => []]);
        }

        self::hardTimeout($link, $timeout);

        $start = microtime(true);
        $ok = @mysqli_real_connect(
            $link,
            (string) $c['host'],
            (string) $c['user'],
            (string) $c['pass'],
            null,
            (int) $c['port']
        );
        $ms = (int) round((microtime(true) - $start) * 1000);

        if (!$ok) {
            self::$linkOk = false;
            $errno = mysqli_connect_errno();
            $err   = mysqli_connect_error();
            $slow  = $ms > ($timeout * 1000) - 200;

            $fix = $slow
                ? 'La connexion TCP passe mais le serveur ne repond pas au handshake : il est probablement '
                  . 'bloque (CPU a 100%) ou casse. Arretez le processus mysqld, videz C:\\xampp\\mysql\\data '
                  . '(sauvegardez-le), puis relancez mysql.exe depuis le Control Panel XAMPP.'
                : match ($errno) {
                    1045 => 'Identifiants refuses. Verifiez DEVCHECK_DB_USER / DEVCHECK_DB_PASS, ou donnez un mot de passe a root via le Control Panel XAMPP (ou "ALTER USER \'root\'@\'localhost\' IDENTIFIED BY \'...\'").',
                    1044 => 'L\'utilisateur n\'a pas le droit de creer une base. Accordez-l\'en ALL PRIVILEGES.',
                    1049 => 'La base attendue n\'existe pas (normal : le test la cree).',
                    default => 'Verifiez que le service MySQL est demarre et que le port correspond.',
                };

            return new Result(self::CAT, 'Connexion mysqli', Result::KO, $name,
                'Echec apres ' . $ms . ' ms - errno ' . $errno . ' : ' . $err,
                $fix,
                ['steps' => [], 'elapsed_ms' => $ms]);
        }

        $server  = mysqli_get_server_info($link);
        $charset = mysqli_character_set_name($link) ?: '?';
        $sqlMode = (string) (mysqli_fetch_assoc(mysqli_query($link, 'SELECT @@sql_mode'))['@@sql_mode'] ?? '');

        mysqli_close($link);
        self::$linkOk = true;

        $value = sprintf('v%s | %s | %d ms', $server, $charset, $ms);

        if ($ms > 1500) {
            return new Result(self::CAT, 'Connexion mysqli', Result::WARN, $value,
                'Connexion fonctionnelle mais anormalement lente (' . $ms . ' ms).',
                'Verifier que le serveur ne swappe pas et que l\'antivirus n\'inspecte pas C:\\xampp\\mysql\\data.',
                ['elapsed_ms' => $ms]);
        }

        return new Result(self::CAT, 'Connexion mysqli', Result::OK, $value,
            'SQL mode : ' . ($sqlMode !== '' ? $sqlMode : 'defaut'),
            null,
            ['elapsed_ms' => $ms, 'server' => $server, 'charset' => $charset]);
    }

    /**
     * Self-test complet : CREATE DATABASE / TABLE, INSERT, SELECT, UPDATE, DELETE.
     *
     * @param array<string,mixed> $c
     */
    private static function crud(array $c): Result
    {
        $steps = [];
        $db    = self::sanitizeIdentifier((string) $c['database']);
        $host  = (string) $c['host'];
        $port  = (int) $c['port'];
        $label = sprintf('%s@%s:%d', $c['user'], $host, $port);

        if (self::$linkOk === false) {
            return new Result(self::CAT, 'Self-test CRUD', Result::SKIP, $label,
                'Ignore : la connexion mysqli a deja echoue, ce test ne peut pas aboutir.', null, ['steps' => $steps]);
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $link = mysqli_init();
        if (!$link) {
            return new Result(self::CAT, 'Self-test CRUD', Result::SKIP, $label, 'mysqli indisponible.');
        }
        self::hardTimeout($link, (int) $c['connect_timeout']);

        if (!@mysqli_real_connect($link, $host, (string) $c['user'], (string) $c['pass'], null, $port)) {
            return new Result(self::CAT, 'Self-test CRUD', Result::SKIP, $label,
                'Ignore : la connexion a echoue, le test ne peut pas demarrer.', null, ['steps' => $steps]);
        }

        $fail = null;

        $step = static function (string $name, callable $fn) use (&$steps, &$fail): void {
            if ($fail !== null) {
                $steps[$name] = 'ignore';
                return;
            }
            try {
                $msg = $fn();
                $steps[$name] = $msg === null ? 'ok' : 'ko: ' . $msg;
                if ($msg !== null) {
                    $fail = $name . ' : ' . $msg;
                }
            } catch (\Throwable $e) {
                $steps[$name] = 'ko: ' . $e->getMessage();
                $fail = $name . ' : ' . $e->getMessage();
            }
        };

        // $exec renvoie null si la requete reussit, sinon le message d'erreur.
        $exec = static function (\mysqli $l, string $sql): ?string {
            $r = @mysqli_query($l, $sql);
            if ($r === false) {
                return mysqli_error($l);
            }
            if ($r instanceof mysqli_result) {
                $r->free();
            }
            return null;
        };

        $step('CREATE DATABASE', static fn () => $exec($link,
            'CREATE DATABASE IF NOT EXISTS `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'));
        $step('USE DATABASE', static fn () => $exec($link, 'USE `' . $db . '`'));
        $step('CREATE TABLE', static fn () => $exec($link,
            'CREATE TABLE IF NOT EXISTS `probe` ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY,'
            . 'label VARCHAR(64) NOT NULL,'
            . 'score INT DEFAULT 0,'
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        ));
        $step('TRUNCATE', static fn () => $exec($link, 'TRUNCATE TABLE `probe`'));

        $step('INSERT (prepared)', static function () use ($link) {
            $st = @mysqli_prepare($link, 'INSERT INTO `probe` (label, score) VALUES (?, ?)');
            if (!$st) {
                return mysqli_error($link);
            }
            $label = 'devcheck ' . bin2hex(random_bytes(3));
            @mysqli_stmt_bind_param($st, 'si', $label, $score);
            $score = 42;
            $r = @mysqli_stmt_execute($st);
            $id = mysqli_insert_id($link);
            @mysqli_stmt_close($st);
            return $r ? null : 'execute failed';
        });

        $step('SELECT', static function () use ($link) {
            $r = @mysqli_query($link, 'SELECT COUNT(*) c FROM `probe`');
            if (!$r) {
                return mysqli_error($link);
            }
            $row = mysqli_fetch_assoc($r);
            $r->free();
            return ((int) $row['c'] === 1) ? null : ' attendu 1 ligne, trouve ' . $row['c'];
        });

        $step('UPDATE', static function () use ($link) {
            $r = @mysqli_query($link, 'UPDATE `probe` SET score = score + 8');
            if (!$r) {
                return mysqli_error($link);
            }
            return mysqli_affected_rows($link) === 1 ? null : 'aucune ligne mise a jour';
        });

        $step('DELETE', static function () use ($link) {
            $r = @mysqli_query($link, 'DELETE FROM `probe`');
            if (!$r) {
                return mysqli_error($link);
            }
            return mysqli_affected_rows($link) === 1 ? null : 'aucune ligne supprimee';
        });

        $step('DROP TABLE', static fn () => $exec($link, 'DROP TABLE IF EXISTS `probe`'));

        if (!($c['keep_database'] ?? false)) {
            $step('DROP DATABASE', static fn () => $exec($link, 'DROP DATABASE IF EXISTS `' . $db . '`'));
        }

        @mysqli_close($link);

        $ok = $fail === null;
        $done = count(array_filter($steps, static fn ($v) => str_starts_with((string) $v, 'ok')));

        return new Result(self::CAT, 'Self-test CRUD', $ok ? Result::OK : Result::KO,
            $ok ? $done . '/' . count($steps) . ' etapes' : 'echec',
            $ok
                ? 'Ecriture, lecture, requetes preparees et nettoyage valides sur la base `' . $db . '`.'
                : 'Echec : ' . (string) $fail,
            $ok ? null : 'Consultez la liste detailee des etapes.',
            ['steps' => $steps]);
    }

    /** @param array<string,mixed> $c */
    private static function transactions(array $c): Result
    {
        if (self::$linkOk === false) {
            return new Result(self::CAT, 'Transactions (InnoDB)', Result::SKIP, '-',
                'Ignore : la connexion mysqli a deja echoue, ce test ne peut pas aboutir.');
        }

        $db = self::sanitizeIdentifier((string) $c['database']);

        mysqli_report(MYSQLI_REPORT_OFF);
        $link = mysqli_init();
        if (!$link) {
            return new Result(self::CAT, 'Transactions (InnoDB)', Result::SKIP, '-', 'mysqli indisponible.');
        }
        self::hardTimeout($link, (int) $c['connect_timeout']);

        if (!@mysqli_real_connect($link, (string) $c['host'], (string) $c['user'], (string) $c['pass'], null, (int) $c['port'])) {
            return new Result(self::CAT, 'Transactions (InnoDB)', Result::SKIP, '-', 'Ignore : connexion impossible.');
        }

        $table = 'tx_probe';
        $dbQ   = '`' . $db . '`';
        $tQ    = '`' . $table . '`';
        $steps = [];

        $ok = true;
        $detail = '';

        if (@mysqli_query($link, 'CREATE DATABASE IF NOT EXISTS ' . $dbQ) === false) {
            $ok = false;
            $detail = 'CREATE DATABASE : ' . mysqli_error($link);
        } elseif (@mysqli_query($link, "CREATE TABLE IF NOT EXISTS {$dbQ}.{$tQ} (id INT PRIMARY KEY, v INT) ENGINE=InnoDB") === false) {
            $ok = false;
            $detail = 'CREATE TABLE : ' . mysqli_error($link);
        } else {
            $steps['autocommit off'] = @mysqli_autocommit($link, false) ? 'ok' : 'ko';
            @mysqli_query($link, "DELETE FROM {$dbQ}.{$tQ} WHERE id = 1");
            @mysqli_begin_transaction($link);
            @mysqli_query($link, "INSERT INTO {$dbQ}.{$tQ} (id, v) VALUES (1, 100)");
            @mysqli_commit($link);

            @mysqli_begin_transaction($link);
            @mysqli_query($link, "UPDATE {$dbQ}.{$tQ} SET v = 999 WHERE id = 1");
            @mysqli_rollback($link);

            $r = @mysqli_query($link, "SELECT v FROM {$dbQ}.{$tQ} WHERE id = 1");
            $v = $r ? (int) (mysqli_fetch_assoc($r)['v'] ?? 0) : -1;
            if ($r) {
                $r->free();
            }

            $steps['rollback'] = $v === 100 ? 'ok' : 'ko (v=' . $v . ', attendu 100)';
            $ok = ($v === 100);

            @mysqli_query($link, "DROP TABLE IF EXISTS {$dbQ}.{$tQ}");
            if (!($c['keep_database'] ?? false)) {
                @mysqli_query($link, 'DROP DATABASE IF EXISTS ' . $dbQ);
            }
        }

        @mysqli_close($link);

        return new Result(self::CAT, 'Transactions (InnoDB)', $ok ? Result::OK : Result::KO,
            $ok ? 'rollback valide' : 'echec',
            $ok ? 'Les transactions et le moteur InnoDB fonctionnent correctement.' : $detail,
            null,
            ['steps' => $steps]);
    }

    private static function sanitizeIdentifier(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $id) ?: 'devcheck_db';
    }
}
