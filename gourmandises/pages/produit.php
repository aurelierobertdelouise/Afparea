<?php $restant = $prochaine ? stock_restant($prod, $prochaine) : ($prod['disponible'] ? null : 0); ?>
<p class="crumbs"><a href="<?= e(url()) ?>">Catalogue</a> › <a href="<?= e(url('accueil', ['cat' => $prod['categorie_slug']])) ?>#catalogue"><?= e($prod['categorie']) ?></a> › <?= e($prod['nom']) ?></p>
<article class="product">
    <div class="product-img">
        <img src="<?= e(asset($prod['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="<?= e($prod['nom']) ?>" width="600" height="450">
    </div>
    <div class="product-info panel">
        <span class="pill"><?= e($prod['categorie']) ?></span>
        <span class="pill <?= $restant === 0 ? 'pill-r' : 'pill-g' ?>"><?= $restant === 0 ? 'Épuisé' : 'En stock' ?></span>
        <h1><?= e($prod['nom']) ?></h1>
        <p class="price big"><?= prix((int) $prod['prix_cents']) ?></p>
        <p><?= nl2br(e($prod['description'])) ?></p>
        <dl class="specs">
            <?php if ($prod['allergenes']): ?><dt>Allergènes</dt><dd><?= e($prod['allergenes']) ?></dd><?php endif; ?>
            <?php if ($prod['conservation']): ?><dt>Conservation</dt><dd><?= e($prod['conservation']) ?></dd><?php endif; ?>
            <?php if ($prod['quota'] > 0): ?><dt>Quota</dt><dd><?= (int) $prod['quota'] ?> <?= e(mb_strtolower($prod['categorie'])) ?> maximum par commande</dd><?php endif; ?>
        </dl>
        <?php if ($restant !== 0): ?>
        <form method="post" action="<?= e(url('panier-ajouter')) ?>" class="addform">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $prod['id'] ?>">
            <input type="hidden" name="retour" value="panier">
            <div class="qty" data-qty>
                <button type="button" data-step="-1" aria-label="Moins">−</button>
                <input type="number" name="qte" value="1" min="1" max="<?= (int) ($prod['quota'] ?: 50) ?>" aria-label="Quantité">
                <button type="button" data-step="1" aria-label="Plus">+</button>
            </div>
            <button class="btn btn-cta" type="submit"><?= icon('cart') ?> Ajouter au panier</button>
        </form>
        <?php endif; ?>
    </div>
</article>
