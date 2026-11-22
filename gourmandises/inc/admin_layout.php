<?php $current = $_GET['p'] ?? 'admin'; ?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Administration') ?> · Espace équipe</title>
    <link rel="icon" type="image/png" href="<?= e(asset('assets/img/favicon.png')) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Pacifico&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
</head>
<body class="admin">
<header class="topbar">
    <div class="wrap topbar-in">
        <a class="brand" href="<?= e(url('admin')) ?>">
            <img src="<?= e(asset('assets/img/logo-afpar.png')) ?>" alt="Logo AFPAR" width="44" height="50">
            <span><strong>Espace équipe</strong><small><?= e(cfg('app_name')) ?></small></span>
        </a>
        <?php if (is_admin()): ?>
        <nav class="mainnav admin-nav">
            <a href="<?= e(url('admin')) ?>" class="<?= in_array($current, ['admin', 'admin-commande'], true) ? 'on' : '' ?>">Commandes</a>
            <a href="<?= e(url('admin-produits')) ?>" class="<?= in_array($current, ['admin-produits', 'admin-produit'], true) ? 'on' : '' ?>">Produits</a>
            <a href="<?= e(url('admin-categories')) ?>" class="<?= $current === 'admin-categories' ? 'on' : '' ?>">Catégories</a>
            <a href="<?= e(url()) ?>" target="_blank" rel="noopener">Voir la boutique ↗</a>
            <a href="<?= e(url('admin-logout')) ?>">Déconnexion</a>
        </nav>
        <?php endif; ?>
    </div>
</header>
<main class="wrap main">
    <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
