# Gourmandises AFPAR — boutique en ligne (PHP)

Application de commande de **pains et viennoiseries** (livraison ou Click & Collect),
réécrite en PHP à partir du concept de l'application Glide « Gourmandises »,
aux couleurs de l'AFPAR (marine `#2F2E4E`, vert `#4EB688`, jaune `#FCC324`)
et illustrée avec les visuels « Pains en ligne » / « Viennoiseries en ligne ».

## Fonctionnalités

**Côté client** (mobile-first, installable comme une appli sur l'écran d'accueil)
- Accueil, rayons *Pains* et *Viennoiseries*, fiche produit avec allergènes
- Panier (ajout sans rechargement, +/−, suppression)
- Commande : Click & Collect (gratuit) ou Livraison (frais configurables, offerts au-delà d'un seuil),
  choix du jour et du créneau, coordonnées, paiement à la réception
- Page de confirmation + **suivi de commande** (n° de commande + téléphone)

**Espace équipe** (`index.php?p=admin`)
- Liste des commandes filtrable (statut, jour), changement de statut en un clic
  (Nouvelle → En préparation → Prête / En livraison → Terminée)
- **Fournée du jour** : total à produire par article, imprimable
- Gestion des produits (prix, description, allergènes, photo, mise en ligne) et des catégories

## Technique
- PHP ≥ 8.0, **aucune dépendance** (pas de Composer), PDO
- Base **SQLite** (zéro configuration) ou **MySQL/MariaDB** (recommandé sur LWS)
- Tables créées et catalogue pré-rempli automatiquement au premier lancement
- Sécurité : requêtes préparées, jetons CSRF, mots de passe hachés, en-têtes HTTP,
  dossiers `inc/`, `pages/`, `data/` et `config.php` inaccessibles depuis le web,
  pas d'exécution de script dans le dossier d'images envoyées

## Déploiement sur LWS Performance

1. **Base de données** (recommandé) : panel LWS → *Bases de données MySQL* → créer une base,
   noter hôte, nom, utilisateur et mot de passe.
2. **Configuration** : copier `config.sample.php` en `config.php` et renseigner :
   - `db.driver` → `'mysql'` et les identifiants de la base (ou laisser `'sqlite'`)
   - `admin_password_hash` → générer un nouveau hash :
     `php -r "echo password_hash('MotDePasseFort', PASSWORD_DEFAULT);"`
     (ou via un petit fichier PHP temporaire, à supprimer ensuite)
   - `base_url` → `''` si à la racine du domaine, `'/gourmandises'` si dans un sous-dossier
   - frais de livraison, créneaux, codes postaux livrés, adresse de retrait, e-mail de notification
3. **Envoi des fichiers** : transférer le **contenu** du dossier `gourmandises/` via le
   gestionnaire de fichiers LWS ou en FTP (FileZilla) dans `htdocs/` (ou un sous-dossier).
   Bien inclure les fichiers cachés `.htaccess`.
4. **Droits** : les dossiers `data/` et `assets/img/uploads/` doivent être accessibles
   en écriture (755 ou 775 suffisent généralement chez LWS).
5. **PHP** : panel LWS → *Configuration PHP* → choisir PHP 8.1 ou plus récent.
6. **HTTPS** : activer le certificat SSL Let's Encrypt dans le panel, puis décommenter
   le bloc « Forcer HTTPS » dans `.htaccess`.
7. Ouvrir le site, puis `index.php?p=admin` → se connecter (identifiant `admin`, le mot de
   passe choisi). ⚠️ Le mot de passe de démonstration du fichier exemple est
   `gourmandises2026` : **à changer avant mise en ligne**.

## Test en local
```bash
cd gourmandises
php -S localhost:8000
# puis ouvrir http://localhost:8000
```

## Arborescence
```
gourmandises/
├── index.php              routeur (index.php?p=…)
├── config.sample.php      modèle de configuration → copier en config.php
├── .htaccess              sécurité, cache, compression
├── manifest.webmanifest   installation sur mobile
├── inc/                   bootstrap, base de données, panier, fonctions, gabarits
├── pages/                 vues publiques + admin/
├── assets/css|js|img      charte AFPAR, scripts, logo, affiches et photos produits
└── data/                  base SQLite (si utilisée) — non accessible depuis le web
```

## Paiement
Le règlement se fait **à la réception** (CB ou espèces) : aucune donnée bancaire n'est saisie
ni stockée. Un paiement en ligne (Stripe, SumUp, PayPal…) pourra être branché ultérieurement
dans `pages/commande_controller.php`.
