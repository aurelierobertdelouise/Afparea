<p class="crumbs"><a href="<?= e(url('gestion-produits')) ?>">← Catalogue</a></p>
<h1 class="h-page">Catégories &amp; quotas</h1>
<p class="lead-admin">Le <strong>quota</strong> est le nombre maximum d'articles de la catégorie par commande (0 = sans limite). Au-delà, le panier affiche une alerte rouge et masque le bouton de paiement.</p>
<div class="cat-admin">
    <?php foreach ([...$cats, ['id' => 0, 'slug' => '', 'nom' => '', 'accroche' => '', 'description' => '', 'image' => '', 'quota' => 0, 'ordre' => count($cats) + 1]] as $c): ?>
    <form method="post" enctype="multipart/form-data" class="panel">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="image_actuelle" value="<?= e($c['image']) ?>">
        <h2><?= $c['id'] ? e($c['nom']) : 'Nouvelle catégorie' ?></h2>
        <?php if ($c['image']): ?><img class="preview" src="<?= e(asset($c['image'])) ?>" alt=""><?php endif; ?>
        <div class="fields">
            <label>Nom <input name="nom" value="<?= e($c['nom']) ?>" required></label>
            <label>Quota / commande <input name="quota" type="number" min="0" value="<?= (int) $c['quota'] ?>"></label>
            <label class="full">Description <textarea name="description" rows="2"><?= e($c['description']) ?></textarea></label>
            <label>Ordre <input name="ordre" type="number" value="<?= (int) $c['ordre'] ?>"></label>
            <label>Bandeau <input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
        </div>
        <div class="row-actions">
            <button class="btn btn-cta btn-sm" name="action" value="save">Enregistrer</button>
            <?php if ($c['id']): ?><button class="btn btn-ghost btn-sm danger" name="action" value="delete" data-confirm="Supprimer cette catégorie ?">Supprimer</button><?php endif; ?>
        </div>
    </form>
    <?php endforeach; ?>
</div>
