<?php $page_key = 'gestion-colisage'; ?>
<div class="admin-head">
    <h1 class="h-page">Feuilles de colisage — <?= e(date_fr($jour)) ?></h1>
    <div class="toolbar no-print"><button class="btn btn-ghost btn-sm" data-print><?= icon('print') ?> Imprimer</button></div>
</div>
<?php require __DIR__ . '/_filtres.php'; ?>
<?php if (!$parCentre): ?>
    <div class="panel empty"><p>Aucune commande à expédier pour ce jour.</p></div>
<?php endif; ?>
<?php foreach ($parCentre as $nom => $pc): ?>
    <section class="panel sheet">
        <header class="sheet-head">
            <div><h2><?= icon('truck') ?> <?= e($nom) ?></h2><p class="muted">Point relais : <?= e($pc['point_relais']) ?> · <?= e(date_fr($jour)) ?></p></div>
            <div class="sheet-count"><b><?= count($pc['commandes']) ?></b><span>sac<?= count($pc['commandes']) > 1 ? 's' : '' ?> nominatif<?= count($pc['commandes']) > 1 ? 's' : '' ?></span></div>
        </header>
        <div class="sheet-cols">
            <div>
                <h3>Total du bac</h3>
                <ul class="sum-lines"><?php ksort($pc['produits']); foreach ($pc['produits'] as $p => $q): ?><li><span><?= e($p) ?></span><b><?= (int) $q ?></b></li><?php endforeach; ?></ul>
            </div>
            <div>
                <h3>Détail des sacs</h3>
                <table class="table compact">
                    <?php foreach ($pc['commandes'] as $c): ?>
                        <tr><td>☐</td><td><strong><?= e($c['nom'] . ' ' . $c['prenom']) ?></strong><br><small class="muted"><?= e($c['reference']) ?></small></td>
                            <td><?php foreach ($lignes[$c['id']] ?? [] as $l): ?><?= (int) $l['quantite'] ?> <?= e($l['nom']) ?><br><?php endforeach; ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <p class="sign">Préparé par : ______________________ &nbsp; Remis au transport le : ____/____ à ____h____</p>
    </section>
<?php endforeach; ?>
