<?php

declare(strict_types=1);

/**
 * Point d'entrée unique de l'API : api/index.php?r=/chemin
 * (aucune réécriture d'URL requise, fonctionne sur tout hébergement PHP).
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/schema.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/model.php';
require __DIR__ . '/../app/import.php';
require __DIR__ . '/../app/routes_core.php';
require __DIR__ . '/../app/routes_courses.php';
require __DIR__ . '/../app/fpa.php';
require __DIR__ . '/../app/routes_ai.php';

const ROUTES = [
    ['POST', '/login', 'route_login'],
    ['POST', '/logout', 'route_logout'],
    ['GET', '/me', 'route_me'],
    ['POST', '/password', 'route_password'],
    ['GET', '/users', 'route_users_list'],
    ['POST', '/users', 'route_users_create'],
    ['PATCH', '/users/(\d+)', 'route_users_update'],
    ['POST', '/users/(\d+)/reset-password', 'route_users_reset'],
    ['GET', '/titres', 'route_titres_list'],
    ['POST', '/titres', 'route_titres_create'],
    ['PATCH', '/titres/(\d+)', 'route_titres_update'],
    ['DELETE', '/titres/(\d+)', 'route_titres_delete'],
    ['GET', '/courses', 'route_courses_list'],
    ['POST', '/courses', 'route_courses_create'],
    ['GET', '/courses/(\d+)', 'route_course_get'],
    ['PATCH', '/courses/(\d+)', 'route_course_update'],
    ['DELETE', '/courses/(\d+)', 'route_course_delete'],
    ['POST', '/courses/(\d+)/header', 'route_course_header_set'],
    ['DELETE', '/courses/(\d+)/header', 'route_course_header_delete'],
    ['POST', '/courses/(\d+)/nodes', 'route_node_create'],
    ['PATCH', '/nodes/(\d+)', 'route_node_update'],
    ['DELETE', '/nodes/(\d+)', 'route_node_delete'],
    ['POST', '/courses/(\d+)/reorder', 'route_course_reorder'],
    ['POST', '/courses/(\d+)/items', 'route_item_create'],
    ['GET', '/items/(\d+)', 'route_item_get'],
    ['PATCH', '/items/(\d+)', 'route_item_update'],
    ['DELETE', '/items/(\d+)', 'route_item_delete'],
    ['PUT', '/items/(\d+)/completion', 'route_item_completion'],
    ['POST', '/items/(\d+)/submission', 'route_submission_create'],
    ['PATCH', '/submissions/(\d+)', 'route_submission_grade'],
    ['GET', '/files/(\d+)', 'route_file_get'],
    ['DELETE', '/files/(\d+)', 'route_file_delete'],
    ['GET', '/courses/(\d+)/participants', 'route_participants'],
    ['POST', '/courses/(\d+)/enroll', 'route_enroll'],
    ['DELETE', '/courses/(\d+)/enroll/(\d+)', 'route_unenroll'],
    ['POST', '/courses/(\d+)/import', 'route_import'],
    ['GET', '/ai/status', 'route_ai_status'],
    ['GET', '/ai/conversations', 'route_ai_conversations'],
    ['POST', '/ai/conversations', 'route_ai_conversation_create'],
    ['GET', '/ai/conversations/(\d+)', 'route_ai_conversation_get'],
    ['DELETE', '/ai/conversations/(\d+)', 'route_ai_conversation_delete'],
    ['POST', '/ai/conversations/(\d+)/documents', 'route_ai_document_create'],
    ['DELETE', '/ai/documents/(\d+)', 'route_ai_document_delete'],
    ['POST', '/ai/conversations/(\d+)/messages', 'route_ai_message'],
    ['POST', '/ai/proposals/(\d+)/generate', 'route_ai_generate'],
];

try {
    security_headers();
    check_requirements();
    csrf_guard();
    ensure_schema();

    // Certains hébergements filtrent PATCH, PUT et DELETE : le client les envoie en POST avec cet en-tête.
    $method = $_SERVER['REQUEST_METHOD'];
    $override = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '');
    if ($method === 'POST' && in_array($override, ['PATCH', 'PUT', 'DELETE'], true)) {
        $method = $override;
    }
    $path = is_string($_GET['r'] ?? null) ? $_GET['r'] : '';

    foreach (ROUTES as [$routeMethod, $pattern, $handler]) {
        if ($routeMethod === $method && preg_match('#^' . $pattern . '$#', $path, $matches)) {
            $handler(array_map('intval', array_slice($matches, 1)));
        }
    }
    throw new HttpError(404, 'Ressource introuvable.');
} catch (HttpError $e) {
    if (!headers_sent()) {
        json_out(['error' => $e->getMessage()], $e->status);
    }
} catch (Throwable $e) {
    error_log('[AFPAREA] ' . $e);
    if (!headers_sent()) {
        json_out(['error' => 'Erreur interne du serveur.'], 500);
    }
}
