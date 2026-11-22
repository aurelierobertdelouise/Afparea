<?php $paid = in_array($cmd['statut'], STATUTS_ACTIFS, true); ?>
<?php if (!empty($_GET['ok'])): ?>
<ol class="tunnel"><li class="done"><span>1</span> Récapitulatif</li><li class="done"><span>2</span> Paiement</li><li class="on"><span>3</span> Confirmation</li></ol>
<?php endif; ?>
<article class="panel receipt">
    <?php if ($paid): ?>
        <div class="confirm-ico"><?= icon('check') ?></div>
        <h1 class="script center"><span class="g">Merci</span> <span class="y"><?= e($cmd['prenom']) ?>&nbsp;!</span></h1>
        <p class="center">Commande payée — un reçu a été envoyé à <strong><?= e($cmd['email']) ?></strong>.</p>
    <?php else: ?>
        <h1 class="h-card center">Commande <?= e($cmd['reference']) ?></h1>
        <p class="center"><span class="pill pill-r"><?= e(statut_label($cmd['statut'])) ?></span></p>
    <?php endif; ?>

    <div class="receipt-grid">
        <div class="receipt-code">
            <?php if ($paid): ?>
                <div class="qr" data-qr="<?= e(abs_url('gestion-commande', ['ref' => $cmd['reference']])) ?>" aria-label="QR code de retrait"></div>
                <p class="code-label">Code de retrait</p>
                <p class="code"><?= e($cmd['code_retrait']) ?></p>
            <?php endif; ?>
            <p class="ref">N° <?= e($cmd['reference']) ?></p>
        </div>
        <div>
            <p class="status-line"><span class="st st-<?= e($cmd['statut']) ?>"><?= e(statut_label($cmd['statut'])) ?></span></p>
            <p><?= icon('clock') ?> <?= e(date_fr($cmd['date_retrait'])) ?><?= $cmd['creneau'] ? ' — ' . e($cmd['creneau']) : '' ?></p>
            <p><?= icon('pin') ?> <?= e($cmd['centre_nom']) ?> — <?= e($cmd['point_relais']) ?></p>
            <ul class="sum-lines">
                <?php foreach ($lignes as $l): ?><li><span><?= (int) $l['quantite'] ?> × <?= e($l['nom']) ?></span><span><?= prix($l['quantite'] * $l['prix_cents']) ?></span></li><?php endforeach; ?>
            </ul>
            <p class="row total"><span>Total payé</span><strong><?= prix((int) $cmd['total_cents']) ?></strong></p>
            <?php if ($cmd['paid_at']): ?><p class="small muted">Payée le <?= e(date('d/m/Y à H:i', strtotime($cmd['paid_at']))) ?><?= $cmd['stripe_payment'] === 'simulation' ? ' (simulation)' : ' via Stripe' ?></p><?php endif; ?>
            <?php if ($cmd['remise_at']): ?><p class="small muted">Remise le <?= e(date('d/m/Y à H:i', strtotime($cmd['remise_at']))) ?></p><?php endif; ?>
        </div>
    </div>
    <?php if ($paid && $cmd['statut'] !== 'recuperee'): ?>
        <p class="hint"><?= icon('shop') ?> Présentez ce QR code ou votre code de retrait au point relais.</p>
    <?php endif; ?>
</article>
<p class="center no-print">
    <button class="btn btn-ghost" type="button" data-print><?= icon('print') ?> Imprimer</button>
    <a class="btn btn-green" href="<?= e(url('mes-commandes')) ?>">Mes commandes</a>
</p>
<script src="<?= e(asset('assets/js/vendor/qrcode.js')) ?>" defer></script>
