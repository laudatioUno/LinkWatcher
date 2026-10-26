<?php

use LinkWatcher\Util;

/** @var array $urls @var array $counts @var array $filters @var array $languages @var array $keyCounts @var ?array $lastRun */

$problemCount = $counts['error'];
$health = $counts['total'] > 0 ? (int) round(($counts['ok'] + $counts['warning']) / max(1, $counts['total'] - $counts['ignored']) * 100) : 100;
$state = $problemCount > 0 ? 'error' : ($counts['warning'] > 0 ? 'warning' : ($counts['pending'] > 0 ? 'pending' : 'ok'));
$headline = match ($state) {
    'error' => $problemCount === 1 ? '1 URL ist nicht erreichbar' : "{$problemCount} URLs sind nicht erreichbar",
    'warning' => 'Alle URLs erreichbar, ' . $counts['warning'] . ' mit Warnung',
    'pending' => 'Noch nicht alle URLs geprüft',
    default => 'Alle URLs sind gesund',
};
$filterUrl = static function (array $override) use ($filters): string {
    return '?' . http_build_query(array_filter(['p' => 'dashboard'] + $override + $filters, static fn($v) => $v !== ''));
};
?>
<section class="hero hero-<?= h($state) ?>">
    <div class="hero-main">
        <div class="hero-dot"></div>
        <div>
            <h1><?= h($headline) ?></h1>
            <p class="muted">
                <?php if ($lastRun): ?>
                    Letzte Prüfung <?= h(Util::ago($lastRun['started_at'])) ?> (<?= h(Util::fmt($lastRun['started_at'])) ?>,
                    <?= $lastRun['trigger'] === 'cron' ? 'nächtlicher Lauf' : 'manuell' ?>)
                    · <a href="?p=run&id=<?= (int) $lastRun['id'] ?>">Protokoll</a>
                <?php else: ?>
                    Noch keine Prüfung durchgeführt – starte die erste mit „Jetzt prüfen“.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="hero-health">
        <div class="health-value"><?= $health ?><span>%</span></div>
        <div class="muted small">erreichbar</div>
    </div>
</section>

<section class="kpis">
    <a class="kpi" href="<?= h($filterUrl(['status' => ''])) ?>">
        <span class="kpi-num"><?= $counts['total'] ?></span><span class="kpi-label">URLs</span>
    </a>
    <a class="kpi kpi-ok" href="<?= h($filterUrl(['status' => 'ok'])) ?>">
        <span class="kpi-num"><?= $counts['ok'] ?></span><span class="kpi-label">OK</span>
    </a>
    <a class="kpi kpi-warning" href="<?= h($filterUrl(['status' => 'warning'])) ?>">
        <span class="kpi-num"><?= $counts['warning'] ?></span><span class="kpi-label">Warnungen</span>
    </a>
    <a class="kpi kpi-error" href="<?= h($filterUrl(['status' => 'error'])) ?>">
        <span class="kpi-num"><?= $counts['error'] ?></span><span class="kpi-label">Fehler</span>
    </a>
    <a class="kpi kpi-ignored" href="<?= h($filterUrl(['status' => 'ignored'])) ?>">
        <span class="kpi-num"><?= $counts['ignored'] ?></span><span class="kpi-label">Ignoriert</span>
    </a>
    <a class="kpi" href="?p=keys">
        <span class="kpi-num"><?= $keyCounts['active'] ?></span><span class="kpi-label">Textkeys<?= $keyCounts['archived'] ? ' · ' . $keyCounts['archived'] . ' archiviert' : '' ?></span>
    </a>
</section>

<section class="card">
    <form class="filters" method="get">
        <input type="hidden" name="p" value="dashboard">
        <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="URL oder Textkey suchen …">
        <select name="status">
            <option value="">Alle Status</option>
            <?php foreach (['error', 'warning', 'ok', 'pending', 'ignored'] as $s): ?>
                <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= h(Util::statusLabel($s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="lang">
            <option value="">Alle Sprachen</option>
            <?php foreach ($languages as $l): ?>
                <option value="<?= h($l) ?>" <?= $filters['lang'] === $l ? 'selected' : '' ?>><?= h(strtoupper($l)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Filtern</button>
        <?php if (array_filter($filters)): ?><a class="btn btn-ghost" href="?p=dashboard">Zurücksetzen</a><?php endif; ?>
    </form>

    <?php if (!$urls): ?>
        <p class="empty"><?= $counts['total'] ? 'Keine URLs für diesen Filter.' : 'Noch keine URLs erfasst. Prüfe die Quellen unter Einstellungen und starte eine Prüfung.' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Status</th>
                    <th>URL</th>
                    <th>Ergebnis</th>
                    <th>Textkeys</th>
                    <th>Sprachen</th>
                    <th>Geprüft</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($urls as $u): ?>
                    <tr class="row-<?= h($u['display_status']) ?>">
                        <td><span class="pill pill-<?= h($u['display_status']) ?>"><?= h(Util::statusLabel($u['display_status'])) ?></span></td>
                        <td class="url-cell">
                            <a href="?p=url&id=<?= (int) $u['id'] ?>" class="url-link"><?= h($u['url']) ?></a>
                            <a href="<?= h($u['url']) ?>" target="_blank" rel="noopener noreferrer" class="ext" title="URL öffnen">↗</a>
                        </td>
                        <td class="result">
                            <?= h($u['ignored'] ? ($u['ignore_reason'] ?: 'wird nicht geprüft') : ($u['message'] ?? '–')) ?>
                            <?php if ($u['display_status'] === 'error' && $u['failing_since']): ?>
                                <div class="muted small">seit <?= h(Util::fmt($u['failing_since'], 'd.m.Y')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="keys-cell">
                            <?php foreach (array_slice($u['key_names'], 0, 2) as $k): ?>
                                <code><?= h($k) ?></code>
                            <?php endforeach; ?>
                            <?php if (count($u['key_names']) > 2): ?><span class="muted small">+<?= count($u['key_names']) - 2 ?></span><?php endif; ?>
                        </td>
                        <td><?php foreach ($u['languages'] as $l): ?><span class="lang"><?= h(strtoupper($l)) ?></span><?php endforeach; ?></td>
                        <td class="nowrap muted small" title="<?= h(Util::fmt($u['last_checked_at'])) ?>"><?= h(Util::ago($u['last_checked_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
