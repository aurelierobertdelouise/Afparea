<?php
$err = fn(string $k) => isset($errors[$k]) ? '<span class="err">' . e($errors[$k]) . '</span>' : '';
$fee = delivery_fee($subtotal, 'livraison');
?>
<h1 class="h-page">Finaliser ma commande</h1>
<?php if ($errors): ?><div class="flash flash-error">Merci de corriger les champs signalés.</div><?php endif; ?>
<form method="post" action="<?= e(url('commande')) ?>" class="checkout" novalidate>
    <?= csrf_field() ?>
    <div class="checkout-main">
        <fieldset class="panel">
            <legend>1. Mode de réception</legend>
            <div class="modes">
                <label class="mode">
                    <input type="radio" name="mode" value="retrait" <?= $data['mode'] !== 'livraison' ? 'checked' : '' ?>>
                    <span><?= icon('shop') ?><strong>Click &amp; Collect</strong><small>Gratuit — <?= e(cfg('shop_address')) ?></small></span>
                </label>
                <label class="mode">
                    <input type="radio" name="mode" value="livraison" <?= $data['mode'] === 'livraison' ? 'checked' : '' ?>>
                    <span><?= icon('truck') ?><strong>Livraison</strong><small><?= $fee ? prix($fee) : 'Offerte' ?><?php if ($fee && cfg('free_delivery_from_cents')): ?> — offerte dès <?= prix((int) cfg('free_delivery_from_cents')) ?><?php endif; ?></small></span>
                </label>
            </div>
            <div class="fields delivery-only" data-delivery>
                <label class="full">Adresse <input name="adresse" value="<?= e($data['adresse']) ?>" autocomplete="street-address"><?= $err('adresse') ?></label>
                <label>Code postal <input name="code_postal" value="<?= e($data['code_postal']) ?>" inputmode="numeric" maxlength="5" autocomplete="postal-code"><?= $err('code_postal') ?></label>
                <label>Ville <input name="ville" value="<?= e($data['ville']) ?>" autocomplete="address-level2"><?= $err('ville') ?></label>
            </div>
        </fieldset>

        <fieldset class="panel">
            <legend>2. Date &amp; créneau</legend>
            <div class="fields">
                <label>Jour
                    <select name="date_souhaitee"><?php foreach ($dates as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === $data['date_souhaitee'] ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                    <?= $err('date_souhaitee') ?>
                </label>
                <label>Créneau
                    <select name="creneau"><?php foreach ($slots as $s): ?><option <?= $s === $data['creneau'] ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
                    <?= $err('creneau') ?>
                </label>
            </div>
        </fieldset>

        <fieldset class="panel">
            <legend>3. Vos coordonnées</legend>
            <div class="fields">
                <label>Nom et prénom <input name="nom" value="<?= e($data['nom']) ?>" required autocomplete="name"><?= $err('nom') ?></label>
                <label>Téléphone <input name="telephone" type="tel" value="<?= e($data['telephone']) ?>" required autocomplete="tel"><?= $err('telephone') ?></label>
                <label class="full"><span>E-mail <small class="muted">(facultatif)</small></span> <input name="email" type="email" value="<?= e($data['email']) ?>" autocomplete="email"><?= $err('email') ?></label>
                <label class="full"><span>Commentaire <small class="muted">(facultatif)</small></span> <textarea name="commentaire" rows="2" maxlength="500" placeholder="Ex. : baguette bien cuite, digicode…"><?= e($data['commentaire']) ?></textarea></label>
            </div>
        </fieldset>
    </div>

    <aside class="panel summary">
        <h2>Ma commande</h2>
        <ul class="sum-lines">
            <?php foreach ($lines as $l): ?>
                <li><span><?= (int) $l['quantite'] ?> × <?= e($l['produit']['nom']) ?></span><span><?= prix($l['total']) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <p class="row"><span>Sous-total</span><strong><?= prix($subtotal) ?></strong></p>
        <p class="row" data-fee-row data-fee="<?= $fee ?>" data-sub="<?= $subtotal ?>"><span>Livraison</span><strong data-fee-val><?= $data['mode'] === 'livraison' ? prix($fee) : prix(0) ?></strong></p>
        <p class="row total"><span>Total</span><strong data-total><?= prix($subtotal + ($data['mode'] === 'livraison' ? $fee : 0)) ?></strong></p>
        <p class="pay-note"><?= icon('lock') ?> Paiement à la réception (CB ou espèces).</p>
        <label class="check"><input type="checkbox" name="cgv" value="1"> J'accepte que mes coordonnées soient utilisées pour traiter ma commande.</label>
        <?= $err('cgv') ?>
        <button class="btn btn-cta btn-block" type="submit"><?= icon('check') ?> Confirmer la commande</button>
        <a class="btn btn-ghost btn-block" href="<?= e(url('panier')) ?>">Modifier le panier</a>
    </aside>
</form>
