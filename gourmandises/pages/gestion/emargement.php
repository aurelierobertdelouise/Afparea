<?php $page_key = 'gestion-emargement'; $centreRequired = true; ?>
<div class="admin-head">
    <h1 class="h-page">Émargement — <?= e($centre['nom'] ?? '') ?></h1>
    <div class="toolbar no-print">
        <button class="btn btn-cta btn-sm" type="button" data-scan-open hidden><?= icon('box') ?> Scanner un QR code</button>
        <button class="btn btn-ghost btn-sm" data-print><?= icon('print') ?> Version papier</button>
    </div>
</div>
<?php require __DIR__ . '/_filtres.php'; ?>
<p class="print-only">Point relais : <?= e($centre['point_relais'] ?? '') ?> — <?= e(date_fr($jour)) ?></p>
<form method="get" action="<?= e(url()) ?>" class="panel inline-search no-print">
    <input type="hidden" name="p" value="gestion-commande"><input type="hidden" name="jour" value="<?= e($jour) ?>">
    <label class="grow">Retrouver par code de retrait <input name="code" inputmode="numeric" maxlength="4" placeholder="1234"></label>
    <button class="btn btn-green">Chercher</button>
    <input class="grow" type="search" placeholder="Filtrer par nom…" data-filter-rows=".emarg tbody tr" aria-label="Filtrer par nom">
</form>
<?php if (!$commandes): ?>
    <div class="panel empty"><p>Aucune commande à distribuer ce jour dans ce centre.</p></div>
<?php else:
    $remises = count(array_filter($commandes, fn($c) => $c['statut'] === 'recuperee')); ?>
<p class="progress-txt"><b data-remises><?= $remises ?></b> / <?= count($commandes) ?> commande(s) remise(s)</p>
<div class="panel table-wrap">
    <table class="table emarg">
        <thead><tr><th>Salarié</th><th>Contenu</th><th>Code</th><th class="no-print">Statut</th><th>Signature / remise</th></tr></thead>
        <tbody>
        <?php foreach ($commandes as $c): ?>
            <tr data-ref="<?= e($c['reference']) ?>" data-name="<?= e(mb_strtolower($c['nom'] . ' ' . $c['prenom'])) ?>" class="<?= $c['statut'] === 'recuperee' ? 'done' : '' ?>">
                <td><strong><?= e($c['nom']) ?></strong> <?= e($c['prenom']) ?><br><small class="muted"><?= e($c['reference']) ?></small></td>
                <td class="small"><?php foreach ($lignes[$c['id']] ?? [] as $l): ?><?= (int) $l['quantite'] ?> <?= e($l['nom']) ?><br><?php endforeach; ?></td>
                <td><b><?= e($c['code_retrait']) ?></b></td>
                <td class="no-print"><span class="st st-<?= e($c['statut']) ?>" data-status><?= e(statut_label($c['statut'])) ?></span></td>
                <td class="sigcell">
                    <?php if ($c['statut'] === 'recuperee'): ?>
                        <span class="small">✓ <?= e(date('H:i', strtotime((string) $c['remise_at']))) ?> · <?= e($c['remise_par']) ?></span>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('gestion-statut')) ?>" class="no-print" data-remise>
                            <?= csrf_field() ?><input type="hidden" name="ref" value="<?= e($c['reference']) ?>"><input type="hidden" name="statut" value="recuperee">
                            <input type="hidden" name="retour" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                            <button class="btn btn-cta btn-sm" data-confirm="Confirmer la remise à <?= e($c['prenom'] . ' ' . $c['nom']) ?> ?">Remis ✓</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php /* Scanner de QR code : s'affiche par-dessus la liste */ ?>
<div class="scan-overlay" data-scan hidden role="dialog" aria-modal="true" aria-label="Scanner un QR code de retrait"
     data-scan-url="<?= e(url('gestion-scan', ['jour' => $jour, 'centre' => $centreId])) ?>"
     data-statut-url="<?= e(url('gestion-statut')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <div class="scan-box">
        <div class="scan-head">
            <strong>Scanner le QR code du reçu</strong>
            <button type="button" class="icon-btn scan-close" data-scan-close aria-label="Fermer">✕</button>
        </div>
        <div class="scan-video" data-scan-camera>
            <video playsinline muted></video>
            <span class="scan-frame" aria-hidden="true"></span>
            <p class="scan-msg" data-scan-msg>Activation de la caméra…</p>
        </div>
        <div class="scan-result" data-scan-result hidden></div>
    </div>
</div>
<script src="<?= e(asset('assets/js/vendor/jsQR.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/scanner.js')) ?>" defer></script>
