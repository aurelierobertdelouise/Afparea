<div class="admin-head">
    <h1 class="h-page">Commandes</h1>
    <div class="kpis">
        <div><b><?= (int) $stats['n'] ?></b><span>commandes</span></div>
        <div><b><?= prix((int) $stats['ca']) ?></b><span>chiffre d'affaires</span></div>
    </div>
</div>

<form class="panel filters" method="get" action="<?= e(url()) ?>">
    <input type="hidden" name="p" value="admin">
    <label>Statut
        <select name="statut" onchange="this.form.submit()">
            <option value="actives" <?= $statut === 'actives' ? 'selected' : '' ?>>À traiter</option>
            <option value="toutes" <?= $statut === 'toutes' ? 'selected' : '' ?>>Toutes</option>
            <?php foreach (statuts() as $k => $v): ?><option value="<?= e($k) ?>" <?= $statut === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label>Jour <input type="date" name="jour" value="<?= e($jour) ?>" onchange="this.form.submit()"></label>
    <?php if ($jour): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin', ['statut' => $statut])) ?>">Tous les jours</a><?php endif; ?>
</form>

<div class="admin-cols">
    <div class="panel table-wrap">
        <?php if (!$commandes): ?>
            <p class="empty">Aucune commande.</p>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Réf.</th><th>Client</th><th>Mode</th><th>Pour le</th><th>Total</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($commandes as $c): ?>
                <tr>
                    <td><a href="<?= e(url('admin-commande', ['id' => $c['id']])) ?>"><strong><?= e($c['reference']) ?></strong></a></td>
                    <td><?= e($c['nom']) ?><br><small class="muted"><?= e($c['telephone']) ?></small></td>
                    <td><span class="pill <?= $c['mode'] === 'livraison' ? 'pill-y' : 'pill-g' ?>"><?= e(mode_label($c['mode'])) ?></span></td>
                    <td><?= e(date('d/m', strtotime($c['date_souhaitee']))) ?><br><small class="muted"><?= e($c['creneau']) ?></small></td>
                    <td><?= prix((int) $c['total_cents']) ?></td>
                    <td>
                        <form method="post" action="<?= e(url('admin-commande', ['id' => $c['id']])) ?>">
                            <?= csrf_field() ?><input type="hidden" name="retour" value="liste">
                            <select name="statut" class="st st-<?= e($c['statut']) ?>" onchange="this.form.submit()">
                                <?php foreach (statuts() as $k => $v): ?><option value="<?= e($k) ?>" <?= $c['statut'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <aside class="panel fournee">
        <h2>Fournée du <?= e(date('d/m/Y', strtotime($jourProd))) ?></h2>
        <?php if (!$fournee): ?>
            <p class="muted">Rien à préparer.</p>
        <?php else: ?>
            <ul class="sum-lines"><?php foreach ($fournee as $f): ?><li><span><?= e($f['nom']) ?></span><strong><?= (int) $f['qte'] ?></strong></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <button class="btn btn-ghost btn-sm btn-block" onclick="window.print()">Imprimer</button>
    </aside>
</div>
