<div class="admin-head">
    <h1 class="h-page">Catalogue</h1>
    <div class="toolbar">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('gestion-categories')) ?>">Catégories &amp; quotas</a>
        <a class="btn btn-cta btn-sm" href="<?= e(url('gestion-produit')) ?>">+ Nouveau produit</a>
    </div>
</div>
<form class="panel filters" method="get" action="<?= e(url()) ?>">
    <input type="hidden" name="p" value="gestion-produits">
    <label>Réservations du <input type="date" name="jour" value="<?= e($jour) ?>" data-autosubmit></label>
    <p class="small muted grow"><strong>Disponible</strong> = en stock / épuisé (bascule immédiate). <strong>Stock du jour</strong> = maximum fabriqué par jour de retrait (vide = illimité). <strong>En ligne</strong> = visible au catalogue.</p>
</form>
<div class="panel table-wrap">
    <table class="table">
        <thead><tr><th></th><th>Produit</th><th>Prix</th><th>Disponible</th><th>Stock du jour</th><th>Réservé le <?= e(date('d/m', strtotime($jour))) ?></th><th>En ligne</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($produits as $p): ?>
            <tr class="<?= $p['actif'] ? '' : 'off' ?>">
                <td><img class="thumb" src="<?= e(asset($p['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="" width="64" height="48"></td>
                <td><a href="<?= e(url('gestion-produit', ['id' => $p['id']])) ?>"><strong><?= e($p['nom']) ?></strong></a><br><small class="muted"><?= e($p['categorie']) ?></small></td>
                <td><?= prix((int) $p['prix_cents']) ?></td>
                <td>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="dispo">
                        <button class="switch <?= $p['disponible'] ? 'on' : '' ?>" aria-label="<?= $p['disponible'] ? 'Marquer épuisé' : 'Remettre en stock' ?>"><span></span></button>
                        <small><?= $p['disponible'] ? 'En stock' : 'Épuisé' ?></small>
                    </form>
                </td>
                <td>
                    <form method="post" class="stock-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="stock">
                        <input type="number" name="stock_jour" min="0" value="<?= $p['stock_jour'] === null ? '' : (int) $p['stock_jour'] ?>" placeholder="∞" aria-label="Stock du jour">
                        <button class="btn btn-ghost btn-sm">OK</button>
                    </form>
                </td>
                <td><b><?= (int) $p['reserve'] ?></b><?= $p['stock_jour'] !== null ? ' / ' . (int) $p['stock_jour'] : '' ?></td>
                <td>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="action" value="actif">
                        <button class="switch <?= $p['actif'] ? 'on' : '' ?>" aria-label="Afficher / masquer"><span></span></button>
                    </form>
                </td>
                <td class="right"><a class="btn btn-ghost btn-sm" href="<?= e(url('gestion-produit', ['id' => $p['id']])) ?>">Modifier</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
