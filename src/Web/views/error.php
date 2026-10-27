<?php /** @var string $title @var string $message */ ?>
<div class="login">
    <div class="login-card">
        <h1><?= h($title) ?></h1>
        <div class="banner banner-error"><?= h($message) ?></div>
        <p><a href="?p=dashboard">Zur Übersicht</a></p>
    </div>
</div>
