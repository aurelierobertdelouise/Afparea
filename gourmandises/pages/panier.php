<h1 class="h-page">Mon panier</h1>
<?php if (!$lines): ?>
    <div class="panel empty">
        <p>Votre panier est vide… pour l'instant !</p>
        <a class="btn btn-green" href="<?= e(url()) ?>">Découvrir le catalogue</a>
    </div>
<?php else: ?>
<form method="post" action="<?= e(url('panier-maj')) ?>" class="cart" data-cart>
    <?= csrf_field() ?>
    <div>
        <div class="panel cart-lines">
            <?php foreach ($lines as $l): $p = $l['produit']; ?>
                <div class="cart-line" data-line data-cat="<?= (int) $p['categorie_id'] ?>" data-price="<?= (int) $p['prix_cents'] ?>">
                    <img src="<?= e(asset($p['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="" width="96" height="72">
                    <div class="cl-name">
                        <strong><?= e($p['nom']) ?></strong>
                        <span class="muted"><?= e($p['categorie']) ?> · <?= prix((int) $p['prix_cents']) ?> l'unité</span>
                    </div>
                    <div class="qty" data-qty>
                        <button type="button" data-step="-1" aria-label="Moins">−</button>
                        <input type="number" name="qte[<?= (int) $p['id'] ?>]" value="<?= (int) $l['quantite'] ?>" min="0" max="50" aria-label="Quantité <?= e($p['nom']) ?>">
                        <button type="button" data-step="1" aria-label="Plus">+</button>
                    </div>
                    <strong class="cl-total" data-line-total><?= prix($l['total']) ?></strong>
                    <button class="icon-btn" name="retirer" value="<?= (int) $p['id'] ?>" aria-label="Retirer <?= e($p['nom']) ?>"><?= icon('trash') ?></button>
                </div>
            <?php endforeach; ?>
            <div class="cart-actions">
                <button class="btn btn-ghost btn-sm" name="vider" value="1" data-confirm="Vider le panier ?">Vider le panier</button>
                <button class="btn btn-ghost btn-sm" type="submit" data-save hidden>Enregistrer les quantités</button>
            </div>
        </div>
    </div>
    <aside class="panel summary">
        <h2>Quotas &amp; total</h2>
        <?php foreach ($totaux as $cid => $t): if ($t['quota'] <= 0 && !$t['total']) continue; ?>
            <div class="quota <?= $t['quota'] > 0 && $t['total'] > $t['quota'] ? 'over' : '' ?>" data-quota="<?= (int) $cid ?>" data-max="<?= (int) $t['quota'] ?>" data-label="<?= e(mb_strtolower($t['nom'])) ?>">
                <div class="row"><span><?= e($t['nom']) ?></span><strong><span data-count><?= (int) $t['total'] ?></span><?= $t['quota'] > 0 ? ' / ' . (int) $t['quota'] : '' ?></strong></div>
                <?php if ($t['quota'] > 0): ?><div class="bar"><span style="width:<?= min(100, round($t['total'] / $t['quota'] * 100)) ?>%"></span></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div data-quota-errors>
            <?php foreach ($quotaErrors as $q): ?><p class="alert-red" role="alert"><?= icon('alert') ?> <?= e($q) ?></p><?php endforeach; ?>
        </div>
        <p class="row total"><span>Total TTC</span><strong data-total><?= prix($total) ?></strong></p>
        <button class="btn btn-cta btn-block" type="submit" name="go" value="recap" data-checkout <?= $quotaErrors ? 'hidden' : '' ?>><?= icon('card') ?> Passer au paiement</button>
        <a class="btn btn-ghost btn-block" href="<?= e(url()) ?>">Continuer mes achats</a>
    </aside>
</form>
<?php endif; ?>
