<?php

/**
 * Configuration d'AFPAREA.
 * Copiez ce fichier sous le nom « config.php » puis renseignez les valeurs.
 */
return [
    // Base de données MySQL (créée dans le panneau de votre hébergement)
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_password' => '',

    // Compte administrateur créé au premier lancement.
    // Le mot de passe est provisoire (10 caractères au minimum) : il sera à changer à la première connexion.
    'admin_email' => 'admin@afpar.re',
    'admin_password' => '',

    // Assistant FPA : clé de l'API Claude (https://platform.claude.com). Laissez vide pour désactiver l'assistant.
    'anthropic_api_key' => '',
    // 'fpa_model' => 'claude-opus-5-5',
    // 'fpa_effort' => 'high',      // low, medium, high, xhigh ou max
    // 'fpa_fallbacks' => 'on',     // 'off' : pas de repli sur un autre modèle quand une demande est déclinée

    // Taille maximale d'un fichier déposé, en Mo (dans la limite fixée par l'hébergement)
    // 'max_upload_mb' => 50,
];
