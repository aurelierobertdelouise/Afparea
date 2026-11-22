<h1 class="h-page">Mes commandes</h1>
<?php if (!$commandes): ?>
    <div class="panel empty"><p>Vous n'avez pas encore passé de commande.</p><a class="btn btn-green" href="<?= e(url()) ?>">Voir le catalogue</a></div>
<?php else: ?>
    <div class="orders">
        <?php foreach ($commandes as $c): ?>
            <a class="panel order-card" href="<?= e(url('commande', ['ref' => $c['reference']])) ?>">
                <div>
                    <strong><?= e(date_fr($c['date_retrait'])) ?></strong>
                    <span class="muted small"><?= e($c['reference']) ?> · <?= e($c['centre_nom']) ?></span>
                </div>
                <span class="st st-<?= e($c['statut']) ?>"><?= e(statut_label($c['statut'])) ?></span>
                <strong class="price"><?= prix((int) $c['total_cents']) ?></strong>
            </a>
        <?php endforeach; ?>
    </div>
    <p class="small muted center">Les commandes sont conservées <?= (int) cfg('retention_months', 6) ?> mois puis supprimées automatiquement.</p>
<?php endif; ?>
