<?php
declare(strict_types=1);

$lines = cart_lines();
if (!$lines) {
    flash('info', 'Votre panier est vide.');
    redirect(url('panier'));
}
$subtotal = cart_subtotal($lines);
if ($subtotal < (int) cfg('min_order_cents', 0)) {
    redirect(url('panier'));
}

$dates = dates_disponibles();
$slots = (array) cfg('slots', []);
$errors = [];
$data = $_SESSION['client'] ?? [];
$data += ['nom' => '', 'telephone' => '', 'email' => '', 'mode' => 'retrait', 'adresse' => '', 'code_postal' => '', 'ville' => '', 'date_souhaitee' => array_key_first($dates), 'creneau' => $slots[0] ?? '', 'commentaire' => ''];

if (is_post()) {
    csrf_check();
    foreach (array_keys($data) as $k) {
        $data[$k] = post($k);
    }
    $data['mode'] = $data['mode'] === 'livraison' ? 'livraison' : 'retrait';

    if (mb_strlen($data['nom']) < 2) {
        $errors['nom'] = 'Indiquez votre nom.';
    }
    if (!preg_match('/^\+?[0-9 .\-]{9,20}$/', $data['telephone'])) {
        $errors['telephone'] = 'Numéro de téléphone invalide.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Adresse e-mail invalide.';
    }
    if (!isset($dates[$data['date_souhaitee']])) {
        $errors['date_souhaitee'] = 'Choisissez une date.';
    }
    if (!in_array($data['creneau'], $slots, true)) {
        $errors['creneau'] = 'Choisissez un créneau.';
    }
    if ($data['mode'] === 'livraison') {
        if (mb_strlen($data['adresse']) < 5) {
            $errors['adresse'] = 'Indiquez votre adresse.';
        }
        if (!preg_match('/^\d{5}$/', $data['code_postal'])) {
            $errors['code_postal'] = 'Code postal à 5 chiffres.';
        } elseif (($zips = (array) cfg('delivery_zip_codes', [])) && !in_array($data['code_postal'], $zips, true)) {
            $errors['code_postal'] = 'Nous ne livrons pas encore cette zone. Choisissez le Click & Collect.';
        }
        if (mb_strlen($data['ville']) < 2) {
            $errors['ville'] = 'Indiquez votre ville.';
        }
    } else {
        $data['adresse'] = $data['code_postal'] = $data['ville'] = '';
    }
    if (empty($_POST['cgv'])) {
        $errors['cgv'] = 'Merci d\'accepter les conditions.';
    }

    if (!$errors) {
        $pdo = db();
        $fee = delivery_fee($subtotal, $data['mode']);
        $pdo->beginTransaction();
        try {
            do {
                $ref = 'GA-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
                $exists = $pdo->prepare('SELECT 1 FROM commandes WHERE reference = ?');
                $exists->execute([$ref]);
            } while ($exists->fetchColumn());
            $token = bin2hex(random_bytes(16));

            $st = $pdo->prepare('INSERT INTO commandes (reference, token, nom, telephone, email, mode, adresse, code_postal, ville, date_souhaitee, creneau, commentaire, sous_total_cents, frais_cents, total_cents, paiement, statut, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$ref, $token, $data['nom'], $data['telephone'], $data['email'], $data['mode'], $data['adresse'], $data['code_postal'], $data['ville'],
                $data['date_souhaitee'], $data['creneau'], $data['commentaire'], $subtotal, $fee, $subtotal + $fee, 'sur_place', 'nouvelle', date('Y-m-d H:i:s')]);
            $cid = (int) $pdo->lastInsertId();

            $li = $pdo->prepare('INSERT INTO commande_lignes (commande_id, produit_id, nom, prix_cents, quantite) VALUES (?,?,?,?,?)');
            foreach ($lines as $l) {
                $li->execute([$cid, $l['produit']['id'], $l['produit']['nom'], $l['produit']['prix_cents'], $l['quantite']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        // Mémorise les coordonnées pour la prochaine commande
        $_SESSION['client'] = array_intersect_key($data, array_flip(['nom', 'telephone', 'email', 'mode', 'adresse', 'code_postal', 'ville']));
        cart_clear();
        notify_new_order($ref, $data, $lines, $subtotal + $fee);

        redirect(url('confirmation', ['ref' => $ref, 't' => $token]));
    }
}

render('commande', [
    'title' => 'Ma commande', 'lines' => $lines, 'subtotal' => $subtotal,
    'data' => $data, 'errors' => $errors, 'dates' => $dates, 'slots' => $slots,
]);

function notify_new_order(string $ref, array $data, array $lines, int $total): void
{
    $to = (string) cfg('notify_email', '');
    if ($to === '' || !function_exists('mail')) {
        return;
    }
    $body = "Nouvelle commande $ref\n\n"
        . 'Client : ' . $data['nom'] . ' — ' . $data['telephone'] . "\n"
        . 'Mode : ' . mode_label($data['mode']) . ' le ' . $data['date_souhaitee'] . ' (' . $data['creneau'] . ")\n";
    if ($data['mode'] === 'livraison') {
        $body .= 'Adresse : ' . $data['adresse'] . ', ' . $data['code_postal'] . ' ' . $data['ville'] . "\n";
    }
    $body .= "\n";
    foreach ($lines as $l) {
        $body .= $l['quantite'] . ' x ' . $l['produit']['nom'] . ' = ' . prix($l['total']) . "\n";
    }
    $body .= "\nTotal : " . prix($total) . "\n";
    $headers = 'Content-Type: text/plain; charset=UTF-8';
    @mail($to, '=?UTF-8?B?' . base64_encode('[Gourmandises] Commande ' . $ref) . '?=', $body, $headers);
}
