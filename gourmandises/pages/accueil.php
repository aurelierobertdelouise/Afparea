<section class="hero">
    <div class="hero-txt">
        <h1 class="script"><span class="g">Gourmandises</span><br><span class="n">en</span> <span class="y">ligne</span></h1>
        <p class="lead">Pains croustillants et viennoiseries pur beurre, préparés par les apprenti·e·s de l'AFPAR.</p>
        <p class="hero-sub"><b class="script-w">Faites-vous</b> <b class="script y">livrer</b></p>
        <p class="muted-light">Commandez sur notre application, choisissez la livraison ou le Click &amp; Collect.</p>
        <a class="btn btn-cta" href="#categories"><?= icon('cart') ?> Commander maintenant</a>
    </div>
    <div class="hero-img">
        <img src="<?= e(asset('assets/img/bandeau-viennoiseries.jpg')) ?>" alt="Croissant, brioche, pain au chocolat et chausson aux pommes" width="1055" height="500">
    </div>
</section>

<section id="categories" class="section">
    <h2 class="h-sec">Nos rayons</h2>
    <div class="cats">
        <?php foreach ($categories as $c): ?>
            <a class="cat" href="<?= e(url('categorie', ['c' => $c['slug']])) ?>">
                <img src="<?= e(asset($c['image'] ?: $c['affiche'])) ?>" alt="Affiche <?= e($c['accroche']) ?>" loading="lazy">
                <span class="cat-lbl"><span><?= e($c['nom']) ?></span> <b>Voir les produits →</b></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <h2 class="h-sec">Toutes nos gourmandises</h2>
    <div class="grid">
        <?php foreach ($populaires as $p) echo product_card($p); ?>
    </div>
</section>
