<h1 class="h-page">Centres &amp; points relais</h1>
<div class="cat-admin">
    <?php foreach ([...$centres, ['id' => 0, 'nom' => '', 'point_relais' => '', 'actif' => 1, 'ordre' => count($centres) + 1]] as $c): ?>
    <form method="post" class="panel">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <h2><?= icon('pin') ?> <?= $c['id'] ? e($c['nom']) : 'Nouveau centre' ?></h2>
        <?php if ($c['id']): ?><p class="small muted"><?= (int) ($counts[$c['id']] ?? 0) ?> salarié(s) rattaché(s)</p><?php endif; ?>
        <div class="fields">
            <label class="full">Nom <input name="nom" value="<?= e($c['nom']) ?>" required></label>
            <label class="full">Point relais <input name="point_relais" value="<?= e($c['point_relais']) ?>" placeholder="Accueil, Salle des formateurs, Secrétariat…"></label>
            <label>Ordre <input name="ordre" type="number" value="<?= (int) $c['ordre'] ?>"></label>
            <label class="check"><input type="checkbox" name="actif" value="1" <?= $c['actif'] ? 'checked' : '' ?>> Livré actuellement</label>
        </div>
        <div class="row-actions"><button class="btn btn-cta btn-sm">Enregistrer</button></div>
    </form>
    <?php endforeach; ?>
</div>
