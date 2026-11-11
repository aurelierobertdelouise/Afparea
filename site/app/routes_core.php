<?php

declare(strict_types=1);

/** Connexion, comptes, titres professionnels. */

const ROLES = ['admin', 'formateur', 'stagiaire'];

function route_login(): never
{
    $body = input();
    $email = mb_strtolower(str_in($body['email'] ?? '', 190), 'UTF-8');
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $email);
    login_throttle_check($key);
    $user = row('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user || !$user['active'] || !password_verify($password, $user['password_hash'])) {
        login_throttle_fail($key);
        throw new HttpError(401, 'Adresse e-mail ou mot de passe incorrect.');
    }
    login_throttle_clear($key);
    create_session((int) $user['id']);
    json_out(['user' => public_user($user)]);
}

function route_logout(): never
{
    destroy_session();
    json_out(['ok' => true]);
}

function route_me(): never
{
    $user = current_user();
    json_out(['user' => $user ? public_user($user) : null]);
}

function route_password(): never
{
    $user = current_user() ?? throw new HttpError(401, 'Connexion requise.');
    $body = input();
    $current = is_string($body['current'] ?? null) ? $body['current'] : '';
    if (!password_verify($current, $user['password_hash'])) {
        throw new HttpError(400, 'Mot de passe actuel incorrect.');
    }
    check_password_policy($body['next'] ?? null);
    if ($body['next'] === $current) {
        throw new HttpError(400, 'Choisissez un mot de passe différent de l’actuel.');
    }
    q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($body['next'], PASSWORD_DEFAULT), $user['id']]);
    destroy_user_sessions((int) $user['id']);
    create_session((int) $user['id']);
    json_out(['ok' => true]);
}

// ---------- Utilisateurs (administration) ----------

function route_users_list(): never
{
    require_role('admin');
    json_out(['users' => array_map('public_user', rows('SELECT * FROM users ORDER BY last_name, first_name, email'))]);
}

function create_account(string $email, string $firstName, string $lastName, string $role): array
{
    $password = generate_password();
    $id = insert('INSERT INTO users (email, password_hash, first_name, last_name, role, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)',
        [$email, password_hash($password, PASSWORD_DEFAULT), $firstName, $lastName, $role, now_iso()]);
    return ['id' => $id, 'password' => $password];
}

function route_users_create(): never
{
    require_role('admin');
    $body = input();
    $email = mb_strtolower(str_in($body['email'] ?? '', 190, true, 'E-mail'), 'UTF-8');
    if (!preg_match(EMAIL_RE, $email)) {
        throw new HttpError(400, 'Adresse e-mail invalide.');
    }
    if (!in_array($body['role'] ?? null, ROLES, true)) {
        throw new HttpError(400, 'Rôle invalide.');
    }
    if (val('SELECT 1 FROM users WHERE email = ?', [$email])) {
        throw new HttpError(409, 'Un compte existe déjà avec cette adresse.');
    }
    $created = create_account($email, str_in($body['first_name'] ?? '', 100), str_in($body['last_name'] ?? '', 100), $body['role']);
    json_out(['user' => public_user(row('SELECT * FROM users WHERE id = ?', [$created['id']])), 'password' => $created['password']], 201);
}

function route_users_update(array $p): never
{
    $me = require_role('admin');
    $user = row('SELECT * FROM users WHERE id = ?', [$p[0]]) ?? throw new HttpError(404, 'Utilisateur introuvable.');
    $body = input();
    $next = $user;
    if (array_key_exists('first_name', $body)) {
        $next['first_name'] = str_in($body['first_name'], 100);
    }
    if (array_key_exists('last_name', $body)) {
        $next['last_name'] = str_in($body['last_name'], 100);
    }
    if (array_key_exists('role', $body)) {
        if (!in_array($body['role'], ROLES, true)) {
            throw new HttpError(400, 'Rôle invalide.');
        }
        $next['role'] = $body['role'];
    }
    if (array_key_exists('active', $body)) {
        $next['active'] = bool_in($body['active']) ? 1 : 0;
    }
    if ((int) $user['id'] === (int) $me['id'] && ($next['role'] !== 'admin' || !$next['active'])) {
        throw new HttpError(400, 'Vous ne pouvez pas retirer vos propres droits d’administration.');
    }
    q('UPDATE users SET first_name = ?, last_name = ?, role = ?, active = ? WHERE id = ?',
        [$next['first_name'], $next['last_name'], $next['role'], (int) $next['active'], $user['id']]);
    if (!$next['active']) {
        destroy_user_sessions((int) $user['id']);
    }
    json_out(['user' => public_user($next)]);
}

/** Réinitialisation : par l'admin, ou par un formateur pour un stagiaire de l'un de ses parcours. */
function route_users_reset(array $p): never
{
    $me = require_user();
    $user = row('SELECT * FROM users WHERE id = ?', [$p[0]]) ?? throw new HttpError(404, 'Utilisateur introuvable.');
    if ($me['role'] !== 'admin') {
        $shared = $me['role'] === 'formateur' && $user['role'] === 'stagiaire' && val(
            "SELECT 1 FROM enrollments mine JOIN enrollments theirs ON theirs.course_id = mine.course_id
             WHERE mine.user_id = ? AND mine.role = 'formateur' AND theirs.user_id = ? LIMIT 1", [$me['id'], $user['id']]);
        if (!$shared) {
            throw new HttpError(403, 'Accès réservé.');
        }
    }
    $password = generate_password();
    q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    destroy_user_sessions((int) $user['id']);
    json_out(['password' => $password]);
}

// ---------- Titres professionnels ----------

function route_titres_list(): never
{
    require_user();
    json_out(['titres' => rows('SELECT t.*, (SELECT COUNT(*) FROM courses c WHERE c.titre_id = t.id) AS course_count FROM titres t ORDER BY t.name')]);
}

function titre_fields(): array
{
    $body = input();
    return [
        str_in($body['code'] ?? '', 40),
        str_in($body['name'] ?? '', 200, true, 'Intitulé'),
        str_in($body['level'] ?? '', 40),
        str_in($body['description'] ?? '', 2000),
    ];
}

function route_titres_create(): never
{
    require_role('admin', 'formateur');
    json_out(['id' => insert('INSERT INTO titres (code, name, level, description) VALUES (?, ?, ?, ?)', titre_fields())], 201);
}

function route_titres_update(array $p): never
{
    require_role('admin', 'formateur');
    if (!val('SELECT 1 FROM titres WHERE id = ?', [$p[0]])) {
        throw new HttpError(404, 'Titre introuvable.');
    }
    q('UPDATE titres SET code = ?, name = ?, level = ?, description = ? WHERE id = ?', [...titre_fields(), $p[0]]);
    json_out(['ok' => true]);
}

function route_titres_delete(array $p): never
{
    require_role('admin');
    q('DELETE FROM titres WHERE id = ?', [$p[0]]);
    json_out(['ok' => true]);
}
