<p class="crumbs"><a href="<?= e(url('gestion', ['jour' => $cmd['date_retrait']])) ?>">← Tableau du <?= e(date_fr($cmd['date_retrait'], false)) ?></a></p>
<div class="admin-head">
    <h1 class="h-page">Commande <?= e($cmd['reference']) ?></h1>
    <span class="st st-<?= e($cmd['statut']) ?> big"><?= e(statut_label($cmd['statut'])) ?></span>
</div>
<?php if ($cmd['date_retrait'] !== date('Y-m-d') && in_array($cmd['statut'], ['payee', 'prete'], true)): ?>
    <div class="flash flash-info">Attention : cette commande est prévue pour le <?= e(date_fr($cmd['date_retrait'])) ?>.</div>
<?php endif; ?>
<div class="info-grid">
    <div class="panel">
        <h2><?= icon('user') ?> Salarié</h2>
        <p class="bigname"><?= e($cmd['prenom'] . ' ' . $cmd['nom']) ?></p>
        <?php if ($cmd['email']): ?><p class="small"><?= e($cmd['email']) ?></p><?php endif; ?>
        <?php if ($cmd['telephone']): ?><p class="small"><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $cmd['telephone'])) ?>"><?= e($cmd['telephone']) ?></a></p><?php endif; ?>
        <p class="code-inline">Code de retrait : <strong><?= e($cmd['code_retrait']) ?></strong></p>
    </div>
    <div class="panel">
        <h2><?= icon('pin') ?> Livraison</h2>
        <p><?= e($cmd['centre_nom']) ?> — <?= e($cmd['point_relais']) ?></p>
        <p><?= icon('clock') ?> <?= e(date_fr($cmd['date_retrait'])) ?><?= $cmd['creneau'] ? ' · ' . e($cmd['creneau']) : '' ?></p>
        <?php if ($cmd['paid_at']): ?><p class="small muted">Payée le <?= e(date('d/m/Y H:i', strtotime($cmd['paid_at']))) ?><?= $cmd['stripe_payment'] && $cmd['stripe_payment'] !== 'simulation' ? ' · Stripe ' . e($cmd['stripe_payment']) : '' ?></p><?php endif; ?>
        <?php if ($cmd['remise_at']): ?><p class="small muted">Remise le <?= e(date('d/m/Y H:i', strtotime($cmd['remise_at']))) ?> par <?= e($cmd['remise_par']) ?></p><?php endif; ?>
    </div>
    <div class="panel">
        <h2><?= icon('box') ?> Contenu</h2>
        <ul class="sum-lines"><?php foreach ($lignes as $l): ?><li><span><?= (int) $l['quantite'] ?> × <?= e($l['nom']) ?></span><span><?= prix($l['quantite'] * $l['prix_cents']) ?></span></li><?php endforeach; ?></ul>
        <p class="row total"><span>Total</span><strong><?= prix((int) $cmd['total_cents']) ?></strong></p>
    </div>
</div>
<?php if ($cmd['statut'] !== 'en_attente'): ?>
<div class="panel actions-bar">
    <?php
    $actions = ['payee' => 'À préparer', 'prete' => 'Prête', 'recuperee' => 'Remise au salarié ✓'];
    if ($isAdmin) { $actions['annulee'] = 'Annuler'; }
    foreach ($actions as $s => $lbl): if ($s === $cmd['statut']) continue; ?>
        <form method="post" action="<?= e(url('gestion-statut')) ?>">
            <?= csrf_field() ?><input type="hidden" name="ref" value="<?= e($cmd['reference']) ?>"><input type="hidden" name="statut" value="<?= $s ?>">
            <button class="btn <?= $s === 'recuperee' ? 'btn-cta' : ($s === 'annulee' ? 'btn-ghost danger' : 'btn-ghost') ?>" <?= $s === 'annulee' ? 'data-confirm="Annuler cette commande ? (le remboursement se fait depuis le tableau de bord Stripe)"' : '' ?>><?= e($lbl) ?></button>
        </form>
    <?php endforeach; ?>
</div>
<?php endif; ?>
