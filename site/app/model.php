<?php

declare(strict_types=1);

/** Parcours : droits d'accès, arborescence, progression. */

const NODE_TYPES = ['module', 'sequence', 'seance'];
const ITEM_TYPES = ['etiquette', 'page', 'fichier', 'dossier', 'lien', 'video', 'depot'];
/** Type de nœud attendu selon le type du parent (root = racine du parcours). */
const CHILD_TYPE = ['root' => 'module', 'module' => 'sequence', 'sequence' => 'seance'];

/** Rôle de l'utilisateur dans le parcours : 'formateur', 'stagiaire' ou null. Les admins sont formateurs partout. */
function course_role(array $user, int $courseId): ?string
{
    if ($user['role'] === 'admin') {
        return 'formateur';
    }
    return val('SELECT role FROM enrollments WHERE course_id = ? AND user_id = ?', [$courseId, $user['id']]);
}

/**
 * Charge le parcours et vérifie l'accès. $trainer exige les droits de formateur.
 * @return array{0: array, 1: string} parcours et rôle
 */
function access_course(array $user, int $courseId, bool $trainer = false): array
{
    $course = row('SELECT * FROM courses WHERE id = ?', [$courseId]);
    $role = $course ? course_role($user, $courseId) : null;
    if (!$role || ($role === 'stagiaire' && !$course['visible'])) {
        throw new HttpError(404, 'Parcours introuvable.');
    }
    if ($trainer && $role !== 'formateur') {
        throw new HttpError(403, 'Action réservée aux formateurs du parcours.');
    }
    return [$course, $role];
}

function is_open(array $row, string $at): bool
{
    return $row['visible'] && (!$row['available_from'] || $row['available_from'] <= $at);
}

/** Un élément est-il consultable par un stagiaire (lui-même et tous ses ancêtres visibles et disponibles) ? */
function item_open_for_students(array $item): bool
{
    $at = now_iso();
    if (!is_open($item, $at)) {
        return false;
    }
    $node = row('SELECT * FROM nodes WHERE id = ?', [$item['node_id']]);
    while ($node) {
        if (!is_open($node, $at)) {
            return false;
        }
        $node = $node['parent_id'] ? row('SELECT * FROM nodes WHERE id = ?', [$node['parent_id']]) : null;
    }
    return true;
}

/** Identifiants des éléments comptés dans la progression : non masqués (ni leurs ancêtres), hors étiquettes. */
function trackable_item_ids(int $courseId): array
{
    $nodes = [];
    foreach (rows('SELECT id, parent_id, visible FROM nodes WHERE course_id = ?', [$courseId]) as $node) {
        $nodes[$node['id']] = $node;
    }
    $shown = function (?int $id) use (&$shown, $nodes): bool {
        $node = $nodes[$id] ?? null;
        return $node !== null && $node['visible'] && (!$node['parent_id'] || $shown($node['parent_id']));
    };
    $ids = [];
    foreach (rows("SELECT id, node_id FROM items WHERE course_id = ? AND visible = 1 AND type != 'etiquette'", [$courseId]) as $item) {
        if ($shown($item['node_id'])) {
            $ids[] = $item['id'];
        }
    }
    return $ids;
}

function progress_for(int $courseId, int $userId, ?array $trackable = null): array
{
    $trackable ??= trackable_item_ids($courseId);
    if (!$trackable) {
        return ['done' => 0, 'total' => 0, 'percent' => 0];
    }
    $completed = array_flip(col('SELECT c.item_id FROM completions c JOIN items i ON i.id = c.item_id
        WHERE i.course_id = ? AND c.user_id = ?', [$courseId, $userId]));
    $done = count(array_filter($trackable, fn ($id) => isset($completed[$id])));
    return ['done' => $done, 'total' => count($trackable), 'percent' => (int) round($done / count($trackable) * 100)];
}

function file_view(array $file): array
{
    return ['id' => (int) $file['id'], 'name' => $file['original_name'], 'size' => (int) $file['size'], 'mime' => $file['mime']];
}

function item_data(array $item): array
{
    return json_decode($item['data'], true) ?: [];
}

/**
 * Arborescence complète du parcours pour l'utilisateur.
 * Formateur : tout, avec les indicateurs de visibilité.
 * Stagiaire : éléments masqués absents ; éléments programmés présents mais verrouillés (titre et date seulement).
 */
function course_tree(array $course, string $role, int $userId): array
{
    $at = now_iso();
    $trainer = $role === 'formateur';
    $courseId = (int) $course['id'];

    $filesByItem = [];
    foreach (rows("SELECT * FROM files WHERE course_id = ? AND kind = 'resource' ORDER BY id", [$courseId]) as $file) {
        $filesByItem[$file['item_id']][] = file_view($file);
    }
    $done = array_flip(col('SELECT c.item_id FROM completions c JOIN items i ON i.id = c.item_id
        WHERE i.course_id = ? AND c.user_id = ?', [$courseId, $userId]));
    $submissions = [];
    foreach (rows('SELECT s.item_id, s.submitted_at, s.grade FROM submissions s JOIN items i ON i.id = s.item_id
        WHERE i.course_id = ? AND s.user_id = ?', [$courseId, $userId]) as $submission) {
        $submissions[$submission['item_id']] = ['submitted_at' => $submission['submitted_at'], 'grade' => $submission['grade']];
    }
    $stats = [];
    if ($trainer) {
        foreach (rows('SELECT s.item_id, COUNT(*) AS n, SUM(s.grade IS NULL) AS pending FROM submissions s
            JOIN items i ON i.id = s.item_id WHERE i.course_id = ? GROUP BY s.item_id', [$courseId]) as $stat) {
            $stats[$stat['item_id']] = ['n' => (int) $stat['n'], 'pending' => (int) $stat['pending']];
        }
    }
    $itemsByNode = [];
    foreach (rows('SELECT * FROM items WHERE course_id = ? ORDER BY position, id', [$courseId]) as $item) {
        $itemsByNode[$item['node_id']][] = $item;
    }
    $childrenByParent = [];
    foreach (rows('SELECT * FROM nodes WHERE course_id = ? ORDER BY position, id', [$courseId]) as $node) {
        $childrenByParent[$node['parent_id'] ?? 0][] = $node;
    }

    $flags = fn (array $row) => [
        'visible' => (bool) $row['visible'],
        'available_from' => $row['available_from'],
        'scheduled' => $row['available_from'] !== null && $row['available_from'] > $at,
    ];

    $viewItem = function (array $item) use ($trainer, $flags, $filesByItem, $done, $submissions, $stats): ?array {
        $base = ['id' => (int) $item['id'], 'node_id' => (int) $item['node_id'], 'type' => $item['type'], 'title' => $item['title']] + $flags($item);
        if (!$trainer && !$item['visible']) {
            return null;
        }
        if (!$trainer && $base['scheduled']) {
            return $base + ['locked' => true];
        }
        $view = $base + ['data' => (object) item_data($item), 'files' => $filesByItem[$item['id']] ?? [], 'done' => isset($done[$item['id']])];
        if ($item['type'] === 'depot') {
            $view['submission'] = $submissions[$item['id']] ?? null;
            if ($trainer) {
                $view['submission_stats'] = $stats[$item['id']] ?? ['n' => 0, 'pending' => 0];
            }
        }
        return $view;
    };

    $viewNode = function (array $node) use (&$viewNode, $viewItem, $trainer, $flags, $itemsByNode, $childrenByParent): ?array {
        $base = [
            'id' => (int) $node['id'], 'parent_id' => $node['parent_id'] === null ? null : (int) $node['parent_id'],
            'type' => $node['type'], 'title' => $node['title'], 'duration_minutes' => (int) $node['duration_minutes'],
        ] + $flags($node);
        if (!$trainer && !$node['visible']) {
            return null;
        }
        if (!$trainer && $base['scheduled']) {
            return $base + ['locked' => true, 'children' => [], 'items' => []];
        }
        return $base + [
            'description' => $node['description'],
            'items' => array_values(array_filter(array_map($viewItem, $itemsByNode[$node['id']] ?? []))),
            'children' => array_values(array_filter(array_map($viewNode, $childrenByParent[$node['id']] ?? []))),
        ];
    };

    return array_values(array_filter(array_map($viewNode, $childrenByParent[0] ?? [])));
}

function course_summary(array $course, string $role, int $userId): array
{
    $id = (int) $course['id'];
    $titre = $course['titre_id'] ? row('SELECT id, code, name, level FROM titres WHERE id = ?', [$course['titre_id']]) : null;
    $summary = [
        'id' => $id, 'title' => $course['title'], 'summary' => $course['summary'], 'color' => $course['color'],
        'visible' => (bool) $course['visible'], 'start_date' => $course['start_date'], 'end_date' => $course['end_date'],
        'header_file_id' => $course['header_file_id'] === null ? null : (int) $course['header_file_id'],
        'titre' => $titre, 'role' => $role,
        'duration_minutes' => (int) val("SELECT COALESCE(SUM(duration_minutes), 0) FROM nodes WHERE course_id = ? AND type = 'seance'", [$id]),
        'progress' => $role === 'stagiaire' ? progress_for($id, $userId) : null,
    ];
    if ($role === 'formateur') {
        $summary['student_count'] = (int) val("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND role = 'stagiaire'", [$id]);
    }
    return $summary;
}

function next_position(string $table, string $column, ?int $parentId, int $courseId): int
{
    // $table et $column sont des constantes du code, jamais des valeurs reçues.
    return (int) val("SELECT COALESCE(MAX(position), -1) + 1 FROM $table WHERE course_id = ? AND $column <=> ?", [$courseId, $parentId]);
}

function insert_node(int $courseId, ?int $parentId, string $type, array $fields): int
{
    return insert('INSERT INTO nodes (course_id, parent_id, type, title, description, duration_minutes, position, visible, available_from)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $courseId, $parentId, $type, $fields['title'], $fields['description'] ?? '', (int) ($fields['duration_minutes'] ?? 0),
        next_position('nodes', 'parent_id', $parentId, $courseId), ($fields['visible'] ?? true) ? 1 : 0, $fields['available_from'] ?? null,
    ]);
}

function insert_item(int $courseId, int $nodeId, string $type, string $title, array $data = [], bool $visible = true, ?string $availableFrom = null): int
{
    return insert('INSERT INTO items (course_id, node_id, type, title, data, position, visible, available_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $courseId, $nodeId, $type, $title, json_encode((object) $data, JSON_UNESCAPED_UNICODE),
        next_position('items', 'node_id', $nodeId, $courseId), $visible ? 1 : 0, $availableFrom,
    ]);
}

function insert_course(array $user, array $fields): int
{
    $id = insert('INSERT INTO courses (titre_id, title, summary, color, visible, start_date, end_date, created_by, created_at)
        VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)', [
        $fields['titre_id'] ?? null, $fields['title'], $fields['summary'] ?? '', $fields['color'] ?? 'navy',
        $fields['start_date'] ?? null, $fields['end_date'] ?? null, $user['id'], now_iso(),
    ]);
    q("INSERT INTO enrollments (course_id, user_id, role, created_at) VALUES (?, ?, 'formateur', ?)", [$id, $user['id'], now_iso()]);
    return $id;
}
