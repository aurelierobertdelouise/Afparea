<form class="panel narrow login" method="post" action="<?= e(url('admin-login')) ?>">
    <?= csrf_field() ?>
    <img src="<?= e(asset('assets/img/logo-afpar.png')) ?>" alt="" width="70" height="80">
    <h1 class="h-page">Espace équipe</h1>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <div class="fields">
        <label class="full">Identifiant <input name="user" autocomplete="username" required autofocus></label>
        <label class="full">Mot de passe <input name="password" type="password" autocomplete="current-password" required></label>
    </div>
    <button class="btn btn-cta btn-block" type="submit">Se connecter</button>
</form>
