<h1 class="h-page">Mon panier</h1>
<?php if (!$lines): ?>
    <div class="panel empty">
        <p>Votre panier est vide… pour l'instant !</p>
        <a class="btn btn-green" href="<?= e(url()) ?>">Découvrir nos gourmandises</a>
    </div>
<?php else: ?>
<form method="post" action="<?= e(url('panier-maj')) ?>" class="cart">
    <?= csrf_field() ?>
    <div class="panel cart-lines">
        <?php foreach ($lines as $l): $p = $l['produit']; ?>
            <div class="cart-line">
                <img src="<?= e(asset($p['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="" width="96" height="72">
                <div class="cl-name">
                    <strong><?= e($p['nom']) ?></strong>
                    <span class="muted"><?= prix((int) $p['prix_cents']) ?> l'unité</span>
                </div>
                <div class="qty" data-qty data-autosubmit>
                    <button type="button" data-step="-1" aria-label="Moins">−</button>
                    <input type="number" name="qte[<?= (int) $p['id'] ?>]" value="<?= (int) $l['quantite'] ?>" min="0" max="99" aria-label="Quantité <?= e($p['nom']) ?>">
                    <button type="button" data-step="1" aria-label="Plus">+</button>
                </div>
                <strong class="cl-total"><?= prix($l['total']) ?></strong>
                <button class="icon-btn" name="retirer" value="<?= (int) $p['id'] ?>" aria-label="Retirer <?= e($p['nom']) ?>"><?= icon('trash') ?></button>
            </div>
        <?php endforeach; ?>
        <div class="cart-actions">
            <button class="btn btn-ghost btn-sm" name="vider" value="1" data-confirm="Vider le panier ?">Vider le panier</button>
            <button class="btn btn-ghost btn-sm js-hide" type="submit">Mettre à jour</button>
        </div>
    </div>
    <aside class="panel summary">
        <h2>Récapitulatif</h2>
        <p class="row"><span>Sous-total</span><strong><?= prix($subtotal) ?></strong></p>
        <?php if (cfg('free_delivery_from_cents')): ?>
            <p class="muted small">Livraison <?= prix((int) cfg('delivery_fee_cents')) ?>, offerte dès <?= prix((int) cfg('free_delivery_from_cents')) ?>. Click &amp; Collect gratuit.</p>
        <?php endif; ?>
        <?php if ($subtotal < (int) cfg('min_order_cents', 0)): ?>
            <p class="flash flash-info">Commande minimum : <?= prix((int) cfg('min_order_cents')) ?>.</p>
        <?php else: ?>
            <a class="btn btn-cta btn-block" href="<?= e(url('commande')) ?>"><?= icon('check') ?> Valider ma commande</a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-block" href="<?= e(url()) ?>">Continuer mes achats</a>
    </aside>
</form>
<?php endif; ?>
