<?php

use LinkWatcher\Util;

/** @var array $keys @var string $show @var string $q @var array $keyCounts */
$tabs = ['active' => 'Aktiv (' . $keyCounts['active'] . ')', 'archived' => 'Archiviert (' . $keyCounts['archived'] . ')', 'all' => 'Alle'];
?>
<div class="page-head">
    <h1>Textkeys</h1>
    <p class="muted">Alle Textkeys mit URL aus den Quelldateien. Verschwundene Keys werden archiviert und bei Wiederauftauchen reaktiviert.</p>
</div>

<section class="card">
    <div class="filters">
        <div class="tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="?p=keys&amp;show=<?= $key ?><?= $q !== '' ? '&amp;q=' . h(urlencode($q)) : '' ?>" class="<?= $show === $key ? 'is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="get" class="filters-inline">
            <input type="hidden" name="p" value="keys">
            <input type="hidden" name="show" value="<?= h($show) ?>">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Key oder Text suchen …">
            <button class="btn" type="submit">Suchen</button>
        </form>
    </div>

    <?php if (!$keys): ?>
        <p class="empty">Keine Textkeys gefunden.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Textkey</th>
                    <th>Quelle</th>
                    <th>URLs</th>
                    <th>Erfasst</th>
                    <th><?= $show === 'active' ? 'Geändert' : 'Archiviert' ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $k): ?>
                    <?php $archived = $k['archived_at'] !== null || !$k['source_active']; ?>
                    <tr class="<?= $archived ? 'is-archived' : '' ?>">
                        <td>
                            <code><?= h($k['key_name']) ?></code>
                            <?php if ($archived): ?><span class="pill pill-ignored">archiviert</span><?php endif; ?>
                            <?php $line = Util::lineUrl($k['view_url'], $k['line'] ? (int) $k['line'] : null); ?>
                            <?php if ($line && !$archived): ?><a class="ext small" href="<?= h($line) ?>" target="_blank" rel="noopener noreferrer">GitHub ↗</a><?php endif; ?>
                        </td>
                        <td class="nowrap"><?= h($k['label']) ?> <span class="lang"><?= h(strtoupper($k['language'])) ?></span></td>
                        <td class="url-list">
                            <?php foreach ($k['urls'] as $u): ?>
                                <div><i class="dot dot-<?= h($u['status']) ?>"></i><a href="?p=url&amp;id=<?= $u['id'] ?>"><?= h($u['url']) ?></a></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="nowrap muted small"><?= h(Util::fmt($k['first_seen_at'], 'd.m.Y')) ?></td>
                        <td class="nowrap muted small">
                            <?php if ($archived): ?>
                                <?= h(Util::fmt($k['archived_at'], 'd.m.Y')) ?>
                                <?= $k['archive_reason'] === 'source_removed' || !$k['source_active'] ? '<br>Quelle entfernt' : '' ?>
                            <?php else: ?>
                                <?= h(Util::fmt($k['changed_at'], 'd.m.Y')) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
