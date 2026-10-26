<?php

use LinkWatcher\Util;

/** @var array $run @var array $problems */
$statusMap = [
    'ok' => ['ok', 'Alles OK'],
    'problems' => ['error', 'Probleme gefunden'],
    'failed' => ['error', 'Abgebrochen'],
    'aborted' => ['ignored', 'Unterbrochen'],
    'running' => ['pending', 'Läuft …'],
];
[$cls, $label] = $statusMap[$run['status']] ?? ['pending', $run['status']];
$mailMap = ['sent' => 'Zusammenfassung versendet', 'skipped' => 'nicht nötig (keine Probleme)', 'disabled' => 'deaktiviert für diesen Lauf', 'failed' => 'Versand fehlgeschlagen'];
?>
<div class="page-head">
    <a class="back" href="?p=runs">← Prüfläufe</a>
    <div class="url-head">
        <span class="pill pill-<?= $cls ?>"><?php if ($run['status'] === 'running'): ?><span class="spinner"></span> <?php endif; ?><?= h($label) ?></span>
        <h1>Prüflauf #<?= (int) $run['id'] ?></h1>
    </div>
    <p class="muted">
        <?= $run['trigger'] === 'cron' ? 'Nächtlicher Lauf' : 'Manuell gestartet' ?> am <?= h(Util::fmt($run['started_at'], 'd.m.Y \u\m H:i:s')) ?>
        <?php if ($run['finished_at']): ?> · Dauer <?= max(0, strtotime($run['finished_at'] . ' UTC') - strtotime($run['started_at'] . ' UTC')) ?> s<?php endif; ?>
        <?php if ($run['mail_status']): ?> · Mail: <?= h($mailMap[$run['mail_status']] ?? $run['mail_status']) ?><?php endif; ?>
    </p>
</div>

<?php if ($run['status'] !== 'running'): ?>
    <section class="kpis">
        <div class="kpi"><span class="kpi-num"><?= (int) $run['urls_checked'] ?></span><span class="kpi-label">URLs geprüft</span></div>
        <div class="kpi kpi-ok"><span class="kpi-num"><?= (int) $run['urls_ok'] ?></span><span class="kpi-label">OK</span></div>
        <div class="kpi kpi-warning"><span class="kpi-num"><?= (int) $run['urls_warning'] ?></span><span class="kpi-label">Warnungen</span></div>
        <div class="kpi kpi-error"><span class="kpi-num"><?= (int) $run['urls_error'] ?></span><span class="kpi-label">Fehler</span></div>
        <div class="kpi"><span class="kpi-num"><?= (int) $run['keys_new'] ?> / <?= (int) $run['keys_archived'] ?></span><span class="kpi-label">Keys neu / archiviert</span></div>
    </section>
<?php endif; ?>

<?php if ($problems): ?>
    <section class="card">
        <h2>Auffälligkeiten</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Status</th><th>URL</th><th>Ergebnis</th></tr></thead>
                <tbody>
                <?php foreach ($problems as $p): ?>
                    <tr>
                        <td><span class="pill pill-<?= h($p['status']) ?>"><?= h(Util::statusLabel($p['status'])) ?></span></td>
                        <td class="url-cell"><a href="?p=url&amp;id=<?= (int) $p['url_id'] ?>" class="url-link"><?= h($p['url']) ?></a></td>
                        <td><?= h($p['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Protokoll</h2>
    <pre class="log"><?= h($run['log'] ?: '…') ?></pre>
    <?php if ($run['status'] === 'running'): ?><p class="muted small">Die Seite aktualisiert sich automatisch.</p><?php endif; ?>
</section>
