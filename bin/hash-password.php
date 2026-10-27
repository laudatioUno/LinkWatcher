<?php

declare(strict_types=1);

/*
 * Creates the password hash for the dashboard login.
 *   php bin/hash-password.php
 * Copy the output into config.php → 'admin' => ['password_hash' => '…'].
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$password = $argv[1] ?? null;
if ($password === null) {
    echo 'Passwort: ';
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
    }
    echo PHP_EOL;
}

if (strlen($password) < 10) {
    fwrite(STDERR, "Bitte mindestens 10 Zeichen verwenden.\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
