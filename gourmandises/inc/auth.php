<?php
declare(strict_types=1);

/*
 * Authentification par code PIN envoyé sur la messagerie professionnelle (CDC §6.1 et §7.2).
 * - Seuls les domaines autorisés (@afpar.com) peuvent demander un code.
 * - Code à usage unique, haché en base, durée de vie limitée, essais limités.
 * Rôles : client | referent (centre) | admin (Admin / Boulangerie).
 */

const ROLES = [
    'client'   => 'Salarié (client)',
    'referent' => 'Référent centre',
    'admin'    => 'Admin / Boulangerie',
];

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function email_domain_allowed(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = substr(strrchr($email, '@'), 1);
    return in_array($domain, array_map('strtolower', (array) cfg('allowed_domains', ['afpar.com'])), true);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Crée et envoie un code. Retourne null si OK, sinon un message d'erreur. */
function otp_send(string $email): ?string
{
    $email = normalize_email($email);
    if (!email_domain_allowed($email)) {
        return 'Accès réservé au personnel AFPAR : utilisez votre adresse professionnelle @' . implode(' / @', (array) cfg('allowed_domains')) . '.';
    }
    $pdo = db();
    $since = date('Y-m-d H:i:s', time() - 3600);
    $st = $pdo->prepare('SELECT COUNT(*) FROM otp_codes WHERE (email = ? OR ip = ?) AND created_at > ?');
    $st->execute([$email, client_ip(), $since]);
    if ((int) $st->fetchColumn() >= (int) cfg('otp_max_per_hour', 5)) {
        return 'Trop de demandes de code. Réessayez dans une heure.';
    }

    $len = max(4, (int) cfg('otp_length', 6));
    $code = str_pad((string) random_int(0, 10 ** $len - 1), $len, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE email = ? AND used = 0')->execute([$email]);
    $pdo->prepare('INSERT INTO otp_codes (email, code_hash, expires_at, attempts, used, ip, created_at) VALUES (?,?,?,0,0,?,?)')
        ->execute([$email, password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 60 * (int) cfg('otp_ttl_minutes', 10)), client_ip(), date('Y-m-d H:i:s')]);

    $ttl = (int) cfg('otp_ttl_minutes', 10);
    $body = "Bonjour,\n\nVotre code de connexion à " . cfg('app_name') . " est :\n\n    $code\n\n"
        . "Il est valable $ttl minutes et ne peut être utilisé qu'une seule fois.\n"
        . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\n— " . cfg('app_name');
    $sent = send_mail($email, 'Votre code de connexion : ' . $code, $body);

    if (cfg('otp_debug')) {
        $_SESSION['otp_debug_code'] = $code;
    } elseif (!$sent) {
        return 'L\'e-mail n\'a pas pu être envoyé. Contactez l\'administrateur.';
    }
    return null;
}

/** Vérifie le code ; connecte l'utilisateur si valide. */
function otp_verify(string $email, string $code): ?string
{
    $email = normalize_email($email);
    $code = preg_replace('/\D+/', '', $code);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM otp_codes WHERE email = ? AND used = 0 ORDER BY id DESC LIMIT 1');
    $st->execute([$email]);
    $otp = $st->fetch();
    if (!$otp || strtotime($otp['expires_at']) < time()) {
        return 'Code expiré. Demandez un nouveau code.';
    }
    if ((int) $otp['attempts'] >= (int) cfg('otp_max_attempts', 5)) {
        $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$otp['id']]);
        return 'Trop d\'essais. Demandez un nouveau code.';
    }
    if (!password_verify($code, $otp['code_hash'])) {
        $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
        return 'Code incorrect.';
    }
    $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$otp['id']]);

    // Création du compte à la 1re connexion
    $user = user_by_email($email);
    if (!$user) {
        $role = in_array($email, array_map('normalize_email', (array) cfg('admin_emails', [])), true) ? 'admin' : 'client';
        $pdo->prepare('INSERT INTO users (email, role, created_at) VALUES (?,?,?)')->execute([$email, $role, date('Y-m-d H:i:s')]);
        $user = user_by_email($email);
    }
    $pdo->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $user['id']]);

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    unset($_SESSION['otp_email'], $_SESSION['otp_debug_code']);
    current_user(true);
    return null;
}

function user_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

/** Utilisateur connecté (relu en base à chaque requête : un changement de rôle s'applique immédiatement). */
function current_user(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $cache = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT u.*, c.nom AS centre_nom, c.point_relais FROM users u LEFT JOIN centres c ON c.id = u.centre_id WHERE u.id = ?');
        $st->execute([$_SESSION['uid']]);
        $cache = $st->fetch() ?: null;
        if (!$cache) {
            unset($_SESSION['uid']);
        }
    }
    return $cache;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function profile_complete(array $u): bool
{
    return $u['consent_at'] && $u['nom'] !== '' && $u['prenom'] !== '' && $u['centre_id'];
}

/** Exige une connexion + profil complet + consentement RGPD. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect(url('connexion'));
    }
    if (!profile_complete($u)) {
        redirect(url('bienvenue'));
    }
    return $u;
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u && in_array($u['role'], $roles, true);
}

function is_admin(): bool
{
    return has_role('admin');
}

function is_staff(): bool
{
    return has_role('admin', 'referent');
}

/** Back-office : admin (tous centres) ou référent (son centre uniquement). */
function require_staff(): array
{
    $u = require_login();
    if (!in_array($u['role'], ['admin', 'referent'], true)) {
        http_response_code(403);
        render('erreur', ['title' => 'Accès refusé', 'message' => 'Cet espace est réservé aux gestionnaires.']);
        exit;
    }
    return $u;
}

function require_admin_role(): array
{
    $u = require_staff();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        render('erreur', ['title' => 'Accès refusé', 'message' => 'Cette fonction est réservée aux administrateurs.']);
        exit;
    }
    return $u;
}

/** Filtre SQL de cloisonnement par centre pour les référents (§7.2). */
function centre_scope(array $u, string $col = 'c.centre_id'): array
{
    if ($u['role'] === 'admin') {
        return ['', []];
    }
    return [" AND $col = ?", [(int) $u['centre_id']]];
}
