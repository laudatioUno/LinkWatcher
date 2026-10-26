<?php

declare(strict_types=1);

/* Sends a test mail with the configured settings:  php bin/test-mail.php */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use LinkWatcher\Config;
use LinkWatcher\Mail\Mailer;

try {
    $mailer = Mailer::fromConfig(Config::load());
    $mailer->send('Testmail', "Die Mailkonfiguration von LinkWatcher funktioniert.\n");
    echo 'Testmail versendet an: ' . implode(', ', $mailer->recipients()) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
