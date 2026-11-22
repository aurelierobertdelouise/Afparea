<article class="panel prose">
    <h1 class="h-card">Mentions légales &amp; protection des données</h1>
    <h2>Éditeur</h2>
    <p>AFPAR — Association pour la Formation Professionnelle des Adultes de La Réunion. Responsable du traitement : <?= e(cfg('responsable_traitement')) ?>.</p>
    <h2>Hébergement</h2>
    <p><?= e(cfg('hebergeur')) ?>.</p>
    <h2>Objet du service</h2>
    <p>Application interne de réservation et de paiement en ligne des pains et viennoiseries issus de la production pédagogique de la filière Boulangerie (CFPA de Saint-André), réservée au personnel de l'AFPAR.</p>
    <h2>Données personnelles (RGPD)</h2>
    <table class="table">
        <tr><th>Finalité</th><td>Confection, expédition et remise des commandes. Aucun traitement marketing ni transmission à des tiers.</td></tr>
        <tr><th>Base légale</th><td>Exécution de la commande passée par le salarié.</td></tr>
        <tr><th>Données</th><td>Nom, prénom, e-mail professionnel @afpar.com, fonction, centre d'affectation, téléphone professionnel (facultatif), contenu des commandes.</td></tr>
        <tr><th>Données bancaires</th><td>Aucune saisie, transit ni stockage de numéro de carte par l'AFPAR : le paiement est traité intégralement par Stripe (certifié PCI-DSS).</td></tr>
        <tr><th>Destinataires</th><td>Équipe Boulangerie (production), référents de centre (distribution, limitée à leur centre), administrateurs. Sous-traitants : LWS (hébergement), Stripe (paiement).</td></tr>
        <tr><th>Conservation</th><td>Commandes : <?= (int) cfg('retention_months', 6) ?> mois, puis purge automatique. Codes de connexion : 24 h. Compte : jusqu'à sa suppression.</td></tr>
        <tr><th>Vos droits</th><td>Accès, rectification, effacement (depuis « Mon profil »), limitation et opposition, auprès du DPO de l'AFPAR : <a href="mailto:<?= e(cfg('dpo_email')) ?>"><?= e(cfg('dpo_email')) ?></a>. Réclamation possible auprès de la CNIL (cnil.fr).</td></tr>
    </table>
    <h2>Cookies</h2>
    <p>Un unique cookie technique de session (« GOURMANDISES ») est utilisé pour la connexion et le panier. Aucun cookie publicitaire ni de mesure d'audience ; aucune ressource tierce n'est chargée (polices hébergées localement).</p>
    <h2>Sécurité</h2>
    <p>Connexion restreinte aux adresses @afpar.com par code à usage unique, cloisonnement des données par rôle (salarié, référent de centre, administrateur), échanges chiffrés (HTTPS).</p>
    <img class="cofin" src="<?= e(asset('assets/img/cofinancement.jpg')) ?>" alt="Projet cofinancé par l'Union européenne et la Région Réunion (FSE+). Partenaires : Région Réunion, Département de La Réunion, CIVIS, Saint-Paul, Qualiopi, SGI Europe, Charte de la diversité." width="1400" height="562" loading="lazy">
    <p class="center"><a class="btn btn-green" href="<?= e(url()) ?>">Retour</a></p>
</article>
