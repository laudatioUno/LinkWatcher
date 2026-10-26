<?php /** @var ?string $error @var string $csrf */ ?>
<div class="login">
    <div class="login-card">
        <div class="login-brand">
            <img src="assets/icon.svg" alt="" width="44" height="44">
            <h1><?= h($appName) ?></h1>
            <p>Gesundheitszustand aller URLs in den Textkeys</p>
        </div>
        <?php if ($error): ?>
            <div class="banner banner-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" action="?p=login" class="stack">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <label>Benutzername
                <input type="text" name="username" autocomplete="username" required autofocus>
            </label>
            <label>Passwort
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="btn btn-primary btn-block" type="submit">Anmelden</button>
        </form>
    </div>
</div>
