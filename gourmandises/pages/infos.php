<h1 class="h-page">Infos pratiques</h1>
<div class="info-grid">
    <div class="panel"><?= icon('truck') ?><h2>Livraison</h2><p>Frais de <?= prix((int) cfg('delivery_fee_cents')) ?><?php if (cfg('free_delivery_from_cents')): ?>, offerts dès <?= prix((int) cfg('free_delivery_from_cents')) ?> d'achat<?php endif; ?>. Choisissez votre jour et votre créneau lors de la commande.</p></div>
    <div class="panel"><?= icon('shop') ?><h2>Click &amp; Collect</h2><p>Retrait gratuit au point de vente :<br><strong><?= e(cfg('shop_address')) ?></strong></p><?php if (cfg('shop_phone')): ?><p>Tél. <?= e(cfg('shop_phone')) ?></p><?php endif; ?></div>
    <div class="panel"><?= icon('lock') ?><h2>Paiement sécurisé</h2><p>Réglez à la livraison ou au retrait (CB, espèces). Aucune donnée bancaire n'est saisie ni stockée sur le site.</p></div>
    <div class="panel"><?= icon('clock') ?><h2>Créneaux</h2><ul><?php foreach (cfg('slots', []) as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul></div>
</div>
