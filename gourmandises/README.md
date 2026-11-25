# Gourmandises AFPAR — application PHP

Application interne de **réservation et de paiement en ligne** des pains et viennoiseries
de la production pédagogique de la filière Boulangerie (CFPA de Saint-André), **réservée au
personnel AFPAR** et livrée dans les centres. Elle est conforme au *Cahier des spécifications
fonctionnelles V2*, développée en PHP pour l'hébergement **LWS Performance**, aux couleurs
de l'AFPAR (marine `#2F2E4E`, vert `#4EB688`, jaune `#FCC324`).

## Conformité au cahier des charges

| § CDC | Exigence | Mise en œuvre |
|---|---|---|
| 5.1 / 6.1 | Accès exclusif `@afpar.com`, autres domaines bloqués | Contrôle navigateur **et** serveur (`allowed_domains`), message bloquant |
| 6.1 | Connexion par code PIN e-mail | Code à 6 chiffres, haché, valable 10 min, 5 essais, 5 envois/h, usage unique |
| 6.1 | Fiche profil : nom, prénom, e-mail, fonction, centre, téléphone (optionnel) | Écran d'onboarding obligatoire à la 1re connexion + page « Mon profil » |
| 5.3 | Centre de rattachement = lieu de livraison par défaut, modifiable avant paiement | Pré-sélectionné au récapitulatif, modifiable (mission sur un autre site) |
| 5.3 | Point relais identifié par centre | Table `centres` (6 centres pré-remplis), gérée dans *Gestion → Centres* |
| 6.2 | Grille photo / titre / description / prix / allergènes / disponibilité | Cartes produit avec badge *En stock* / *Épuisé* / *Plus que N* |
| 6.2 | Onglets *Tous*, *Viennoiseries*, *Pains* ; fiche produit + quantité | Oui |
| 5.2 / 6.3 | Quotas 10 viennoiseries / 3 pains, calcul temps réel, alerte rouge, bouton paiement masqué | Totaux par catégorie en direct ; messages exacts du CDC ; **double contrôle serveur** (accès direct au paiement refusé). Quotas modifiables par catégorie |
| 6.4 | Étape 1 : récapitulatif + date et créneau | Jours de production, **heure limite J-1 12h**, dates fermées, créneaux configurables |
| 5.4 / 6.4 | Étape 2 : paiement immédiat obligatoire par Stripe (CB, Apple Pay, Google Pay) | **Stripe Checkout** (page hébergée par Stripe) + **webhook signé** |
| 6.4 | Étape 3 : statut *Payée*, panier vidé, reçu avec n° + QR code / code de retrait | Reçu à l'écran et par e-mail, QR code + code à 4 chiffres |
| 6.5 | Catalogue : ajout / modification, prix, activation des stocks du jour | Bascule *En stock / Épuisé*, **stock maximum par jour** (réservations décomptées), photo |
| 6.5 | Vue du jour : *À préparer*, *Prête*, *Récupérée* | Tableau Kanban, changement de statut en un clic |
| 6.5 / 2.4 | Récapitulatif de production du jour J | *Fournil* : totaux par produit + matrice produit × centre, imprimable |
| 5.3 | Feuille de colisage / expédition par centre | *Colisage* : un bac par centre, sacs nominatifs, imprimable |
| 5.3 / 2.5 | Feuille d'émargement / distribution locale | *Émargement* sur tablette (bouton « Remis », horodaté, nom du remettant) ou papier |
| 6.4 / 5.3 | Remise au point relais par QR code | Bouton **« Scanner un QR code »** sur la page Émargement (caméra du téléphone ou de la tablette, sans application à installer) : nom, contenu, alertes mauvais jour / déjà remise / autre centre, validation « Remis ✓ » en un geste. Repli : code de retrait à 4 chiffres |
| 6.5 | Export CSV / Excel | UTF-8 + BOM, séparateur `;`, protection contre l'injection de formules |
| 7.2 | Un salarié ne voit que ses commandes | Contrôle systématique côté serveur (équivalent des *Row Owners* Glide) |
| 7.2 | Référent centre : uniquement son centre ; Admin/Boulangerie : transversal | Rôles `client` / `referent` / `admin`, filtre SQL forcé par centre |
| 7.1 | Finalité, minimisation, aucune donnée bancaire | Seules les données listées par le CDC sont collectées ; carte saisie chez Stripe uniquement |
| 7.1 | Conservation 6 mois puis purge | Purge automatique (contrôle horaire) + bouton manuel dans *Utilisateurs* |
| 7.1 | Écran d'onboarding avec mention des droits (DPO) | Case d'information obligatoire à la 1re connexion, page *Mentions légales & RGPD* |
| 7.1 | Droit à l'effacement | « Supprimer mon compte » (commandes anonymisées) ; suppression possible par un admin |

**Sécurité complémentaire (audit DSI, étape 3)** : requêtes préparées (PDO), jetons CSRF,
cookies `HttpOnly` / `SameSite` / `Secure`, `session_regenerate_id` à la connexion,
Content-Security-Policy stricte (aucun script inline, aucune ressource tierce : **polices
et librairie QR hébergées localement**), HSTS, `X-Frame-Options`, anti open-redirect,
dossiers `inc/`, `pages/`, `data/` et `config.php` inaccessibles depuis le web,
aucune exécution de script dans le dossier des images envoyées.

### Écarts et points ouverts

- **Technologie** : le CDC (§3) préconisait Glide ; l'application est livrée en PHP à la
  demande, pour être hébergée sur LWS. Les tables reprennent la modélisation prévue
  (Utilisateurs, Centres, Produits, Catégories, Commandes, Lignes de commande).
- **Notification de retrait par téléphone** : le numéro est collecté (facultatif) mais
  aucun SMS n'est envoyé (nécessiterait un prestataire SMS). Un e-mail de reçu est envoyé.
- **SSO** : le CDC retient le code PIN par e-mail ; un SSO Microsoft 365 pourra être ajouté
  si la DSI le demande.
- **Remboursement** : une annulation par l'admin ne rembourse pas automatiquement ;
  le remboursement se fait depuis le tableau de bord Stripe.
- **Prix et créneaux** : valeurs indicatives, à remplacer par le catalogue du formateur
  Boulangerie et la matrice logistique (étape 1 du CDC).

## Rôles

| Rôle | Accès |
|---|---|
| Salarié (client) | Catalogue, panier, paiement, ses commandes, son profil |
| Référent centre | + Tableau du jour et émargement **de son centre uniquement** |
| Admin / Boulangerie | + Tous les centres, fournil, colisage, catalogue, centres, utilisateurs et rôles |

Les adresses listées dans `admin_emails` deviennent administrateur à leur 1re connexion.
Les autres rôles s'attribuent dans *Gestion → Utilisateurs*.

## Déploiement sur LWS Performance

1. **PHP** : dans le panel LWS, sélectionner PHP 8.1 ou plus récent (extensions `pdo_mysql`
   et `curl`, actives par défaut).
2. **Base MySQL** : panel LWS → *Bases de données MySQL* → créer une base.
3. **Configuration** : copier `config.sample.php` en `config.php`, puis renseigner :
   - `app_url` (URL publique en `https://`), `base_url` (si sous-dossier)
   - `db` → `driver` = `'mysql'` et les identifiants de la base
   - `admin_emails` → les adresses @afpar.com des administrateurs
   - `mail_from` → une adresse existante sur le domaine hébergé (pour l'envoi des codes)
   - `stripe_secret_key` et `stripe_webhook_secret` (voir ci-dessous)
   - calendrier : `production_days`, `cutoff_days`, `cutoff_time`, `closed_dates`, `slots`
   - `dpo_email`, `responsable_traitement`
   - **`otp_debug` doit rester à `false`**
4. **Envoi des fichiers** : transférer le contenu du dossier `gourmandises/` (y compris les
   fichiers `.htaccess`) dans `htdocs/` ou un sous-dossier, par FTP ou via le gestionnaire de fichiers.
5. **Droits d'écriture** : `data/` et `assets/img/uploads/` (755 ou 775).
6. **HTTPS** : activer le certificat SSL Let's Encrypt, puis décommenter « Forcer HTTPS » dans `.htaccess`.
7. Les tables et le catalogue initial sont créés automatiquement au premier accès.

### Stripe

1. Dans Stripe → *Développeurs → Clés API*, copier la clé secrète (`sk_test_…` pour le
   pilote, `sk_live_…` en production) dans `stripe_secret_key`.
2. Dans *Développeurs → Webhooks*, ajouter le point de terminaison
   `https://<domaine>/stripe-webhook.php` avec les événements `checkout.session.completed`,
   `checkout.session.async_payment_succeeded` et `checkout.session.expired`.
   Copier le secret de signature (`whsec_…`) dans `stripe_webhook_secret`.
3. Pour Apple Pay / Google Pay : *Paramètres → Moyens de paiement*, les activer, et pour
   Apple Pay, vérifier le domaine.

Sans clé Stripe, l'application fonctionne en **mode simulation** (bandeau jaune) : utile pour
la phase pilote, aucun paiement réel n'est alors encaissé.

## Test en local

```bash
cd gourmandises
cp config.sample.php config.php     # puis mettre 'otp_debug' => true (code affiché à l'écran)
php -S localhost:8000
```

## Arborescence

```
gourmandises/
├── index.php              routeur (index.php?p=…)
├── stripe-webhook.php     confirmation des paiements par Stripe
├── verif.php              diagnostic d'installation (activé par 'install_check')
├── config.sample.php      modèle de configuration → config.php (non versionné)
├── inc/                   bootstrap, base, auth (PIN), panier, commandes/quotas, Stripe, gabarits
├── pages/                 vues salarié + gestion/ (back-office)
├── assets/                CSS, JS, polices et librairie QR locales, logo, visuels
└── data/                  base SQLite éventuelle — inaccessible depuis le web
```
