<?php

use LinkWatcher\Util;

/** @var array $matrix key => lang => label => status @var array $languages @var array $labels */
?>
<div class="page-head">
    <h1>Matrix</h1>
    <p class="muted">Jeder Textkey pro Sprache – ein Punkt pro Quelldatei (<?= h(implode(', ', $labels)) ?>). Klick führt zu den betroffenen URLs.</p>
</div>

<section class="card">
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
                    <th>Textkey</th>
                    <?php foreach ($languages as $l): ?><th class="center"><?= h(strtoupper($l)) ?></th><?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matrix as $key => $byLang): ?>
                    <tr>
                        <td><code><?= h($key) ?></code></td>
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
