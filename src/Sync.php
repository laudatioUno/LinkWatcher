<?php

declare(strict_types=1);

namespace LinkWatcher;

use LinkWatcher\Parser\ParserFactory;
use LinkWatcher\Parser\UrlExtractor;
use PDO;

/**
 * Fetches all configured source files and synchronises the text keys that contain URLs.
 * New keys are added, changed keys updated, vanished keys archived (and restored if they come back).
 */
final class Sync
{
    /** @var callable(string):void */
    private $log;

    public function __construct(
        private Config $config,
        private PDO $pdo,
        ?callable $log = null,
        private ?SourceFetcher $fetcher = null,
    ) {
        $this->log = $log ?? static function (string $m): void {
        };
        $this->fetcher ??= new SourceFetcher($config->get('checker'));
    }

    /**
     * @return array{keys_new:int,keys_changed:int,keys_archived:int,keys_restored:int,keys_active:int,
     *               source_errors:list<array{label:string,language:string,error:string,view_url:?string}>,
     *               new_keys:list<string>,archived_keys:list<string>}
     */
    public function run(): array
    {
        $stats = [
            'keys_new' => 0, 'keys_changed' => 0, 'keys_archived' => 0, 'keys_restored' => 0, 'keys_active' => 0,
            'source_errors' => [], 'new_keys' => [], 'archived_keys' => [],
        ];

        $files = $this->config->sourceFiles();
        $configuredKeys = array_column($files, 'source_key');
        $this->deactivateRemovedSources($configuredKeys, $stats);

        foreach ($files as $file) {
            $name = "{$file['label']} [{$file['language']}]";
            $sourceId = $this->upsertSource($file);

            $result = $this->fetcher->fetch($file['fetch_url']);
            if (!$result['ok']) {
                $this->sourceError($sourceId, $file, (string) $result['error'], $stats);
                ($this->log)("✗ {$name}: {$result['error']}");
                continue;
            }

            try {
                $entries = ParserFactory::for($file['format'])->parse($result['body']);
            } catch (\Throwable $e) {
                $this->sourceError($sourceId, $file, 'Parser: ' . $e->getMessage(), $stats);
                ($this->log)("✗ {$name}: Parser – {$e->getMessage()}");
                continue;
            }

            $withUrls = $this->filterEntries($entries);
            $previousCount = (int) $this->pdo->query("SELECT key_count FROM sources WHERE id = {$sourceId}")->fetchColumn();

            // Safety net: a file that suddenly has no URL keys at all is far more likely broken than intentional.
            if ($withUrls === [] && $previousCount > 0) {
                $msg = 'Datei enthält keine Textkeys mit URLs mehr – Archivierung übersprungen, bitte prüfen.';
                $this->sourceError($sourceId, $file, $msg, $stats);
                ($this->log)("✗ {$name}: {$msg}");
                continue;
            }

            $this->syncKeys($sourceId, $file, $withUrls, $stats);

            $stmt = $this->pdo->prepare(
                'UPDATE sources SET content_hash = ?, last_fetched_at = ?, last_fetch_ok = 1, last_error = NULL, key_count = ? WHERE id = ?'
            );
            $stmt->execute([hash('sha256', $result['body']), Util::now(), count($withUrls), $sourceId]);
            ($this->log)("✓ {$name}: " . count($entries) . ' Keys gelesen, ' . count($withUrls) . ' mit URL');
        }

        $stats['keys_active'] = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM text_keys k JOIN sources s ON s.id = k.source_id WHERE k.archived_at IS NULL AND s.active = 1'
        )->fetchColumn();

        return $stats;
    }

    /** Keeps entries that contain at least one URL and match the optional key filter. */
    private function filterEntries(array $entries): array
    {
        $filter = $this->config->get('key_filter');
        $out = [];
        foreach ($entries as $entry) {
            if ($filter && !preg_match($filter, $entry['key'])) {
                continue;
            }
            $urls = UrlExtractor::extract($entry['value']);
            if ($urls === []) {
                continue;
            }
            $entry['urls'] = $urls;
            $out[$entry['key']] = $entry; // last definition wins on duplicate keys
        }
        return $out;
    }

    private function syncKeys(int $sourceId, array $file, array $entries, array &$stats): void
    {
        $now = Util::now();
        $label = "{$file['label']} [{$file['language']}]";

        $this->pdo->beginTransaction();
        try {
            $existing = [];
            $stmt = $this->pdo->prepare('SELECT * FROM text_keys WHERE source_id = ?');
            $stmt->execute([$sourceId]);
            foreach ($stmt as $row) {
                $existing[$row['key_name']] = $row;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO text_keys (source_id, key_name, value, line, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $update = $this->pdo->prepare(
                'UPDATE text_keys SET value = ?, line = ?, last_seen_at = ?, changed_at = ?, archived_at = NULL, archive_reason = NULL WHERE id = ?'
            );

            foreach ($entries as $key => $entry) {
                if (!isset($existing[$key])) {
                    $insert->execute([$sourceId, $key, $entry['value'], $entry['line'], $now, $now]);
                    $keyId = (int) $this->pdo->lastInsertId();
                    $stats['keys_new']++;
                    $stats['new_keys'][] = "{$key} ({$label})";
                } else {
                    $row = $existing[$key];
                    $keyId = (int) $row['id'];
                    $changed = $row['value'] !== $entry['value'];
                    if ($row['archived_at'] !== null) {
                        $stats['keys_restored']++;
                    } elseif ($changed) {
                        $stats['keys_changed']++;
                    }
                    $update->execute([$entry['value'], $entry['line'], $now, $changed ? $now : $row['changed_at'], $keyId]);
                }
                $this->linkUrls($keyId, $entry['urls'], $now);
            }

            $archive = $this->pdo->prepare("UPDATE text_keys SET archived_at = ?, archive_reason = 'removed' WHERE id = ?");
            foreach ($existing as $key => $row) {
                if (!isset($entries[$key]) && $row['archived_at'] === null) {
                    $archive->execute([$now, $row['id']]);
                    $stats['keys_archived']++;
                    $stats['archived_keys'][] = "{$key} ({$label})";
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function linkUrls(int $keyId, array $urls, string $now): void
    {
        $this->pdo->prepare('DELETE FROM key_urls WHERE text_key_id = ?')->execute([$keyId]);
        $insertUrl = $this->pdo->prepare('INSERT OR IGNORE INTO urls (url, first_seen_at) VALUES (?, ?)');
        $findUrl = $this->pdo->prepare('SELECT id FROM urls WHERE url = ?');
        $link = $this->pdo->prepare('INSERT OR IGNORE INTO key_urls (text_key_id, url_id) VALUES (?, ?)');
        foreach ($urls as $url) {
            $insertUrl->execute([$url, $now]);
            $findUrl->execute([$url]);
            $link->execute([$keyId, (int) $findUrl->fetchColumn()]);
        }
    }

    private function upsertSource(array $file): int
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO sources (source_key, label, language, format, fetch_url, view_url, active)
            VALUES (:source_key, :label, :language, :format, :fetch_url, :view_url, 1)
            ON CONFLICT (source_key) DO UPDATE SET
                label = excluded.label, language = excluded.language, format = excluded.format,
                fetch_url = excluded.fetch_url, view_url = excluded.view_url, active = 1
            SQL);
        $stmt->execute($file);
        $find = $this->pdo->prepare('SELECT id FROM sources WHERE source_key = ?');
        $find->execute([$file['source_key']]);
        return (int) $find->fetchColumn();
    }

    private function sourceError(int $sourceId, array $file, string $error, array &$stats): void
    {
        $this->pdo->prepare('UPDATE sources SET last_fetched_at = ?, last_fetch_ok = 0, last_error = ? WHERE id = ?')
            ->execute([Util::now(), $error, $sourceId]);
        $stats['source_errors'][] = [
            'label' => $file['label'],
            'language' => $file['language'],
            'error' => $error,
            'view_url' => $file['view_url'],
        ];
    }

    /** Sources removed from the config are deactivated and their keys archived. */
    private function deactivateRemovedSources(array $configuredKeys, array &$stats): void
    {
        $rows = $this->pdo->query('SELECT id, source_key FROM sources WHERE active = 1')->fetchAll();
        foreach ($rows as $row) {
            if (in_array($row['source_key'], $configuredKeys, true)) {
                continue;
            }
            $this->pdo->prepare('UPDATE sources SET active = 0 WHERE id = ?')->execute([$row['id']]);
            $stmt = $this->pdo->prepare(
                "UPDATE text_keys SET archived_at = ?, archive_reason = 'source_removed' WHERE source_id = ? AND archived_at IS NULL"
            );
            $stmt->execute([Util::now(), $row['id']]);
            $stats['keys_archived'] += $stmt->rowCount();
            ($this->log)("Quelle {$row['source_key']} ist nicht mehr konfiguriert – {$stmt->rowCount()} Keys archiviert");
        }
    }
}
