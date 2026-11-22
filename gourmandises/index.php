<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$page = get('p', 'accueil');

try {
    maintenance();

    // Pages accessibles sans connexion
    switch ($page) {
        case 'connexion':
        case 'deconnexion':
        case 'bienvenue':
            require APP_ROOT . '/pages/auth_controller.php';
            exit;
        case 'mentions':
            render('mentions', ['title' => 'Mentions légales & données personnelles']);
            exit;
    }

    if (str_starts_with($page, 'gestion')) {
        require APP_ROOT . '/pages/gestion/controller.php';
        exit;
    }

    $user = require_login();

    switch ($page) {
        // ------------------------------------------------------- Catalogue
        case 'accueil':
            $dates = dates_retrait();
            $filtre = get('cat', 'tous');
            render('accueil', [
                'title' => 'Catalogue',
                'produits' => produits(),
                'filtre' => $filtre,
                'prochaine' => array_key_first($dates),
                'dates' => $dates,
            ]);
            break;

        case 'produit':
            $prod = produit((int) get('id'));
            if (!$prod || !$prod['actif']) {
                not_found();
            }
            $dates = dates_retrait();
            render('produit', ['title' => $prod['nom'], 'prod' => $prod, 'prochaine' => array_key_first($dates)]);
            break;

        // ----------------------------------------------------------- Panier
        case 'panier-ajouter':
            if (!is_post()) {
                redirect(url('panier'));
            }
            csrf_check();
            $prod = produit((int) post('id'));
            $ajax = post('ajax') === '1';
            $msg = null;
            if ($prod && $prod['actif'] && $prod['disponible']) {
                cart_add((int) $prod['id'], max(1, (int) post('qte', '1')));
                [, $errs] = quota_check(cart_lines());
                $msg = $errs ? $errs[0] : null;
            }
            if ($ajax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => (bool) $prod, 'count' => cart_count(), 'nom' => $prod['nom'] ?? '', 'quota' => $msg]);
                exit;
            }
            if ($prod) {
                flash($msg ? 'error' : 'success', $msg ?? '« ' . $prod['nom'] . ' » a été ajouté au panier.');
            }
            redirect(post('retour') === 'panier' ? url('panier') : safe_back($_SERVER['HTTP_REFERER'] ?? null, url()));
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
                if (post('go') === 'recap') {
                    redirect(url('recapitulatif'));
                }
            }
            redirect(url('panier'));
            break;

        case 'panier':
            $lines = cart_lines();
            [$totaux, $quotaErrors] = quota_check($lines);
            render('panier', ['title' => 'Mon panier', 'lines' => $lines, 'total' => cart_total($lines), 'totaux' => $totaux, 'quotaErrors' => $quotaErrors]);
            break;

        // ---------------------------------------- Tunnel de commande (§6.4)
        case 'recapitulatif':
        case 'payer':
        case 'paiement-simulation':
        case 'paiement-retour':
        case 'paiement-annule':
            require APP_ROOT . '/pages/checkout_controller.php';
            break;

        // ------------------------------------------------- Espace salarié
        case 'commande': // reçu
            $cmd = commande_by_ref(strtoupper(get('ref')));
            // Cloisonnement : un salarié ne voit que ses propres commandes (§7.2)
            if (!$cmd || ((int) $cmd['user_id'] !== (int) $user['id'] && !is_staff())) {
                not_found();
            }
            if ((int) $cmd['user_id'] !== (int) $user['id']) {
                redirect(url('gestion-commande', ['ref' => $cmd['reference']]));
            }
            render('recu', ['title' => 'Commande ' . $cmd['reference'], 'cmd' => $cmd, 'lignes' => commande_lignes((int) $cmd['id'])]);
            break;

        case 'mes-commandes':
            $st = db()->prepare("SELECT * FROM commandes WHERE user_id = ? AND statut <> 'en_attente' ORDER BY date_retrait DESC, id DESC");
            $st->execute([$user['id']]);
            render('mes_commandes', ['title' => 'Mes commandes', 'commandes' => $st->fetchAll()]);
            break;

        case 'profil':
            require APP_ROOT . '/pages/profil_controller.php';
            break;

        default:
            not_found();
    }
} catch (PDOException $e) {
    error_log('[gourmandises] ' . $e->getMessage());
    http_response_code(500);
    render('erreur', ['title' => 'Erreur', 'message' => 'La base de données est indisponible. Vérifiez la configuration (config.php).']);
} catch (RuntimeException $e) {
    error_log('[gourmandises] ' . $e->getMessage());
    http_response_code(502);
    render('erreur', ['title' => 'Erreur', 'message' => $e->getMessage()]);
}
