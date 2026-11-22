<h1 class="h-page">Mon profil</h1>
<?php foreach ($errors as $er): ?><div class="flash flash-error"><?= e($er) ?></div><?php endforeach; ?>
<div class="admin-form">
    <form method="post" class="panel">
        <?= csrf_field() ?>
        <div class="fields">
            <label>Prénom <input name="prenom" value="<?= e($u['prenom']) ?>" required></label>
            <label>Nom <input name="nom" value="<?= e($u['nom']) ?>" required></label>
            <label class="full">E-mail professionnel <input value="<?= e($u['email']) ?>" disabled></label>
            <label>Fonction <input name="fonction" value="<?= e($u['fonction']) ?>"></label>
            <label>Téléphone pro <small class="muted">(facultatif)</small> <input name="telephone" type="tel" value="<?= e($u['telephone']) ?>"></label>
            <label class="full">Centre de rattachement (livraison par défaut)
                <select name="centre_id"><?php foreach (centres() as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $u['centre_id'] ? 'selected' : '' ?>><?= e($c['nom']) ?> — <?= e($c['point_relais']) ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <button class="btn btn-cta btn-block" type="submit">Enregistrer</button>
    </form>
    <div>
        <div class="panel">
            <h2><?= icon('lock') ?> Mes données</h2>
            <p class="small">Rôle : <strong><?= e(ROLES[$u['role']] ?? $u['role']) ?></strong><br>
            Compte créé le <?= e(date('d/m/Y', strtotime($u['created_at']))) ?><br>
            Information RGPD acceptée le <?= e(date('d/m/Y', strtotime((string) $u['consent_at']))) ?></p>
            <p class="small">Vos commandes sont supprimées après <?= (int) cfg('retention_months', 6) ?> mois. Pour toute question : <a href="mailto:<?= e(cfg('dpo_email')) ?>">DPO AFPAR</a>.</p>
            <a class="btn btn-ghost btn-sm btn-block" href="<?= e(url('deconnexion')) ?>"><?= icon('logout') ?> Se déconnecter</a>
        </div>
        <form method="post" class="panel danger-zone">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="supprimer">
            <h2>Supprimer mon compte</h2>
            <p class="small">Vos informations personnelles seront effacées ; l'historique comptable des commandes est anonymisé.</p>
            <label>Tapez SUPPRIMER pour confirmer <input name="confirmation" autocomplete="off"></label>
            <button class="btn btn-ghost btn-sm btn-block danger" type="submit">Supprimer définitivement</button>
        </form>
    </div>
</div>
