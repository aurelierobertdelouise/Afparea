<?php $page_key = 'gestion-production'; $noCentre = true; $total = array_sum(array_column($prod, 'qte')); ?>
<div class="admin-head">
    <h1 class="h-page">Récapitulatif fournil — <?= e(date_fr($jour)) ?></h1>
    <div class="toolbar no-print"><button class="btn btn-ghost btn-sm" data-print><?= icon('print') ?> Imprimer</button></div>
</div>
<?php require __DIR__ . '/_filtres.php'; ?>
<?php if (!$prod): ?>
    <div class="panel empty"><p>Aucune commande payée pour ce jour.</p></div>
<?php else: ?>
<div class="panel">
    <h2>Quantités totales à fabriquer <span class="pill"><?= (int) $total ?> pièces</span></h2>
    <div class="prod-tiles">
        <?php foreach ($prod as $p): ?><div class="tile"><b><?= (int) $p['qte'] ?></b><span><?= e($p['nom']) ?></span><small><?= e($p['categorie']) ?></small></div><?php endforeach; ?>
    </div>
</div>
<div class="panel table-wrap">
    <h2>Répartition par centre</h2>
    <table class="table matrix">
        <thead><tr><th>Produit</th><?php foreach ($centres as $c): ?><th><?= e($c['nom']) ?></th><?php endforeach; ?><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($prod as $p): ?>
            <tr><th><?= e($p['nom']) ?></th>
                <?php foreach ($centres as $c): $v = $matrice[$p['nom']][$c['nom']] ?? 0; ?><td class="<?= $v ? '' : 'zero' ?>"><?= $v ?: '·' ?></td><?php endforeach; ?>
                <td><b><?= (int) $p['qte'] ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
