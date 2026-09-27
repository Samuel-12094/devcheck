<?php
/**
 * DevEnv Doctor - autoloader minimal (aucune dependance externe).
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'DevCheck\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
