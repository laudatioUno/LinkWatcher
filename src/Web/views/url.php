<?php

use LinkWatcher\Util;

/** @var array $url @var array $keys @var array $history @var string $csrf */
$status = $url['ignored'] ? 'ignored' : $url['status'];
$timeline = array_reverse(array_slice($history, 0, 60));
?>
<div class="page-head">
    <a class="back" href="?p=dashboard">← Übersicht</a>
    <div class="url-head">
        <span class="pill pill-<?= h($status) ?>"><?= h(Util::statusLabel($status)) ?></span>
        <h1 class="url-title"><?= h($url['url']) ?></h1>
    </div>
    <div class="actions">
        <a class="btn" href="<?= h($url['url']) ?>" target="_blank" rel="noopener noreferrer">URL öffnen ↗</a>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="recheck">
            <input type="hidden" name="id" value="<?= (int) $url['id'] ?>">
            <button class="btn btn-primary" type="submit">Jetzt prüfen</button>
        </form>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Letzte Prüfung</h2>
        <dl class="facts">
            <dt>Ergebnis</dt><dd><?= h($url['message'] ?? '–') ?></dd>
            <dt>HTTP-Status</dt><dd><?= $url['http_code'] ? (int) $url['http_code'] : '–' ?> <?= $url['method'] ? '<span class="muted small">(' . h($url['method']) . ')</span>' : '' ?></dd>
            <dt>Antwortzeit</dt><dd><?= $url['response_ms'] !== null ? number_format((int) $url['response_ms'], 0, '.', "'") . ' ms' : '–' ?></dd>
            <?php if ((int) $url['redirect_count'] > 0): ?>
                <dt>Weiterleitung</dt><dd><?= (int) $url['redirect_count'] ?>× → <a href="<?= h($url['final_url']) ?>" target="_blank" rel="noopener noreferrer" class="break"><?= h($url['final_url']) ?></a></dd>
            <?php endif; ?>
            <dt>Geprüft</dt><dd><?= h(Util::fmt($url['last_checked_at'])) ?></dd>
            <dt>Zuletzt OK</dt><dd><?= h(Util::fmt($url['last_ok_at'])) ?></dd>
            <?php if ($url['failing_since']): ?>
                <dt>Fehlerhaft seit</dt><dd><?= h(Util::fmt($url['failing_since'])) ?> (<?= (int) $url['consecutive_failures'] ?> Prüfungen)</dd>
            <?php endif; ?>
            <dt>Erstmals gesehen</dt><dd><?= h(Util::fmt($url['first_seen_at'], 'd.m.Y')) ?></dd>
        </dl>
    </section>

    <section class="card">
        <h2>Überwachung</h2>
        <?php if ($url['ignored']): ?>
            <p>Diese URL wird <strong>nicht geprüft</strong><?= $url['ignore_reason'] ? ': ' . h($url['ignore_reason']) : '.' ?></p>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="unignore">
                <input type="hidden" name="id" value="<?= (int) $url['id'] ?>">
                <button class="btn" type="submit">Wieder überwachen</button>
            </form>
        <?php else: ?>
            <p class="muted">Bekannte Ausnahme (z.B. Bot-Schutz, Login-Seite)? Dann von der Prüfung ausnehmen – sie löst dann keinen Alarm mehr aus.</p>
            <form method="post" class="stack">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="ignore">
                <input type="hidden" name="id" value="<?= (int) $url['id'] ?>">
                <input type="text" name="reason" maxlength="300" placeholder="Begründung (optional)">
                <button class="btn" type="submit">Ignorieren</button>
            </form>
        <?php endif; ?>

        <?php if ($timeline): ?>
            <h3>Verlauf</h3>
            <div class="timeline" aria-label="Verlauf der letzten Prüfungen">
                <?php foreach ($timeline as $c): ?>
                    <i class="tick tick-<?= h($c['status']) ?>" title="<?= h(Util::fmt($c['checked_at']) . ' – ' . $c['message']) ?>"></i>
                <?php endforeach; ?>
            </div>
            <div class="muted small">Letzte <?= count($timeline) ?> Prüfungen, älteste links</div>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <h2>Verwendet in <?= count($keys) ?> Textkey<?= count($keys) === 1 ? '' : 's' ?></h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Textkey</th><th>Quelle</th><th>Text</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): ?>
                <?php $archived = $k['archived_at'] !== null || !$k['source_active']; ?>
                <tr class="<?= $archived ? 'is-archived' : '' ?>">
                    <td><code><?= h($k['key_name']) ?></code><?= $archived ? ' <span class="pill pill-ignored">archiviert</span>' : '' ?></td>
                    <td class="nowrap"><?= h($k['label']) ?> <span class="lang"><?= h(strtoupper($k['language'])) ?></span></td>
                    <td class="value"><?= h(mb_strimwidth($k['value'], 0, 220, '…')) ?></td>
                    <td class="nowrap">
                        <?php $line = Util::lineUrl($k['view_url'], $k['line'] ? (int) $k['line'] : null); ?>
                        <?php if ($line && !$archived): ?><a href="<?= h($line) ?>" target="_blank" rel="noopener noreferrer">GitHub ↗</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>Prüfhistorie</h2>
    <?php if (!$history): ?>
        <p class="empty">Noch nicht geprüft.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Zeitpunkt</th><th>Status</th><th>Ergebnis</th><th>Methode</th><th class="right">Zeit</th><th class="right">Versuche</th></tr></thead>
                <tbody>
                <?php foreach ($history as $c): ?>
                    <tr>
                        <td class="nowrap"><?= h(Util::fmt($c['checked_at'])) ?></td>
                        <td><span class="pill pill-<?= h($c['status']) ?>"><?= h(Util::statusLabel($c['status'])) ?></span></td>
                        <td><?= h($c['message']) ?></td>
                        <td class="muted small"><?= h($c['method']) ?></td>
                        <td class="right nowrap"><?= (int) $c['response_ms'] ?> ms</td>
                        <td class="right"><?= (int) $c['attempts'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
