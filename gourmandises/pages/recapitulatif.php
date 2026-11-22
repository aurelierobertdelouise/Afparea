<ol class="tunnel" aria-label="Étapes de la commande">
    <li class="on"><span>1</span> Récapitulatif</li>
    <li><span>2</span> Paiement</li>
    <li><span>3</span> Confirmation</li>
</ol>
<h1 class="h-page">Récapitulatif de ma commande</h1>
<?php foreach ($errors as $er): ?><div class="flash flash-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" action="<?= e(url('payer')) ?>" class="checkout">
    <?= csrf_field() ?>
    <div class="checkout-main">
        <fieldset class="panel">
            <legend><?= icon('pin') ?> Lieu de livraison</legend>
            <div class="fields">
                <label class="full">Centre de livraison
                    <select name="centre_id" data-centre-select>
                        <?php foreach ($centres as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" data-relais="<?= e($c['point_relais']) ?>" <?= (string) $c['id'] === (string) $data['centre_id'] ? 'selected' : '' ?>>
                                <?= e($c['nom']) ?><?= (int) $c['id'] === (int) $user['centre_id'] ? ' (mon centre)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="relais"><?= icon('shop') ?> Point relais : <strong data-relais><?= e(centre((int) $data['centre_id'])['point_relais'] ?? '') ?></strong></p>
            <p class="small muted">Votre centre habituel est proposé par défaut. Modifiez-le si vous êtes en mission sur un autre site.</p>
        </fieldset>

        <fieldset class="panel">
            <legend><?= icon('clock') ?> Date &amp; créneau de retrait</legend>
            <?php if (!$dates): ?>
                <div class="flash flash-info">Aucune date de retrait n'est ouverte pour le moment (<?= e(cutoff_text()) ?>).</div>
            <?php else: ?>
            <div class="fields">
                <label>Jour
                    <select name="date_retrait"><?php foreach ($dates as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === $data['date_retrait'] ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                </label>
                <?php if ($slots): ?>
                <label>Créneau
                    <select name="creneau"><?php foreach ($slots as $s): ?><option <?= $s === $data['creneau'] ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
                </label>
                <?php endif; ?>
            </div>
            <p class="small muted"><?= e(cutoff_text()) ?>.</p>
            <?php endif; ?>
        </fieldset>

        <fieldset class="panel">
            <legend><?= icon('user') ?> Commande au nom de</legend>
            <p><strong><?= e($user['prenom'] . ' ' . $user['nom']) ?></strong> — <?= e($user['email']) ?><?= $user['telephone'] ? ' — ' . e($user['telephone']) : '' ?></p>
            <p class="small"><a href="<?= e(url('profil')) ?>">Modifier mon profil</a></p>
        </fieldset>
    </div>

    <aside class="panel summary">
        <h2>Ma commande</h2>
        <ul class="sum-lines">
            <?php foreach ($lines as $l): ?>
                <li><span><?= (int) $l['quantite'] ?> × <?= e($l['produit']['nom']) ?></span><span><?= prix($l['total']) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php foreach ($totaux as $t): if ($t['quota'] > 0): ?>
            <p class="row small"><span><?= e($t['nom']) ?></span><span><?= (int) $t['total'] ?> / <?= (int) $t['quota'] ?></span></p>
        <?php endif; endforeach; ?>
        <p class="row total"><span>Total TTC</span><strong><?= prix($total) ?></strong></p>
        <p class="pay-note"><?= icon('lock') ?> Paiement immédiat et sécurisé par Stripe : carte bancaire, Apple Pay, Google Pay.</p>
        <button class="btn btn-cta btn-block" type="submit" <?= $dates ? '' : 'disabled' ?>><?= icon('card') ?> Payer <?= prix($total) ?></button>
        <a class="btn btn-ghost btn-block" href="<?= e(url('panier')) ?>">Modifier le panier</a>
    </aside>
</form>
