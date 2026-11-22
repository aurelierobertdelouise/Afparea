<div class="panel empty">
    <h1 class="h-card"><?= e($title ?? 'Erreur') ?></h1>
    <p><?= e($message ?? 'Une erreur est survenue.') ?></p>
    <a class="btn btn-green" href="<?= e(url()) ?>">Retour</a>
</div>
