<div class="admin-head">
    <h1 class="h-page">Produits</h1>
    <a class="btn btn-cta btn-sm" href="<?= e(url('admin-produit')) ?>">+ Nouveau produit</a>
</div>
<div class="panel table-wrap">
    <table class="table">
        <thead><tr><th></th><th>Produit</th><th>Catégorie</th><th>Prix</th><th>En ligne</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($produits as $p): ?>
            <tr class="<?= $p['actif'] ? '' : 'off' ?>">
                <td><img class="thumb" src="<?= e(asset($p['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="" width="64" height="48"></td>
                <td><a href="<?= e(url('admin-produit', ['id' => $p['id']])) ?>"><strong><?= e($p['nom']) ?></strong></a></td>
                <td><?= e($p['categorie']) ?></td>
                <td><?= prix((int) $p['prix_cents']) ?></td>
                <td>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="toggle">
                        <button class="switch <?= $p['actif'] ? 'on' : '' ?>" aria-label="Activer / désactiver"><span></span></button>
                    </form>
                </td>
                <td class="right">
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin-produit', ['id' => $p['id']])) ?>">Modifier</a>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="delete">
                        <button class="icon-btn" data-confirm="Supprimer « <?= e($p['nom']) ?> » ?" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
