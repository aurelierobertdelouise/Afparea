<?php if ($cmd): ?>
    <?php if ($confirm): ?>
        <div class="panel confirm">
            <div class="confirm-ico"><?= icon('check') ?></div>
            <h1 class="script"><span class="g">Merci</span> <span class="y"><?= e(explode(' ', $cmd['nom'])[0]) ?> !</span></h1>
            <p>Votre commande <strong><?= e($cmd['reference']) ?></strong> est bien enregistrée.</p>
            <p class="muted">Conservez ce numéro : il vous permet de suivre votre commande avec votre téléphone.</p>
        </div>
    <?php else: ?>
        <h1 class="h-page">Commande <?= e($cmd['reference']) ?></h1>
    <?php endif; ?>

    <?php $steps = ['nouvelle', 'preparation', $cmd['mode'] === 'livraison' ? 'en_livraison' : 'prete', 'terminee'];
    $idx = array_search($cmd['statut'], $steps, true);
    if ($cmd['statut'] === 'prete' && $cmd['mode'] === 'livraison') { $idx = 1; } ?>
    <?php if ($cmd['statut'] === 'annulee'): ?>
        <div class="flash flash-error">Cette commande a été annulée.</div>
    <?php else: ?>
        <ol class="steps">
            <?php foreach ($steps as $i => $s): ?>
                <li class="<?= $idx !== false && $i <= $idx ? 'done' : '' ?>"><span><?= $i + 1 ?></span><?= e(statut_label($s)) ?></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <div class="info-grid">
        <div class="panel">
            <h2><?= icon($cmd['mode'] === 'livraison' ? 'truck' : 'shop') ?> <?= e(mode_label($cmd['mode'])) ?></h2>
            <p><?= icon('clock') ?> <?= e(date_fr($cmd['date_souhaitee'])) ?>, <?= e($cmd['creneau']) ?></p>
            <p><?= icon('pin') ?> <?= $cmd['mode'] === 'livraison' ? e($cmd['adresse'] . ', ' . $cmd['code_postal'] . ' ' . $cmd['ville']) : e(cfg('shop_address')) ?></p>
        </div>
        <div class="panel">
            <h2>Détail</h2>
            <ul class="sum-lines">
                <?php foreach ($lignes as $l): ?>
                    <li><span><?= (int) $l['quantite'] ?> × <?= e($l['nom']) ?></span><span><?= prix($l['quantite'] * $l['prix_cents']) ?></span></li>
                <?php endforeach; ?>
                <?php if ($cmd['frais_cents']): ?><li><span>Livraison</span><span><?= prix((int) $cmd['frais_cents']) ?></span></li><?php endif; ?>
            </ul>
            <p class="row total"><span>Total à régler</span><strong><?= prix((int) $cmd['total_cents']) ?></strong></p>
        </div>
    </div>
    <p class="center"><a class="btn btn-green" href="<?= e(url()) ?>">Retour à la boutique</a></p>
<?php else: ?>
    <h1 class="h-page">Suivre ma commande</h1>
    <form class="panel narrow" method="get" action="<?= e(url()) ?>">
        <input type="hidden" name="p" value="suivi">
        <div class="fields">
            <label class="full">Numéro de commande <input name="ref" value="<?= e($ref) ?>" placeholder="GA-261122-XXXX" required></label>
            <label class="full">Téléphone utilisé <input name="tel" type="tel" required></label>
        </div>
        <button class="btn btn-cta btn-block" type="submit">Voir ma commande</button>
    </form>
<?php endif; ?>
