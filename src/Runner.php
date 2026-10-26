<?php

declare(strict_types=1);

namespace LinkWatcher;

use LinkWatcher\Mail\Mailer;
use PDO;

/**
 * Orchestrates one complete run: lock → sync keys → check URLs → store → alert → cleanup.
 * Used by the nightly cron (bin/run.php) and by the "Jetzt prüfen" button in the web GUI.
 */
final class Runner
{
    /** @var resource|null */
    private $lock = null;
    private ?int $runId = null;
    private array $logLines = [];
    /** @var callable(string):void|null */
    private $echo;

    public function __construct(private Config $config, private PDO $pdo, ?callable $echo = null)
    {
        $this->echo = $echo;
    }

    public function isRunning(): bool
    {
        $fh = @fopen($this->lockPath(), 'c');
        if ($fh === false) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    /**
     * Acquires the lock and creates the run record.
     * @throws AlreadyRunningException
     */
    public function begin(string $trigger): int
    {
        $fh = fopen($this->lockPath(), 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            throw new AlreadyRunningException('Es läuft bereits eine Prüfung.');
        }
        $this->lock = $fh;

        // A run still marked "running" without holding the lock has crashed.
        $this->pdo->exec("UPDATE runs SET status = 'aborted', finished_at = COALESCE(finished_at, started_at) WHERE status = 'running'");

        $stmt = $this->pdo->prepare("INSERT INTO runs (trigger, started_at, status, log) VALUES (?, ?, 'running', '')");
        $stmt->execute([$trigger, Util::now()]);
        $this->runId = (int) $this->pdo->lastInsertId();
        return $this->runId;
    }

    /** Executes the run created by begin(). Never throws; failures are recorded on the run. */
    public function execute(bool $sendMail = true): array
    {
        if ($this->runId === null) {
            throw new \LogicException('begin() muss vor execute() aufgerufen werden.');
        }
        $runId = $this->runId;
        $startedAt = (string) $this->pdo->query("SELECT started_at FROM runs WHERE id = {$runId}")->fetchColumn();
        $result = ['run_id' => $runId, 'status' => 'failed'];

        try {
            $this->log('Prüflauf #' . $runId . ' gestartet');

            $sync = (new Sync($this->config, $this->pdo, fn(string $m) => $this->log($m)))->run();
            $this->log(sprintf(
                'Textkeys: %d aktiv, %d neu, %d geändert, %d archiviert, %d reaktiviert',
                $sync['keys_active'], $sync['keys_new'], $sync['keys_changed'], $sync['keys_archived'], $sync['keys_restored']
            ));

            $urls = $this->urlsToCheck();
            $this->log(count($urls) . ' eindeutige URLs werden geprüft …');
            $checks = (new Checker($this->config->get('checker'), fn(string $m) => $this->log($m)))->checkAll($urls);
            $outcome = $this->store($runId, $urls, $checks, $startedAt);

            $counts = [
                'checked' => count($checks),
                'ok' => count(array_filter($checks, static fn($c) => $c['status'] === 'ok')),
                'warning' => count($outcome['warnings']),
                'error' => count($outcome['errors']),
            ];
            $this->log("Ergebnis: {$counts['ok']} OK, {$counts['warning']} Warnungen, {$counts['error']} Fehler");

            $mailStatus = $this->alert($sendMail, [
                'run_id' => $runId,
                'started_at' => $startedAt,
                'errors' => $outcome['errors'],
                'warnings' => $outcome['warnings'],
                'recovered' => $outcome['recovered'],
                'source_errors' => $sync['source_errors'],
                'sync' => $sync,
                'counts' => $counts,
            ]);

            $this->purge();

            $status = ($outcome['errors'] || $sync['source_errors']) ? 'problems' : 'ok';
            $stmt = $this->pdo->prepare(<<<'SQL'
                UPDATE runs SET finished_at = ?, status = ?, keys_active = ?, keys_new = ?, keys_changed = ?, keys_archived = ?,
                    keys_restored = ?, source_errors = ?, urls_checked = ?, urls_ok = ?, urls_warning = ?, urls_error = ?, mail_status = ?
                WHERE id = ?
                SQL);
            $stmt->execute([
                Util::now(), $status, $sync['keys_active'], $sync['keys_new'], $sync['keys_changed'], $sync['keys_archived'],
                $sync['keys_restored'], count($sync['source_errors']), $counts['checked'], $counts['ok'], $counts['warning'],
                $counts['error'], $mailStatus, $runId,
            ]);
            $this->log('Prüflauf beendet');
            $this->heartbeat();
            $result = ['run_id' => $runId, 'status' => $status, 'counts' => $counts, 'mail' => $mailStatus];
        } catch (\Throwable $e) {
            $this->log('ABBRUCH: ' . $e->getMessage());
            $mailStatus = 'skipped';
            if ($sendMail && $this->config->get('alerts.enabled')) {
                try {
                    Mailer::fromConfig($this->config)->send(
                        'Prüflauf fehlgeschlagen',
                        "Der LinkWatcher-Prüflauf #{$runId} ist mit einem Fehler abgebrochen:\n\n{$e->getMessage()}\n\n{$e->getFile()}:{$e->getLine()}"
                    );
                    $mailStatus = 'sent';
                } catch (\Throwable $mailError) {
                    $mailStatus = 'failed';
                    $this->log('Mailversand fehlgeschlagen: ' . $mailError->getMessage());
                }
            }
            $this->pdo->prepare("UPDATE runs SET finished_at = ?, status = 'failed', mail_status = ? WHERE id = ?")
                ->execute([Util::now(), $mailStatus, $runId]);
            $result['error'] = $e->getMessage();
        } finally {
            $this->release();
        }

        return $result;
    }

    /** Checks a single URL immediately (dashboard "Jetzt prüfen" on the detail page). */
    public function checkSingle(int $urlId): array
    {
        $stmt = $this->pdo->prepare('SELECT url FROM urls WHERE id = ?');
        $stmt->execute([$urlId]);
        $url = $stmt->fetchColumn();
        if ($url === false) {
            throw new \RuntimeException('URL nicht gefunden.');
        }
        $cfg = $this->config->get('checker');
        $cfg['retry_attempts'] = 0;
        $checks = (new Checker($cfg))->checkAll([$urlId => (string) $url]);
        $this->store(null, [$urlId => (string) $url], $checks, Util::now());
        return $checks[$urlId];
    }

    /** @return array<int,string> id => url of all URLs referenced by active keys and not ignored */
    private function urlsToCheck(): array
    {
        $rows = $this->pdo->query(<<<'SQL'
            SELECT u.id, u.url FROM urls u
            WHERE u.ignored = 0 AND EXISTS (
                SELECT 1 FROM key_urls ku
                JOIN text_keys k ON k.id = ku.text_key_id AND k.archived_at IS NULL
                JOIN sources s ON s.id = k.source_id AND s.active = 1
                WHERE ku.url_id = u.id)
            ORDER BY u.url
            SQL)->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row['url'];
        }
        return $out;
    }

    /** Persists check results and works out what is new / still broken / recovered. */
    private function store(?int $runId, array $urls, array $checks, string $runStartedAt): array
    {
        $now = Util::now();
        $errors = [];
        $warnings = [];
        $recovered = [];

        $select = $this->pdo->prepare('SELECT status, failing_since, consecutive_failures FROM urls WHERE id = ?');
        $update = $this->pdo->prepare(<<<'SQL'
            UPDATE urls SET status = :status, message = :message, http_code = :http_code, final_url = :final_url,
                redirect_count = :redirect_count, response_ms = :response_ms, method = :method, last_checked_at = :now,
                last_ok_at = CASE WHEN :status <> 'error' THEN :now ELSE last_ok_at END,
                failing_since = :failing_since, consecutive_failures = :failures
            WHERE id = :id
            SQL);
        $insert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO checks (run_id, url_id, checked_at, status, message, http_code, final_url, redirect_count, response_ms, method, attempts)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL);

        $this->pdo->beginTransaction();
        foreach ($checks as $id => $c) {
            $select->execute([$id]);
            $prev = $select->fetch() ?: ['status' => 'pending', 'failing_since' => null, 'consecutive_failures' => 0];
            $isError = $c['status'] === 'error';
            $wasError = $prev['status'] === 'error';
            $failingSince = $isError ? ($wasError && $prev['failing_since'] ? $prev['failing_since'] : $now) : null;

            $update->execute([
                'status' => $c['status'], 'message' => $c['message'], 'http_code' => $c['http_code'],
                'final_url' => $c['final_url'], 'redirect_count' => $c['redirect_count'], 'response_ms' => $c['response_ms'],
                'method' => $c['method'], 'now' => $now, 'failing_since' => $failingSince,
                'failures' => $isError ? ((int) $prev['consecutive_failures'] + 1) : 0, 'id' => $id,
            ]);
            $insert->execute([
                $runId, $id, $now, $c['status'], $c['message'], $c['http_code'], $c['final_url'],
                $c['redirect_count'], $c['response_ms'], $c['method'], $c['attempts'],
            ]);

            $item = ['url_id' => $id, 'url' => $urls[$id], 'message' => $c['message'], 'failing_since' => $failingSince];
            if ($isError) {
                $errors[] = $item + ['is_new' => !$wasError];
            } elseif ($c['status'] === 'warning') {
                $warnings[] = $item + ['is_new' => $prev['status'] !== 'warning'];
            }
            if ($wasError && !$isError) {
                $recovered[] = $item;
            }
        }
        $this->pdo->commit();

        foreach ([&$errors, &$warnings] as &$list) {
            foreach ($list as &$item) {
                $item['keys'] = $this->keysForUrl($item['url_id']);
            }
            unset($item);
            // New problems first, then alphabetical.
            usort($list, static fn($a, $b) => [$b['is_new'], $a['url']] <=> [$a['is_new'], $b['url']]);
        }
        unset($list);

        return ['errors' => $errors, 'warnings' => $warnings, 'recovered' => $recovered];
    }

    private function keysForUrl(int $urlId): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT k.key_name, k.line, s.label, s.language, s.view_url
            FROM key_urls ku
            JOIN text_keys k ON k.id = ku.text_key_id AND k.archived_at IS NULL
            JOIN sources s ON s.id = k.source_id AND s.active = 1
            WHERE ku.url_id = ?
            ORDER BY k.key_name, s.label, s.language
            SQL);
        $stmt->execute([$urlId]);
        return $stmt->fetchAll();
    }

    private function alert(bool $sendMail, array $data): string
    {
        if (!$sendMail) {
            $this->log('Mailversand für diesen Lauf deaktiviert');
            return 'disabled';
        }
        if (!$this->config->get('alerts.enabled')) {
            return 'disabled';
        }

        $errors = $data['errors'];
        if ($this->config->get('alerts.only_new_problems')) {
            $hasProblem = array_filter($errors, static fn($e) => $e['is_new']) || $data['source_errors'];
        } else {
            $hasProblem = $errors || $data['source_errors'];
        }
        if (!$hasProblem && $this->config->get('alerts.on_warnings') && $data['warnings']) {
            $hasProblem = true;
        }
        if (!$hasProblem) {
            $this->log('Keine Probleme – keine Mail versendet');
            return 'skipped';
        }

        if (!$this->config->get('alerts.on_warnings')) {
            $data['warnings'] = [];
        }

        try {
            [$subject, $text, $html] = (new AlertMail($this->config))->build($data);
            Mailer::fromConfig($this->config)->send($subject, $text, $html);
            $this->log('Zusammenfassung per Mail versendet: ' . $subject);
            return 'sent';
        } catch (\Throwable $e) {
            $this->log('Mailversand fehlgeschlagen: ' . $e->getMessage());
            return 'failed';
        }
    }

    private function purge(): void
    {
        $days = (int) $this->config->get('history_days', 90);
        if ($days <= 0) {
            return;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $this->pdo->prepare('DELETE FROM checks WHERE checked_at < ?')->execute([$cutoff]);
        $this->pdo->prepare("DELETE FROM runs WHERE started_at < ? AND status <> 'running'")->execute([$cutoff]);
    }

    /** Optional ping to an external monitor (e.g. healthchecks.io) so a dead cron gets noticed. */
    private function heartbeat(): void
    {
        $url = (string) $this->config->get('alerts.heartbeat_url');
        if ($url === '') {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        if (curl_exec($ch) === false) {
            $this->log('Heartbeat-Ping fehlgeschlagen: ' . curl_error($ch));
        }
        curl_close($ch);
    }

    private function log(string $message): void
    {
        $line = '[' . date('H:i:s') . '] ' . $message;
        $this->logLines[] = $line;
        if ($this->echo) {
            ($this->echo)($line);
        }
        if ($this->runId !== null) {
            $this->pdo->prepare('UPDATE runs SET log = ? WHERE id = ?')->execute([implode("\n", $this->logLines), $this->runId]);
        }
    }

    private function release(): void
    {
        if ($this->lock) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function lockPath(): string
    {
        $path = (string) $this->config->get('lock_file');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $path;
    }
}
