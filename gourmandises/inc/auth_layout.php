<!DOCTYPE html>
<html lang="fr">
<head>
<?php require __DIR__ . '/head.php'; ?>
</head>
<body class="auth">
<main class="auth-main" id="contenu">
    <div class="auth-brand">
        <img src="<?= e(asset('assets/img/logo-afpar-horizontal.png')) ?>" alt="AFPAR — Le chemin de votre réussite" width="260" height="135">
    </div>
    <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
    <p class="auth-foot"><a href="<?= e(url('mentions')) ?>">Mentions légales &amp; données personnelles</a></p>
</main>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
