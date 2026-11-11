<?php

declare(strict_types=1);

/** Assistant pédagogique FPA : consignes, outil de proposition de parcours et validation de ses sorties. */

const FPA_SYSTEM_PROMPT = <<<'EOT'
Tu es FPA, l'assistant pédagogique des formatrices et formateurs de l'AFPAR (Association pour la Formation Professionnelle des Adultes à La Réunion). Tu es intégré à AFPAREA, la plateforme de formation de l'AFPAR, et seuls les formateurs ont accès à toi.

## Ton rôle
Tu aides à concevoir l'ingénierie pédagogique de parcours préparant aux titres professionnels du ministère du Travail. À partir du REAC (Référentiel Emploi Activités Compétences) et du REV (Référentiel d'Évaluation) fournis par le formateur, tu proposes un découpage précis du parcours, avec des durées réalistes, puis tu l'enregistres avec l'outil proposer_parcours pour que le formateur puisse le générer sur la plateforme en un clic.

## Structure d'un parcours sur AFPAREA
Trois niveaux, toujours dans cet ordre : module > séquence > séance.
- Un module correspond en général à une activité-type du REAC (un CCP), ou à un temps transversal : accueil et intégration, période en entreprise, préparation à la certification, session d'examen.
- Une séquence regroupe les séances visant une même compétence professionnelle du REAC, ou un même objectif intermédiaire.
- Une séance est une unité de face-à-face ou de travail d'une demi-journée (3,5 h) ou d'une journée (7 h) ; elle porte des objectifs opérationnels observables et, si utile, des activités.

## Ce qui rend un découpage juste
- Appuie-toi sur le contenu réel des référentiels : reprends les intitulés exacts des activités-types et des compétences, et couvre chaque compétence et chaque savoir ou savoir-faire décrit. Ne cite jamais un élément de référentiel que tu n'as pas lu dans les documents ; si un référentiel manque ou est illisible, dis-le et demande-le plutôt que de l'inventer.
- Le REV fixe les modalités de certification : prévois les évaluations en cours de formation (ECF) qu'il exige, la constitution progressive du dossier professionnel, les évaluations passées en cours de formation, et l'entraînement à l'épreuve finale (mise en situation professionnelle, entretien technique, questionnaire professionnel, entretien final selon le titre).
- Les durées doivent tenir dans le volume annoncé par le formateur. Base de calcul : 35 h par semaine, 7 h par jour. Pondère selon la complexité des compétences et le niveau du titre, réserve du temps à la pratique et aux mises en situation (les titres professionnels s'acquièrent surtout par la pratique), et vérifie que la somme des séances correspond au total visé avant d'enregistrer.
- Les périodes en entreprise apparaissent comme des modules ou séquences distincts, avec leur durée.
- La progression va du simple au complexe et suit la logique du métier ; indique les prérequis quand l'ordre compte.

## Comment travailler avec le formateur
- Avant de construire, il te faut : le titre visé, la durée totale en centre et en entreprise, et le public (prérequis, effectif, modalité présentielle ou mixte). Si une de ces informations manque et change réellement le découpage, pose la question, brièvement et en une seule fois. Sinon, fais une hypothèse raisonnable et annonce-la.
- Quand tu as ce qu'il faut, présente d'abord en quelques lignes la logique du découpage (répartition des heures par module, choix structurants, hypothèses), puis appelle proposer_parcours avec le parcours complet. N'écris pas le détail du découpage dans ta réponse : la plateforme affiche la proposition sous forme d'arborescence. N'ajoute rien après l'appel à l'outil.
- Quand le formateur demande un ajustement, rappelle proposer_parcours avec le parcours complet mis à jour, et non la seule partie modifiée : chaque proposition remplace la précédente.
- Tu peux aussi répondre à toute question d'ingénierie pédagogique : objectifs, scénarios de séance, modalités d'évaluation, individualisation, différenciation.

Réponds en français, de façon directe et concrète, comme un collègue expérimenté en ingénierie de formation.
EOT;

const FPA_ACTIVITY_TYPES = ['page', 'depot', 'etiquette'];

/** Définition de l'outil, au format du SDK PHP (clés en camelCase). */
function fpa_proposal_tool(): array
{
    $string = ['type' => 'string'];
    return [
        'name' => 'proposer_parcours',
        'description' => 'Enregistre une proposition complète de parcours de formation (modules > séquences > séances, avec durées) et l’affiche au formateur, qui pourra la générer sur la plateforme. À appeler dès que le découpage est prêt, puis à chaque ajustement, toujours avec le parcours entier.',
        // La proposition est longue : elle est transmise au fur et à mesure de sa rédaction, puis validée par fpa_normalize_proposal().
        'eagerInputStreaming' => true,
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'titre' => $string + ['description' => 'Titre du parcours, par exemple « TP Formateur professionnel d’adultes — session 2026 ».'],
                'resume' => $string + ['description' => 'Présentation du parcours en 2 à 4 phrases : public, finalité, organisation.'],
                'modules' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'titre' => $string,
                        'description' => $string + ['description' => 'Activité-type ou finalité du module, compétences visées.'],
                        'sequences' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'titre' => $string,
                                'description' => $string + ['description' => 'Compétence professionnelle visée et critères de réussite.'],
                                'seances' => ['type' => 'array', 'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'titre' => $string,
                                        'duree_heures' => ['type' => 'number', 'description' => 'Durée en heures, en général 3.5 ou 7.'],
                                        'objectifs' => ['type' => 'array', 'items' => $string, 'description' => 'Objectifs opérationnels de la séance.'],
                                        'activites' => [
                                            'type' => 'array',
                                            'description' => 'Éléments à créer dans la séance (facultatif) : « page » pour un déroulé ou une consigne, « depot » pour un espace de dépôt de production des stagiaires (ECF, dossier professionnel…), « etiquette » pour un court texte affiché.',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'type' => ['type' => 'string', 'enum' => FPA_ACTIVITY_TYPES],
                                                    'titre' => $string,
                                                    'contenu' => $string + ['description' => 'Texte de la page, consigne du dépôt ou texte de l’étiquette (Markdown simple).'],
                                                ],
                                                'required' => ['type', 'titre', 'contenu'],
                                            ],
                                        ],
                                    ],
                                    'required' => ['titre', 'duree_heures', 'objectifs'],
                                ]],
                            ],
                            'required' => ['titre', 'seances'],
                        ]],
                    ],
                    'required' => ['titre', 'sequences'],
                ]],
            ],
            'required' => ['titre', 'modules'],
        ],
    ];
}

function fpa_text(mixed $value, int $max): string
{
    return is_string($value) ? mb_substr(trim($value), 0, $max, 'UTF-8') : '';
}

function fpa_list(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new InvalidArgumentException("$label : liste attendue");
    }
    return $value;
}

function fpa_title(mixed $value, string $label): string
{
    $title = fpa_text($value, 200);
    if ($title === '') {
        throw new InvalidArgumentException("$label : titre manquant");
    }
    return $title;
}

/**
 * Valide l'entrée de l'outil et la ramène à une forme sûre.
 * L'entrée arrive en flux sans validation côté API : elle peut être tronquée ou incomplète.
 * @throws InvalidArgumentException si la proposition est inutilisable
 */
function fpa_normalize_proposal(mixed $input): array
{
    if (!is_array($input) || !$input) {
        throw new InvalidArgumentException('proposition vide');
    }
    $total = 0.0;
    $seanceCount = 0;
    $modules = [];
    foreach (fpa_list($input['modules'] ?? null, 'modules') as $m => $module) {
        $moduleLabel = 'module ' . ($m + 1);
        $module = is_array($module) ? $module : [];
        $sequences = [];
        foreach (fpa_list($module['sequences'] ?? null, $moduleLabel) as $s => $sequence) {
            $sequenceLabel = "$moduleLabel, séquence " . ($s + 1);
            $sequence = is_array($sequence) ? $sequence : [];
            $seances = [];
            foreach (fpa_list($sequence['seances'] ?? null, $sequenceLabel) as $k => $seance) {
                $label = "$sequenceLabel, séance " . ($k + 1);
                $seance = is_array($seance) ? $seance : [];
                $duree = $seance['duree_heures'] ?? null;
                if (!is_numeric($duree) || $duree <= 0 || $duree > 400) {
                    throw new InvalidArgumentException("$label : durée invalide");
                }
                $duree = round((float) $duree, 2);
                $total += $duree;
                $seanceCount++;
                $activites = [];
                foreach (is_array($seance['activites'] ?? null) ? $seance['activites'] : [] as $activite) {
                    if (is_array($activite) && in_array($activite['type'] ?? null, FPA_ACTIVITY_TYPES, true) && fpa_text($activite['titre'] ?? null, 200) !== '') {
                        $activites[] = ['type' => $activite['type'], 'titre' => fpa_text($activite['titre'], 200), 'contenu' => fpa_text($activite['contenu'] ?? null, 20000)];
                    }
                }
                $objectifs = array_map(fn ($o) => fpa_text($o, 500), is_array($seance['objectifs'] ?? null) ? $seance['objectifs'] : []);
                $seances[] = [
                    'titre' => fpa_title($seance['titre'] ?? null, $label),
                    'duree_heures' => $duree,
                    'objectifs' => array_values(array_filter($objectifs, fn ($o) => $o !== '')),
                    'activites' => $activites,
                ];
            }
            $sequences[] = [
                'titre' => fpa_title($sequence['titre'] ?? null, $sequenceLabel),
                'description' => fpa_text($sequence['description'] ?? null, 4000),
                'seances' => $seances,
            ];
        }
        $modules[] = [
            'titre' => fpa_title($module['titre'] ?? null, $moduleLabel),
            'description' => fpa_text($module['description'] ?? null, 4000),
            'sequences' => $sequences,
        ];
    }
    if (!$modules) {
        throw new InvalidArgumentException('aucun module');
    }
    if (!$seanceCount) {
        throw new InvalidArgumentException('aucune séance');
    }
    return [
        'titre' => fpa_title($input['titre'] ?? null, 'parcours'),
        'resume' => fpa_text($input['resume'] ?? null, 4000),
        'duree_totale_heures' => round($total, 2),
        'modules' => $modules,
    ];
}
