<?php
declare(strict_types=1);

/** @var string $page */
$me = require_staff();
$pdo = db();
$isAdmin = $me['role'] === 'admin';

/** Date de travail : ?jour=AAAA-MM-JJ, sinon aujourd'hui. */
$jour = get('jour');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
    $jour = date('Y-m-d');
}
/** Centre : un référent est toujours limité au sien (§7.2). */
$centreId = $isAdmin ? (int) get('centre') : (int) $me['centre_id'];

function scoped_orders(array $me, string $jour, int $centreId, array $statuts): array
{
    $in = implode(',', array_fill(0, count($statuts), '?'));
    $sql = "SELECT * FROM commandes c WHERE c.date_retrait = ? AND c.statut IN ($in)";
    $args = array_merge([$jour], $statuts);
    [$scope, $sargs] = centre_scope($me);
    $sql .= $scope;
    $args = array_merge($args, $sargs);
    if ($centreId) {
        $sql .= ' AND c.centre_id = ?';
        $args[] = $centreId;
    }
    $st = db()->prepare($sql . ' ORDER BY c.centre_nom, c.nom, c.prenom');
    $st->execute($args);
    return $st->fetchAll();
}

function lignes_par_commande(array $commandes): array
{
    if (!$commandes) {
        return [];
    }
    $ids = array_column($commandes, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM commande_lignes WHERE commande_id IN ($in) ORDER BY id");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $l) {
        $out[$l['commande_id']][] = $l;
    }
    return $out;
}

function find_scoped_order(array $me, string $ref): array
{
    $cmd = commande_by_ref(strtoupper($ref));
    if (!$cmd) {
        not_found();
    }
    if ($me['role'] !== 'admin' && (int) $cmd['centre_id'] !== (int) $me['centre_id']) {
        // Message explicite sans divulguer le contenu ni l'identité (cloisonnement §7.2)
        http_response_code(403);
        render('erreur', [
            'title' => 'Commande d\'un autre centre',
            'message' => 'La commande ' . $cmd['reference'] . ' est à retirer à ' . $cmd['centre_nom']
                . ($cmd['point_relais'] ? ' (' . $cmd['point_relais'] . ')' : '') . ', le ' . date_fr($cmd['date_retrait']) . '.',
        ], 'admin_layout');
        exit;
    }
    return $cmd;
}

$common = ['me' => $me, 'isAdmin' => $isAdmin, 'jour' => $jour, 'centreId' => $centreId];

switch ($page) {
    // ------------------------------------------- Tableau du jour (§6.5)
    case 'gestion':
        $commandes = scoped_orders($me, $jour, $centreId, ['payee', 'prete', 'recuperee']);
        $cols = ['payee' => [], 'prete' => [], 'recuperee' => []];
        foreach ($commandes as $c) {
            $cols[$c['statut']][] = $c;
        }
        $statsSql = "SELECT COUNT(*) AS n, COALESCE(SUM(total_cents),0) AS ca FROM commandes c WHERE c.date_retrait = ? AND c.statut IN ('payee','prete','recuperee')";
        [$scope, $sargs] = centre_scope($me);
        $st = $pdo->prepare($statsSql . $scope);
        $st->execute(array_merge([$jour], $sargs));
        render('gestion/tableau', $common + ['title' => 'Tableau du jour', 'cols' => $cols, 'lignes' => lignes_par_commande($commandes), 'stats' => $st->fetch()], 'admin_layout');
        break;

    // ----------------------------------------------- Changement de statut
    case 'gestion-statut':
        if (!is_post()) {
            redirect(url('gestion'));
        }
        csrf_check();
        $cmd = find_scoped_order($me, post('ref'));
        $s = post('statut');
        $allowed = $isAdmin ? array_keys(statuts()) : ['payee', 'prete', 'recuperee'];
        if (in_array($s, $allowed, true) && $cmd['statut'] !== 'en_attente') {
            if ($s === 'recuperee') {
                $pdo->prepare('UPDATE commandes SET statut = ?, remise_at = ?, remise_par = ? WHERE id = ?')
                    ->execute([$s, date('Y-m-d H:i:s'), trim($me['prenom'] . ' ' . $me['nom']), $cmd['id']]);
            } else {
                $pdo->prepare('UPDATE commandes SET statut = ?, remise_at = NULL, remise_par = \'\' WHERE id = ?')->execute([$s, $cmd['id']]);
            }
            if (post('ajax') === '1') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'statut' => $s, 'label' => statut_label($s), 'heure' => date('H:i')]);
                exit;
            }
            flash('success', $cmd['reference'] . ' : ' . statut_label($s) . '.');
        }
        redirect(safe_back(post('retour'), url('gestion-commande', ['ref' => $cmd['reference']])));
        break;

    // ----------------------------------- Scanner de QR code (émargement)
    case 'gestion-scan':
        header('Content-Type: application/json');
        $ref = strtoupper(get('ref'));
        $cmd = $ref !== '' ? commande_by_ref($ref) : null;
        if (!$cmd && preg_match('/^\d{4}$/', get('code'))) {
            $st = $pdo->prepare('SELECT reference FROM commandes c WHERE c.code_retrait = ? AND c.date_retrait = ?' . centre_scope($me)[0]);
            $st->execute(array_merge([get('code'), $jour], centre_scope($me)[1]));
            $cmd = ($r = $st->fetchColumn()) ? commande_by_ref((string) $r) : null;
        }
        if (!$cmd || $cmd['statut'] === 'en_attente') {
            echo json_encode(['ok' => false, 'erreur' => 'Commande introuvable.']);
            exit;
        }
        if (!$isAdmin && (int) $cmd['centre_id'] !== (int) $me['centre_id']) {
            echo json_encode(['ok' => false, 'erreur' => 'Commande d\'un autre centre : à retirer à ' . $cmd['centre_nom']
                . ($cmd['point_relais'] ? ' (' . $cmd['point_relais'] . ')' : '') . ', le ' . date_fr($cmd['date_retrait']) . '.']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'ref' => $cmd['reference'],
            'nom' => trim($cmd['prenom'] . ' ' . $cmd['nom']),
            'code' => $cmd['code_retrait'],
            'statut' => $cmd['statut'],
            'statut_label' => statut_label($cmd['statut']),
            'remise' => $cmd['remise_at'] ? date('H:i', strtotime($cmd['remise_at'])) . ($cmd['remise_par'] ? ' par ' . $cmd['remise_par'] : '') : '',
            'centre' => $cmd['centre_nom'],
            'date' => date_fr($cmd['date_retrait']),
            'autre_jour' => $cmd['date_retrait'] !== date('Y-m-d'),
            'autre_centre_admin' => $isAdmin && $centreId && (int) $cmd['centre_id'] !== $centreId,
            'lignes' => array_map(fn($l) => (int) $l['quantite'] . ' × ' . $l['nom'], commande_lignes((int) $cmd['id'])),
            'total' => prix((int) $cmd['total_cents']),
        ]);
        exit;

    case 'gestion-commande':
        $ref = get('ref');
        if ($ref === '' && get('code') !== '') {
            // Recherche par code de retrait + jour (saisie manuelle au point relais)
            $st = $pdo->prepare("SELECT reference FROM commandes c WHERE c.code_retrait = ? AND c.date_retrait = ?" . centre_scope($me)[0]);
            $st->execute(array_merge([get('code'), $jour], centre_scope($me)[1]));
            $ref = (string) $st->fetchColumn();
            if ($ref === '') {
                flash('error', 'Aucune commande avec ce code pour le ' . date_fr($jour, false) . '.');
                redirect(url('gestion-emargement', ['jour' => $jour]));
            }
        }
        $cmd = find_scoped_order($me, $ref);
        render('gestion/commande', $common + ['title' => 'Commande ' . $cmd['reference'], 'cmd' => $cmd, 'lignes' => commande_lignes((int) $cmd['id'])], 'admin_layout');
        break;

    // -------------------------------- Récapitulatif de production (fournil)
    case 'gestion-production':
        require_admin_role();
        $st = $pdo->prepare("SELECT l.produit_id, l.nom, cat.nom AS categorie, SUM(l.quantite) AS qte
            FROM commande_lignes l JOIN commandes c ON c.id = l.commande_id LEFT JOIN categories cat ON cat.id = l.categorie_id
            WHERE c.date_retrait = ? AND c.statut IN ('payee','prete','recuperee')
            GROUP BY l.produit_id, l.nom, cat.nom, cat.ordre ORDER BY cat.ordre, l.nom");
        $st->execute([$jour]);
        $prod = $st->fetchAll();
        $st = $pdo->prepare("SELECT l.nom, c.centre_nom, SUM(l.quantite) AS qte FROM commande_lignes l JOIN commandes c ON c.id = l.commande_id
            WHERE c.date_retrait = ? AND c.statut IN ('payee','prete','recuperee') GROUP BY l.nom, c.centre_nom");
        $st->execute([$jour]);
        $matrice = [];
        foreach ($st->fetchAll() as $r) {
            $matrice[$r['nom']][$r['centre_nom']] = (int) $r['qte'];
        }
        render('gestion/production', $common + ['title' => 'Récapitulatif fournil', 'prod' => $prod, 'matrice' => $matrice, 'centres' => centres(false)], 'admin_layout');
        break;

    // --------------------------------- Feuille de colisage par centre
    case 'gestion-colisage':
        require_admin_role();
        $commandes = scoped_orders($me, $jour, $centreId, ['payee', 'prete', 'recuperee']);
        $lignes = lignes_par_commande($commandes);
        $parCentre = [];
        foreach ($commandes as $c) {
            $k = $c['centre_nom'];
            $parCentre[$k]['point_relais'] = $c['point_relais'];
            $parCentre[$k]['commandes'][] = $c;
            foreach ($lignes[$c['id']] ?? [] as $l) {
                $parCentre[$k]['produits'][$l['nom']] = ($parCentre[$k]['produits'][$l['nom']] ?? 0) + (int) $l['quantite'];
            }
        }
        render('gestion/colisage', $common + ['title' => 'Feuilles de colisage', 'parCentre' => $parCentre, 'lignes' => $lignes, 'centres' => centres(false)], 'admin_layout');
        break;

    // ------------------------- Feuille d'émargement / distribution locale
    case 'gestion-emargement':
        if ($isAdmin && !$centreId) {
            $centreId = (int) (centres()[0]['id'] ?? 0);
        }
        $commandes = scoped_orders($me, $jour, $centreId, ['payee', 'prete', 'recuperee']);
        render('gestion/emargement', ['centreId' => $centreId] + $common + [
            'title' => 'Émargement', 'commandes' => $commandes, 'lignes' => lignes_par_commande($commandes),
            'centre' => centre($centreId), 'centres' => centres(false),
        ], 'admin_layout');
        break;

    // ------------------------------------------------- Export CSV / Excel
    case 'gestion-export':
        $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('du')) ? get('du') : $jour;
        $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('au')) ? get('au') : $du;
        $sql = "SELECT c.*, l.nom AS produit, l.quantite, l.prix_cents FROM commandes c JOIN commande_lignes l ON l.commande_id = c.id
                WHERE c.date_retrait BETWEEN ? AND ? AND c.statut IN ('payee','prete','recuperee','annulee')";
        [$scope, $sargs] = centre_scope($me);
        $args = array_merge([$du, $au], $sargs);
        if ($centreId) {
            $sql .= ' AND c.centre_id = ?';
            $args[] = $centreId;
        }
        $st = $pdo->prepare($sql . $scope . ' ORDER BY c.date_retrait, c.centre_nom, c.nom, c.id');
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [$r['reference'], date_fr($r['date_retrait'], false), $r['creneau'], $r['centre_nom'], $r['point_relais'], $r['nom'], $r['prenom'], $r['email'],
                $r['produit'], (int) $r['quantite'], number_format($r['prix_cents'] / 100, 2, ',', ''), number_format($r['quantite'] * $r['prix_cents'] / 100, 2, ',', ''),
                number_format($r['total_cents'] / 100, 2, ',', ''), statut_label($r['statut']), $r['code_retrait'], $r['paid_at'], $r['remise_at'], $r['remise_par']];
        }
        send_csv("commandes_{$du}_{$au}.csv", ['Référence', 'Date retrait', 'Créneau', 'Centre', 'Point relais', 'Nom', 'Prénom', 'E-mail', 'Produit', 'Quantité', 'Prix unitaire', 'Montant ligne', 'Total commande', 'Statut', 'Code retrait', 'Payée le', 'Remise le', 'Remise par'], $rows);
        break;

    // ---------------------------------------------------------- Catalogue
    case 'gestion-produits':
        require_admin_role();
        if (is_post()) {
            csrf_check();
            $id = (int) post('id');
            switch (post('action')) {
                case 'dispo':
                    $pdo->prepare('UPDATE produits SET disponible = 1 - disponible WHERE id = ?')->execute([$id]);
                    break;
                case 'actif':
                    $pdo->prepare('UPDATE produits SET actif = 1 - actif WHERE id = ?')->execute([$id]);
                    break;
                case 'stock':
                    $v = post('stock_jour');
                    $pdo->prepare('UPDATE produits SET stock_jour = ? WHERE id = ?')->execute([$v === '' ? null : max(0, (int) $v), $id]);
                    flash('success', 'Stock journalier mis à jour.');
                    break;
            }
            redirect(url('gestion-produits', ['jour' => $jour]));
        }
        $produits = produits(null, false);
        foreach ($produits as &$p) {
            $p['reserve'] = quantite_reservee((int) $p['id'], $jour);
        }
        unset($p);
        render('gestion/produits', $common + ['title' => 'Catalogue', 'produits' => $produits], 'admin_layout');
        break;

    case 'gestion-produit':
        require_admin_role();
        $id = (int) get('id');
        $prod = $id ? produit($id) : null;
        if ($id && !$prod) {
            not_found();
        }
        $prod = $prod ?: ['id' => 0, 'categorie_id' => 0, 'nom' => '', 'description' => '', 'prix_cents' => 0, 'image' => '', 'allergenes' => '', 'conservation' => '', 'stock_jour' => null, 'disponible' => 1, 'actif' => 1, 'ordre' => 0];
        $errors = [];
        if (is_post()) {
            csrf_check();
            $prod['nom'] = mb_substr(post('nom'), 0, 150);
            $prod['description'] = post('description');
            $prod['prix_cents'] = to_cents(post('prix'));
            $prod['categorie_id'] = (int) post('categorie_id');
            $prod['allergenes'] = mb_substr(post('allergenes'), 0, 180);
            $prod['conservation'] = mb_substr(post('conservation'), 0, 180);
            $prod['stock_jour'] = post('stock_jour') === '' ? null : max(0, (int) post('stock_jour'));
            $prod['ordre'] = (int) post('ordre');
            $prod['actif'] = isset($_POST['actif']) ? 1 : 0;
            $prod['disponible'] = isset($_POST['disponible']) ? 1 : 0;
            if ($prod['nom'] === '') { $errors[] = 'Le nom est obligatoire.'; }
            if ($prod['prix_cents'] <= 0) { $errors[] = 'Le prix doit être supérieur à 0.'; }
            if (!in_array($prod['categorie_id'], array_map('intval', array_column(categories(), 'id')), true)) { $errors[] = 'Choisissez une catégorie.'; }
            if (!empty($_FILES['image']['name'])) {
                $up = upload_image($_FILES['image']);
                str_starts_with($up, 'assets/') ? $prod['image'] = $up : $errors[] = $up;
            }
            if (!$errors) {
                $vals = [$prod['categorie_id'], $prod['nom'], $prod['description'], $prod['prix_cents'], $prod['image'], $prod['allergenes'], $prod['conservation'], $prod['stock_jour'], $prod['disponible'], $prod['actif'], $prod['ordre']];
                if ($prod['id']) {
                    $pdo->prepare('UPDATE produits SET categorie_id=?, nom=?, description=?, prix_cents=?, image=?, allergenes=?, conservation=?, stock_jour=?, disponible=?, actif=?, ordre=? WHERE id=?')->execute([...$vals, $prod['id']]);
                } else {
                    $pdo->prepare('INSERT INTO produits (categorie_id, nom, description, prix_cents, image, allergenes, conservation, stock_jour, disponible, actif, ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
                }
                flash('success', 'Produit enregistré.');
                redirect(url('gestion-produits'));
            }
        }
        render('gestion/produit', $common + ['title' => $prod['id'] ? 'Modifier ' . $prod['nom'] : 'Nouveau produit', 'prod' => $prod, 'errors' => $errors], 'admin_layout');
        break;

    case 'gestion-categories':
        require_admin_role();
        if (is_post()) {
            csrf_check();
            $id = (int) post('id');
            $nom = mb_substr(post('nom'), 0, 100);
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $nom))), '-');
            if (post('action') === 'delete' && $id) {
                $n = $pdo->prepare('SELECT COUNT(*) FROM produits WHERE categorie_id = ?');
                $n->execute([$id]);
                if ($n->fetchColumn() > 0) {
                    flash('error', 'Impossible : cette catégorie contient encore des produits.');
                } else {
                    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                    flash('success', 'Catégorie supprimée.');
                }
            } elseif ($nom !== '' && $slug !== '') {
                $image = post('image_actuelle');
                if (!empty($_FILES['image']['name'])) {
                    $up = upload_image($_FILES['image']);
                    str_starts_with($up, 'assets/') ? $image = $up : flash('error', $up);
                }
                $vals = [$slug, $nom, post('accroche') ?: $nom . ' en ligne', post('description'), $image, max(0, (int) post('quota')), (int) post('ordre')];
                try {
                    if ($id) {
                        $pdo->prepare('UPDATE categories SET slug=?, nom=?, accroche=?, description=?, image=?, quota=?, ordre=? WHERE id=?')->execute([...$vals, $id]);
                    } else {
                        $pdo->prepare('INSERT INTO categories (slug, nom, accroche, description, image, quota, ordre) VALUES (?,?,?,?,?,?,?)')->execute($vals);
                    }
                    flash('success', 'Catégorie enregistrée.');
                } catch (PDOException $ex) {
                    flash('error', 'Ce nom de catégorie existe déjà.');
                }
            }
            redirect(url('gestion-categories'));
        }
        render('gestion/categories', $common + ['title' => 'Catégories & quotas', 'cats' => categories()], 'admin_layout');
        break;

    // ------------------------------------------------------------ Centres
    case 'gestion-centres':
        require_admin_role();
        if (is_post()) {
            csrf_check();
            $id = (int) post('id');
            $vals = [mb_substr(post('nom'), 0, 120), mb_substr(post('point_relais'), 0, 150), isset($_POST['actif']) ? 1 : 0, (int) post('ordre')];
            if ($vals[0] !== '') {
                try {
                    if ($id) {
                        $pdo->prepare('UPDATE centres SET nom=?, point_relais=?, actif=?, ordre=? WHERE id=?')->execute([...$vals, $id]);
                    } else {
                        $pdo->prepare('INSERT INTO centres (nom, point_relais, actif, ordre) VALUES (?,?,?,?)')->execute($vals);
                    }
                    flash('success', 'Centre enregistré.');
                } catch (PDOException $ex) {
                    flash('error', 'Ce nom de centre existe déjà.');
                }
            }
            redirect(url('gestion-centres'));
        }
        $counts = $pdo->query('SELECT centre_id, COUNT(*) AS n FROM users GROUP BY centre_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        render('gestion/centres', $common + ['title' => 'Centres & points relais', 'centres' => centres(false), 'counts' => $counts], 'admin_layout');
        break;

    // ------------------------------------------------------- Utilisateurs
    case 'gestion-utilisateurs':
        require_admin_role();
        if (is_post()) {
            csrf_check();
            $id = (int) post('id');
            if (post('action') === 'delete' && $id !== (int) $me['id']) {
                $pdo->prepare("UPDATE commandes SET nom='Compte supprimé', prenom='', email='', telephone='', user_id=NULL WHERE user_id = ?")->execute([$id]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                flash('success', 'Compte supprimé et commandes anonymisées.');
            } elseif (isset(ROLES[post('role')])) {
                if ($id === (int) $me['id'] && post('role') !== 'admin') {
                    flash('error', 'Vous ne pouvez pas retirer votre propre rôle administrateur.');
                } else {
                    $pdo->prepare('UPDATE users SET role = ?, centre_id = ? WHERE id = ?')->execute([post('role'), centre((int) post('centre_id')) ? (int) post('centre_id') : null, $id]);
                    flash('success', 'Utilisateur mis à jour.');
                }
            }
            if (post('action') === 'purge') {
                $r = maintenance(true);
                flash('success', 'Maintenance exécutée : ' . $r['purgees'] . ' commande(s) purgée(s), ' . $r['expirees'] . ' commande(s) non payée(s) annulée(s).');
            }
            redirect(url('gestion-utilisateurs', ['q' => get('q')]));
        }
        $q = get('q');
        $sql = 'SELECT u.*, c.nom AS centre_nom FROM users u LEFT JOIN centres c ON c.id = u.centre_id';
        $args = [];
        if ($q !== '') {
            $sql .= ' WHERE u.email LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?';
            $args = ["%$q%", "%$q%", "%$q%"];
        }
        $st = $pdo->prepare($sql . ' ORDER BY u.role, u.nom, u.prenom LIMIT 300');
        $st->execute($args);
        render('gestion/utilisateurs', $common + ['title' => 'Utilisateurs & rôles', 'users' => $st->fetchAll(), 'q' => $q, 'centres' => centres(false)], 'admin_layout');
        break;

    default:
        not_found();
}

/** Enregistre une image envoyée ; retourne le chemin relatif ou un message d'erreur. */
function upload_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Échec de l\'envoi de l\'image.';
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        return 'Image trop lourde (4 Mo maximum).';
    }
    $info = @getimagesize($file['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) {
        return 'Format accepté : JPG, PNG ou WEBP.';
    }
    $dir = APP_ROOT . '/assets/img/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return 'Impossible d\'enregistrer l\'image (droits du dossier assets/img/uploads).';
    }
    return 'assets/img/uploads/' . $name;
}
