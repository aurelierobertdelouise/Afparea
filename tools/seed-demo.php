<?php

declare(strict_types=1);

/**
 * Jeu de données de démonstration (formatrice, six stagiaires, un parcours d'exemple).
 * À lancer en ligne de commande uniquement : php tools/seed-demo.php — jamais en production.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../site/app/bootstrap.php';
require __DIR__ . '/../site/app/schema.php';
require __DIR__ . '/../site/app/auth.php';
require __DIR__ . '/../site/app/model.php';

ensure_schema();
$password = getenv('DEMO_PASSWORD') ?: generate_password();
$hash = password_hash($password, PASSWORD_DEFAULT);
$inDays = fn (int $days) => now_iso(time() + $days * 86400);

$user = function (string $email, string $first, string $last, string $role) use ($hash): array {
    $existing = row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($existing) {
        return $existing;
    }
    $id = insert('INSERT INTO users (email, password_hash, first_name, last_name, role, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)',
        [$email, $hash, $first, $last, $role, now_iso()]);
    return ['id' => $id, 'email' => $email, 'role' => $role];
};
$titre = fn (string $code, string $name, string $level): int => (int) (val('SELECT id FROM titres WHERE name = ?', [$name])
    ?? insert("INSERT INTO titres (code, name, level, description) VALUES (?, ?, ?, '')", [$code, $name, $level]));

tx(function () use ($user, $titre, $inDays) {
    $trainer = $user('formatrice.demo@afpar.re', 'Marie', 'Hoarau', 'formateur');
    $students = [];
    foreach ([['Lucas', 'Payet'], ['Anaïs', 'Grondin'], ['Mathieu', 'Rivière'], ['Océane', 'Fontaine'], ['Kevin', 'Boyer'], ['Sarah', 'Técher']] as [$first, $last]) {
        $ascii = strtr(mb_strtolower("$first.$last"), ['ï' => 'i', 'è' => 'e', 'é' => 'e']);
        $students[] = $user("$ascii@demo.afpar.re", $first, $last, 'stagiaire');
    }
    $fpa = $titre('FPA', 'Formateur professionnel d’adultes', 'Niveau 5');
    $titre('SC', 'Secrétaire comptable', 'Niveau 4');
    $titre('DWWM', 'Développeur web et web mobile', 'Niveau 5');

    if (val("SELECT 1 FROM courses WHERE title LIKE 'TP Formateur professionnel%' LIMIT 1")) {
        return;
    }
    $course = insert_course($trainer, [
        'titre_id' => $fpa,
        'title' => 'TP Formateur professionnel d’adultes — session 2026',
        'summary' => 'Parcours préparant au titre professionnel FPA : concevoir, préparer et animer des actions de formation, accompagner les apprenants.',
        'start_date' => $inDays(-20), 'end_date' => $inDays(160),
    ]);
    q('UPDATE courses SET visible = 1 WHERE id = ?', [$course]);
    foreach ($students as $student) {
        q("INSERT INTO enrollments (course_id, user_id, role, created_at) VALUES (?, ?, 'stagiaire', ?)", [$course, $student['id'], now_iso()]);
    }

    $accueil = insert_node($course, null, 'module', ['title' => 'Accueil et intégration', 'description' => 'Découverte du parcours, du titre et de la plateforme.']);
    $seq0 = insert_node($course, $accueil, 'sequence', ['title' => 'Démarrer la formation']);
    $s0 = insert_node($course, $seq0, 'seance', ['title' => 'Présentation du titre et du parcours', 'duration_minutes' => 210,
        'description' => "- Situer les deux activités-types du titre\n- Identifier les modalités de certification"]);
    insert_item($course, $s0, 'etiquette', 'Étiquette', ['text' => '**Bienvenue !** Retrouvez ici toutes les ressources de votre première journée.']);
    $page = insert_item($course, $s0, 'page', 'Livret d’accueil', ['text' => "## Votre parcours\n\nLe titre **Formateur professionnel d’adultes** se compose de deux certificats de compétences professionnelles (CCP).\n\n- CCP 1 : préparer et animer des actions de formation collectives\n- CCP 2 : construire des parcours individualisés et accompagner les apprenants\n\n## Votre plateforme\n\nCochez chaque élément une fois terminé : votre progression se met à jour."]);
    insert_item($course, $s0, 'lien', 'Fiche du titre sur France Compétences', ['url' => 'https://www.francecompetences.fr/recherche/rncp/']);
    $s1 = insert_node($course, $seq0, 'seance', ['title' => 'Contrat pédagogique et positionnement', 'duration_minutes' => 210]);
    insert_item($course, $s1, 'depot', 'Dépôt : auto-positionnement', ['instructions' => 'Complétez la grille d’auto-positionnement puis déposez-la ici au format PDF.', 'due_at' => $inDays(5), 'max_grade' => 20]);

    $ccp1 = insert_node($course, null, 'module', ['title' => 'CCP 1 — Préparer et animer des actions de formation collectives', 'description' => 'Activité-type 1 du référentiel.']);
    $seq1 = insert_node($course, $ccp1, 'sequence', ['title' => 'Élaborer la progression pédagogique d’une action de formation']);
    $s2 = insert_node($course, $seq1, 'seance', ['title' => 'Analyser une demande de formation', 'duration_minutes' => 420]);
    insert_item($course, $s2, 'page', 'Du besoin à l’objectif de formation', ['text' => 'Une demande de formation se traduit en **objectifs de formation**, puis en **objectifs pédagogiques**.']);
    insert_item($course, $s2, 'video', 'Vidéo : formuler un objectif pédagogique', ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ']);
    $s3 = insert_node($course, $seq1, 'seance', ['title' => 'Construire une progression pédagogique', 'duration_minutes' => 420]);
    insert_item($course, $s3, 'depot', 'Dépôt : ma progression pédagogique', ['instructions' => 'Déposez la progression pédagogique construite en sous-groupe.', 'due_at' => $inDays(12), 'max_grade' => 20]);
    $seq2 = insert_node($course, $ccp1, 'sequence', ['title' => 'Concevoir un scénario pédagogique et une séquence de formation', 'available_from' => $inDays(9)]);
    insert_node($course, $seq2, 'seance', ['title' => 'Méthodes et techniques pédagogiques', 'duration_minutes' => 420]);
    insert_node($course, $seq2, 'seance', ['title' => 'Scénariser une séance multimodale', 'duration_minutes' => 420]);
    $seq3 = insert_node($course, $ccp1, 'sequence', ['title' => 'Animer un temps de formation collectif', 'visible' => false]);
    insert_node($course, $seq3, 'seance', ['title' => 'Techniques d’animation de groupe', 'duration_minutes' => 420]);

    $ccp2 = insert_node($course, null, 'module', ['title' => 'CCP 2 — Construire des parcours individualisés et accompagner les apprenants', 'available_from' => $inDays(45)]);
    $seq4 = insert_node($course, $ccp2, 'sequence', ['title' => 'Concevoir l’ingénierie de parcours individualisés']);
    insert_node($course, $seq4, 'seance', ['title' => 'Positionnement et individualisation', 'duration_minutes' => 420]);

    // Un peu d'activité pour illustrer la progression.
    foreach (array_slice($students, 0, 4) as $student) {
        q('INSERT IGNORE INTO completions (item_id, user_id, completed_at) VALUES (?, ?, ?)', [$page, $student['id'], now_iso()]);
    }
});

echo "Données de démonstration prêtes.\n  Formatrice : formatrice.demo@afpar.re\n  Stagiaire  : lucas.payet@demo.afpar.re\n  Mot de passe des comptes de démonstration : $password\n";
