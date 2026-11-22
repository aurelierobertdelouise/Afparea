<?php
/**
 * Gourmandises AFPAR — configuration
 * Copiez ce fichier en « config.php » puis adaptez les valeurs.
 */
return [
    // Nom affiché dans l'application
    'app_name'     => 'Gourmandises AFPAR',
    'app_baseline' => 'Pains & viennoiseries en ligne',

    // URL de base (laisser vide si l'appli est à la racine du domaine,
    // sinon par ex. '/gourmandises')
    'base_url' => '',

    // ---- Base de données -------------------------------------------------
    // 'sqlite' : aucune configuration, fichier créé dans data/ (idéal pour démarrer)
    // 'mysql'  : base MySQL/MariaDB créée dans le panel LWS (recommandé en production)
    'db' => [
        'driver'   => 'sqlite',
        'sqlite'   => __DIR__ . '/data/gourmandises.sqlite',
        'host'     => 'localhost',     // LWS : souvent « localhost » ou « 127.0.0.1 »
        'port'     => 3306,
        'name'     => 'nom_de_la_base',
        'user'     => 'utilisateur',
        'password' => 'mot_de_passe',
    ],

    // ---- Back-office -----------------------------------------------------
    // Identifiant + hash du mot de passe administrateur.
    // Générer un hash : php -r "echo password_hash('VotreMotDePasse', PASSWORD_DEFAULT);"
    // Le hash ci-dessous correspond à « gourmandises2026 » : À CHANGER !
    'admin_user'          => 'admin',
    'admin_password_hash' => '$2y$10$iFFb5r9DkQCrtXd/FdGGRe6zf.hMTRfkNVZem.uBZ9HwmUdjHzajm',

    // ---- Commande --------------------------------------------------------
    'delivery_fee_cents'      => 250,   // frais de livraison (2,50 €)
    'free_delivery_from_cents'=> 2000,  // livraison offerte dès 20 €
    'min_order_cents'         => 300,   // commande minimum
    'delivery_zip_codes'      => [],    // ex. ['97400','97490'] ; vide = toutes zones
    'slots' => ['07:00 - 08:00', '08:00 - 09:00', '09:00 - 10:00', '11:30 - 12:30', '16:00 - 17:00', '17:00 - 18:00'],
    'lead_days'  => 0,                  // délai minimal (jours) avant la date souhaitée
    'max_days'   => 14,                 // horizon de commande

    // Coordonnées du point de retrait (Click & Collect)
    'shop_address' => 'AFPAR — Atelier boulangerie, La Réunion',
    'shop_phone'   => '',
    'shop_email'   => '',

    // E-mail de notification des nouvelles commandes (vide = désactivé)
    'notify_email' => '',

    // Fuseau horaire
    'timezone' => 'Indian/Reunion',
];
