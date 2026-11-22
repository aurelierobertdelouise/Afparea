<?php
declare(strict_types=1);

/** @var array $user  @var string $page */

// -------------------------------------------- Retour / annulation paiement
if ($page === 'paiement-retour') {
    $cmd = commande_by_ref(strtoupper(get('ref')));
    if (!$cmd || (int) $cmd['user_id'] !== (int) $user['id']) {
        not_found();
    }
    if ($cmd['statut'] === 'en_attente' && stripe_enabled() && get('session_id') !== '') {
        $paid = stripe_confirm_session(get('session_id'));
        $cmd = $paid ?: $cmd;
    }
    if ($cmd['statut'] !== 'en_attente') {
        cart_clear();
        redirect(url('commande', ['ref' => $cmd['reference'], 'ok' => 1]));
    }
    flash('info', 'Paiement en cours de validation par Stripe. Votre reçu apparaîtra dans « Mes commandes » dès confirmation.');
    redirect(url('mes-commandes'));
}

if ($page === 'paiement-annule') {
    $cmd = commande_by_ref(strtoupper(get('ref')));
    if ($cmd && (int) $cmd['user_id'] === (int) $user['id'] && $cmd['statut'] === 'en_attente') {
        db()->prepare("UPDATE commandes SET statut = 'annulee' WHERE id = ?")->execute([$cmd['id']]);
    }
    flash('info', 'Paiement annulé : aucune somme n\'a été débitée. Votre panier est conservé.');
    redirect(url('panier'));
}

// ----------------------------- Paiement simulé (uniquement sans clé Stripe)
if ($page === 'paiement-simulation') {
    if (stripe_enabled()) {
        not_found();
    }
    $cmd = commande_by_ref(strtoupper(get('ref')));
    if (!$cmd || (int) $cmd['user_id'] !== (int) $user['id']) {
        not_found();
    }
    if ($cmd['statut'] !== 'en_attente') {
        redirect(url('commande', ['ref' => $cmd['reference']]));
    }
    if (is_post()) {
        csrf_check();
        mark_order_paid($cmd['reference'], 'simulation', 'simulation');
        cart_clear();
        redirect(url('commande', ['ref' => $cmd['reference'], 'ok' => 1]));
    }
    render('paiement_simulation', ['title' => 'Paiement', 'cmd' => $cmd, 'lignes' => commande_lignes((int) $cmd['id'])]);
    return;
}

// --------------------------------------------- Étape 1 : récapitulatif
$lines = cart_lines();
if (!$lines) {
    flash('info', 'Votre panier est vide.');
    redirect(url());
}
[$totaux, $quotaErrors] = quota_check($lines);
if ($quotaErrors) {
    // Blocage conditionnel (§6.3) : impossible de passer au paiement
    foreach ($quotaErrors as $q) {
        flash('error', $q);
    }
    redirect(url('panier'));
}

$dates = dates_retrait();
$slots = (array) cfg('slots', []);
$centres = centres();
$data = [
    'date_retrait' => $_SESSION['checkout']['date_retrait'] ?? array_key_first($dates),
    'creneau' => $_SESSION['checkout']['creneau'] ?? ($slots[0] ?? ''),
    'centre_id' => (string) ($_SESSION['checkout']['centre_id'] ?? $user['centre_id']),
];
$errors = [];

if ($page === 'payer' && is_post()) {
    csrf_check();
    $data = ['date_retrait' => post('date_retrait'), 'creneau' => post('creneau'), 'centre_id' => post('centre_id')];
    $_SESSION['checkout'] = $data;
    $centre = centre((int) $data['centre_id']);

    if (!isset($dates[$data['date_retrait']])) {
        $errors[] = 'Cette date de retrait n\'est plus disponible (' . cutoff_text() . ').';
    }
    if ($slots && !in_array($data['creneau'], $slots, true)) {
        $errors[] = 'Choisissez un créneau de retrait.';
    }
    if (!$centre || !$centre['actif']) {
        $errors[] = 'Choisissez un lieu de livraison.';
    }
    if (!$errors) {
        foreach ($lines as $l) {
            $r = stock_restant($l['produit'], $data['date_retrait']);
            if ($r !== null && $l['quantite'] > $r) {
                $errors[] = $r === 0
                    ? '« ' . $l['produit']['nom'] . ' » est épuisé pour le ' . date_fr($data['date_retrait']) . '.'
                    : 'Il ne reste que ' . $r . ' « ' . $l['produit']['nom'] . ' » pour le ' . date_fr($data['date_retrait']) . '.';
            }
        }
    }

    if (!$errors) {
        $pdo = db();
        // Une seule commande en attente de paiement par salarié
        $pdo->prepare("UPDATE commandes SET statut = 'annulee' WHERE user_id = ? AND statut = 'en_attente'")->execute([$user['id']]);

        $pdo->beginTransaction();
        try {
            $ref = generate_reference();
            $pdo->prepare('INSERT INTO commandes (reference, user_id, centre_id, centre_nom, point_relais, nom, prenom, email, telephone, date_retrait, creneau, total_cents, statut, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $user['id'], $centre['id'], $centre['nom'], $centre['point_relais'], $user['nom'], $user['prenom'], $user['email'], $user['telephone'],
                    $data['date_retrait'], $data['creneau'], cart_total($lines), 'en_attente', date('Y-m-d H:i:s')]);
            $cid = (int) $pdo->lastInsertId();
            $li = $pdo->prepare('INSERT INTO commande_lignes (commande_id, produit_id, categorie_id, nom, prix_cents, quantite) VALUES (?,?,?,?,?,?)');
            foreach ($lines as $l) {
                $p = $l['produit'];
                $li->execute([$cid, $p['id'], $p['categorie_id'], $p['nom'], $p['prix_cents'], $l['quantite']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        $cmd = commande_by_ref($ref);
        // Étape 2 : paiement immédiat obligatoire (§5.4)
        if (stripe_enabled()) {
            redirect(stripe_checkout($cmd, commande_lignes($cid)));
        }
        redirect(url('paiement-simulation', ['ref' => $ref]));
    }
}

render('recapitulatif', [
    'title' => 'Récapitulatif',
    'lines' => $lines, 'total' => cart_total($lines), 'totaux' => $totaux,
    'dates' => $dates, 'slots' => $slots, 'centres' => $centres,
    'data' => $data, 'errors' => $errors, 'user' => $user,
]);
