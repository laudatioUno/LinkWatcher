<?php

declare(strict_types=1);

/*
 * LinkWatcher bootstrap: autoloader, helpers, config + timezone.
 * Everything outside public/ must NOT be reachable from the web.
 */

define('LW_ROOT', dirname(__DIR__));
define('LW_VERSION', trim((string) @file_get_contents(LW_ROOT . '/VERSION')) ?: 'dev');

spl_autoload_register(static function (string $class): void {
    $prefix = 'LinkWatcher\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';
