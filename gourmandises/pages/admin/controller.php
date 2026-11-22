<?php
declare(strict_types=1);

$page = $_GET['p'] ?? 'admin';

// ---------------------------------------------------------------- Connexion
if ($page === 'admin-login') {
    if (is_admin()) {
        redirect(url('admin'));
    }
    $error = '';
    if (is_post()) {
        csrf_check();
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] > 8) {
            sleep(2);
        }
        if (hash_equals((string) cfg('admin_user'), post('user'))
            && password_verify(post('password'), (string) cfg('admin_password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['login_attempts'] = 0;
            redirect(url('admin'));
        }
        $error = 'Identifiants incorrects.';
    }
    render('admin/login', ['title' => 'Connexion équipe', 'error' => $error], 'admin_layout');
    return;
}

if ($page === 'admin-logout') {
    unset($_SESSION['admin']);
    session_regenerate_id(true);
    redirect(url());
}

require_admin();
$pdo = db();

switch ($page) {
    // ----------------------------------------------------------- Commandes
    case 'admin':
        $statut = (string) ($_GET['statut'] ?? 'actives');
        $jour = (string) ($_GET['jour'] ?? '');
        $sql = 'SELECT * FROM commandes WHERE 1=1';
        $args = [];
        if ($statut === 'actives') {
            $sql .= " AND statut NOT IN ('terminee','annulee')";
        } elseif (isset(statuts()[$statut])) {
            $sql .= ' AND statut = ?';
            $args[] = $statut;
        }
        if ($jour !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
            $sql .= ' AND date_souhaitee = ?';
            $args[] = $jour;
        }
        $sql .= ' ORDER BY date_souhaitee, creneau, id';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $commandes = $st->fetchAll();

        // Fournée : quantités à préparer pour le jour choisi (ou aujourd'hui)
        $jourProd = $jour ?: date('Y-m-d');
        $st = $pdo->prepare("SELECT l.nom, SUM(l.quantite) AS qte FROM commande_lignes l JOIN commandes c ON c.id = l.commande_id
            WHERE c.date_souhaitee = ? AND c.statut <> 'annulee' GROUP BY l.nom ORDER BY l.nom");
        $st->execute([$jourProd]);
        $fournee = $st->fetchAll();

        $stats = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(total_cents),0) AS ca FROM commandes WHERE statut <> 'annulee'")->fetch();
        render('admin/commandes', compact('commandes', 'statut', 'jour', 'fournee', 'jourProd', 'stats') + ['title' => 'Commandes'], 'admin_layout');
        break;

    case 'admin-commande':
        $id = (int) ($_GET['id'] ?? 0);
        if (is_post()) {
            csrf_check();
            $s = post('statut');
            if (isset(statuts()[$s])) {
                $pdo->prepare('UPDATE commandes SET statut = ? WHERE id = ?')->execute([$s, $id]);
                flash('success', 'Statut mis à jour : ' . statut_label($s) . '.');
            }
            if (post('suppr') === '1') {
                $pdo->prepare('DELETE FROM commande_lignes WHERE commande_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM commandes WHERE id = ?')->execute([$id]);
                flash('success', 'Commande supprimée.');
                redirect(url('admin'));
            }
            redirect(post('retour') === 'liste' ? url('admin') : url('admin-commande', ['id' => $id]));
        }
        $st = $pdo->prepare('SELECT * FROM commandes WHERE id = ?');
        $st->execute([$id]);
        $cmd = $st->fetch();
        if (!$cmd) {
            not_found();
        }
        $st = $pdo->prepare('SELECT * FROM commande_lignes WHERE commande_id = ?');
        $st->execute([$id]);
        render('admin/commande', ['title' => 'Commande ' . $cmd['reference'], 'cmd' => $cmd, 'lignes' => $st->fetchAll()], 'admin_layout');
        break;

    // ------------------------------------------------------------ Produits
    case 'admin-produits':
        if (is_post()) {
            csrf_check();
            $id = (int) post('id');
            if (post('action') === 'toggle') {
                $pdo->prepare('UPDATE produits SET actif = 1 - actif WHERE id = ?')->execute([$id]);
            } elseif (post('action') === 'delete') {
                $pdo->prepare('DELETE FROM produits WHERE id = ?')->execute([$id]);
                flash('success', 'Produit supprimé.');
            }
            redirect(url('admin-produits'));
        }
        render('admin/produits', ['title' => 'Produits', 'produits' => produits(null, false)], 'admin_layout');
        break;

    case 'admin-produit':
        $id = (int) ($_GET['id'] ?? 0);
        $prod = $id ? produit($id) : null;
        if ($id && !$prod) {
            not_found();
        }
        $prod = $prod ?: ['id' => 0, 'categorie_id' => 0, 'nom' => '', 'description' => '', 'prix_cents' => 0, 'image' => '', 'allergenes' => '', 'actif' => 1, 'ordre' => 0];
        $errors = [];
        if (is_post()) {
            csrf_check();
            $prod['nom'] = post('nom');
            $prod['description'] = post('description');
            $prod['prix_cents'] = to_cents(post('prix'));
            $prod['categorie_id'] = (int) post('categorie_id');
            $prod['allergenes'] = post('allergenes');
            $prod['ordre'] = (int) post('ordre');
            $prod['actif'] = isset($_POST['actif']) ? 1 : 0;
            if ($prod['nom'] === '') {
                $errors[] = 'Le nom est obligatoire.';
            }
            if ($prod['prix_cents'] <= 0) {
                $errors[] = 'Le prix doit être supérieur à 0.';
            }
            if (!in_array($prod['categorie_id'], array_map('intval', array_column(categories(), 'id')), true)) {
                $errors[] = 'Choisissez une catégorie.';
            }
            if (!empty($_FILES['image']['name'])) {
                $up = upload_image($_FILES['image']);
                if (str_starts_with($up, 'assets/')) {
                    $prod['image'] = $up;
                } else {
                    $errors[] = $up;
                }
            }
            if (!$errors) {
                $vals = [$prod['categorie_id'], $prod['nom'], $prod['description'], $prod['prix_cents'], $prod['image'], $prod['allergenes'], $prod['actif'], $prod['ordre']];
                if ($prod['id']) {
                    $pdo->prepare('UPDATE produits SET categorie_id=?, nom=?, description=?, prix_cents=?, image=?, allergenes=?, actif=?, ordre=? WHERE id=?')
                        ->execute([...$vals, $prod['id']]);
                } else {
                    $pdo->prepare('INSERT INTO produits (categorie_id, nom, description, prix_cents, image, allergenes, actif, ordre) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute($vals);
                }
                flash('success', 'Produit enregistré.');
                redirect(url('admin-produits'));
            }
        }
        render('admin/produit', ['title' => $prod['id'] ? 'Modifier ' . $prod['nom'] : 'Nouveau produit', 'prod' => $prod, 'errors' => $errors], 'admin_layout');
        break;

    // ---------------------------------------------------------- Catégories
    case 'admin-categories':
        if (is_post()) {
            csrf_check();
            $id = (int) post('id');
            $nom = post('nom');
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', post('slug') ?: $nom) ?: '')), '-');
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
                    if (str_starts_with($up, 'assets/')) {
                        $image = $up;
                    } else {
                        flash('error', $up);
                    }
                }
                $vals = [$slug, $nom, post('accroche') ?: $nom . ' en ligne', post('description'), $image, (int) post('ordre')];
                try {
                    if ($id) {
                        $pdo->prepare('UPDATE categories SET slug=?, nom=?, accroche=?, description=?, image=?, ordre=? WHERE id=?')->execute([...$vals, $id]);
                    } else {
                        $pdo->prepare('INSERT INTO categories (slug, nom, accroche, description, image, ordre) VALUES (?,?,?,?,?,?)')->execute($vals);
                    }
                    flash('success', 'Catégorie enregistrée.');
                } catch (PDOException $ex) {
                    flash('error', 'Ce nom de catégorie existe déjà.');
                }
            }
            redirect(url('admin-categories'));
        }
        render('admin/categories', ['title' => 'Catégories', 'cats' => categories()], 'admin_layout');
        break;
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
