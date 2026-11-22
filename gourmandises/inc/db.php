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
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('db.host'), (int) cfg('db.port', 3306), cfg('db.name'));
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.password'), $options);
    } else {
        $file = cfg('db.sqlite', APP_ROOT . '/data/gourmandises.sqlite');
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $file, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    db_install($pdo, $driver);
    return $pdo;
}

function db_install(PDO $pdo, string $driver): void
{
    try {
        $pdo->query('SELECT 1 FROM settings LIMIT 1');
        return; // déjà installée
    } catch (PDOException $e) {
    }

    $my   = $driver === 'mysql';
    $pk   = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk   = $my ? 'INT UNSIGNED' : 'INTEGER';
    $str  = $my ? 'VARCHAR(190)' : 'TEXT';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $sql = [
        "CREATE TABLE centres (
            id $pk,
            nom $str NOT NULL UNIQUE,
            point_relais $str NOT NULL DEFAULT '',
            actif INT NOT NULL DEFAULT 1,
            ordre INT NOT NULL DEFAULT 0
        )$tail",
        "CREATE TABLE users (
            id $pk,
            email $str NOT NULL UNIQUE,
            nom $str NOT NULL DEFAULT '',
            prenom $str NOT NULL DEFAULT '',
            fonction $str NOT NULL DEFAULT '',
            telephone $str NOT NULL DEFAULT '',
            centre_id $fk NULL,
            role $str NOT NULL DEFAULT 'client',
            consent_at $str NULL,
            created_at $str NOT NULL,
            last_login $str NULL,
            FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE SET NULL
        )$tail",
        "CREATE TABLE otp_codes (
            id $pk,
            email $str NOT NULL,
            code_hash $str NOT NULL,
            expires_at $str NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            used INT NOT NULL DEFAULT 0,
            ip $str NOT NULL DEFAULT '',
            created_at $str NOT NULL
        )$tail",
        "CREATE TABLE categories (
            id $pk,
            slug $str NOT NULL UNIQUE,
            nom $str NOT NULL,
            accroche $str NOT NULL DEFAULT '',
            description TEXT,
            image $str NOT NULL DEFAULT '',
            quota INT NOT NULL DEFAULT 0,
            ordre INT NOT NULL DEFAULT 0
        )$tail",
        "CREATE TABLE produits (
            id $pk,
            categorie_id $fk NOT NULL,
            nom $str NOT NULL,
            description TEXT,
            prix_cents INT NOT NULL DEFAULT 0,
            image $str NOT NULL DEFAULT '',
            allergenes $str NOT NULL DEFAULT '',
            conservation $str NOT NULL DEFAULT '',
            stock_jour INT NULL,
            disponible INT NOT NULL DEFAULT 1,
            actif INT NOT NULL DEFAULT 1,
            ordre INT NOT NULL DEFAULT 0,
            FOREIGN KEY (categorie_id) REFERENCES categories(id)
        )$tail",
        "CREATE TABLE commandes (
            id $pk,
            reference $str NOT NULL UNIQUE,
            user_id $fk NULL,
            centre_id $fk NULL,
            centre_nom $str NOT NULL DEFAULT '',
            point_relais $str NOT NULL DEFAULT '',
            nom $str NOT NULL DEFAULT '',
            prenom $str NOT NULL DEFAULT '',
            email $str NOT NULL DEFAULT '',
            telephone $str NOT NULL DEFAULT '',
            date_retrait $str NOT NULL,
            creneau $str NOT NULL DEFAULT '',
            total_cents INT NOT NULL DEFAULT 0,
            statut $str NOT NULL DEFAULT 'en_attente',
            code_retrait $str NOT NULL DEFAULT '',
            stripe_session $str NULL,
            stripe_payment $str NULL,
            paid_at $str NULL,
            remise_at $str NULL,
            remise_par $str NOT NULL DEFAULT '',
            created_at $str NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE SET NULL
        )$tail",
        "CREATE TABLE commande_lignes (
            id $pk,
            commande_id $fk NOT NULL,
            produit_id $fk NULL,
            categorie_id $fk NULL,
            nom $str NOT NULL,
            prix_cents INT NOT NULL,
            quantite INT NOT NULL,
            FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE
        )$tail",
        "CREATE TABLE settings (
            k $str NOT NULL PRIMARY KEY,
            v TEXT
        )$tail",
        'CREATE INDEX idx_cmd_date ON commandes (date_retrait, statut)',
        'CREATE INDEX idx_cmd_user ON commandes (user_id)',
        'CREATE INDEX idx_otp_email ON otp_codes (email)',
    ];
    foreach ($sql as $q) {
        $pdo->exec($q);
    }
    db_seed($pdo);
}

/** Données initiales : centres AFPAR (§5.3), catalogue issu des visuels, quotas (§5.2). */
function db_seed(PDO $pdo): void
{
    $centres = [
        ['Saint-André (CFPA)', 'Accueil du centre'],
        ['Saint-Paul', 'Accueil du centre'],
        ['Saint-Pierre', 'Accueil du centre'],
        ['Saint-François', 'Accueil du centre'],
        ['La Jamaïque', 'Accueil du centre'],
        ['Direction Générale', 'Secrétariat de direction'],
    ];
    $st = $pdo->prepare('INSERT INTO centres (nom, point_relais, actif, ordre) VALUES (?,?,1,?)');
    foreach ($centres as $i => $c) {
        $st->execute([$c[0], $c[1], $i + 1]);
    }

    $cats = [
        ['viennoiseries', 'Viennoiseries', 'Viennoiseries en ligne', 'Croissants pur beurre, pains au chocolat, brioches et chaussons, préparés par les stagiaires de la filière Boulangerie.', 'assets/img/bandeau-viennoiseries.jpg', (int) cfg('quota_viennoiseries', 10), 1],
        ['pains', 'Pains', 'Pains en ligne', 'Baguettes, pains de campagne, complets et aux graines, cuits au CFPA de Saint-André.', 'assets/img/bandeau-pains.jpg', (int) cfg('quota_pains', 3), 2],
    ];
    $st = $pdo->prepare('INSERT INTO categories (slug, nom, accroche, description, image, quota, ordre) VALUES (?,?,?,?,?,?,?)');
    $ids = [];
    foreach ($cats as $c) {
        $st->execute($c);
        $ids[$c[0]] = (int) $pdo->lastInsertId();
    }

    // Prix indicatifs : à remplacer par le catalogue du formateur Boulangerie.
    $produits = [
        ['viennoiseries', 'Croissant', 'Croissant pur beurre, feuilleté et fondant.', 100, 'croissant', 'Gluten, lait, œuf', 'À consommer dans la journée'],
        ['viennoiseries', 'Pain au chocolat', 'Pâte feuilletée pur beurre et deux barres de chocolat.', 110, 'pain-au-chocolat', 'Gluten, lait, œuf, soja', 'À consommer dans la journée'],
        ['viennoiseries', 'Brioche', 'Brioche moelleuse au beurre, parsemée de sucre perlé.', 200, 'brioche', 'Gluten, lait, œuf', '2 jours dans un sac fermé'],
        ['viennoiseries', 'Chausson aux pommes', 'Feuilletage doré garni de compote de pommes.', 140, 'chausson-aux-pommes', 'Gluten, lait, œuf', 'À consommer dans la journée'],
        ['pains', 'Baguette', 'Baguette croustillante et dorée, mie alvéolée.', 90, 'baguette', 'Gluten', 'À consommer dans la journée'],
        ['pains', 'Baguette tradition', 'Farine de tradition française, fermentation lente.', 120, 'baguette-tradition', 'Gluten', 'À consommer dans la journée'],
        ['pains', 'Pain de campagne', 'Gros pain rond au levain, saveur rustique.', 300, 'pain-de-campagne', 'Gluten', '3 à 4 jours dans un torchon'],
        ['pains', 'Pain complet', 'Farine complète riche en fibres.', 250, 'pain-complet', 'Gluten', '2 à 3 jours'],
        ['pains', 'Pain aux graines', 'Tournesol, lin, sésame et courge.', 280, 'pain-aux-graines', 'Gluten, sésame', '2 à 3 jours'],
    ];
    $st = $pdo->prepare('INSERT INTO produits (categorie_id, nom, description, prix_cents, image, allergenes, conservation, stock_jour, disponible, actif, ordre) VALUES (?,?,?,?,?,?,?,NULL,1,1,?)');
    foreach ($produits as $i => $p) {
        $st->execute([$ids[$p[0]], $p[1], $p[2], $p[3], 'assets/img/produits/' . $p[4] . '.jpg', $p[5], $p[6], $i + 1]);
    }

    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute(['installed_at', date('c')]);
}

function setting(string $k, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(string $k, string $v): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
}
