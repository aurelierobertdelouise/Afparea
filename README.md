# AFPAREA — plateforme LMS de l'AFPAR

Plateforme de formation inspirée de Moodle, aux couleurs de l'AFPAR : parcours préparant aux titres professionnels,
découpés en **modules > séquences > séances**, ressources et espaces de dépôt, suivi de progression, inscription par lot,
et **FPA**, un assistant pédagogique IA réservé aux formateurs.

## Fonctionnalités

| Domaine | Ce que fait la plateforme |
|---|---|
| Accès sécurisé | Connexion par e-mail et mot de passe, trois profils (administration, formateur·rice, stagiaire), mot de passe provisoire à changer à la première connexion. |
| Tableau de bord | Chaque utilisateur voit les parcours auxquels il est inscrit, avec sa progression (stagiaire) ou l'effectif (formateur). Filtre par titre professionnel. |
| Titres professionnels | Liste des titres préparés ; chaque parcours se rattache à un titre. |
| Parcours | Découpage modules / séquences / séances avec durées, image d'en-tête, couleur, dates, présentation. |
| Ressources | Page, fichier, dossier, lien web, vidéo (YouTube / Vimeo intégrés), étiquette, dépôt. |
| Dépôts | Les stagiaires déposent leurs productions ; le formateur les consulte, note et commente. |
| Édition | Mode édition : glisser-déposer à tous les niveaux, icône œil pour masquer / afficher, icône horloge pour programmer la mise à disposition. |
| Programmation | Un module, une séquence, une séance ou une ressource peut s'ouvrir automatiquement à une date et heure données. Avant cette date, le stagiaire voit le titre verrouillé, pas le contenu. |
| Inscriptions | Individuelle par e-mail, ou par lot depuis un fichier **Excel (.xlsx) ou CSV** avec aperçu avant validation. Les comptes manquants sont créés et les identifiants provisoires téléchargeables. |
| Progression | Les stagiaires cochent les éléments terminés ; un dépôt remis est coché automatiquement. Les formateurs voient la progression de chacun. |
| Assistant FPA | À partir du REAC et du REV (PDF), propose un découpage avec durées, l'ajuste au fil de la conversation, puis génère le parcours sur la plateforme. |

## Installation

Prérequis : Node.js 20.12 ou plus récent.

```bash
npm install
cp .env.example .env    # puis renseignez les valeurs
npm start
```

Au premier démarrage, un compte administrateur est créé (`ADMIN_EMAIL`, par défaut `admin@afpar.re`) et son mot de passe
provisoire s'affiche dans la console. Ouvrez ensuite http://localhost:3000.

Pour découvrir la plateforme avec un parcours d'exemple, une formatrice et six stagiaires :

```bash
npm run seed:demo       # affiche les identifiants de démonstration ; à ne pas lancer en production
```

## Configuration (`.env`)

| Variable | Rôle |
|---|---|
| `PORT` | Port d'écoute (3000 par défaut). |
| `DATA_DIR` | Dossier de la base SQLite et des fichiers déposés (`./data` par défaut). **C'est le dossier à sauvegarder.** |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Compte administrateur créé au premier démarrage. |
| `ANTHROPIC_API_KEY` | Clé de l'API Claude, nécessaire à l'assistant FPA. Sans elle, le reste de la plateforme fonctionne normalement. |
| `FPA_MODEL`, `FPA_EFFORT` | Modèle (`claude-opus-5-5` par défaut) et niveau d'effort (`high` par défaut) de l'assistant. |
| `FPA_FALLBACKS` | `off` pour désactiver le repli automatique sur un autre modèle quand une demande est déclinée. |
| `MAX_UPLOAD_MB` | Taille maximale d'un fichier (50 Mo par défaut). |
| `COOKIE_SECURE` | `true` en production derrière HTTPS. |
| `TRUST_PROXY` | `1` si la plateforme est derrière un proxy inverse (nginx, Caddy…). |

En production, placez la plateforme derrière HTTPS (proxy inverse) avec `COOKIE_SECURE=true` et `TRUST_PROXY=1`.

## Fichier d'inscription par lot

Une ligne par stagiaire, colonnes `nom`, `prénom`, `email` (l'ordre est libre si la première ligne porte ces en-têtes ;
`mail`, `courriel`, `adresse mail` sont aussi reconnus). Formats acceptés : `.xlsx`, `.csv` (séparateur `;`, `,` ou tabulation).
Un modèle est téléchargeable depuis la fenêtre d'import.

## Assistant FPA

Accessible aux formateurs et à l'administration uniquement (contrôle côté serveur). Le formateur joint le REAC et le REV
en PDF, précise la durée et le public ; l'assistant présente sa logique puis une proposition structurée, affichée en
arborescence. Un clic sur « Générer ce parcours » crée le parcours, masqué aux stagiaires tant que le formateur ne l'ouvre pas.
Les documents sont transmis à l'API Claude d'Anthropic pour produire la réponse.

## Sécurité

- Mots de passe hachés (scrypt), sessions par cookie `HttpOnly` / `SameSite`, limitation des tentatives de connexion.
- Toutes les règles d'accès sont appliquées par le serveur : un stagiaire ne reçoit jamais le contenu d'un élément masqué
  ou pas encore disponible, ni les productions des autres stagiaires.
- Protection CSRF par en-tête dédié, politique de sécurité du contenu (CSP), fichiers servis en téléchargement sauf formats sûrs.

## Développement

```bash
npm run dev     # redémarrage automatique
npm test        # tests unitaires et d'intégration (règles d'accès, import, dépôts)
```

- `server/` — API Express, base SQLite (`better-sqlite3`), assistant (`fpa.js`, `routes/ai.js`).
- `public/` — interface (Preact + htm, sans étape de compilation), styles dans `css/app.css`.
- `test/` — tests exécutés avec le lanceur intégré de Node.
