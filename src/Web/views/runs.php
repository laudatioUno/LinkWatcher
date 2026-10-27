<?php

use LinkWatcher\Util;

/** @var array $runs @var bool $running */
$statusMap = [
    'ok' => ['ok', 'OK'],
    'problems' => ['error', 'Probleme'],
    'failed' => ['error', 'Abgebrochen'],
    'aborted' => ['ignored', 'Unterbrochen'],
    'running' => ['pending', 'Läuft'],
];
$mailMap = ['sent' => 'gesendet', 'skipped' => '–', 'disabled' => 'aus', 'failed' => 'Fehler'];
?>
<div class="page-head">
    <h1>Prüfläufe</h1>
    <p class="muted">Nächtliche und manuelle Läufe der letzten Zeit.</p>
</div>

<section class="card">
    <?php if (!$runs): ?>
        <p class="empty">Noch keine Prüfläufe.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>#</th><th>Start</th><th>Auslöser</th><th>Status</th>
                    <th class="right">URLs</th><th class="right">OK</th><th class="right">Warn.</th><th class="right">Fehler</th>
                    <th>Keys</th><th>Mail</th><th class="right">Dauer</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($runs as $r): ?>
                    <?php [$cls, $label] = $statusMap[$r['status']] ?? ['pending', $r['status']]; ?>
                    <tr>
                        <td><a href="?p=run&amp;id=<?= (int) $r['id'] ?>"><?= (int) $r['id'] ?></a></td>
                        <td class="nowrap"><a href="?p=run&amp;id=<?= (int) $r['id'] ?>"><?= h(Util::fmt($r['started_at'])) ?></a></td>
                        <td><?= $r['trigger'] === 'cron' ? 'Cron' : ($r['trigger'] === 'manual' ? 'Manuell' : h($r['trigger'])) ?></td>
                        <td><span class="pill pill-<?= $cls ?>"><?= h($label) ?></span></td>
                        <td class="right"><?= h($r['urls_checked'] ?? '–') ?></td>
                        <td class="right"><?= h($r['urls_ok'] ?? '–') ?></td>
                        <td class="right"><?= h($r['urls_warning'] ?? '–') ?></td>
                        <td class="right <?= (int) $r['urls_error'] > 0 ? 'text-error' : '' ?>"><?= h($r['urls_error'] ?? '–') ?></td>
                        <td class="small nowrap">
                            <?= (int) $r['keys_new'] ? '+' . (int) $r['keys_new'] . ' neu ' : '' ?>
                            <?= (int) $r['keys_archived'] ? (int) $r['keys_archived'] . ' archiviert' : '' ?>
                            <?= (int) $r['source_errors'] ? '<span class="text-error">' . (int) $r['source_errors'] . ' Quellfehler</span>' : '' ?>
                        </td>
                        <td class="small"><?= h($mailMap[$r['mail_status'] ?? ''] ?? '–') ?></td>
                        <td class="right nowrap small">
                            <?php if ($r['finished_at']): ?>
                                <?= max(0, strtotime($r['finished_at'] . ' UTC') - strtotime($r['started_at'] . ' UTC')) ?> s
                            <?php else: ?>…<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
