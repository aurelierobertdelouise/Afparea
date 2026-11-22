<?php $err = fn(string $k) => isset($errors[$k]) ? '<span class="err">' . e($errors[$k]) . '</span>' : ''; ?>
<form method="post" action="<?= e(url('bienvenue')) ?>" class="panel auth-card wide">
    <?= csrf_field() ?>
    <h1 class="h-card">Bienvenue&nbsp;!</h1>
    <p class="muted">Première connexion avec <strong><?= e($u['email']) ?></strong>. Complétez votre profil : votre centre de rattachement sera votre lieu de livraison par défaut.</p>

    <div class="fields">
        <label>Prénom <input name="prenom" value="<?= e($data['prenom']) ?>" autocomplete="given-name" required><?= $err('prenom') ?></label>
        <label>Nom <input name="nom" value="<?= e($data['nom']) ?>" autocomplete="family-name" required><?= $err('nom') ?></label>
        <label>Fonction <input name="fonction" value="<?= e($data['fonction']) ?>" placeholder="Ex. : Formateur·rice, Assistant·e…"></label>
        <label>Centre / lieu de travail
            <select name="centre_id" required>
                <option value="">— Choisir —</option>
                <?php foreach (centres() as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) $c['id'] === $data['centre_id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
            </select><?= $err('centre_id') ?>
        </label>
        <label class="full"><span>Téléphone professionnel <small class="muted">(facultatif, pour être prévenu·e du retrait)</small></span>
            <input name="telephone" type="tel" value="<?= e($data['telephone']) ?>" autocomplete="tel"><?= $err('telephone') ?>
        </label>
    </div>

    <div class="rgpd-box">
        <h2><?= icon('lock') ?> Vos données personnelles</h2>
        <ul>
            <li><strong>Finalité :</strong> uniquement la confection, l'expédition et la remise de vos commandes. Aucun usage marketing, aucune transmission à des tiers.</li>
            <li><strong>Données collectées :</strong> nom, prénom, e-mail professionnel, fonction, centre d'affectation, téléphone professionnel (facultatif).</li>
            <li><strong>Paiement :</strong> vos données bancaires sont saisies exclusivement chez Stripe (certifié PCI-DSS) et ne sont jamais stockées par l'AFPAR.</li>
            <li><strong>Conservation :</strong> vos commandes sont supprimées automatiquement au bout de <?= (int) cfg('retention_months', 6) ?> mois.</li>
            <li><strong>Vos droits :</strong> accès, rectification et suppression depuis « Mon profil » ou auprès du DPO de l'AFPAR : <a href="mailto:<?= e(cfg('dpo_email')) ?>"><?= e(cfg('dpo_email')) ?></a>.</li>
        </ul>
        <label class="check"><input type="checkbox" name="consent" value="1"> J'ai pris connaissance de ces informations et des <a href="<?= e(url('mentions')) ?>" target="_blank" rel="noopener">mentions légales</a>.</label>
        <?= $err('consent') ?>
    </div>

    <button class="btn btn-cta btn-block" type="submit"><?= icon('check') ?> Accéder au catalogue</button>
    <p class="center"><a class="small" href="<?= e(url('deconnexion')) ?>">Se déconnecter</a></p>
</form>
