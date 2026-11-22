<?php
$current = $_GET['p'] ?? 'gestion';
$me = current_user();
$q = array_filter(['jour' => $_GET['jour'] ?? null, 'centre' => $_GET['centre'] ?? null]);
$nav = [
    'gestion' => ['Tableau du jour', 'list', false],
    'gestion-emargement' => ['Émargement', 'check', false],
    'gestion-production' => ['Fournil', 'bread', true],
    'gestion-colisage' => ['Colisage', 'box', true],
    'gestion-produits' => ['Catalogue', 'croissant', true],
    'gestion-centres' => ['Centres', 'pin', true],
    'gestion-utilisateurs' => ['Utilisateurs', 'user', true],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<?php require __DIR__ . '/head.php'; ?>
</head>
<body class="admin">
<a class="skip" href="#contenu">Aller au contenu</a>
<header class="topbar">
    <div class="wrap topbar-in">
        <a class="brand" href="<?= e(url('gestion')) ?>">
            <img src="<?= e(asset('assets/img/logo-afpar.png')) ?>" alt="AFPAR" width="44" height="50">
            <span><strong>Gestion</strong><small><?= e(ROLES[$me['role']] ?? '') ?><?= $me['role'] === 'referent' ? ' · ' . e($me['centre_nom']) : '' ?></small></span>
        </a>
        <nav class="mainnav admin-nav" aria-label="Gestion">
            <?php foreach ($nav as $k => [$label, $ico, $adminOnly]):
                if ($adminOnly && $me['role'] !== 'admin') continue;
                $on = $current === $k || ($k === 'gestion-produits' && in_array($current, ['gestion-produit', 'gestion-categories'], true)) || ($k === 'gestion' && $current === 'gestion-commande'); ?>
                <a href="<?= e(url($k, in_array($k, ['gestion', 'gestion-emargement', 'gestion-production', 'gestion-colisage'], true) ? $q : [])) ?>" class="<?= $on ? 'on' : '' ?>"><?= icon($ico) ?> <?= e($label) ?></a>
            <?php endforeach; ?>
            <a href="<?= e(url()) ?>"><?= icon('shop') ?> Boutique</a>
        </nav>
    </div>
</header>
<main class="wrap main" id="contenu">
    <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
