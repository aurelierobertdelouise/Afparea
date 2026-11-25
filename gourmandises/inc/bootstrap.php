<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    $configFile = APP_ROOT . '/config.sample.php';
}
$GLOBALS['CONFIG'] = require $configFile;

/** Lecture d'une valeur de configuration (clé « a.b » acceptée). */
function cfg(string $key, $default = null)
{
    $value = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

date_default_timezone_set((string) cfg('timezone', 'Europe/Paris'));
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('GOURMANDISES');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim((string) cfg('base_url', ''), '/') . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/cart.php';
require __DIR__ . '/orders.php';
require __DIR__ . '/stripe.php';

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'; base-uri 'self'");
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
