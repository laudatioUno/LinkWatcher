<?php /** @var string $content @var string $title */ ?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · <?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= h(LW_VERSION) ?>">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
</head>
<body class="bare">
<?= $content ?>
</body>
</html>
