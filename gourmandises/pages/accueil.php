<?php $u = current_user(); $cats = categories(); ?>
<section class="hero">
    <div class="hero-txt">
        <p class="hello">Bonjour <?= e($u['prenom']) ?> 👋</p>
        <h1 class="script"><span class="g">Gourmandises</span><br><span class="n">en</span> <span class="y">ligne</span></h1>
        <p class="lead">Les pains et viennoiseries des stagiaires Boulangerie du CFPA de Saint-André, livrés à <strong><?= e($u['centre_nom']) ?></strong> (<?= e($u['point_relais']) ?>).</p>
        <div class="infos-chips">
            <span><?= icon('clock') ?> <?= e(cutoff_text()) ?></span>
            <?php if ($prochaine): ?><span><?= icon('truck') ?> Prochain retrait : <?= e($dates[$prochaine]) ?></span><?php endif; ?>
        </div>
        <div class="quota-chips">
            <?php foreach ($cats as $c): if ($c['quota'] > 0): ?>
                <span><?= icon($c['slug'] === 'pains' ? 'bread' : 'croissant') ?> <?= (int) $c['quota'] ?> <?= e(mb_strtolower($c['nom'])) ?> max / commande</span>
            <?php endif; endforeach; ?>
        </div>
    </div>
    <div class="hero-img">
        <img src="<?= e(asset('assets/img/bandeau-viennoiseries.jpg')) ?>" alt="Croissant, brioche, pain au chocolat et chausson aux pommes" width="1055" height="500">
    </div>
</section>

<section class="section" id="catalogue">
    <div class="tabs" role="tablist" aria-label="Filtrer par catégorie">
        <a role="tab" href="<?= e(url('accueil', ['cat' => 'tous'])) ?>#catalogue" data-filter="tous" aria-selected="<?= $filtre === 'tous' ? 'true' : 'false' ?>">Tous</a>
        <?php foreach ($cats as $c): ?>
            <a role="tab" href="<?= e(url('accueil', ['cat' => $c['slug']])) ?>#catalogue" data-filter="<?= e($c['slug']) ?>" aria-selected="<?= $filtre === $c['slug'] ? 'true' : 'false' ?>"><?= e($c['nom']) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="grid" data-grid>
        <?php foreach ($produits as $p):
            if ($filtre !== 'tous' && $p['categorie_slug'] !== $filtre) { echo str_replace('<article class="card', '<article hidden class="card', product_card($p, $prochaine)); continue; }
            echo product_card($p, $prochaine);
        endforeach; ?>
    </div>
    <?php if (!$prochaine): ?>
        <div class="flash flash-info">Aucune date de retrait n'est ouverte à la commande pour le moment.</div>
    <?php endif; ?>
</section>
