<p class="crumbs"><a href="<?= e(url('gestion-produits')) ?>">← Catalogue</a></p>
<h1 class="h-page"><?= e($title) ?></h1>
<?php foreach ($errors as $er): ?><div class="flash flash-error"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" class="admin-form">
    <?= csrf_field() ?>
    <div class="panel">
        <div class="fields">
            <label class="full">Nom <input name="nom" value="<?= e($prod['nom']) ?>" required></label>
            <label>Catégorie
                <select name="categorie_id"><?php foreach (categories() as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $prod['categorie_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Prix TTC (€) <input name="prix" value="<?= e(number_format($prod['prix_cents'] / 100, 2, ',', '')) ?>" inputmode="decimal" required></label>
            <label class="full">Description <textarea name="description" rows="3"><?= e($prod['description']) ?></textarea></label>
            <label>Allergènes <input name="allergenes" value="<?= e($prod['allergenes']) ?>" placeholder="Gluten, lait, œuf…"></label>
            <label>Conservation <input name="conservation" value="<?= e($prod['conservation']) ?>" placeholder="À consommer dans la journée"></label>
            <label>Stock maximum par jour <small class="muted">(vide = illimité)</small> <input name="stock_jour" type="number" min="0" value="<?= $prod['stock_jour'] === null ? '' : (int) $prod['stock_jour'] ?>"></label>
            <label>Ordre d'affichage <input name="ordre" type="number" value="<?= (int) $prod['ordre'] ?>"></label>
            <label class="check"><input type="checkbox" name="disponible" value="1" <?= $prod['disponible'] ? 'checked' : '' ?>> Disponible (en stock)</label>
            <label class="check"><input type="checkbox" name="actif" value="1" <?= $prod['actif'] ? 'checked' : '' ?>> Visible dans le catalogue</label>
        </div>
    </div>
    <div class="panel">
        <h2>Photo</h2>
        <img class="preview" data-preview src="<?= e(asset($prod['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="">
        <label>Remplacer l'image (JPG, PNG, WEBP – 4 Mo max) <input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-preview-input></label>
        <button class="btn btn-cta btn-block" type="submit">Enregistrer</button>
    </div>
</form>
