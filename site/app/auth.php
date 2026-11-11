<?php

declare(strict_types=1);

/** Comptes, sessions, contrôle d'accès. */

const SESSION_DAYS = 7;
const SESSION_COOKIE = 'afparea_sid';
const MAX_LOGIN_ATTEMPTS = 8;
const LOGIN_WINDOW_SECONDS = 900;

function check_password_policy(mixed $password): void
{
    if (!is_string($password) || text_len($password) < 10) {
        throw new HttpError(400, 'Le mot de passe doit contenir au moins 10 caractères.');
    }
    if (strlen($password) > 200) {
        throw new HttpError(400, 'Mot de passe trop long.');
    }
}

/** Mot de passe provisoire lisible (sans caractères ambigus). */
function generate_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function session_cookie(string $value, int $expires): void
{
    setcookie(SESSION_COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https(),
    ]);
}

function create_session(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + SESSION_DAYS * 86400;
    q('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)', [hash('sha256', $token), $userId, now_iso($expires)]);
    session_cookie($token, $expires);
    // Ménage occasionnel des sessions et tentatives expirées.
    if (random_int(1, 20) === 1) {
        q('DELETE FROM sessions WHERE expires_at < ?', [now_iso()]);
        q('DELETE FROM login_attempts WHERE until_ts < ?', [time()]);
    }
}

function destroy_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (is_string($token) && $token !== '') {
        q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    }
    session_cookie('', time() - 3600);
}

function destroy_user_sessions(int $userId): void
{
    q('DELETE FROM sessions WHERE user_id = ?', [$userId]);
}

function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'], 'email' => $user['email'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name'],
        'role' => $user['role'], 'must_change_password' => (bool) $user['must_change_password'], 'active' => (bool) $user['active'],
    ];
}

/** Utilisateur de la session en cours, ou null. */
function current_user(): ?array
{
    static $loaded = false, $user = null;
    if (!$loaded) {
        $loaded = true;
        $token = $_COOKIE[SESSION_COOKIE] ?? '';
        if (is_string($token) && $token !== '') {
            $user = row('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
                WHERE s.token_hash = ? AND s.expires_at > ? AND u.active = 1', [hash('sha256', $token), now_iso()]);
        }
    }
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        throw new HttpError(401, 'Connexion requise.');
    }
    // Tant que le mot de passe provisoire n'est pas changé, seul le compte est accessible.
    if ($user['must_change_password']) {
        throw new HttpError(403, 'Vous devez d’abord changer votre mot de passe.');
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_user();
    if (!in_array($user['role'], $roles, true)) {
        throw new HttpError(403, 'Accès réservé.');
    }
    return $user;
}

/**
 * Protection CSRF : toute requête modifiante doit porter un en-tête personnalisé,
 * qu'un formulaire ou une page tierce ne peut pas ajouter.
 */
function csrf_guard(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'afparea') {
        throw new HttpError(403, 'Requête refusée.');
    }
}

/** Limitation des tentatives de connexion (par adresse IP et e-mail). */
function login_throttle_check(string $key): void
{
    $entry = row('SELECT attempts, until_ts FROM login_attempts WHERE k = ?', [$key]);
    if ($entry && $entry['until_ts'] > time() && $entry['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        throw new HttpError(429, 'Trop de tentatives. Réessayez dans quelques minutes.');
    }
}

function login_throttle_fail(string $key): void
{
    $entry = row('SELECT until_ts FROM login_attempts WHERE k = ?', [$key]);
    if (!$entry || $entry['until_ts'] < time()) {
        q('REPLACE INTO login_attempts (k, attempts, until_ts) VALUES (?, 1, ?)', [$key, time() + LOGIN_WINDOW_SECONDS]);
    } else {
        q('UPDATE login_attempts SET attempts = attempts + 1 WHERE k = ?', [$key]);
    }
}

function login_throttle_clear(string $key): void
{
    q('DELETE FROM login_attempts WHERE k = ?', [$key]);
}
