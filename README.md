# AFPAREA — plateforme LMS de l'AFPAR

Plateforme de formation inspirée de Moodle, aux couleurs de l'AFPAR : parcours préparant aux titres professionnels,
découpés en **modules > séquences > séances**, ressources et espaces de dépôt, suivi de progression, inscription par lot,
et **FPA**, un assistant pédagogique IA réservé aux formateurs.

Elle fonctionne sur un hébergement mutualisé classique : **PHP 8.1 ou plus récent et MySQL**, sans ligne de commande.

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

## Installation sur un hébergement LWS (LWS Panel)

Seul le contenu du dossier **`site/`** est à mettre en ligne. Comptez une quinzaine de minutes.

### 1. Préparer l'hébergement

1. Dans le LWS Panel, ouvrez la rubrique de configuration PHP et choisissez **PHP 8.3** (8.1 au minimum).
2. Dans la rubrique des bases de données MySQL, **créez une base** et notez quatre informations : le serveur (hôte),
   le nom de la base, l'utilisateur et le mot de passe.
3. Vérifiez que le certificat SSL (HTTPS) est actif sur votre domaine.

### 2. Envoyer les fichiers

1. Choisissez l'emplacement : la racine du site, un sous-dossier (par exemple `formation/`, accessible à
   `https://votre-domaine/formation/`) ou un sous-domaine créé dans le LWS Panel.
2. Avec le gestionnaire de fichiers du LWS Panel, envoyez l'archive ZIP du dossier `site/` à cet emplacement puis
   décompressez-la. Par FTP (FileZilla), envoyez directement le contenu de `site/`.
3. Vérifiez que les fichiers `.htaccess` sont bien présents (ils sont parfois masqués : activez l'affichage des fichiers cachés).

### 3. Configurer

1. Copiez `config.sample.php` sous le nom **`config.php`**.
2. Ouvrez `config.php` avec l'éditeur du gestionnaire de fichiers et renseignez :
   - `db_host`, `db_name`, `db_user`, `db_password` : les informations notées à l'étape 1 ;
   - `admin_email` et `admin_password` : le compte administrateur (mot de passe provisoire, 10 caractères au minimum) ;
   - `anthropic_api_key` : la clé de l'API Claude pour l'assistant FPA (facultatif : sans clé, le reste fonctionne).

### 4. Premier lancement

1. Ouvrez l'adresse du site : les tables sont créées automatiquement.
2. Connectez-vous avec `admin_email` et `admin_password` ; la plateforme vous demande de choisir un nouveau mot de passe.
3. Une fois connecté·e, vous pouvez effacer la valeur de `admin_password` dans `config.php`.
4. Créez les comptes formateurs dans « Utilisateurs », puis les titres professionnels et les parcours.

### En cas de difficulté

| Message ou symptôme | Que faire |
|---|---|
| « Connexion à la base de données impossible » | Vérifiez les quatre paramètres `db_…` de `config.php` (l'hôte n'est pas toujours `localhost`). |
| « Configuration absente » | Le fichier doit s'appeler exactement `config.php` et se trouver à côté de `index.html`. |
| « Extensions PHP manquantes » | Activez les extensions indiquées dans la configuration PHP du LWS Panel. |
| « AFPAREA nécessite PHP 8.1 » | Changez la version de PHP dans le LWS Panel. |
| Erreur 500 dès l'ouverture | Renommez provisoirement le fichier `.htaccess` de la racine pour vérifier s'il est en cause, puis contactez le support LWS. |
| Envoi de fichier refusé | La taille maximale dépend de l'hébergement (`upload_max_filesize` et `post_max_size` dans la configuration PHP). |
| L'assistant s'interrompt sur un long découpage | La rédaction continue côté serveur et la réponse apparaît dès qu'elle est prête. Si l'hébergeur arrête le traitement, demandez le découpage CCP par CCP. |

### Sauvegardes et mises à jour

- **À sauvegarder** : la base MySQL (export depuis phpMyAdmin) et le dossier `data/uploads/` (fichiers déposés).
- **Mise à jour** : remplacez tous les fichiers sauf `config.php` et le dossier `data/`.

## Assistant FPA

Accessible aux formateurs et à l'administration uniquement (contrôle côté serveur). Le formateur joint le REAC et le REV
en PDF, précise la durée et le public ; l'assistant présente sa logique puis une proposition structurée, affichée en
arborescence. Un clic sur « Générer ce parcours » crée le parcours, masqué aux stagiaires tant que le formateur ne l'ouvre pas.

Les documents joints sont transmis à l'API Claude d'Anthropic pour produire la réponse. Réglages possibles dans `config.php` :
`fpa_model` (par défaut `claude-opus-5-5`), `fpa_effort` (par défaut `high`), `fpa_fallbacks` (`off` pour désactiver le repli
automatique sur un autre modèle quand une demande est déclinée).

## Sécurité

- Mots de passe hachés, sessions par cookie `HttpOnly` / `SameSite` (et `Secure` en HTTPS), limitation des tentatives de connexion.
- Toutes les règles d'accès sont appliquées par le serveur : un stagiaire ne reçoit jamais le contenu d'un élément masqué
  ou pas encore disponible, ni les productions des autres stagiaires.
- Protection CSRF par en-tête dédié, politique de sécurité du contenu (CSP), fichiers servis en téléchargement sauf formats sûrs.
- Les dossiers `app/`, `data/`, `vendor/` et le fichier `config.php` sont interdits d'accès direct par les fichiers `.htaccess`.
  Les fichiers déposés sont enregistrés sous des noms aléatoires et ne sont lisibles qu'à travers la plateforme.

## Développement

Organisation du dépôt :

- `site/` — ce qui est mis en ligne : interface (`index.html`, `js/`, `css/`, Preact + htm sans étape de compilation),
  API (`api/index.php`), code PHP (`app/`), bibliothèques PHP (`vendor/`, dont le SDK officiel d'Anthropic).
- `tools/seed-demo.php` — jeu de données de démonstration (ligne de commande).
- `tests/` — tests unitaires PHP et tests d'intégration.

Lancer en local (PHP 8.1+, MySQL ou MariaDB) :

```bash
cp site/config.sample.php site/config.php      # puis renseignez la base de données
php -S localhost:8000 -t site
php tools/seed-demo.php                         # parcours d'exemple, une formatrice, six stagiaires
```

Tests :

```bash
php tests/unit.php
# Tests d'intégration : la base indiquée est vidée, utilisez une base dédiée dont le nom contient « test ».
AFPAREA_DB_HOST=127.0.0.1 AFPAREA_DB_NAME=afparea_test AFPAREA_DB_USER=… AFPAREA_DB_PASSWORD=… node --test tests/api.test.mjs
```

Chaque paramètre de `config.php` peut être remplacé par une variable d'environnement `AFPAREA_…`
(par exemple `AFPAREA_DB_NAME`). Les bibliothèques PHP se mettent à jour avec `composer update` dans `site/`.
