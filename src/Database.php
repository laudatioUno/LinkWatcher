<?php

declare(strict_types=1);

namespace LinkWatcher;

use PDO;

final class Database
{
    private const SCHEMA_VERSION = 1;

    public static function open(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Datenverzeichnis kann nicht erstellt werden: {$dir}");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
        $version = (int) ($pdo->query("SELECT v FROM meta WHERE k = 'schema_version'")->fetchColumn() ?: 0);
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        $pdo->beginTransaction();
        if ($version < 1) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE sources (
                    id              INTEGER PRIMARY KEY,
                    source_key      TEXT NOT NULL UNIQUE,
                    label           TEXT NOT NULL,
                    language        TEXT NOT NULL,
                    format          TEXT NOT NULL,
                    fetch_url       TEXT NOT NULL,
                    view_url        TEXT,
                    content_hash    TEXT,
                    last_fetched_at TEXT,
                    last_fetch_ok   INTEGER,
                    last_error      TEXT,
                    key_count       INTEGER NOT NULL DEFAULT 0,
                    active          INTEGER NOT NULL DEFAULT 1
                );

                CREATE TABLE text_keys (
                    id             INTEGER PRIMARY KEY,
                    source_id      INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
                    key_name       TEXT NOT NULL,
                    value          TEXT NOT NULL,
                    line           INTEGER,
                    first_seen_at  TEXT NOT NULL,
                    last_seen_at   TEXT NOT NULL,
                    changed_at     TEXT,
                    archived_at    TEXT,
                    archive_reason TEXT,
                    UNIQUE (source_id, key_name)
                );
                CREATE INDEX idx_text_keys_name ON text_keys (key_name);

                CREATE TABLE urls (
                    id                   INTEGER PRIMARY KEY,
                    url                  TEXT NOT NULL UNIQUE,
                    status               TEXT NOT NULL DEFAULT 'pending',
                    message              TEXT,
                    http_code            INTEGER,
                    final_url            TEXT,
                    redirect_count       INTEGER,
                    response_ms          INTEGER,
                    method               TEXT,
                    last_checked_at      TEXT,
                    last_ok_at           TEXT,
                    failing_since        TEXT,
                    consecutive_failures INTEGER NOT NULL DEFAULT 0,
                    ignored              INTEGER NOT NULL DEFAULT 0,
                    ignore_reason        TEXT,
                    first_seen_at        TEXT NOT NULL
                );

                CREATE TABLE key_urls (
                    text_key_id INTEGER NOT NULL REFERENCES text_keys(id) ON DELETE CASCADE,
                    url_id      INTEGER NOT NULL REFERENCES urls(id) ON DELETE CASCADE,
                    PRIMARY KEY (text_key_id, url_id)
                );
                CREATE INDEX idx_key_urls_url ON key_urls (url_id);

                CREATE TABLE runs (
                    id            INTEGER PRIMARY KEY,
                    trigger       TEXT NOT NULL,
                    started_at    TEXT NOT NULL,
                    finished_at   TEXT,
                    status        TEXT NOT NULL,
                    keys_active   INTEGER,
                    keys_new      INTEGER,
                    keys_changed  INTEGER,
                    keys_archived INTEGER,
                    keys_restored INTEGER,
                    source_errors INTEGER,
                    urls_checked  INTEGER,
                    urls_ok       INTEGER,
                    urls_warning  INTEGER,
                    urls_error    INTEGER,
                    mail_status   TEXT,
                    log           TEXT
                );

                CREATE TABLE checks (
                    id             INTEGER PRIMARY KEY,
                    run_id         INTEGER REFERENCES runs(id) ON DELETE SET NULL,
                    url_id         INTEGER NOT NULL REFERENCES urls(id) ON DELETE CASCADE,
                    checked_at     TEXT NOT NULL,
                    status         TEXT NOT NULL,
                    message        TEXT,
                    http_code      INTEGER,
                    final_url      TEXT,
                    redirect_count INTEGER,
                    response_ms    INTEGER,
                    method         TEXT,
                    attempts       INTEGER
                );
                CREATE INDEX idx_checks_url ON checks (url_id, checked_at);
                CREATE INDEX idx_checks_run ON checks (run_id);
                SQL);
        }
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('schema_version', ?)");
        $stmt->execute([(string) self::SCHEMA_VERSION]);
        $pdo->commit();
    }
}
