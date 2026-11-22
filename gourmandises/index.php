<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$page = isset($_GET['p']) && is_string($_GET['p']) ? $_GET['p'] : 'accueil';

try {
    switch ($page) {
        // ------------------------------------------------------------ Public
        case 'accueil':
            render('accueil', ['title' => 'Accueil', 'categories' => categories(), 'populaires' => produits()]);
            break;

        case 'categorie':
            $cat = categorie_by_slug((string) ($_GET['c'] ?? ''));
            if (!$cat) {
                not_found();
            }
            render('categorie', ['title' => $cat['nom'], 'cat' => $cat, 'produits' => produits((int) $cat['id'])]);
            break;

        case 'produit':
            $prod = produit((int) ($_GET['id'] ?? 0));
            if (!$prod || !$prod['actif']) {
                not_found();
            }
            render('produit', ['title' => $prod['nom'], 'prod' => $prod]);
            break;

        case 'panier-ajouter':
            if (!is_post()) {
                redirect(url('panier'));
            }
            csrf_check();
            $prod = produit((int) post('id'));
            if ($prod && $prod['actif']) {
                cart_add((int) $prod['id'], max(1, (int) post('qte', '1')));
                if (($_POST['ajax'] ?? '') === '1') {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'count' => cart_count(), 'nom' => $prod['nom']]);
                    exit;
                }
                flash('success', '« ' . $prod['nom'] . ' » a été ajouté au panier.');
            }
            redirect(post('retour') === 'panier' ? url('panier') : ($_SERVER['HTTP_REFERER'] ?? url('panier')));
            break;

        case 'panier-maj':
            if (is_post()) {
                csrf_check();
                foreach ((array) ($_POST['qte'] ?? []) as $id => $q) {
                    cart_set((int) $id, (int) $q);
                }
                if (isset($_POST['retirer'])) {
                    cart_set((int) $_POST['retirer'], 0);
                }
                if (isset($_POST['vider'])) {
                    cart_clear();
                }
            }
            redirect(url('panier'));
            break;

        case 'panier':
            $lines = cart_lines();
            render('panier', ['title' => 'Mon panier', 'lines' => $lines, 'subtotal' => cart_subtotal($lines)]);
            break;

        case 'commande':
            require APP_ROOT . '/pages/commande_controller.php';
            break;

        case 'confirmation':
        case 'suivi':
            $ref = strtoupper(trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? '')));
            $tel = preg_replace('/\D+/', '', (string) ($_GET['tel'] ?? $_POST['tel'] ?? ''));
            $token = (string) ($_GET['t'] ?? '');
            $cmd = null;
            if ($ref !== '') {
                $st = db()->prepare('SELECT * FROM commandes WHERE reference = ?');
                $st->execute([$ref]);
                $c = $st->fetch();
                if ($c && (($token !== '' && hash_equals($c['token'], $token))
                    || ($tel !== '' && preg_replace('/\D+/', '', $c['telephone']) === $tel))) {
                    $cmd = $c;
                } elseif ($ref !== '' && ($tel !== '' || $token !== '')) {
                    flash('error', 'Aucune commande ne correspond à ces informations.');
                }
            }
            $lignes = [];
            if ($cmd) {
                $st = db()->prepare('SELECT * FROM commande_lignes WHERE commande_id = ?');
                $st->execute([$cmd['id']]);
                $lignes = $st->fetchAll();
            }
            render('suivi', ['title' => 'Suivi de commande', 'cmd' => $cmd, 'lignes' => $lignes, 'confirm' => $page === 'confirmation', 'ref' => $ref]);
            break;

        case 'infos':
            render('infos', ['title' => 'Infos pratiques']);
            break;

        // ------------------------------------------------------------- Admin
        case 'admin-login':
        case 'admin-logout':
        case 'admin':
        case 'admin-commande':
        case 'admin-produits':
        case 'admin-produit':
        case 'admin-categories':
            require APP_ROOT . '/pages/admin/controller.php';
            break;

        default:
            not_found();
    }
} catch (PDOException $e) {
    error_log('[gourmandises] ' . $e->getMessage());
    http_response_code(500);
    render('erreur', ['title' => 'Erreur', 'message' => 'La base de données est indisponible. Vérifiez la configuration (config.php).']);
}
