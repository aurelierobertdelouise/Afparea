<p class="crumbs"><a href="<?= e(url()) ?>">Accueil</a> › <a href="<?= e(url('categorie', ['c' => $prod['categorie_slug']])) ?>"><?= e($prod['categorie']) ?></a> › <?= e($prod['nom']) ?></p>
<article class="product">
    <div class="product-img">
        <img src="<?= e(asset($prod['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="<?= e($prod['nom']) ?>" width="600" height="450">
    </div>
    <div class="product-info panel">
        <span class="pill"><?= e($prod['categorie']) ?></span>
        <h1><?= e($prod['nom']) ?></h1>
        <p class="price big"><?= prix((int) $prod['prix_cents']) ?></p>
        <p><?= nl2br(e($prod['description'])) ?></p>
        <?php if ($prod['allergenes']): ?>
            <p class="muted"><strong>Allergènes :</strong> <?= e($prod['allergenes']) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('panier-ajouter')) ?>" class="addform">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $prod['id'] ?>">
            <input type="hidden" name="retour" value="panier">
            <div class="qty" data-qty>
                <button type="button" data-step="-1" aria-label="Moins">−</button>
                <input type="number" name="qte" value="1" min="1" max="99" aria-label="Quantité">
                <button type="button" data-step="1" aria-label="Plus">+</button>
            </div>
            <button class="btn btn-cta" type="submit"><?= icon('cart') ?> Ajouter au panier</button>
        </form>
    </div>
</article>
