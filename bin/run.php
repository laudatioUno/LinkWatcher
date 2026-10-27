<?php

declare(strict_types=1);

/*
 * LinkWatcher – nightly run (cron).
 *
 *   php bin/run.php            sync keys, check URLs, mail summary on problems
 *   php bin/run.php --no-mail  same, but never send a mail
 *   php bin/run.php --quiet    no output (recommended for cron)
 *
 * Exit codes: 0 = ok, 1 = problems found, 2 = run failed, 3 = already running
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use LinkWatcher\AlreadyRunningException;
use LinkWatcher\Config;
use LinkWatcher\Database;
use LinkWatcher\Runner;

$args = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true) || in_array('-q', $args, true);
$sendMail = !in_array('--no-mail', $args, true);
$trigger = 'cron';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--trigger=')) {
        $trigger = substr($arg, 10);
    }
}

try {
    $config = Config::load();
    $pdo = Database::open($config->get('db_path'));
    $runner = new Runner($config, $pdo, $quiet ? null : static fn(string $line) => print($line . PHP_EOL));
    $runner->begin($trigger);
} catch (AlreadyRunningException $e) {
    $quiet || fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, 'LinkWatcher: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$result = $runner->execute($sendMail);

exit(match ($result['status']) {
    'ok' => 0,
    'problems' => 1,
    default => 2,
});
