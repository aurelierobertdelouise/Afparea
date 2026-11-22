<?php /** Filtres communs jour + centre. @var string $page_key */ ?>
<form class="panel filters no-print" method="get" action="<?= e(url()) ?>">
    <input type="hidden" name="p" value="<?= e($page_key) ?>">
    <label>Jour de retrait <input type="date" name="jour" value="<?= e($jour) ?>" data-autosubmit></label>
    <?php if ($isAdmin && empty($noCentre)): ?>
    <label>Centre
        <select name="centre" data-autosubmit>
            <?php if (empty($centreRequired)): ?><option value="0">Tous les centres</option><?php endif; ?>
            <?php foreach (centres(false) as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $centreId ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>
    <div class="quick-days">
        <?php foreach ([0 => "Aujourd'hui", 1 => 'Demain'] as $d => $lbl): $v = date('Y-m-d', strtotime("+$d day")); ?>
            <a class="btn btn-sm <?= $v === $jour ? 'btn-green' : 'btn-ghost' ?>" href="<?= e(url($page_key, array_filter(['jour' => $v, 'centre' => $centreId ?: null]))) ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
    <noscript><button class="btn btn-sm btn-green">Filtrer</button></noscript>
</form>
