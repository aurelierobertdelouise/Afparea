<?php
/**
 * Gourmandises AFPAR — configuration
 * Copiez ce fichier en « config.php » puis adaptez les valeurs.
 * Ne JAMAIS versionner config.php (il contient les clés Stripe).
 */
return [
    'app_name'     => 'Gourmandises AFPAR',
    'app_baseline' => 'Pains & viennoiseries de la filière Boulangerie',

    // URL publique complète (sans « / » final) — utilisée dans les e-mails et pour Stripe.
    // Ex. : 'https://gourmandises.afpar.com' ou 'https://www.afpar.com/gourmandises'
    'app_url'  => 'http://localhost:8000',
    // Chemin de base si l'appli est dans un sous-dossier (ex. '/gourmandises'), sinon ''
    'base_url' => '',

    // ---- Base de données -------------------------------------------------
    // 'mysql' recommandé sur LWS ; 'sqlite' pour un test rapide.
    'db' => [
        'driver'   => 'sqlite',
        'sqlite'   => __DIR__ . '/data/gourmandises.sqlite',
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'nom_de_la_base',
        'user'     => 'utilisateur',
        'password' => 'mot_de_passe',
    ],

    // ---- Accès (cahier des charges §5.1 et §6.1) ------------------------
    // Seuls les e-mails de ces domaines peuvent se connecter.
    'allowed_domains' => ['afpar.com'],
    // Comptes automatiquement promus Administrateur à leur 1re connexion.
    'admin_emails' => ['prenom.nom@afpar.com'],
    // Code PIN envoyé par e-mail
    'otp_length'        => 6,
    'otp_ttl_minutes'   => 10,
    'otp_max_attempts'  => 5,   // essais par code
    'otp_max_per_hour'  => 5,   // envois par adresse et par heure
    // UNIQUEMENT pour tester en local sans serveur mail : affiche le code à l'écran.
    // Doit rester à false en production (contrôle DSI).
    'otp_debug' => false,

    // ---- E-mails ---------------------------------------------------------
    // Sur LWS, l'adresse d'expédition doit exister sur le domaine hébergé.
    'mail_from'      => 'no-reply@afpar.com',
    'mail_from_name' => 'Gourmandises AFPAR',

    // ---- Paiement Stripe (§5.4) -----------------------------------------
    // Clés disponibles dans le tableau de bord Stripe > Développeurs > Clés API.
    // Sans clé secrète, l'application fonctionne en MODE SIMULATION (pilote / démo).
    'stripe_secret_key'     => '',  // sk_live_... ou sk_test_...
    'stripe_webhook_secret' => '',  // whsec_... (webhook vers /stripe-webhook.php)
    'currency' => 'eur',

    // ---- Quotas par commande (§5.2) — modifiables aussi par catégorie dans l'admin
    // (valeurs utilisées à l'installation)
    'quota_viennoiseries' => 10,
    'quota_pains'         => 3,

    // ---- Calendrier de production (§8 étape 1 : « J-1 à 12h ») -----------
    'production_days' => [1, 2, 3, 4, 5],   // 1 = lundi … 7 = dimanche
    'cutoff_days'     => 1,                 // commande au plus tard J-1…
    'cutoff_time'     => '12:00',           // … avant 12h00
    'max_days'        => 14,                // horizon de réservation
    'closed_dates'    => [],                // ex. ['2026-12-25', '2027-01-01']
    'slots'           => ['Matin (8h00 - 10h00)', 'Midi (12h00 - 13h30)'],

    // ---- RGPD (§7) -------------------------------------------------------
    'retention_months'   => 6,                    // purge des commandes
    'pending_expiry_min' => 30,                   // commande non payée annulée après X min
    'dpo_email'          => 'dpo@afpar.com',
    'responsable_traitement' => 'AFPAR — Directeur d\'exploitation',
    'hebergeur' => 'LWS (Ligne Web Services), 10 rue Penthièvre, 75008 Paris',

    // Page de diagnostic /verif.php : mettre true le temps de l'installation, puis false.
    'install_check' => false,

    'timezone' => 'Indian/Reunion',
];
