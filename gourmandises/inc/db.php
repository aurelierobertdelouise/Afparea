<?php
declare(strict_types=1);

/** Connexion PDO unique (SQLite ou MySQL), schéma créé automatiquement. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = cfg('db.driver', 'sqlite');
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($driver === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            cfg('db.host'), (int) cfg('db.port', 3306), cfg('db.name')
        );
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.password'), $options);
    } else {
        $file = cfg('db.sqlite', APP_ROOT . '/data/gourmandises.sqlite');
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $file, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }

    db_install($pdo, $driver);
    return $pdo;
}

function db_install(PDO $pdo, string $driver): void
{
    $mysql = $driver === 'mysql';
    $pk    = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk    = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $text  = $mysql ? 'TEXT' : 'TEXT';
    $str   = $mysql ? 'VARCHAR(190)' : 'TEXT';
    $tail  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    // Table déjà présente ? On ne fait rien.
    try {
        $pdo->query('SELECT 1 FROM categories LIMIT 1');
        return;
    } catch (PDOException $e) {
        // pas encore installée
    }

    $pdo->exec("CREATE TABLE categories (
        id $pk,
        slug $str NOT NULL UNIQUE,
        nom $str NOT NULL,
        accroche $str NOT NULL DEFAULT '',
        description $text,
        image $str NOT NULL DEFAULT '',
        affiche $str NOT NULL DEFAULT '',
        ordre INT NOT NULL DEFAULT 0
    )$tail");

    $pdo->exec("CREATE TABLE produits (
        id $pk,
        categorie_id $fk NOT NULL,
        nom $str NOT NULL,
        description $text,
        prix_cents INT NOT NULL DEFAULT 0,
        image $str NOT NULL DEFAULT '',
        allergenes $str NOT NULL DEFAULT '',
        actif INT NOT NULL DEFAULT 1,
        ordre INT NOT NULL DEFAULT 0,
        FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE
    )$tail");

    $pdo->exec("CREATE TABLE commandes (
        id $pk,
        reference $str NOT NULL UNIQUE,
        token $str NOT NULL,
        nom $str NOT NULL,
        telephone $str NOT NULL,
        email $str NOT NULL DEFAULT '',
        mode $str NOT NULL,
        adresse $str NOT NULL DEFAULT '',
        code_postal $str NOT NULL DEFAULT '',
        ville $str NOT NULL DEFAULT '',
        date_souhaitee $str NOT NULL,
        creneau $str NOT NULL,
        commentaire $text,
        sous_total_cents INT NOT NULL DEFAULT 0,
        frais_cents INT NOT NULL DEFAULT 0,
        total_cents INT NOT NULL DEFAULT 0,
        paiement $str NOT NULL DEFAULT 'sur_place',
        statut $str NOT NULL DEFAULT 'nouvelle',
        created_at $str NOT NULL
    )$tail");

    $pdo->exec("CREATE TABLE commande_lignes (
        id $pk,
        commande_id $fk NOT NULL,
        produit_id $fk NULL,
        nom $str NOT NULL,
        prix_cents INT NOT NULL,
        quantite INT NOT NULL,
        FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE
    )$tail");

    db_seed($pdo);
}

/** Données de départ reprises des visuels « Pains en ligne » et « Viennoiseries en ligne ». */
function db_seed(PDO $pdo): void
{
    $cats = [
        ['pains', 'Pains', 'Pains en ligne', 'Baguettes, pains de campagne, complets et aux graines : cuits chaque matin par nos apprenti·e·s boulangers.', 'assets/img/bandeau-pains.jpg', 'assets/img/affiche-pains.jpg', 1],
        ['viennoiseries', 'Viennoiseries', 'Viennoiseries en ligne', 'Croissants pur beurre, pains au chocolat, brioches et chaussons : la gourmandise du matin.', 'assets/img/bandeau-viennoiseries.jpg', 'assets/img/affiche-viennoiseries.jpg', 2],
    ];
    $insCat = $pdo->prepare('INSERT INTO categories (slug, nom, accroche, description, image, affiche, ordre) VALUES (?,?,?,?,?,?,?)');
    $ids = [];
    foreach ($cats as $c) {
        $insCat->execute($c);
        $ids[$c[0]] = (int) $pdo->lastInsertId();
    }

    $produits = [
        ['pains', 'Baguette', 'La baguette croustillante et dorée, mie alvéolée.', 110, 'baguette', 'Gluten'],
        ['pains', 'Baguette tradition', 'Farine de tradition française, fermentation lente, croûte craquante.', 130, 'baguette-tradition', 'Gluten'],
        ['pains', 'Pain de campagne', 'Gros pain rond au levain, saveur rustique et longue conservation.', 380, 'pain-de-campagne', 'Gluten'],
        ['pains', 'Pain complet', 'Farine complète riche en fibres, tranché à la demande.', 290, 'pain-complet', 'Gluten'],
        ['pains', 'Pain aux graines', 'Tournesol, lin, sésame et courge pour un pain gourmand et croquant.', 320, 'pain-aux-graines', 'Gluten, sésame'],
        ['viennoiseries', 'Croissant', 'Croissant pur beurre, feuilleté et fondant.', 120, 'croissant', 'Gluten, lait, œuf'],
        ['viennoiseries', 'Pain au chocolat', 'Pâte feuilletée pur beurre et deux barres de chocolat.', 135, 'pain-au-chocolat', 'Gluten, lait, œuf, soja'],
        ['viennoiseries', 'Brioche', 'Brioche moelleuse au beurre, parsemée de sucre perlé.', 250, 'brioche', 'Gluten, lait, œuf'],
        ['viennoiseries', 'Chausson aux pommes', 'Feuilletage doré garni d\'une compote de pommes maison.', 170, 'chausson-aux-pommes', 'Gluten, lait, œuf'],
    ];
    $ins = $pdo->prepare('INSERT INTO produits (categorie_id, nom, description, prix_cents, image, allergenes, actif, ordre) VALUES (?,?,?,?,?,?,1,?)');
    foreach ($produits as $i => $p) {
        $ins->execute([$ids[$p[0]], $p[1], $p[2], $p[3], 'assets/img/produits/' . $p[4] . '.jpg', $p[5], $i + 1]);
    }
}
