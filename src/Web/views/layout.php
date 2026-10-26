<?php
/** @var string $content @var string $title @var string $page @var array $banners @var bool $running @var string $csrf */
$nav = [
    'dashboard' => 'Übersicht',
    'matrix' => 'Matrix',
    'keys' => 'Textkeys',
    'runs' => 'Prüfläufe',
    'settings' => 'Einstellungen',
];
$active = $page === 'url' ? 'dashboard' : ($page === 'run' ? 'runs' : $page);
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="<?= (int) $refresh ?>"><?php endif; ?>
    <title><?= h($title) ?> · <?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= h(LW_VERSION) ?>">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <script src="assets/app.js?v=<?= h(LW_VERSION) ?>" defer></script>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="?p=dashboard">
            <img src="assets/icon.svg" alt="" width="26" height="26">
            <span><?= h($appName) ?></span>
        </a>
        <nav class="nav">
            <?php foreach ($nav as $key => $label): ?>
                <a href="?p=<?= $key ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="topbar-actions">
            <?php if ($running): ?>
                <a class="btn btn-ghost" href="?p=runs"><span class="spinner"></span> Prüfung läuft …</a>
            <?php else: ?>
                <form method="post" class="run-form" data-confirm-busy>
                    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="run">
                    <label class="check" title="Bei Problemen eine Zusammenfassung per Mail senden">
                        <input type="checkbox" name="send_mail" value="1"> Mail
                    </label>
                    <button class="btn btn-primary" type="submit">Jetzt prüfen</button>
                </form>
            <?php endif; ?>
            <a class="logout" href="?p=logout" title="Abmelden">Abmelden</a>
        </div>
    </div>
</header>

<main class="container">
    <?php foreach ($banners as [$type, $msg]): ?>
        <div class="banner banner-<?= h($type) ?>"><?= h($msg) ?></div>
    <?php endforeach; ?>
    <?php if ($flash): ?>
        <div class="banner banner-<?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<footer class="footer">LinkWatcher <?= h(LW_VERSION) ?> · <?= h(date('d.m.Y H:i')) ?></footer>
</body>
</html>
