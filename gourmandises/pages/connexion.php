<section class="panel auth-card">
    <h1 class="script auth-title"><span class="g">Gourmandises</span> <span class="y">en ligne</span></h1>
    <p class="center muted">Précommandez les pains et viennoiseries de la filière Boulangerie, livrés dans votre centre.</p>

    <?php if ($error): ?>
        <div class="flash flash-error" role="alert"><?= icon('alert') ?> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'email'): ?>
        <form method="post" action="<?= e(url('connexion')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="email">
            <label>Votre adresse e-mail professionnelle
                <input type="email" name="email" placeholder="prenom.nom@afpar.com" autocomplete="email" inputmode="email" required autofocus
                       data-domain="<?= e(implode(',', (array) cfg('allowed_domains'))) ?>">
            </label>
            <p class="err" data-domain-error hidden>Accès réservé au personnel AFPAR : utilisez votre adresse @<?= e(implode(' / @', (array) cfg('allowed_domains'))) ?>.</p>
            <button class="btn btn-cta btn-block" type="submit"><?= icon('mail') ?> Recevoir mon code</button>
        </form>
        <p class="small muted center">Un code à <?= (int) cfg('otp_length', 6) ?> chiffres vous sera envoyé sur votre messagerie AFPAR. Pas de mot de passe à retenir.</p>
    <?php else: ?>
        <p class="center">Code envoyé à <strong><?= e($email) ?></strong>.<br><span class="small muted">Valable <?= (int) cfg('otp_ttl_minutes', 10) ?> minutes. Pensez à vérifier vos indésirables.</span></p>
        <?php if ($debugCode): ?>
            <div class="flash flash-info">Mode test (otp_debug) — votre code : <strong><?= e($debugCode) ?></strong></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('connexion')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="code">
            <label>Code de connexion
                <input class="otp" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="<?= (int) cfg('otp_length', 6) + 2 ?>" required autofocus>
            </label>
            <button class="btn btn-cta btn-block" type="submit"><?= icon('lock') ?> Me connecter</button>
        </form>
        <form method="post" action="<?= e(url('connexion')) ?>" class="row-actions">
            <?= csrf_field() ?>
            <button class="linklike" name="action" value="changer">Changer d'adresse</button>
            <button class="linklike" name="action" value="renvoyer">Renvoyer un code</button>
        </form>
    <?php endif; ?>
</section>
