/** Assistant pédagogique FPA : consignes, outil de proposition de parcours et validation de ses sorties. */

export const SYSTEM_PROMPT = `Tu es FPA, l'assistant pédagogique des formatrices et formateurs de l'AFPAR (Association pour la Formation Professionnelle des Adultes à La Réunion). Tu es intégré à AFPAREA, la plateforme de formation de l'AFPAR, et seuls les formateurs ont accès à toi.

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

Réponds en français, de façon directe et concrète, comme un collègue expérimenté en ingénierie de formation.`;

const ACTIVITY_TYPES = ['page', 'depot', 'etiquette'];

export const PROPOSAL_TOOL = {
  name: 'proposer_parcours',
  description: 'Enregistre une proposition complète de parcours de formation (modules > séquences > séances, avec durées) et l’affiche au formateur, qui pourra la générer sur la plateforme. À appeler dès que le découpage est prêt, puis à chaque ajustement, toujours avec le parcours entier.',
  eager_input_streaming: true,
  input_schema: {
    type: 'object',
    properties: {
      titre: { type: 'string', description: 'Titre du parcours, par exemple « TP Formateur professionnel d’adultes — session 2026 ».' },
      resume: { type: 'string', description: 'Présentation du parcours en 2 à 4 phrases : public, finalité, organisation.' },
      modules: {
        type: 'array',
        items: {
          type: 'object',
          properties: {
            titre: { type: 'string' },
            description: { type: 'string', description: 'Activité-type ou finalité du module, compétences visées.' },
            sequences: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  titre: { type: 'string' },
                  description: { type: 'string', description: 'Compétence professionnelle visée et critères de réussite.' },
                  seances: {
                    type: 'array',
                    items: {
                      type: 'object',
                      properties: {
                        titre: { type: 'string' },
                        duree_heures: { type: 'number', description: 'Durée en heures, en général 3.5 ou 7.' },
                        objectifs: { type: 'array', items: { type: 'string' }, description: 'Objectifs opérationnels de la séance.' },
                        activites: {
                          type: 'array',
                          description: 'Éléments à créer dans la séance (facultatif) : « page » pour un déroulé ou une consigne, « depot » pour un espace de dépôt de production des stagiaires (ECF, dossier professionnel…), « etiquette » pour un court texte affiché.',
                          items: {
                            type: 'object',
                            properties: {
                              type: { type: 'string', enum: ACTIVITY_TYPES },
                              titre: { type: 'string' },
                              contenu: { type: 'string', description: 'Texte de la page, consigne du dépôt ou texte de l’étiquette (Markdown simple).' },
                            },
                            required: ['type', 'titre', 'contenu'],
                          },
                        },
                      },
                      required: ['titre', 'duree_heures', 'objectifs'],
                    },
                  },
                },
                required: ['titre', 'seances'],
              },
            },
          },
          required: ['titre', 'sequences'],
        },
      },
    },
    required: ['titre', 'modules'],
  },
};

const text = (value, max) => (typeof value === 'string' ? value.trim().slice(0, max) : '');

function list(value, label) {
  if (!Array.isArray(value)) throw new Error(`${label} : liste attendue`);
  return value;
}

function required(value, label) {
  const result = text(value, 200);
  if (!result) throw new Error(`${label} : titre manquant`);
  return result;
}

/**
 * Valide l'entrée de l'outil et la ramène à une forme sûre.
 * L'entrée arrive en flux sans validation côté API : elle peut être tronquée ou incomplète.
 */
export function normalizeProposal(input) {
  if (!input || typeof input !== 'object') throw new Error('proposition vide');
  const modules = list(input.modules, 'modules').map((module, m) => ({
    titre: required(module?.titre, `module ${m + 1}`),
    description: text(module.description, 4000),
    sequences: list(module.sequences, `module ${m + 1}`).map((sequence, s) => ({
      titre: required(sequence?.titre, `module ${m + 1}, séquence ${s + 1}`),
      description: text(sequence.description, 4000),
      seances: list(sequence.seances, `module ${m + 1}, séquence ${s + 1}`).map((seance, k) => {
        const label = `module ${m + 1}, séquence ${s + 1}, séance ${k + 1}`;
        const duree = Number(seance?.duree_heures);
        if (!Number.isFinite(duree) || duree <= 0 || duree > 400) throw new Error(`${label} : durée invalide`);
        return {
          titre: required(seance.titre, label),
          duree_heures: Math.round(duree * 100) / 100,
          objectifs: (Array.isArray(seance.objectifs) ? seance.objectifs : []).map((o) => text(o, 500)).filter(Boolean),
          activites: (Array.isArray(seance.activites) ? seance.activites : [])
            .filter((a) => a && ACTIVITY_TYPES.includes(a.type) && text(a.titre, 200))
            .map((a) => ({ type: a.type, titre: text(a.titre, 200), contenu: text(a.contenu, 20000) })),
        };
      }),
    })),
  }));
  if (!modules.length) throw new Error('aucun module');
  if (!modules.some((module) => module.sequences.some((sequence) => sequence.seances.length))) throw new Error('aucune séance');
  const total = modules.reduce((sum, module) => sum + module.sequences.reduce(
    (inner, sequence) => inner + sequence.seances.reduce((acc, seance) => acc + seance.duree_heures, 0), 0), 0);
  return {
    titre: required(input.titre, 'parcours'),
    resume: text(input.resume, 4000),
    duree_totale_heures: Math.round(total * 100) / 100,
    modules,
  };
}
