<?php
declare(strict_types=1);

// ---------------------------------------------------------------- Statuts
/** Cycle de vie d'une commande (CDC §6.5 : À préparer → Prête → Récupérée). */
function statuts(): array
{
    return [
        'en_attente' => 'En attente de paiement',
        'payee'      => 'Payée · à préparer',
        'prete'      => 'Prête',
        'recuperee'  => 'Récupérée',
        'annulee'    => 'Annulée',
    ];
}

function statut_label(string $s): string
{
    return statuts()[$s] ?? $s;
}

/** Statuts qui comptent dans la production et les stocks. */
const STATUTS_ACTIFS = ['payee', 'prete', 'recuperee'];

// ------------------------------------------------------------ Calendrier
/**
 * Jours de retrait possibles : jours de production, hors dates fermées,
 * et commande passée avant l'heure limite (ex. J-1 à 12h00).
 */
function dates_retrait(): array
{
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $prod = array_map('intval', (array) cfg('production_days', [1, 2, 3, 4, 5]));
    $closed = (array) cfg('closed_dates', []);
    $now = new DateTimeImmutable('now');
    [$h, $m] = array_map('intval', explode(':', (string) cfg('cutoff_time', '12:00')) + [0, 0]);
    $out = [];
    for ($i = 0; $i <= (int) cfg('max_days', 14); $i++) {
        $d = (new DateTimeImmutable('today'))->modify("+$i day");
        if (!in_array((int) $d->format('N'), $prod, true) || in_array($d->format('Y-m-d'), $closed, true)) {
            continue;
        }
        $limit = $d->modify('-' . (int) cfg('cutoff_days', 1) . ' day')->setTime($h, $m);
        if ($now >= $limit) {
            continue;
        }
        $out[$d->format('Y-m-d')] = ucfirst($jours[(int) $d->format('N')]) . ' ' . $d->format('j') . ' ' . $mois[(int) $d->format('n')];
    }
    return $out;
}

function date_fr(string $ymd, bool $long = true): string
{
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($ymd, 0, 10));
    if (!$d) {
        return $ymd;
    }
    return $long ? ucfirst($jours[(int) $d->format('N')]) . ' ' . $d->format('j') . ' ' . $mois[(int) $d->format('n')] . ' ' . $d->format('Y') : $d->format('d/m/Y');
}

function cutoff_text(): string
{
    $j = (int) cfg('cutoff_days', 1);
    return 'Commande au plus tard ' . ($j === 0 ? 'le jour même' : "la veille" . ($j > 1 ? " (J-$j)" : '')) . ' avant ' . str_replace(':', 'h', (string) cfg('cutoff_time', '12:00'));
}

// ----------------------------------------------------------------- Stock
/** Quantité déjà réservée d'un produit pour une date (commandes payées + en attente non expirées). */
function quantite_reservee(int $produitId, string $date): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(l.quantite),0) FROM commande_lignes l JOIN commandes c ON c.id = l.commande_id
        WHERE l.produit_id = ? AND c.date_retrait = ? AND c.statut IN ('en_attente','payee','prete','recuperee')");
    $st->execute([$produitId, $date]);
    return (int) $st->fetchColumn();
}

/** Reste disponible pour une date (null = illimité). */
function stock_restant(array $p, string $date): ?int
{
    if (!$p['disponible']) {
        return 0;
    }
    if ($p['stock_jour'] === null || $p['stock_jour'] === '') {
        return null;
    }
    return max(0, (int) $p['stock_jour'] - quantite_reservee((int) $p['id'], $date));
}

// ---------------------------------------------------------------- Quotas
/**
 * Contrôle des quotas par catégorie (CDC §5.2 / §6.3).
 * Retourne [totaux par catégorie, liste des messages d'erreur].
 */
function quota_check(array $lines): array
{
    $totaux = [];
    foreach (categories() as $c) {
        $totaux[(int) $c['id']] = ['nom' => $c['nom'], 'slug' => $c['slug'], 'quota' => (int) $c['quota'], 'total' => 0];
    }
    foreach ($lines as $l) {
        $cid = (int) $l['produit']['categorie_id'];
        if (isset($totaux[$cid])) {
            $totaux[$cid]['total'] += (int) $l['quantite'];
        }
    }
    $errors = [];
    foreach ($totaux as $t) {
        if ($t['quota'] > 0 && $t['total'] > $t['quota']) {
            $errors[] = quota_message($t['quota'], $t['nom']);
        }
    }
    return [$totaux, $errors];
}

function quota_message(int $quota, string $categorie): string
{
    return 'Quota dépassé : ' . $quota . ' ' . mb_strtolower($categorie) . ' max par commande';
}

// ------------------------------------------------------------- Commandes
function generate_reference(): string
{
    $pdo = db();
    do {
        $ref = 'GA' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $st = $pdo->prepare('SELECT 1 FROM commandes WHERE reference = ?');
        $st->execute([$ref]);
    } while ($st->fetchColumn());
    return $ref;
}

function commande_by_ref(string $ref): ?array
{
    $st = db()->prepare('SELECT * FROM commandes WHERE reference = ?');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

function commande_lignes(int $id): array
{
    $st = db()->prepare('SELECT * FROM commande_lignes WHERE commande_id = ? ORDER BY id');
    $st->execute([$id]);
    return $st->fetchAll();
}

/** Passe la commande à « Payée » (idempotent), vide le panier de l'acheteur, envoie le reçu. */
function mark_order_paid(string $ref, string $session = '', string $payment = ''): ?array
{
    $cmd = commande_by_ref($ref);
    if (!$cmd) {
        return null;
    }
    if ($cmd['statut'] === 'en_attente' || $cmd['statut'] === 'annulee') {
        $code = (string) random_int(1000, 9999);
        db()->prepare("UPDATE commandes SET statut = 'payee', paid_at = ?, code_retrait = ?, stripe_session = COALESCE(NULLIF(?, ''), stripe_session), stripe_payment = NULLIF(?, '') WHERE id = ? AND statut IN ('en_attente','annulee')")
            ->execute([date('Y-m-d H:i:s'), $code, $session, $payment, $cmd['id']]);
        $cmd = commande_by_ref($ref);
        send_receipt($cmd);
    }
    if (current_user() && (int) current_user()['id'] === (int) $cmd['user_id']) {
        cart_clear();
    }
    return $cmd;
}

function send_receipt(array $cmd): void
{
    $lignes = commande_lignes((int) $cmd['id']);
    $txt = "Bonjour {$cmd['prenom']},\n\nMerci pour votre commande ! Le paiement de " . prix((int) $cmd['total_cents']) . " a bien été reçu.\n\n"
        . "N° de commande : {$cmd['reference']}\nCode de retrait : {$cmd['code_retrait']}\n\n"
        . 'Retrait : ' . date_fr($cmd['date_retrait']) . ' — ' . $cmd['creneau'] . "\n"
        . "Lieu : {$cmd['centre_nom']} — {$cmd['point_relais']}\n\n";
    foreach ($lignes as $l) {
        $txt .= "  {$l['quantite']} × {$l['nom']}  " . prix($l['quantite'] * $l['prix_cents']) . "\n";
    }
    $txt .= "\nPrésentez votre code de retrait (ou le QR code de votre reçu) au point relais.\n"
        . 'Votre reçu : ' . rtrim((string) cfg('app_url'), '/') . url('commande', ['ref' => $cmd['reference']]) . "\n\n— " . cfg('app_name');
    send_mail($cmd['email'], 'Votre commande ' . $cmd['reference'] . ' est confirmée', $txt);
}

// ----------------------------------------------------- Maintenance / RGPD
/**
 * Exécutée au plus une fois par heure :
 * - annule les commandes non payées après le délai (libère le stock),
 * - purge les commandes de plus de N mois (CDC §7.1 : 6 mois),
 * - supprime les codes de connexion expirés.
 */
function maintenance(bool $force = false): array
{
    $last = (int) setting('maintenance_at', '0');
    if (!$force && time() - $last < 3600) {
        return [];
    }
    $pdo = db();
    setting_set('maintenance_at', (string) time());

    $exp = date('Y-m-d H:i:s', time() - 60 * (int) cfg('pending_expiry_min', 30));
    $a = $pdo->prepare("UPDATE commandes SET statut = 'annulee' WHERE statut = 'en_attente' AND created_at < ?");
    $a->execute([$exp]);

    $limit = (new DateTimeImmutable('today'))->modify('-' . (int) cfg('retention_months', 6) . ' month')->format('Y-m-d');
    $ids = $pdo->prepare('SELECT id FROM commandes WHERE date_retrait < ?');
    $ids->execute([$limit]);
    $ids = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    foreach (array_chunk($ids, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $pdo->prepare("DELETE FROM commande_lignes WHERE commande_id IN ($in)")->execute($chunk);
        $pdo->prepare("DELETE FROM commandes WHERE id IN ($in)")->execute($chunk);
    }
    $pdo->prepare('DELETE FROM otp_codes WHERE expires_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    setting_set('last_purge', date('Y-m-d H:i') . ' — ' . count($ids) . ' commande(s) purgée(s)');

    return ['expirees' => $a->rowCount(), 'purgees' => count($ids)];
}
