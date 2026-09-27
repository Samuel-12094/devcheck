<?php
/**
 * DevEnv Doctor - configuration
 *
 * Toute la config est surchargeable par variables d'environnement, ce qui evite
 * de versionner des identifiants et permet de tester plusieurs environnements.
 */

declare(strict_types=1);

$env = static function (string $name, ?string $default = null): ?string {
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
};

$systemDrive = $env('SystemDrive', 'C:') ?: 'C:';
$xamppRoot  = $env('DEVCHECK_XAMPP_ROOT', $systemDrive . '\xampp') ?: $systemDrive . '\xampp';

return [
    'app' => [
        'name'    => 'DevEnv Doctor',
        'version' => '1.0.0',
    ],

    'db' => [
        'host'            => $env('DEVCHECK_DB_HOST', '127.0.0.1') ?: '127.0.0.1',
        'port'            => (int) ($env('DEVCHECK_DB_PORT', '3306') ?: 3306),
        'user'            => $env('DEVCHECK_DB_USER', 'root') ?: 'root',
        'pass'            => $env('DEVCHECK_DB_PASS', '') ?? '',
        'database'        => $env('DEVCHECK_DB_NAME', 'devcheck_db') ?: 'devcheck_db',
        'connect_timeout' => 3,
        'keep_database'   => false, // true = on laisse la base de test en place
    ],

    'paths' => [
        'xampp'   => $xamppRoot,
        'php_ini' => $env('DEVCHECK_PHP_INI', $xamppRoot . '\php\php.ini') ?: $xamppRoot . '\php\php.ini',
        'httpd'   => $xamppRoot . '\apache\conf\httpd.conf',
        'storage' => __DIR__ . DIRECTORY_SEPARATOR . 'storage',
    ],

    'net' => [
        'check_outbound' => true,
        'outbound_url'   => 'https://repo.packagist.org/packages.json',
        'probe_timeout'  => 0.8,
        // Sonde HTTP de verification du gestionnaire .php.
        // Mettez false si votre Apache tourne avec ThreadsPerChild 1 : la sonde
        // appelle le serveur depuis une page deja servie par lui, ce qui
        // bloquerait jusqu'au timeout.
        'probe_handler'  => false, // temporairement désactivé : la sonde bloque avec le MPM winnt
        'ports'          => [
            80   => 'Apache (port standard)',
            443  => 'Apache HTTPS',
            3306 => 'MySQL / MariaDB',
            8000 => 'Apache (XAMPP)',
            8080 => 'Serveur alt. / PHP dev server',
            5173 => 'Vite dev server',
        ],
    ],
];
