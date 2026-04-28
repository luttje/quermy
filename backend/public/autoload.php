<?php
declare(strict_types=1);

// Tiny PSR-4 style autoloader. Avoids needing Composer.
spl_autoload_register(function (string $class): void {
    $prefix  = 'Quermy\\';
    $baseDir = __DIR__ . '/../src/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
