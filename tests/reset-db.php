<?php

declare(strict_types=1);

/** Vide la base de TEST indiquée par AFPAREA_DB_* (toutes les tables et les fichiers déposés). Ligne de commande uniquement. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../site/app/bootstrap.php';

if (!str_contains((string) getenv('AFPAREA_DB_NAME'), 'test')) {
    fwrite(STDERR, "Refus : AFPAREA_DB_NAME doit désigner une base de test.\n");
    exit(1);
}
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (col('SHOW TABLES') as $table) {
    db()->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');
