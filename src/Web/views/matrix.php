<?php

use LinkWatcher\Util;

/** @var array $matrix key => lang => label => status @var array $meta @var string $kind @var array $kinds @var array $languages @var array $labels */
$kindLabels = ['app' => 'App-Textkeys', 'faq' => 'Website-FAQ'];
?>
<div class="page-head">
    <h1>Matrix</h1>
    <p class="muted">
        <?php if ($kind === 'faq'): ?>
            Jede FAQ-Frage pro Sprache. Klick führt zu den betroffenen URLs.
        <?php else: ?>
            Jeder Textkey pro Sprache – ein Punkt pro Quelldatei (<?= h(implode(', ', $labels)) ?>). Klick führt zu den betroffenen URLs.
        <?php endif; ?>
    </p>
</div>

<section class="card">
    <?php if (count($kinds) > 1): ?>
        <div class="filters">
            <div class="tabs">
                <?php foreach ($kinds as $k): ?>
                    <a href="?p=matrix&amp;kind=<?= h($k) ?>" class="<?= $kind === $k ? 'is-active' : '' ?>"><?= h($kindLabels[$k] ?? $k) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="legend">
        <?php foreach (['ok', 'warning', 'error', 'pending', 'ignored'] as $s): ?>
            <span><i class="dot dot-<?= $s ?>"></i><?= h(Util::statusLabel($s)) ?></span>
        <?php endforeach; ?>
        <span><i class="dot dot-missing"></i>fehlt in dieser Datei</span>
    </div>

    <?php if (!$matrix): ?>
        <p class="empty">Noch keine Textkeys erfasst.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table matrix">
                <thead>
                <tr>
                    <th><?= $kind === 'faq' ? 'Frage' : 'Textkey' ?></th>
                    <?php foreach ($languages as $l): ?><th class="center"><?= h(strtoupper($l)) ?></th><?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matrix as $key => $byLang): ?>
                    <tr>
                        <td>
                            <?php if (!empty($meta[$key]['title'])): ?>
                                <div class="faq-title"><?= h($meta[$key]['title']) ?></div>
                                <div class="muted small"><?= h($meta[$key]['context'] ?? '') ?></div>
                            <?php else: ?>
                                <code><?= h($key) ?></code>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($languages as $l): ?>
                            <td class="center">
                                <a class="cell-dots" href="?p=dashboard&amp;lang=<?= h(urlencode($l)) ?>&amp;q=<?= h(urlencode((string) $key)) ?>">
                                    <?php foreach ($labels as $label): ?>
                                        <?php $st = $byLang[$l][$label] ?? 'missing'; ?>
                                        <i class="dot dot-<?= h($st) ?>" title="<?= h($label . ': ' . ($st === 'missing' ? 'nicht vorhanden' : Util::statusLabel($st))) ?>"></i>
                                    <?php endforeach; ?>
                                </a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
