<?php

use LinkWatcher\Util;

/** @var array $sources @var array $mail @var bool $mailConfigured @var array $recipients @var array $checker @var array $alerts @var ?string $lastCron @var string $root @var string $csrf */
$yesNo = static fn($v) => $v ? 'ja' : 'nein';
?>
<div class="page-head">
    <h1>Einstellungen</h1>
    <p class="muted">Konfiguriert wird in <code>config/config.php</code> – hier siehst du den aktuellen Stand.</p>
</div>

<section class="card">
    <h2>Quelldateien</h2>
    <?php if (!$sources): ?>
        <p class="empty">Noch keine Quellen synchronisiert. Trage sie in <code>config.php</code> unter <code>sources</code> ein und starte eine Prüfung.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Quelle</th><th>Format</th><th>Datei</th><th class="right">Keys mit URL</th><th>Letzter Abruf</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($sources as $s): ?>
                    <tr class="<?= $s['active'] ? '' : 'is-archived' ?>">
                        <td class="nowrap"><?= h($s['label']) ?> <span class="lang"><?= h(strtoupper($s['language'])) ?></span></td>
                        <td class="small"><?= h($s['format']) ?></td>
                        <td class="url-cell small"><a href="<?= h($s['view_url'] ?: $s['fetch_url']) ?>" target="_blank" rel="noopener noreferrer" class="url-link"><?= h($s['view_url'] ?: $s['fetch_url']) ?></a></td>
                        <td class="right"><?= (int) $s['key_count'] ?></td>
                        <td class="nowrap small"><?= h(Util::fmt($s['last_fetched_at'])) ?></td>
                        <td>
                            <?php if (!$s['active']): ?><span class="pill pill-ignored">entfernt</span>
                            <?php elseif ($s['last_fetch_ok'] === null): ?><span class="pill pill-pending">ungeprüft</span>
                            <?php elseif ((int) $s['last_fetch_ok'] === 1): ?><span class="pill pill-ok">OK</span>
                            <?php else: ?><span class="pill pill-error" title="<?= h($s['last_error']) ?>">Fehler</span>
                                <div class="small text-error"><?= h($s['last_error']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="card">
        <h2>Mailversand</h2>
        <dl class="facts">
            <dt>Status</dt><dd><?= $mailConfigured ? '<span class="pill pill-ok">konfiguriert</span>' : '<span class="pill pill-error">unvollständig</span>' ?></dd>
            <dt>Transport</dt><dd><?= h($mail['transport']) ?></dd>
            <?php if ($mail['transport'] === 'smtp'): ?>
                <dt>Server</dt><dd><?= h($mail['smtp']['host'] ?: '–') ?>:<?= (int) $mail['smtp']['port'] ?> (<?= h($mail['smtp']['encryption']) ?>)</dd>
                <dt>Benutzer</dt><dd><?= h($mail['smtp']['username'] ?: '–') ?></dd>
                <dt>Passwort</dt><dd><?= $mail['smtp']['password'] !== '' ? 'hinterlegt' : 'nicht gesetzt' ?></dd>
            <?php endif; ?>
            <dt>Absender</dt><dd><?= h($mail['from'] ?: '–') ?></dd>
            <dt>Empfänger</dt><dd><?= h($recipients ? implode(', ', $recipients) : '–') ?></dd>
        </dl>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="test_mail">
            <button class="btn" type="submit" <?= $mailConfigured ? '' : 'disabled' ?>>Testmail senden</button>
        </form>
    </section>

    <section class="card">
        <h2>Alarmierung &amp; Prüfung</h2>
        <dl class="facts">
            <dt>Mail bei Fehlern</dt><dd><?= $yesNo($alerts['enabled']) ?><?= $alerts['only_new_problems'] ? ' (nur neue Fehler)' : ' (jede Nacht, solange Fehler bestehen)' ?></dd>
            <dt>Mail bei Warnungen</dt><dd><?= $yesNo($alerts['on_warnings']) ?></dd>
            <dt>Letzter Cron-Lauf</dt><dd><?= h(Util::fmt($lastCron)) ?> (<?= h(Util::ago($lastCron)) ?>)</dd>
            <dt>Heartbeat</dt><dd><?= $alerts['heartbeat_url'] ? 'aktiv' : 'aus' ?></dd>
            <dt>Timeout</dt><dd><?= (int) $checker['timeout'] ?> s, <?= (int) $checker['max_concurrency'] ?> parallel (max. <?= (int) $checker['max_per_host'] ?> pro Host)</dd>
            <dt>Wiederholung</dt><dd><?= (int) $checker['retry_attempts'] ?>× nach <?= (int) $checker['retry_delay'] ?> s</dd>
            <dt>Warnung bei</dt><dd>Weiterleitung: <?= $yesNo($checker['redirect_is_warning']) ?>, HTTP <?= h(implode(', ', $checker['warning_codes'])) ?><?= $checker['slow_threshold_ms'] ? ', langsamer als ' . round($checker['slow_threshold_ms'] / 1000, 1) . ' s' : '' ?></dd>
        </dl>
    </section>
</div>

<section class="card">
    <h2>Cronjob</h2>
    <p>Der nächtliche Lauf wird per Cron auf dem Server gestartet, z.B. jeden Tag um 03:17 Uhr:</p>
    <pre class="log">17 3 * * * php <?= h($root) ?>/bin/run.php --quiet</pre>
    <p class="muted small">Exit-Codes: 0 = OK, 1 = Probleme gefunden, 2 = Lauf fehlgeschlagen, 3 = läuft bereits.</p>
</section>
