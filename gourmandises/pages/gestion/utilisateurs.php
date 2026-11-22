<div class="admin-head">
    <h1 class="h-page">Utilisateurs &amp; rôles</h1>
    <form method="get" action="<?= e(url()) ?>" class="inline-search">
        <input type="hidden" name="p" value="gestion-utilisateurs">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom ou e-mail" aria-label="Rechercher">
        <button class="btn btn-green btn-sm">Rechercher</button>
    </form>
</div>
<p class="lead-admin"><strong>Salarié</strong> : voit uniquement ses commandes. <strong>Référent centre</strong> : tableau du jour et émargement de son centre uniquement. <strong>Admin / Boulangerie</strong> : tous les centres, fournil, colisage, catalogue.</p>
<div class="panel table-wrap">
    <table class="table">
        <thead><tr><th>Salarié</th><th>Fonction</th><th>Centre</th><th>Rôle</th><th>Dernière connexion</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= e(trim($u['prenom'] . ' ' . $u['nom']) ?: '(profil incomplet)') ?></strong><br><small class="muted"><?= e($u['email']) ?></small></td>
                <td class="small"><?= e($u['fonction']) ?></td>
                <td colspan="2">
                    <form method="post" class="role-form">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <select name="centre_id" aria-label="Centre"><option value="0">—</option><?php foreach ($centres as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $u['centre_id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select>
                        <select name="role" aria-label="Rôle"><?php foreach (ROLES as $k => $v): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                        <button class="btn btn-ghost btn-sm">OK</button>
                    </form>
                </td>
                <td class="small"><?= $u['last_login'] ? e(date('d/m/Y H:i', strtotime($u['last_login']))) : '—' ?></td>
                <td class="right">
                    <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="delete">
                        <button class="icon-btn" data-confirm="Supprimer le compte de <?= e($u['email']) ?> ? Ses commandes seront anonymisées." aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<form method="post" class="panel rgpd-admin">
    <?= csrf_field() ?><input type="hidden" name="action" value="purge">
    <h2><?= icon('lock') ?> RGPD — purge des données</h2>
    <p class="small">Les commandes dont la date de retrait dépasse <?= (int) cfg('retention_months', 6) ?> mois sont supprimées automatiquement (contrôle horaire). Dernière exécution : <strong><?= e(setting('last_purge', 'jamais')) ?></strong>.</p>
    <button class="btn btn-ghost btn-sm">Lancer la purge maintenant</button>
</form>
