<ol class="tunnel"><li class="done"><span>1</span> Récapitulatif</li><li class="on"><span>2</span> Paiement</li><li><span>3</span> Confirmation</li></ol>
<div class="panel narrow paysim">
    <div class="flash flash-info"><strong>Mode simulation.</strong> La clé Stripe n'est pas configurée : ce paiement est fictif (phase de test / pilote). En production, cette étape est remplacée par la page de paiement sécurisée Stripe.</div>
    <h1 class="h-card">Commande <?= e($cmd['reference']) ?></h1>
    <ul class="sum-lines">
        <?php foreach ($lignes as $l): ?><li><span><?= (int) $l['quantite'] ?> × <?= e($l['nom']) ?></span><span><?= prix($l['quantite'] * $l['prix_cents']) ?></span></li><?php endforeach; ?>
    </ul>
    <p class="row total"><span>Total</span><strong><?= prix((int) $cmd['total_cents']) ?></strong></p>
    <form method="post">
        <?= csrf_field() ?>
        <button class="btn btn-cta btn-block" type="submit"><?= icon('card') ?> Simuler le paiement</button>
    </form>
    <a class="btn btn-ghost btn-block" href="<?= e(url('paiement-annule', ['ref' => $cmd['reference']])) ?>">Annuler</a>
</div>
