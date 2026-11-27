# Mon Budget — application mobile de gestion de budget

Application web installable sur téléphone (PWA), pensée à partir du tableau « Gestion de Budget » :
catégories, libellés, montants, opérations **récurrentes** et **pointées (terminé)**, reste à vivre,
jours avant la paye, montant disponible par jour et prévisions M+1 à M+6.
Charte graphique aux couleurs du logo : vert anis `#9FBD39` et brun olive `#51470A`.

## Fonctionnalités
- **Saisie rapide** (bouton +) : montant, libellé, catégorie, date, récurrent, pointé.
  Les libellés déjà saisis sont proposés et pré-remplissent catégorie et montant.
- **Accueil** : reste à vivre prévu, revenus/dépenses, solde réel (opérations pointées),
  reste à payer, « tu peux dépenser X € par jour » jusqu'à la prochaine paye, alertes,
  répartition par catégorie, budgets par catégorie, prévisions sur 6 mois.
- **Opérations** : classement par catégorie ou par date d'opération (par jour, plus récentes ou plus anciennes d'abord), recherche, pointage d'un geste, modification/suppression (avec annulation).
- **Historique** : clôture du mois (sauvegarde), création automatique du mois suivant avec les
  opérations récurrentes et le report du solde, comparaison des mois, ajout de mois passés.
  Menu « ⋯ » de chaque mois : ouvrir, rouvrir, importer des opérations, **supprimer** (avec annulation).
- **Import d'un mois complet** : classeur Excel / Google Sheets (.xlsx, choix de la feuille — proposée
  automatiquement d'après le nom du mois), fichier .csv ou lignes copiées-collées. Colonnes reconnues :
  Catégorie (ou Origine), Libellé, Montant, Récurrent ?, Terminé, Date / Opération, Type.
  Aperçu avant import, ajout ou remplacement, création des catégories inconnues.
- **6 mois suivants** (option) : mois prévisionnels préremplis avec les opérations récurrentes,
  consultables et modifiables ; masquer l'option les cache sans les effacer.
- **Réglages** : jour de paye, report du solde, seuil d'alerte, devise, thème clair/sombre,
  catégories (nom, icône, couleur, budget mensuel), choix des blocs affichés,
  sauvegarde/restauration (JSON), export du mois en CSV (Excel / Google Sheets).
- Fonctionne **hors-ligne** ; données stockées uniquement sur l'appareil.
- Lecture des classeurs : [SheetJS](https://sheetjs.com) 0.18.5 (licence Apache-2.0), dans `vendor/`.

## Lancer / installer
Servir le dossier `budget-app/` en HTTPS (GitHub Pages, Netlify…) ou en local :

```bash
cd budget-app && python3 -m http.server 8000
```

Puis ouvrir l'adresse sur le téléphone et choisir « Ajouter à l'écran d'accueil ».
