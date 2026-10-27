<?php

declare(strict_types=1);

namespace LinkWatcher\Web;

use LinkWatcher\AlreadyRunningException;
use LinkWatcher\Config;
use LinkWatcher\Database;
use LinkWatcher\Mail\Mailer;
use LinkWatcher\Runner;
use LinkWatcher\Util;
use PDO;

final class App
{
    private const MAX_LOGIN_FAILS = 5;
    private const LOCKOUT_SECONDS = 900;

    private Config $config;
    private PDO $pdo;

    public function handle(): void
    {
        $this->securityHeaders();

        try {
            $this->config = Config::load();
            $this->pdo = Database::open($this->config->get('db_path'));
        } catch (\Throwable $e) {
            http_response_code(500);
            $this->render('error', ['title' => 'Konfiguration', 'message' => $e->getMessage()], false);
            return;
        }

        $this->startSession();
        $page = (string) ($_GET['p'] ?? 'dashboard');

        if ($page === 'login') {
            $this->login();
            return;
        }
        if (!$this->isLoggedIn()) {
            $this->redirect('?p=login');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->verifyCsrf();
            $this->action((string) ($_POST['action'] ?? ''));
            return;
        }

        match ($page) {
            'dashboard' => $this->dashboard(),
            'matrix' => $this->matrix(),
            'keys' => $this->keys(),
            'url' => $this->urlDetail(),
            'runs' => $this->runs(),
            'run' => $this->runDetail(),
            'settings' => $this->settings(),
            'logout' => $this->logout(),
            default => $this->notFound(),
        };
    }

    // ------------------------------------------------------------------ pages

    private function dashboard(): void
    {
        $urls = $this->activeUrls();
        $counts = ['total' => count($urls), 'ok' => 0, 'warning' => 0, 'error' => 0, 'pending' => 0, 'ignored' => 0];
        foreach ($urls as $u) {
            $counts[$u['display_status']]++;
        }

        $status = (string) ($_GET['status'] ?? '');
        $lang = (string) ($_GET['lang'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));
        $filtered = array_filter($urls, static function (array $u) use ($status, $lang, $q): bool {
            if ($status !== '' && $u['display_status'] !== $status) {
                return false;
            }
            if ($lang !== '' && !in_array($lang, $u['languages'], true)) {
                return false;
            }
            if ($q !== '' && stripos($u['url'] . ' ' . implode(' ', $u['key_names']), $q) === false) {
                return false;
            }
            return true;
        });

        $this->render('dashboard', [
            'title' => 'Übersicht',
            'urls' => $filtered,
            'counts' => $counts,
            'filters' => ['status' => $status, 'lang' => $lang, 'q' => $q],
            'languages' => $this->config->languages(),
            'keyCounts' => $this->keyCounts(),
            'lastRun' => $this->pdo->query('SELECT * FROM runs ORDER BY id DESC LIMIT 1')->fetch() ?: null,
        ]);
    }

    private function matrix(): void
    {
        $rows = $this->pdo->query(<<<'SQL'
            SELECT k.key_name, s.language, s.label, u.status, u.ignored
            FROM text_keys k
            JOIN sources s ON s.id = k.source_id AND s.active = 1
            LEFT JOIN key_urls ku ON ku.text_key_id = k.id
            LEFT JOIN urls u ON u.id = ku.url_id
            WHERE k.archived_at IS NULL
            ORDER BY k.key_name
            SQL)->fetchAll();

        $labels = [];
        foreach ($this->config->get('sources', []) as $group) {
            $labels[] = (string) ($group['label'] ?? '');
        }
        $matrix = [];
        foreach ($rows as $r) {
            $st = $r['ignored'] ? 'ignored' : ($r['status'] ?? 'pending');
            $cell = &$matrix[$r['key_name']][$r['language']][$r['label']];
            if ($cell === null || Util::severity($st) > Util::severity($cell)) {
                $cell = $st;
            }
            unset($cell);
        }

        $this->render('matrix', [
            'title' => 'Matrix',
            'matrix' => $matrix,
            'languages' => $this->config->languages(),
            'labels' => $labels,
        ]);
    }

    private function keys(): void
    {
        $show = (string) ($_GET['show'] ?? 'active');
        $q = trim((string) ($_GET['q'] ?? ''));
        $where = match ($show) {
            'archived' => 'WHERE (k.archived_at IS NOT NULL OR s.active = 0)',
            'all' => 'WHERE 1 = 1',
            default => 'WHERE k.archived_at IS NULL AND s.active = 1',
        };
        $params = [];
        if ($q !== '') {
            $where .= ' AND (k.key_name LIKE ? OR k.value LIKE ?)';
            $params = ["%{$q}%", "%{$q}%"];
        }
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT k.*, s.label, s.language, s.view_url, s.active AS source_active,
                   (SELECT GROUP_CONCAT(u.id || '|' || CASE WHEN u.ignored = 1 THEN 'ignored' ELSE u.status END || '|' || u.url, char(10))
                      FROM key_urls ku JOIN urls u ON u.id = ku.url_id WHERE ku.text_key_id = k.id) AS url_info
            FROM text_keys k JOIN sources s ON s.id = k.source_id
            {$where}
            ORDER BY k.key_name, s.label, s.language
            SQL);
        $stmt->execute($params);
        $keys = $stmt->fetchAll();
        foreach ($keys as &$k) {
            $k['urls'] = [];
            foreach (array_filter(explode("\n", (string) $k['url_info'])) as $info) {
                [$id, $st, $url] = explode('|', $info, 3);
                $k['urls'][] = ['id' => (int) $id, 'status' => $st, 'url' => $url];
            }
        }
        unset($k);

        $this->render('keys', [
            'title' => 'Textkeys',
            'keys' => $keys,
            'show' => $show,
            'q' => $q,
            'keyCounts' => $this->keyCounts(),
        ]);
    }

    private function urlDetail(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM urls WHERE id = ?');
        $stmt->execute([$id]);
        $url = $stmt->fetch();
        if (!$url) {
            $this->notFound();
            return;
        }
        $keys = $this->pdo->prepare(<<<'SQL'
            SELECT k.*, s.label, s.language, s.view_url, s.active AS source_active
            FROM key_urls ku JOIN text_keys k ON k.id = ku.text_key_id JOIN sources s ON s.id = k.source_id
            WHERE ku.url_id = ? ORDER BY (k.archived_at IS NOT NULL), k.key_name, s.label, s.language
            SQL);
        $keys->execute([$id]);
        $history = $this->pdo->prepare('SELECT * FROM checks WHERE url_id = ? ORDER BY checked_at DESC, id DESC LIMIT 90');
        $history->execute([$id]);

        $this->render('url', [
            'title' => 'URL',
            'url' => $url,
            'keys' => $keys->fetchAll(),
            'history' => $history->fetchAll(),
        ]);
    }

    private function runs(): void
    {
        $this->render('runs', [
            'title' => 'Prüfläufe',
            'runs' => $this->pdo->query('SELECT id, trigger, started_at, finished_at, status, urls_checked, urls_ok, urls_warning, urls_error, source_errors, keys_new, keys_archived, mail_status FROM runs ORDER BY id DESC LIMIT 200')->fetchAll(),
            'running' => (new Runner($this->config, $this->pdo))->isRunning(),
        ]);
    }

    private function runDetail(): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM runs WHERE id = ?');
        $stmt->execute([(int) ($_GET['id'] ?? 0)]);
        $run = $stmt->fetch();
        if (!$run) {
            $this->notFound();
            return;
        }
        $problems = $this->pdo->prepare(<<<'SQL'
            SELECT c.*, u.url FROM checks c JOIN urls u ON u.id = c.url_id
            WHERE c.run_id = ? AND c.status <> 'ok' ORDER BY CASE c.status WHEN 'error' THEN 0 ELSE 1 END, u.url
            SQL);
        $problems->execute([$run['id']]);

        $this->render('run', [
            'title' => 'Prüflauf #' . $run['id'],
            'run' => $run,
            'problems' => $problems->fetchAll(),
            'refresh' => $run['status'] === 'running' ? 3 : null,
        ]);
    }

    private function settings(): void
    {
        $mailer = Mailer::fromConfig($this->config);
        $this->render('settings', [
            'title' => 'Einstellungen',
            'sources' => $this->pdo->query('SELECT * FROM sources ORDER BY active DESC, label, language')->fetchAll(),
            'mail' => $this->config->get('mail'),
            'mailConfigured' => $mailer->isConfigured(),
            'recipients' => $mailer->recipients(),
            'checker' => $this->config->get('checker'),
            'alerts' => $this->config->get('alerts'),
            'lastCron' => $this->pdo->query("SELECT started_at FROM runs WHERE trigger = 'cron' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null,
            'root' => LW_ROOT,
        ]);
    }

    // ---------------------------------------------------------------- actions

    private function action(string $action): void
    {
        switch ($action) {
            case 'run':
                $this->startRun(!empty($_POST['send_mail']));
                return;

            case 'recheck':
                $id = (int) ($_POST['id'] ?? 0);
                try {
                    $result = (new Runner($this->config, $this->pdo))->checkSingle($id);
                    $this->flash($result['status'] === 'error' ? 'error' : 'success', 'Geprüft: ' . $result['message']);
                } catch (\Throwable $e) {
                    $this->flash('error', $e->getMessage());
                }
                $this->redirect('?p=url&id=' . $id);

            case 'ignore':
            case 'unignore':
                $id = (int) ($_POST['id'] ?? 0);
                $ignore = $action === 'ignore';
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $this->pdo->prepare('UPDATE urls SET ignored = ?, ignore_reason = ? WHERE id = ?')
                    ->execute([$ignore ? 1 : 0, $ignore ? ($reason !== '' ? mb_substr($reason, 0, 300) : null) : null, $id]);
                $this->flash('success', $ignore ? 'URL wird nicht mehr geprüft.' : 'URL wird wieder überwacht.');
                $this->redirect('?p=url&id=' . $id);

            case 'test_mail':
                try {
                    $mailer = Mailer::fromConfig($this->config);
                    $mailer->send('Testmail', "Die Mailkonfiguration von LinkWatcher funktioniert.\n\nAusgelöst im Dashboard am " . date('d.m.Y H:i') . '.');
                    $this->flash('success', 'Testmail versendet an ' . implode(', ', $mailer->recipients()) . '.');
                } catch (\Throwable $e) {
                    $this->flash('error', 'Testmail fehlgeschlagen: ' . $e->getMessage());
                }
                $this->redirect('?p=settings');

            default:
                $this->redirect('?p=dashboard');
        }
    }

    /**
     * Starts a run from the browser: answer with a redirect to the live run page right away,
     * then keep working in the same PHP process (works with PHP-FPM / LiteSpeed; otherwise the
     * browser simply waits until the run is done).
     */
    private function startRun(bool $sendMail): void
    {
        $runner = new Runner($this->config, $this->pdo);
        try {
            $runId = $runner->begin('manual');
        } catch (AlreadyRunningException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('?p=runs');
        }

        ignore_user_abort(true);
        set_time_limit(0);
        session_write_close();

        header('Location: ?p=run&id=' . $runId, true, 303);
        header('Connection: close');
        header('Content-Length: 0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        $runner->execute($sendMail);
        exit;
    }

    // ------------------------------------------------------------------- auth

    private function login(): void
    {
        if ($this->isLoggedIn()) {
            $this->redirect('?p=dashboard');
        }
        $error = null;
        $hash = (string) $this->config->get('admin.password_hash');

        if ($hash === '') {
            $error = 'Es ist noch kein Passwort gesetzt. Erzeuge einen Hash mit „php bin/hash-password.php“ und trage ihn in config.php unter admin.password_hash ein.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->verifyCsrf();
            $ipKey = 'login_fail:' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $fails = $this->loginFails($ipKey);

            if ($fails['count'] >= self::MAX_LOGIN_FAILS && time() - $fails['since'] < self::LOCKOUT_SECONDS) {
                $error = 'Zu viele Fehlversuche. Bitte in ' . (int) ceil((self::LOCKOUT_SECONDS - (time() - $fails['since'])) / 60) . ' Minuten erneut versuchen.';
            } elseif (hash_equals((string) $this->config->get('admin.username'), (string) ($_POST['username'] ?? ''))
                && password_verify((string) ($_POST['password'] ?? ''), $hash)) {
                $this->pdo->prepare('DELETE FROM meta WHERE k = ?')->execute([$ipKey]);
                session_regenerate_id(true);
                $_SESSION['user'] = $this->config->get('admin.username');
                $this->redirect('?p=dashboard');
            } else {
                usleep(random_int(300000, 700000));
                $fails = ($fails['count'] >= self::MAX_LOGIN_FAILS || time() - $fails['since'] > self::LOCKOUT_SECONDS)
                    ? ['count' => 1, 'since' => time()]
                    : ['count' => $fails['count'] + 1, 'since' => $fails['since']];
                $this->pdo->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$ipKey, json_encode($fails)]);
                $error = 'Benutzername oder Passwort ist falsch.';
            }
        }

        $this->render('login', ['title' => 'Anmelden', 'error' => $error], false);
    }

    /** @return array{count:int,since:int} */
    private function loginFails(string $key): array
    {
        $stmt = $this->pdo->prepare('SELECT v FROM meta WHERE k = ?');
        $stmt->execute([$key]);
        $data = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($data) ? ['count' => (int) $data['count'], 'since' => (int) $data['since']] : ['count' => 0, 'since' => time()];
    }

    private function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->redirect('?p=login');
    }

    private function isLoggedIn(): bool
    {
        return !empty($_SESSION['user']);
    }

    private function startSession(): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('LWSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    private function verifyCsrf(): void
    {
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
            http_response_code(400);
            exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
        }
    }

    // ---------------------------------------------------------------- helpers

    /** All URLs referenced by at least one active key, with aggregated key info. */
    private function activeUrls(): array
    {
        $rows = $this->pdo->query(<<<'SQL'
            SELECT u.*, k.key_name, s.language, s.label
            FROM urls u
            JOIN key_urls ku ON ku.url_id = u.id
            JOIN text_keys k ON k.id = ku.text_key_id AND k.archived_at IS NULL
            JOIN sources s ON s.id = k.source_id AND s.active = 1
            SQL)->fetchAll();

        $urls = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!isset($urls[$id])) {
                $urls[$id] = $r + [
                    'display_status' => $r['ignored'] ? 'ignored' : $r['status'],
                    'key_names' => [],
                    'languages' => [],
                    'refs' => 0,
                ];
            }
            $urls[$id]['key_names'][$r['key_name']] = $r['key_name'];
            $urls[$id]['languages'][$r['language']] = $r['language'];
            $urls[$id]['refs']++;
        }

        $order = array_flip($this->config->languages());
        foreach ($urls as &$u) {
            $u['key_names'] = array_values($u['key_names']);
            $langs = array_values($u['languages']);
            usort($langs, static fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
            $u['languages'] = $langs;
        }
        unset($u);

        $rank = ['error' => 0, 'warning' => 1, 'pending' => 2, 'ok' => 3, 'ignored' => 4];
        usort($urls, static fn($a, $b) => [$rank[$a['display_status']] ?? 9, $a['url']] <=> [$rank[$b['display_status']] ?? 9, $b['url']]);
        return $urls;
    }

    private function keyCounts(): array
    {
        return [
            'active' => (int) $this->pdo->query('SELECT COUNT(*) FROM text_keys k JOIN sources s ON s.id = k.source_id WHERE k.archived_at IS NULL AND s.active = 1')->fetchColumn(),
            'archived' => (int) $this->pdo->query('SELECT COUNT(*) FROM text_keys k JOIN sources s ON s.id = k.source_id WHERE k.archived_at IS NOT NULL OR s.active = 0')->fetchColumn(),
        ];
    }

    /** Alert banners shown on every page (stale cron, broken source files, running check). */
    private function banners(): array
    {
        $banners = [];
        $lastCron = $this->pdo->query("SELECT started_at FROM runs WHERE trigger = 'cron' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
        $staleAfter = (float) $this->config->get('alerts.stale_after_hours', 26);
        $hours = Util::hoursSince($lastCron);
        if ($hours === null) {
            $banners[] = ['warning', 'Es wurde noch kein nächtlicher Cron-Lauf erfasst. Ist der Cronjob eingerichtet? (siehe Einstellungen)'];
        } elseif ($hours > $staleAfter) {
            $banners[] = ['error', 'Der letzte Cron-Lauf war ' . Util::ago($lastCron) . ' (' . Util::fmt($lastCron) . '). Der Cronjob läuft offenbar nicht mehr.'];
        }
        $broken = $this->pdo->query('SELECT label, language, last_error FROM sources WHERE active = 1 AND last_fetch_ok = 0')->fetchAll();
        foreach ($broken as $s) {
            $banners[] = ['error', "Quelldatei {$s['label']} [{$s['language']}] konnte nicht gelesen werden: {$s['last_error']}"];
        }
        return $banners;
    }

    private function render(string $view, array $vars, bool $withLayout = true): void
    {
        $vars['appName'] = isset($this->config) ? (string) $this->config->get('app.name', 'LinkWatcher') : 'LinkWatcher';
        $vars['csrf'] = $_SESSION['csrf'] ?? '';
        $vars['page'] = (string) ($_GET['p'] ?? 'dashboard');
        $vars['flash'] = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        if ($withLayout) {
            $vars['banners'] = $this->banners();
            $vars['running'] = (new Runner($this->config, $this->pdo))->isRunning();
        }

        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/views/' . $view . '.php';
        $content = ob_get_clean();

        if ($withLayout) {
            require __DIR__ . '/views/layout.php';
        } else {
            require __DIR__ . '/views/bare.php';
        }
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = [$type, $message];
    }

    private function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error', ['title' => 'Nicht gefunden', 'message' => 'Diese Seite existiert nicht.']);
    }

    private function securityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
    }
}
