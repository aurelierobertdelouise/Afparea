<?php

declare(strict_types=1);

/** Parcours, découpage, ressources, dépôts, inscriptions. */

const COLORS = ['navy', 'green', 'yellow'];
const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
const INLINE_TYPES = [...IMAGE_TYPES, 'application/pdf', 'video/mp4', 'audio/mpeg'];

function save_file(array $file, array $user, int $courseId, string $kind, ?int $itemId = null, ?int $submissionId = null): int
{
    return insert('INSERT INTO files (stored_name, original_name, mime, size, owner_id, course_id, item_id, submission_id, kind, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [store_upload($file), $file['name'], $file['mime'], $file['size'], $user['id'], $courseId, $itemId, $submissionId, $kind, now_iso()]);
}

// ---------- Parcours ----------

function route_courses_list(): never
{
    $user = require_user();
    $courses = $user['role'] === 'admin'
        ? rows('SELECT * FROM courses ORDER BY created_at DESC, id DESC')
        : rows("SELECT c.* FROM courses c JOIN enrollments e ON e.course_id = c.id
                WHERE e.user_id = ? AND (e.role = 'formateur' OR c.visible = 1) ORDER BY c.created_at DESC, c.id DESC", [$user['id']]);
    json_out(['courses' => array_map(fn ($course) => course_summary($course, course_role($user, (int) $course['id']), (int) $user['id']), $courses)]);
}

function course_fields(array $body, array $current = []): array
{
    $fields = $current;
    if (array_key_exists('title', $body)) {
        $fields['title'] = str_in($body['title'], 200, true, 'Titre');
    }
    if (array_key_exists('summary', $body)) {
        $fields['summary'] = str_in($body['summary'], 4000);
    }
    if (array_key_exists('color', $body)) {
        if (!in_array($body['color'], COLORS, true)) {
            throw new HttpError(400, 'Couleur invalide.');
        }
        $fields['color'] = $body['color'];
    }
    if (array_key_exists('titre_id', $body)) {
        $fields['titre_id'] = $body['titre_id'] ? int_in($body['titre_id']) : null;
        if ($fields['titre_id'] && !val('SELECT 1 FROM titres WHERE id = ?', [$fields['titre_id']])) {
            throw new HttpError(400, 'Titre professionnel introuvable.');
        }
    }
    if (array_key_exists('start_date', $body)) {
        $fields['start_date'] = iso_in($body['start_date'], 'Date de début');
    }
    if (array_key_exists('end_date', $body)) {
        $fields['end_date'] = iso_in($body['end_date'], 'Date de fin');
    }
    if (array_key_exists('visible', $body)) {
        $fields['visible'] = bool_in($body['visible']) ? 1 : 0;
    }
    return $fields;
}

function route_courses_create(): never
{
    $user = require_role('admin', 'formateur');
    $fields = course_fields(input());
    if (empty($fields['title'])) {
        throw new HttpError(400, 'Titre obligatoire.');
    }
    json_out(['id' => tx(fn () => insert_course($user, $fields))], 201);
}

function route_course_get(array $p): never
{
    $user = require_user();
    [$course, $role] = access_course($user, $p[0]);
    json_out(['course' => course_summary($course, $role, (int) $user['id']), 'tree' => course_tree($course, $role, (int) $user['id'])]);
}

function route_course_update(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    $f = course_fields(input(), $course);
    q('UPDATE courses SET title = ?, summary = ?, color = ?, titre_id = ?, start_date = ?, end_date = ?, visible = ? WHERE id = ?',
        [$f['title'], $f['summary'], $f['color'], $f['titre_id'], $f['start_date'], $f['end_date'], (int) $f['visible'], $course['id']]);
    json_out(['ok' => true]);
}

function route_course_delete(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    q('DELETE FROM courses WHERE id = ?', [$course['id']]);
    sweep_orphan_files();
    json_out(['ok' => true]);
}

function route_course_header_set(array $p): never
{
    $user = require_user();
    [$course] = access_course($user, $p[0], true);
    $file = uploads('image')[0] ?? throw new HttpError(400, 'Image manquante.');
    if (!in_array($file['mime'], IMAGE_TYPES, true)) {
        throw new HttpError(400, 'Format d’image non pris en charge (PNG, JPEG, WebP ou GIF).');
    }
    $fileId = tx(function () use ($file, $user, $course) {
        q("DELETE FROM files WHERE course_id = ? AND kind = 'header'", [$course['id']]);
        $id = save_file($file, $user, (int) $course['id'], 'header');
        q('UPDATE courses SET header_file_id = ? WHERE id = ?', [$id, $course['id']]);
        return $id;
    });
    sweep_orphan_files();
    json_out(['header_file_id' => $fileId]);
}

function route_course_header_delete(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    q('UPDATE courses SET header_file_id = NULL WHERE id = ?', [$course['id']]);
    q("DELETE FROM files WHERE course_id = ? AND kind = 'header'", [$course['id']]);
    sweep_orphan_files();
    json_out(['ok' => true]);
}

// ---------- Modules, séquences, séances ----------

function load_node(array $user, int $id): array
{
    $node = row('SELECT * FROM nodes WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Élément introuvable.');
    access_course($user, (int) $node['course_id'], true);
    return $node;
}

function node_fields(array $body, array $current = []): array
{
    $fields = $current;
    if (array_key_exists('title', $body)) {
        $fields['title'] = str_in($body['title'], 200, true, 'Titre');
    }
    if (array_key_exists('description', $body)) {
        $fields['description'] = str_in($body['description'], 10000);
    }
    if (array_key_exists('duration_minutes', $body)) {
        $minutes = $body['duration_minutes'];
        if (!is_numeric($minutes) || $minutes < 0 || $minutes > 100000) {
            throw new HttpError(400, 'Durée invalide.');
        }
        $fields['duration_minutes'] = (int) round((float) $minutes);
    }
    if (array_key_exists('visible', $body)) {
        $fields['visible'] = bool_in($body['visible']) ? 1 : 0;
    }
    if (array_key_exists('available_from', $body)) {
        $fields['available_from'] = iso_in($body['available_from'], 'Date de mise à disposition');
    }
    return $fields;
}

function route_node_create(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    $body = input();
    $parentId = !empty($body['parent_id']) ? int_in($body['parent_id']) : null;
    $expected = CHILD_TYPE['root'];
    if ($parentId) {
        $parent = row('SELECT * FROM nodes WHERE id = ? AND course_id = ?', [$parentId, $course['id']]) ?? throw new HttpError(400, 'Parent introuvable.');
        $expected = CHILD_TYPE[$parent['type']] ?? throw new HttpError(400, 'Une séance ne peut pas contenir de sous-niveau.');
    }
    $fields = node_fields($body);
    if (empty($fields['title'])) {
        throw new HttpError(400, 'Titre obligatoire.');
    }
    $fields['visible'] = ($fields['visible'] ?? 1) !== 0;
    json_out(['id' => insert_node((int) $course['id'], $parentId, $expected, $fields)], 201);
}

function route_node_update(array $p): never
{
    $node = load_node(require_user(), $p[0]);
    $f = node_fields(input(), $node);
    q('UPDATE nodes SET title = ?, description = ?, duration_minutes = ?, visible = ?, available_from = ? WHERE id = ?',
        [$f['title'], $f['description'], (int) $f['duration_minutes'], (int) $f['visible'], $f['available_from'], $node['id']]);
    json_out(['ok' => true]);
}

function route_node_delete(array $p): never
{
    $node = load_node(require_user(), $p[0]);
    q('DELETE FROM nodes WHERE id = ?', [$node['id']]);
    sweep_orphan_files();
    json_out(['ok' => true]);
}

/**
 * Glisser-déposer : le client envoie le conteneur de destination et l'ordre complet de son contenu.
 * kind = 'node' (parent_id = nœud parent ou null) ou 'item' (parent_id = nœud conteneur).
 */
function route_course_reorder(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    $body = input();
    if (!is_array($body['ids'] ?? null)) {
        throw new HttpError(400, 'Ordre invalide.');
    }
    $ids = array_map(fn ($id) => int_in($id), array_values($body['ids']));
    if (count(array_unique($ids)) !== count($ids)) {
        throw new HttpError(400, 'Ordre invalide.');
    }
    $parentId = !empty($body['parent_id']) ? int_in($body['parent_id']) : null;
    $parent = $parentId ? row('SELECT * FROM nodes WHERE id = ? AND course_id = ?', [$parentId, $course['id']]) : null;
    if ($parentId && !$parent) {
        throw new HttpError(400, 'Destination introuvable.');
    }
    $kind = $body['kind'] ?? '';

    tx(function () use ($kind, $ids, $parent, $parentId, $course) {
        if ($kind === 'node') {
            $expected = $parent ? (CHILD_TYPE[$parent['type']] ?? null) : CHILD_TYPE['root'];
            foreach ($ids as $index => $id) {
                // La vérification est séparée de la mise à jour : MySQL ne compte pas une ligne inchangée comme modifiée.
                if (!$expected || !val('SELECT 1 FROM nodes WHERE id = ? AND course_id = ? AND type = ?', [$id, $course['id'], $expected])) {
                    throw new HttpError(400, 'Ce déplacement n’est pas possible à ce niveau.');
                }
                q('UPDATE nodes SET parent_id = ?, position = ? WHERE id = ?', [$parentId, $index, $id]);
            }
        } elseif ($kind === 'item' && $parent) {
            foreach ($ids as $index => $id) {
                if (!val('SELECT 1 FROM items WHERE id = ? AND course_id = ?', [$id, $course['id']])) {
                    throw new HttpError(400, 'Élément introuvable.');
                }
                q('UPDATE items SET node_id = ?, position = ? WHERE id = ?', [$parentId, $index, $id]);
            }
        } else {
            throw new HttpError(400, 'Déplacement invalide.');
        }
    });
    json_out(['ok' => true]);
}

// ---------- Ressources et activités ----------

/** @return array{0: array, 1: array, 2: string} élément, parcours, rôle */
function load_item(array $user, int $id, bool $trainer = false): array
{
    $item = row('SELECT * FROM items WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Élément introuvable.');
    [$course, $role] = access_course($user, (int) $item['course_id'], $trainer);
    if ($role === 'stagiaire' && !item_open_for_students($item)) {
        throw new HttpError(404, 'Élément introuvable ou pas encore disponible.');
    }
    return [$item, $course, $role];
}

function item_fields(string $type, array $body, array $current = []): array
{
    $data = $current;
    if ($type === 'etiquette' || $type === 'page') {
        if (array_key_exists('text', $body)) {
            $data['text'] = str_in($body['text'], 200000);
        }
    } elseif ($type === 'lien' || $type === 'video') {
        if (array_key_exists('url', $body)) {
            $data['url'] = url_in($body['url']);
        }
        if (empty($data['url'])) {
            throw new HttpError(400, 'Adresse web obligatoire.');
        }
    } elseif ($type === 'depot') {
        if (array_key_exists('instructions', $body)) {
            $data['instructions'] = str_in($body['instructions'], 50000);
        }
        if (array_key_exists('due_at', $body)) {
            $data['due_at'] = iso_in($body['due_at'], 'Date limite');
        }
        if (array_key_exists('max_grade', $body)) {
            $max = $body['max_grade'];
            if (!is_numeric($max) || $max <= 0 || $max > 1000) {
                throw new HttpError(400, 'Barème invalide.');
            }
            $data['max_grade'] = (float) $max == (int) $max ? (int) $max : (float) $max;
        }
        $data['max_grade'] ??= 20;
    }
    return $data;
}

function attach_files(array $files, array $user, array $item): void
{
    if (!$files) {
        return;
    }
    if (!in_array($item['type'], ['fichier', 'dossier'], true)) {
        throw new HttpError(400, 'Ce type d’élément n’accepte pas de fichier.');
    }
    if ($item['type'] === 'fichier') {
        if (count($files) > 1) {
            throw new HttpError(400, 'Une ressource « Fichier » ne contient qu’un seul fichier. Utilisez « Dossier ».');
        }
        q('DELETE FROM files WHERE item_id = ?', [$item['id']]);
    }
    foreach ($files as $file) {
        save_file($file, $user, (int) $item['course_id'], 'resource', (int) $item['id']);
    }
}

function route_item_create(array $p): never
{
    $user = require_user();
    [$course] = access_course($user, $p[0], true);
    $body = input();
    $files = uploads('files');
    $type = $body['type'] ?? '';
    if (!in_array($type, ITEM_TYPES, true)) {
        throw new HttpError(400, 'Type d’élément invalide.');
    }
    $nodeId = val('SELECT id FROM nodes WHERE id = ? AND course_id = ?', [int_in($body['node_id'] ?? null), $course['id']])
        ?? throw new HttpError(400, 'Emplacement introuvable.');
    $title = str_in($body['title'] ?? '', 200, $type !== 'etiquette', 'Titre') ?: 'Étiquette';
    if ($type === 'fichier' && !$files) {
        throw new HttpError(400, 'Sélectionnez un fichier.');
    }
    $data = item_fields($type, $body);
    $available = iso_in($body['available_from'] ?? null, 'Date de mise à disposition');
    $id = tx(function () use ($course, $nodeId, $type, $title, $data, $body, $available, $files, $user) {
        $itemId = insert_item((int) $course['id'], (int) $nodeId, $type, $title, $data, bool_in($body['visible'] ?? '1'), $available);
        attach_files($files, $user, ['id' => $itemId, 'type' => $type, 'course_id' => $course['id']]);
        return $itemId;
    });
    json_out(['id' => $id], 201);
}

function route_item_update(array $p): never
{
    $user = require_user();
    [$item] = load_item($user, $p[0], true);
    $body = input();
    $files = uploads('files');
    $title = array_key_exists('title', $body) ? str_in($body['title'], 200, true, 'Titre') : $item['title'];
    $visible = array_key_exists('visible', $body) ? (bool_in($body['visible']) ? 1 : 0) : (int) $item['visible'];
    $available = array_key_exists('available_from', $body) ? iso_in($body['available_from'], 'Date de mise à disposition') : $item['available_from'];
    $data = item_fields($item['type'], $body, item_data($item));
    tx(function () use ($item, $title, $data, $visible, $available, $files, $user) {
        q('UPDATE items SET title = ?, data = ?, visible = ?, available_from = ? WHERE id = ?',
            [$title, json_encode((object) $data, JSON_UNESCAPED_UNICODE), $visible, $available, $item['id']]);
        attach_files($files, $user, $item);
    });
    sweep_orphan_files();
    json_out(['ok' => true]);
}

function route_item_delete(array $p): never
{
    [$item] = load_item(require_user(), $p[0], true);
    q('DELETE FROM items WHERE id = ?', [$item['id']]);
    sweep_orphan_files();
    json_out(['ok' => true]);
}

function submission_view(?array $row): ?array
{
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'], 'comment' => $row['comment'], 'submitted_at' => $row['submitted_at'], 'grade' => $row['grade'],
        'feedback' => $row['feedback'], 'graded_at' => $row['graded_at'],
        'files' => array_map('file_view', rows('SELECT * FROM files WHERE submission_id = ? ORDER BY id', [$row['id']])),
    ];
}

/** Détail d'un élément ; pour un dépôt : ma remise (stagiaire) ou toutes les remises (formateur). */
function route_item_get(array $p): never
{
    $user = require_user();
    [$item, $course, $role] = load_item($user, $p[0]);
    $view = [
        'id' => (int) $item['id'], 'course_id' => (int) $item['course_id'], 'type' => $item['type'], 'title' => $item['title'],
        'data' => (object) item_data($item),
        'files' => array_map('file_view', rows("SELECT * FROM files WHERE item_id = ? AND kind = 'resource' ORDER BY id", [$item['id']])),
        'done' => (bool) val('SELECT 1 FROM completions WHERE item_id = ? AND user_id = ?', [$item['id'], $user['id']]),
    ];
    if ($item['type'] === 'depot') {
        if ($role === 'formateur') {
            $byUser = [];
            foreach (rows('SELECT * FROM submissions WHERE item_id = ?', [$item['id']]) as $submission) {
                $byUser[$submission['user_id']] = $submission;
            }
            $students = rows("SELECT u.id, u.first_name, u.last_name, u.email FROM enrollments e JOIN users u ON u.id = e.user_id
                WHERE e.course_id = ? AND e.role = 'stagiaire' ORDER BY u.last_name, u.first_name", [$course['id']]);
            $view['submissions'] = array_map(fn ($student) => ['student' => $student, 'submission' => submission_view($byUser[$student['id']] ?? null)], $students);
        } else {
            $view['submission'] = submission_view(row('SELECT * FROM submissions WHERE item_id = ? AND user_id = ?', [$item['id'], $user['id']]));
        }
    }
    json_out(['item' => $view, 'role' => $role, 'course' => ['id' => (int) $course['id'], 'title' => $course['title']]]);
}

function route_item_completion(array $p): never
{
    $user = require_user();
    [$item] = load_item($user, $p[0]);
    if (bool_in(input()['done'] ?? false)) {
        q('INSERT IGNORE INTO completions (item_id, user_id, completed_at) VALUES (?, ?, ?)', [$item['id'], $user['id'], now_iso()]);
    } else {
        q('DELETE FROM completions WHERE item_id = ? AND user_id = ?', [$item['id'], $user['id']]);
    }
    json_out(['ok' => true]);
}

// ---------- Dépôts des stagiaires ----------

function route_submission_create(array $p): never
{
    $user = require_user();
    [$item, , $role] = load_item($user, $p[0]);
    $files = uploads('files');
    if ($item['type'] !== 'depot') {
        throw new HttpError(400, 'Cet élément n’est pas un espace de dépôt.');
    }
    if ($role !== 'stagiaire') {
        throw new HttpError(403, 'Seuls les stagiaires déposent une production.');
    }
    $existing = row('SELECT * FROM submissions WHERE item_id = ? AND user_id = ?', [$item['id'], $user['id']]);
    if ($existing && $existing['grade'] !== null) {
        throw new HttpError(400, 'Votre production a déjà été évaluée : elle ne peut plus être modifiée.');
    }
    $kept = $existing ? (int) val('SELECT COUNT(*) FROM files WHERE submission_id = ?', [$existing['id']]) : 0;
    if (!$files && !$kept) {
        throw new HttpError(400, 'Ajoutez au moins un fichier.');
    }
    $comment = str_in(input()['comment'] ?? '', 4000);
    tx(function () use ($existing, $comment, $item, $user, $files) {
        if ($existing) {
            $id = (int) $existing['id'];
            q('UPDATE submissions SET comment = ?, submitted_at = ? WHERE id = ?', [$comment, now_iso(), $id]);
        } else {
            $id = insert("INSERT INTO submissions (item_id, user_id, comment, submitted_at, feedback) VALUES (?, ?, ?, ?, '')",
                [$item['id'], $user['id'], $comment, now_iso()]);
        }
        foreach ($files as $file) {
            save_file($file, $user, (int) $item['course_id'], 'submission', null, $id);
        }
        q('INSERT IGNORE INTO completions (item_id, user_id, completed_at) VALUES (?, ?, ?)', [$item['id'], $user['id'], now_iso()]);
    });
    json_out(['ok' => true]);
}

function route_submission_grade(array $p): never
{
    $user = require_user();
    $submission = row('SELECT s.*, i.course_id, i.data FROM submissions s JOIN items i ON i.id = s.item_id WHERE s.id = ?', [$p[0]])
        ?? throw new HttpError(404, 'Remise introuvable.');
    access_course($user, (int) $submission['course_id'], true);
    $body = input();
    $grade = null;
    if (($body['grade'] ?? '') !== '' && $body['grade'] !== null) {
        $max = item_data($submission)['max_grade'] ?? 20;
        if (!is_numeric($body['grade']) || $body['grade'] < 0 || $body['grade'] > $max) {
            throw new HttpError(400, "La note doit être comprise entre 0 et $max.");
        }
        $grade = (float) $body['grade'];
    }
    q('UPDATE submissions SET grade = ?, feedback = ?, graded_by = ?, graded_at = ? WHERE id = ?',
        [$grade, str_in($body['feedback'] ?? '', 10000), $user['id'], now_iso(), $submission['id']]);
    json_out(['ok' => true]);
}

// ---------- Téléchargement des fichiers ----------

function route_file_get(array $p): never
{
    $user = require_user();
    $file = row('SELECT * FROM files WHERE id = ?', [$p[0]]) ?? throw new HttpError(404, 'Fichier introuvable.');
    [, $role] = access_course($user, (int) $file['course_id']);
    if ($role === 'stagiaire') {
        if ($file['kind'] === 'resource') {
            $item = row('SELECT * FROM items WHERE id = ?', [$file['item_id']]);
            if (!$item || !item_open_for_students($item)) {
                throw new HttpError(404, 'Fichier introuvable.');
            }
        } elseif ($file['kind'] === 'submission') {
            if ((int) val('SELECT user_id FROM submissions WHERE id = ?', [$file['submission_id']]) !== (int) $user['id']) {
                throw new HttpError(404, 'Fichier introuvable.');
            }
        }
    }
    $path = UPLOAD_DIR . '/' . $file['stored_name'];
    if (!is_file($path)) {
        throw new HttpError(404, 'Fichier introuvable.');
    }
    $inline = in_array($file['mime'], INLINE_TYPES, true) && !isset($_GET['download']);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($inline ? $file['mime'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['original_name']));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

function route_file_delete(array $p): never
{
    $file = row("SELECT * FROM files WHERE id = ? AND kind = 'resource'", [$p[0]]) ?? throw new HttpError(404, 'Fichier introuvable.');
    access_course(require_user(), (int) $file['course_id'], true);
    q('DELETE FROM files WHERE id = ?', [$file['id']]);
    sweep_orphan_files();
    json_out(['ok' => true]);
}

// ---------- Participants et inscriptions ----------

function route_participants(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    $trackable = trackable_item_ids((int) $course['id']);
    $people = rows('SELECT u.id, u.first_name, u.last_name, u.email, u.active, e.role FROM enrollments e
        JOIN users u ON u.id = e.user_id WHERE e.course_id = ? ORDER BY e.role, u.last_name, u.first_name', [$course['id']]);
    json_out(['participants' => array_map(fn ($person) => [
        'id' => (int) $person['id'], 'first_name' => $person['first_name'], 'last_name' => $person['last_name'], 'email' => $person['email'],
        'active' => (bool) $person['active'], 'role' => $person['role'],
        'progress' => $person['role'] === 'stagiaire' ? progress_for((int) $course['id'], (int) $person['id'], $trackable) : null,
    ], $people)]);
}

function enroll(int $courseId, int $userId, string $role): bool
{
    return q('INSERT IGNORE INTO enrollments (course_id, user_id, role, created_at) VALUES (?, ?, ?, ?)', [$courseId, $userId, $role, now_iso()])->rowCount() > 0;
}

/** Inscription individuelle par e-mail. Un compte stagiaire est créé si l'adresse est inconnue. */
function route_enroll(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    $body = input();
    $email = mb_strtolower(str_in($body['email'] ?? '', 190, true, 'E-mail'), 'UTF-8');
    if (!preg_match(EMAIL_RE, $email)) {
        throw new HttpError(400, 'Adresse e-mail invalide.');
    }
    $role = ($body['role'] ?? '') === 'formateur' ? 'formateur' : 'stagiaire';
    $firstName = str_in($body['first_name'] ?? '', 100);
    $lastName = str_in($body['last_name'] ?? '', 100);

    $password = tx(function () use ($email, $role, $firstName, $lastName, $course) {
        $user = row('SELECT * FROM users WHERE email = ?', [$email]);
        $password = null;
        if (!$user) {
            if ($role === 'formateur') {
                throw new HttpError(404, 'Aucun compte formateur avec cette adresse. Demandez sa création à l’administration.');
            }
            $created = create_account($email, $firstName, $lastName, 'stagiaire');
            $user = ['id' => $created['id'], 'role' => 'stagiaire'];
            $password = $created['password'];
        }
        if ($role === 'formateur' && $user['role'] === 'stagiaire') {
            throw new HttpError(400, 'Ce compte est un compte stagiaire : il ne peut pas être formateur du parcours.');
        }
        if (!enroll((int) $course['id'], (int) $user['id'], $role)) {
            throw new HttpError(409, 'Cette personne est déjà inscrite au parcours.');
        }
        return $password;
    });
    json_out(['password' => $password], 201);
}

function route_unenroll(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    $target = val('SELECT role FROM enrollments WHERE course_id = ? AND user_id = ?', [$course['id'], $p[1]]);
    if ($target === 'formateur' && (int) val("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND role = 'formateur'", [$course['id']]) <= 1) {
        throw new HttpError(400, 'Le parcours doit conserver au moins un formateur.');
    }
    q('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', [$course['id'], $p[1]]);
    json_out(['ok' => true]);
}

/**
 * Inscription par lot depuis un fichier Excel (.xlsx) ou CSV.
 * ?preview=1 : analyse sans rien écrire, pour vérification avant validation.
 */
function route_import(array $p): never
{
    [$course] = access_course(require_user(), $p[0], true);
    $file = uploads('file')[0] ?? throw new HttpError(400, 'Fichier manquant.');
    ['rows' => $rows, 'errors' => $errors] = read_roster($file['tmp'], $file['name']);
    $preview = isset($_GET['preview']);
    $courseId = (int) $course['id'];

    $result = tx(fn () => array_map(function (array $row) use ($courseId, $preview) {
        $user = row('SELECT id, role, first_name, last_name FROM users WHERE email = ?', [$row['email']]);
        if ($user && val('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?', [$courseId, $user['id']])) {
            return $row + ['status' => 'deja_inscrit'];
        }
        if ($user && $user['role'] !== 'stagiaire') {
            return $row + ['status' => 'compte_non_stagiaire'];
        }
        if ($user) {
            if (!$preview) {
                enroll($courseId, (int) $user['id'], 'stagiaire');
            }
            return ['first_name' => $user['first_name'] ?: $row['first_name'], 'last_name' => $user['last_name'] ?: $row['last_name'], 'status' => 'inscrit'] + $row;
        }
        if ($preview) {
            return $row + ['status' => 'cree'];
        }
        $created = create_account($row['email'], $row['first_name'], $row['last_name'], 'stagiaire');
        enroll($courseId, $created['id'], 'stagiaire');
        return $row + ['status' => 'cree', 'password' => $created['password']];
    }, $rows));
    json_out(['preview' => $preview, 'rows' => $result, 'errors' => $errors]);
}
