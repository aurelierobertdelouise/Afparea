<?php
/** @var string $content */
$cartCount = cart_count();
$current = $_GET['p'] ?? 'accueil';
$currentCat = $_GET['c'] ?? '';
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e(($title ?? '') ? $title . ' · ' : '') ?><?= e(cfg('app_name')) ?></title>
    <meta name="description" content="<?= e(cfg('app_baseline')) ?> — commandez vos pains et viennoiseries, faites-vous livrer ou retirez en Click & Collect.">
    <meta name="theme-color" content="#2F2E4E">
    <link rel="icon" type="image/png" href="<?= e(asset('assets/img/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/img/logo-afpar.png')) ?>">
    <link rel="manifest" href="<?= e(asset('manifest.webmanifest')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700;800&family=Pacifico&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="wrap topbar-in">
        <a class="brand" href="<?= e(url()) ?>">
            <img src="<?= e(asset('assets/img/logo-afpar.png')) ?>" alt="Logo AFPAR" width="44" height="50">
            <span>
                <strong><?= e(cfg('app_name')) ?></strong>
                <small><?= e(cfg('app_baseline')) ?></small>
            </span>
        </a>
        <nav class="mainnav" aria-label="Navigation principale">
            <a href="<?= e(url()) ?>" class="<?= $current === 'accueil' ? 'on' : '' ?>">Accueil</a>
            <?php foreach (categories() as $c): ?>
                <a href="<?= e(url('categorie', ['c' => $c['slug']])) ?>" class="<?= $currentCat === $c['slug'] ? 'on' : '' ?>"><?= e($c['nom']) ?></a>
            <?php endforeach; ?>
            <a href="<?= e(url('suivi')) ?>" class="<?= $current === 'suivi' ? 'on' : '' ?>">Suivi</a>
        </nav>
        <a class="cartbtn" href="<?= e(url('panier')) ?>" aria-label="Panier">
            <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2M10 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
            <span class="badge" data-cart-count <?= $cartCount ? '' : 'hidden' ?>><?= $cartCount ?></span>
        </a>
    </div>
</header>

<main class="wrap main">
    <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<section class="trust">
    <div class="wrap trust-in">
        <div><?= icon('truck') ?><span>Livraison</span></div>
        <div><?= icon('shop') ?><span>Click &amp; Collect</span></div>
        <div><?= icon('lock') ?><span>Paiement sécurisé</span></div>
    </div>
</section>

<footer class="footer">
    <div class="wrap footer-in">
        <p><strong><?= e(cfg('app_name')) ?></strong> — une application pédagogique de l'AFPAR.</p>
        <p><a href="<?= e(url('infos')) ?>">Infos pratiques</a> · <a href="<?= e(url('suivi')) ?>">Suivre ma commande</a> · <a href="<?= e(url('admin')) ?>">Espace équipe</a></p>
    </div>
</footer>

<nav class="tabbar" aria-label="Navigation mobile">
    <a href="<?= e(url()) ?>" class="<?= $current === 'accueil' ? 'on' : '' ?>"><?= icon('home') ?><span>Accueil</span></a>
    <?php foreach (categories() as $c): ?>
        <a href="<?= e(url('categorie', ['c' => $c['slug']])) ?>" class="<?= $currentCat === $c['slug'] ? 'on' : '' ?>"><?= icon($c['slug'] === 'pains' ? 'bread' : 'croissant') ?><span><?= e($c['nom']) ?></span></a>
    <?php endforeach; ?>
    <a href="<?= e(url('panier')) ?>" class="<?= in_array($current, ['panier', 'commande'], true) ? 'on' : '' ?>"><?= icon('cart') ?><span>Panier</span><b class="dot" data-cart-count <?= $cartCount ? '' : 'hidden' ?>><?= $cartCount ?></b></a>
</nav>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
