<section class="banner" style="--bg:url('<?= e(asset($cat['image'])) ?>')">
    <div>
        <h1 class="script"><span class="g"><?= e($cat['nom']) ?></span> <span class="n">en</span> <span class="y">ligne</span></h1>
        <p><?= e($cat['description']) ?></p>
    </div>
</section>
<section class="section">
    <?php if (!$produits): ?>
        <p class="empty">Aucun produit disponible pour le moment.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($produits as $p) echo product_card($p); ?>
        </div>
    <?php endif; ?>
</section>
