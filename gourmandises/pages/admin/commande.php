<p class="crumbs"><a href="<?= e(url('admin')) ?>">← Commandes</a></p>
<div class="admin-head">
    <h1 class="h-page">Commande <?= e($cmd['reference']) ?></h1>
    <form method="post" class="inline">
        <?= csrf_field() ?>
        <select name="statut" class="st st-<?= e($cmd['statut']) ?>" onchange="this.form.submit()">
            <?php foreach (statuts() as $k => $v): ?><option value="<?= e($k) ?>" <?= $cmd['statut'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>
<div class="info-grid">
    <div class="panel">
        <h2>Client</h2>
        <p><strong><?= e($cmd['nom']) ?></strong></p>
        <p><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $cmd['telephone'])) ?>"><?= e($cmd['telephone']) ?></a></p>
        <?php if ($cmd['email']): ?><p><a href="mailto:<?= e($cmd['email']) ?>"><?= e($cmd['email']) ?></a></p><?php endif; ?>
        <p class="muted small">Passée le <?= e(date('d/m/Y à H:i', strtotime($cmd['created_at']))) ?></p>
    </div>
    <div class="panel">
        <h2><?= icon($cmd['mode'] === 'livraison' ? 'truck' : 'shop') ?> <?= e(mode_label($cmd['mode'])) ?></h2>
        <p><?= icon('clock') ?> <?= e(date('d/m/Y', strtotime($cmd['date_souhaitee']))) ?> — <?= e($cmd['creneau']) ?></p>
        <?php if ($cmd['mode'] === 'livraison'): ?>
            <p><?= icon('pin') ?> <?= e($cmd['adresse']) ?>, <?= e($cmd['code_postal'] . ' ' . $cmd['ville']) ?></p>
        <?php endif; ?>
        <?php if ($cmd['commentaire']): ?><p class="note">« <?= e($cmd['commentaire']) ?> »</p><?php endif; ?>
    </div>
    <div class="panel">
        <h2>Articles</h2>
        <ul class="sum-lines">
            <?php foreach ($lignes as $l): ?><li><span><?= (int) $l['quantite'] ?> × <?= e($l['nom']) ?></span><span><?= prix($l['quantite'] * $l['prix_cents']) ?></span></li><?php endforeach; ?>
            <?php if ($cmd['frais_cents']): ?><li><span>Livraison</span><span><?= prix((int) $cmd['frais_cents']) ?></span></li><?php endif; ?>
        </ul>
        <p class="row total"><span>Total</span><strong><?= prix((int) $cmd['total_cents']) ?></strong></p>
    </div>
</div>
<form method="post" class="center">
    <?= csrf_field() ?><input type="hidden" name="suppr" value="1">
    <button class="btn btn-ghost btn-sm danger" data-confirm="Supprimer définitivement cette commande ?">Supprimer la commande</button>
</form>
