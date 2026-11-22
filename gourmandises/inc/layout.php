<?php
/** @var string $content */
$u = current_user();
$cartCount = cart_count();
$current = $_GET['p'] ?? 'accueil';
?><!DOCTYPE html>
<html lang="fr">
<head>
<?php require __DIR__ . '/head.php'; ?>
</head>
<body>
<a class="skip" href="#contenu">Aller au contenu</a>
<?php if (!stripe_enabled()): ?>
    <div class="demo-banner">Mode simulation : aucun paiement réel (clé Stripe non configurée)</div>
<?php endif; ?>
<header class="topbar">
    <div class="wrap topbar-in">
        <a class="brand" href="<?= e(url()) ?>">
            <img src="<?= e(asset('assets/img/logo-afpar.png')) ?>" alt="AFPAR" width="44" height="50">
            <span><strong><?= e(cfg('app_name')) ?></strong><small><?= e(cfg('app_baseline')) ?></small></span>
        </a>
        <?php if ($u): ?>
        <nav class="mainnav" aria-label="Navigation principale">
            <a href="<?= e(url()) ?>" class="<?= in_array($current, ['accueil', 'produit'], true) ? 'on' : '' ?>">Catalogue</a>
            <a href="<?= e(url('mes-commandes')) ?>" class="<?= in_array($current, ['mes-commandes', 'commande'], true) ? 'on' : '' ?>">Mes commandes</a>
            <a href="<?= e(url('profil')) ?>" class="<?= $current === 'profil' ? 'on' : '' ?>">Profil</a>
            <?php if (is_staff()): ?><a href="<?= e(url('gestion')) ?>" class="staff">Gestion</a><?php endif; ?>
        </nav>
        <a class="cartbtn" href="<?= e(url('panier')) ?>" aria-label="Panier (<?= $cartCount ?> article<?= $cartCount > 1 ? 's' : '' ?>)">
            <?= icon('cart') ?>
            <span class="badge" data-cart-count <?= $cartCount ? '' : 'hidden' ?>><?= $cartCount ?></span>
        </a>
        <?php endif; ?>
    </div>
</header>

<main class="wrap main" id="contenu">
    <?php foreach (flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<section class="trust">
    <div class="wrap trust-in">
        <div><?= icon('truck') ?><span>Livré dans votre centre</span></div>
        <div><?= icon('shop') ?><span>Retrait au point relais</span></div>
        <div><?= icon('lock') ?><span>Paiement sécurisé Stripe</span></div>
    </div>
</section>

<footer class="footer">
    <div class="wrap footer-in">
        <img class="footer-logo" src="<?= e(asset('assets/img/logo-afpar-horizontal.png')) ?>" alt="AFPAR — Le chemin de votre réussite" width="200" height="104">
        <p>Production pédagogique de la filière Boulangerie — CFPA de Saint-André.<br>Service réservé au personnel AFPAR.</p>
        <p><a href="<?= e(url('mentions')) ?>">Mentions légales &amp; RGPD</a><?php if ($u): ?> · <a href="<?= e(url('deconnexion')) ?>">Déconnexion</a><?php endif; ?></p>
    </div>
</footer>

<?php if ($u): ?>
<nav class="tabbar" aria-label="Navigation mobile">
    <a href="<?= e(url()) ?>" class="<?= in_array($current, ['accueil', 'produit'], true) ? 'on' : '' ?>"><?= icon('home') ?><span>Catalogue</span></a>
    <a href="<?= e(url('panier')) ?>" class="<?= in_array($current, ['panier', 'recapitulatif'], true) ? 'on' : '' ?>"><?= icon('cart') ?><span>Panier</span><b class="dot" data-cart-count <?= $cartCount ? '' : 'hidden' ?>><?= $cartCount ?></b></a>
    <a href="<?= e(url('mes-commandes')) ?>" class="<?= in_array($current, ['mes-commandes', 'commande'], true) ? 'on' : '' ?>"><?= icon('list') ?><span>Commandes</span></a>
    <?php if (is_staff()): ?>
        <a href="<?= e(url('gestion')) ?>"><?= icon('box') ?><span>Gestion</span></a>
    <?php else: ?>
        <a href="<?= e(url('profil')) ?>" class="<?= $current === 'profil' ? 'on' : '' ?>"><?= icon('user') ?><span>Profil</span></a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
