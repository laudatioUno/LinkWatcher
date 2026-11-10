<?php

declare(strict_types=1);

/*
 * Shows what LinkWatcher reads from a FAQ page – handy before adding a language to the config.
 *
 *   php bin/faq-test.php https://swiyu.admin.ch/de/hilfe            start page: discovers categories
 *   php bin/faq-test.php https://swiyu.admin.ch/de/hilfe-e-id -v    single page, with all links
 *   php bin/faq-test.php ./saved-page.html https://swiyu…/hilfe-e-id  parse a saved file
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use LinkWatcher\Config;
use LinkWatcher\Parser\FaqPageParser;
use LinkWatcher\SourceFetcher;

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '-v'));
$verbose = in_array('-v', $argv, true);
if (!$args) {
    fwrite(STDERR, "Aufruf: php bin/faq-test.php <url> [-v]\n");
    exit(1);
}

$checker = Config::defaults()['checker'];
$fetcher = new SourceFetcher($checker);
$parser = new FaqPageParser();

$load = static function (string $source) use ($fetcher): string {
    if (is_file($source)) {
        return (string) file_get_contents($source);
    }
    $r = $fetcher->fetch($source);
    if (!$r['ok']) {
        fwrite(STDERR, "{$source}: {$r['error']}\n");
        exit(2);
    }
    return $r['body'];
};

$source = $args[0];
$url = $args[1] ?? $source;
$html = $load($source);

$pages = [[$url, $html]];
foreach ($parser->discover($html, $url) as $page) {
    $pages[] = [$page, $load($page)];
}

$total = 0;
$allUrls = [];
foreach ($pages as [$pageUrl, $pageHtml]) {
    $entries = $parser->parse($pageHtml, $pageUrl);
    if ($entries === [] && $pageUrl === $url && count($pages) > 1) {
        echo "Startseite {$pageUrl}: " . (count($pages) - 1) . " Kategorieseiten gefunden\n\n";
        continue;
    }
    echo "== {$pageUrl} – " . count($entries) . " Fragen\n";
    $section = null;
    foreach ($entries as $e) {
        if ($e['section'] !== $section) {
            $section = $e['section'];
            echo "   [{$section}]\n";
        }
        echo sprintf("   %-9s %s (%d Links)\n", $e['key'], mb_strimwidth($e['title'], 0, 90, '…'), count($e['urls']));
        foreach ($e['urls'] as $u) {
            $allUrls[$u] = true;
            if ($verbose) {
                echo "             → {$u}\n";
            }
        }
    }
    $total += count($entries);
    echo "\n";
}
echo "Total: {$total} Fragen, " . count($allUrls) . " verschiedene URLs\n";
exit($total > 0 ? 0 : 1);
