<?php
declare(strict_types=1);

/*
 * Vérification de l'installation sur l'hébergement.
 * Ne fonctionne que si 'install_check' => true dans config.php.
 * À DÉSACTIVER (ou supprimer ce fichier) dès que tout est vert.
 */

require __DIR__ . '/inc/bootstrap.php';

if (!cfg('install_check', false)) {
    http_response_code(404);
    exit('Not found');
}

$checks = [];
$add = function (string $label, bool $ok, string $detail = '', bool $blocking = true) use (&$checks) {
    $checks[] = [$label, $ok ? 'ok' : ($blocking ? 'ko' : 'warn'), $detail];
};

$add('Version de PHP ≥ 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), 'Version actuelle : ' . PHP_VERSION . ' (à changer dans le panel LWS si besoin)');
foreach (['pdo', 'mbstring', 'curl', 'json', 'openssl'] as $ext) {
    $add("Extension PHP « $ext »", extension_loaded($ext));
}
$driver = cfg('db.driver');
$add('Extension PDO ' . $driver, extension_loaded($driver === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite'));
$add('Fichier config.php présent', is_file(__DIR__ . '/config.php'), 'Copier config.sample.php en config.php');
$add('Dossier data/ inscriptible', is_writable(__DIR__ . '/data'), 'Droits 755 ou 775 sur data/');
$add('Dossier assets/img/uploads/ inscriptible', is_writable(__DIR__ . '/assets/img/uploads'), 'Droits 755 ou 775');

try {
    $n = (int) db()->query('SELECT COUNT(*) FROM produits')->fetchColumn();
    $add('Connexion à la base de données (' . $driver . ')', true, "$n produit(s) en base — tables créées");
} catch (Throwable $e) {
    $add('Connexion à la base de données (' . $driver . ')', false, $e->getMessage());
}

$appUrl = (string) cfg('app_url');
$add('app_url en https', str_starts_with($appUrl, 'https://'), $appUrl ?: '(vide)');
$add('Administrateurs définis', (bool) array_filter((array) cfg('admin_emails'), fn($m) => $m !== 'prenom.nom@afpar.com'), 'Renseigner admin_emails');
$add('otp_debug désactivé', !cfg('otp_debug'), 'Doit être false en production');
$add('Adresse DPO renseignée', (string) cfg('dpo_email') !== '');

$stripe = stripe_enabled();
$add('Clé Stripe', $stripe, $stripe ? (str_starts_with((string) cfg('stripe_secret_key'), 'sk_live_') ? 'Mode PRODUCTION (sk_live)' : 'Mode TEST (sk_test)') : 'Absente : mode simulation', false);
if ($stripe) {
    try {
        stripe_request('GET', 'balance');
        $add('Connexion à l\'API Stripe', true, 'Clé valide');
    } catch (Throwable $e) {
        $add('Connexion à l\'API Stripe', false, $e->getMessage());
    }
    $add('Secret du webhook Stripe', (string) cfg('stripe_webhook_secret') !== '', 'Renseigner stripe_webhook_secret (whsec_…)');
}

$mailResult = null;
if (isset($_POST['test_mail']) && filter_var($_POST['test_mail'], FILTER_VALIDATE_EMAIL)) {
    $mailResult = send_mail($_POST['test_mail'], 'Test Gourmandises AFPAR', "Ceci est un e-mail de test.\nSi vous le recevez (hors courrier indésirable), l'envoi des codes de connexion fonctionne.");
}
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Vérification de l'installation</title>
<style>
body{font-family:system-ui,sans-serif;background:#24284A;color:#2F2E4E;margin:0;padding:24px 16px}
main{max-width:760px;margin:auto;background:#fff;border-radius:16px;padding:24px}
h1{margin-top:0}li{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #eee;list-style:none}ul{padding:0}
b{width:24px;flex-shrink:0}.ok{color:#2F9A6E}.ko{color:#D9534F}.warn{color:#C68A00}small{color:#6B6A86;display:block}
input,button{font:inherit;padding:8px 12px;border-radius:8px;border:1px solid #ccc}button{background:#4EB688;color:#fff;border:0}
.alert{background:#FDECEC;padding:12px;border-radius:8px}
</style></head><body><main>
<h1>Vérification de l'installation</h1>
<ul>
<?php foreach ($checks as [$label, $st, $detail]): ?>
<li class="<?= $st ?>"><b><?= $st === 'ok' ? '✔' : ($st === 'ko' ? '✘' : '!') ?></b><span><?= e($label) ?><?php if ($detail): ?><small><?= e($detail) ?></small><?php endif; ?></span></li>
<?php endforeach; ?>
</ul>
<h2>Test d'envoi d'e-mail</h2>
<?php if ($mailResult !== null): ?><p class="<?= $mailResult ? 'ok' : 'ko' ?>"><?= $mailResult ? 'E-mail transmis au serveur : vérifiez la réception (et les indésirables).' : 'Échec de l\'envoi : vérifiez mail_from.' ?></p><?php endif; ?>
<form method="post"><input type="email" name="test_mail" placeholder="prenom.nom@afpar.com" required> <button>Envoyer un test</button></form>
<p class="alert">Une fois tout vert, mettez <code>'install_check' => false</code> dans config.php (ou supprimez verif.php).</p>
</main></body></html>
