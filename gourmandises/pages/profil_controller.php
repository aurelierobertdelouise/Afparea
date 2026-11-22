<?php
declare(strict_types=1);

/** @var array $user */
$errors = [];
if (is_post()) {
    csrf_check();
    if (post('action') === 'supprimer') {
        // Droit à l'effacement (§7.1) : compte supprimé, commandes passées anonymisées.
        if (post('confirmation') !== 'SUPPRIMER') {
            flash('error', 'Tapez SUPPRIMER pour confirmer.');
            redirect(url('profil'));
        }
        $pdo = db();
        $pdo->prepare("UPDATE commandes SET nom='Compte supprimé', prenom='', email='', telephone='', user_id=NULL WHERE user_id = ?")->execute([$user['id']]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
        logout();
        session_start();
        flash('success', 'Votre compte et vos données personnelles ont été supprimés.');
        redirect(url('connexion'));
    }
    $d = [
        'prenom' => mb_substr(post('prenom'), 0, 120), 'nom' => mb_substr(post('nom'), 0, 120),
        'fonction' => mb_substr(post('fonction'), 0, 120), 'telephone' => mb_substr(post('telephone'), 0, 30),
        'centre_id' => (int) post('centre_id'),
    ];
    if ($d['prenom'] === '' || $d['nom'] === '') { $errors[] = 'Nom et prénom sont obligatoires.'; }
    if (!centre($d['centre_id'])) { $errors[] = 'Centre invalide.'; }
    if ($d['telephone'] !== '' && !preg_match('/^\+?[0-9 .\-]{9,20}$/', $d['telephone'])) { $errors[] = 'Numéro de téléphone invalide.'; }
    if (!$errors) {
        db()->prepare('UPDATE users SET prenom=?, nom=?, fonction=?, telephone=?, centre_id=? WHERE id=?')
            ->execute([$d['prenom'], mb_strtoupper($d['nom']), $d['fonction'], $d['telephone'], $d['centre_id'], $user['id']]);
        flash('success', 'Profil mis à jour.');
        redirect(url('profil'));
    }
}
render('profil', ['title' => 'Mon profil', 'u' => current_user(), 'errors' => $errors]);
