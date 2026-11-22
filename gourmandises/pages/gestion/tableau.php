<?php $page_key = 'gestion'; ?>
<div class="admin-head">
    <h1 class="h-page">Commandes du <?= e(date_fr($jour)) ?></h1>
    <div class="kpis">
        <div><b><?= (int) $stats['n'] ?></b><span>commandes payées</span></div>
        <div><b><?= prix((int) $stats['ca']) ?></b><span>encaissé</span></div>
    </div>
</div>
<?php require __DIR__ . '/_filtres.php'; ?>
<div class="toolbar no-print">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('gestion-export', array_filter(['jour' => $jour, 'centre' => $centreId ?: null]))) ?>"><?= icon('download') ?> Export CSV / Excel</a>
    <form method="get" action="<?= e(url()) ?>" class="inline-search">
        <input type="hidden" name="p" value="gestion-commande"><input type="hidden" name="jour" value="<?= e($jour) ?>">
        <input name="code" inputmode="numeric" placeholder="Code de retrait" aria-label="Code de retrait" maxlength="4">
        <button class="btn btn-green btn-sm">Chercher</button>
    </form>
</div>
<div class="kanban">
    <?php foreach (['payee' => 'À préparer', 'prete' => 'Prête', 'recuperee' => 'Récupérée'] as $st => $lbl):
        $next = ['payee' => 'prete', 'prete' => 'recuperee', 'recuperee' => null][$st]; ?>
        <section class="kcol kcol-<?= $st ?>">
            <h2><?= e($lbl) ?> <span class="count"><?= count($cols[$st]) ?></span></h2>
            <?php if (!$cols[$st]): ?><p class="muted small center">—</p><?php endif; ?>
            <?php foreach ($cols[$st] as $c): ?>
                <article class="kcard">
                    <a href="<?= e(url('gestion-commande', ['ref' => $c['reference']])) ?>" class="kcard-head">
                        <strong><?= e($c['prenom'] . ' ' . $c['nom']) ?></strong>
                        <span class="muted small"><?= e($c['reference']) ?> · code <?= e($c['code_retrait']) ?></span>
                    </a>
                    <p class="small"><?= icon('pin') ?> <?= e($c['centre_nom']) ?><?= $c['creneau'] ? ' · ' . e($c['creneau']) : '' ?></p>
                    <ul class="mini">
                        <?php foreach ($lignes[$c['id']] ?? [] as $l): ?><li><b><?= (int) $l['quantite'] ?></b> <?= e($l['nom']) ?></li><?php endforeach; ?>
                    </ul>
                    <?php if ($next): ?>
                    <form method="post" action="<?= e(url('gestion-statut')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="ref" value="<?= e($c['reference']) ?>">
                        <input type="hidden" name="statut" value="<?= $next ?>">
                        <input type="hidden" name="retour" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                        <button class="btn btn-sm btn-block <?= $next === 'recuperee' ? 'btn-cta' : 'btn-green' ?>"><?= $next === 'prete' ? 'Marquer prête →' : 'Remise au salarié ✓' ?></button>
                    </form>
                    <?php else: ?>
                        <p class="small muted">Remise <?= $c['remise_at'] ? 'à ' . e(date('H:i', strtotime($c['remise_at']))) : '' ?> <?= $c['remise_par'] ? 'par ' . e($c['remise_par']) : '' ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
