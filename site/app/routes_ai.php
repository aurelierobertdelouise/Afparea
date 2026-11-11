<?php

declare(strict_types=1);

/** Assistant FPA : conversations, référentiels joints, dialogue avec l'API Claude, génération du parcours. */

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Streaming\MessageAccumulator;

const FPA_MAX_DOC_BYTES = 12 * 1024 * 1024;
const FPA_MAX_TOTAL_DOC_BYTES = 24 * 1024 * 1024; // la requête API est limitée à 32 Mo, base64 compris
const FPA_BUSY_SECONDS = 900;
const FPA_KIND_LABELS = [
    'REAC' => 'REAC (Référentiel Emploi Activités Compétences)',
    'REV' => 'REV (Référentiel d’Évaluation)',
    'AUTRE' => 'Document complémentaire',
];

/** L'assistant est strictement réservé aux formateurs et à l'administration. */
function fpa_user(): array
{
    return require_role('admin', 'formateur');
}

function fpa_configured(): bool
{
    return config('anthropic_api_key') !== null && is_file(APP_ROOT . '/vendor/autoload.php');
}

function fpa_conversation(array $user, int $id): array
{
    return row('SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?', [$id, $user['id']])
        ?? throw new HttpError(404, 'Conversation introuvable.');
}

function fpa_document_view(array $doc): array
{
    return ['id' => (int) $doc['id'], 'kind' => $doc['kind'], 'name' => $doc['name'], 'size' => (int) $doc['size'], 'sent' => $doc['message_id'] !== null];
}

/** Version affichable d'un message enregistré (texte, pièces jointes, propositions). */
function fpa_message_view(array $message): array
{
    $blocks = json_decode($message['content'], true) ?: [];
    $texts = [];
    $documents = [];
    foreach ($blocks as $block) {
        if (($block['type'] ?? '') === 'text') {
            $texts[] = $block['text'];
        } elseif (($block['type'] ?? '') === 'doc_ref') {
            $doc = row('SELECT * FROM ai_documents WHERE id = ?', [$block['document_id']]);
            if ($doc) {
                $documents[] = fpa_document_view($doc);
            }
        }
    }
    $proposals = array_map(fn ($proposal) => [
        'id' => (int) $proposal['id'],
        'course_id' => $proposal['course_id'] === null ? null : (int) $proposal['course_id'],
        'data' => json_decode($proposal['data'], true),
    ], rows('SELECT id, data, course_id FROM ai_proposals WHERE message_id = ? ORDER BY id', [$message['id']]));
    return ['id' => (int) $message['id'], 'role' => $message['role'], 'text' => implode("\n\n", $texts), 'documents' => $documents, 'proposals' => $proposals];
}

function route_ai_status(): never
{
    fpa_user();
    json_out(['configured' => fpa_configured()]);
}

function route_ai_conversations(): never
{
    $user = fpa_user();
    json_out(['conversations' => rows('SELECT id, title, created_at FROM ai_conversations WHERE user_id = ? ORDER BY id DESC', [$user['id']])]);
}

function route_ai_conversation_create(): never
{
    $user = fpa_user();
    $title = str_in(input()['title'] ?? '', 120) ?: 'Nouvelle conversation';
    json_out(['id' => insert('INSERT INTO ai_conversations (user_id, title, created_at) VALUES (?, ?, ?)', [$user['id'], $title, now_iso()])], 201);
}

function route_ai_conversation_get(array $p): never
{
    $conversation = fpa_conversation(fpa_user(), $p[0]);
    $messages = array_map('fpa_message_view', rows('SELECT * FROM ai_messages WHERE conversation_id = ? ORDER BY id', [$conversation['id']]));
    json_out([
        'conversation' => ['id' => (int) $conversation['id'], 'title' => $conversation['title']],
        // Les lignes ne contenant qu'un résultat d'outil sont internes au dialogue avec le modèle.
        'messages' => array_values(array_filter($messages, fn ($m) => $m['text'] !== '' || $m['proposals'] || $m['documents'])),
        'pending_documents' => array_map('fpa_document_view',
            rows('SELECT * FROM ai_documents WHERE conversation_id = ? AND message_id IS NULL ORDER BY id', [$conversation['id']])),
        // Une réponse est en cours de rédaction côté serveur (utile si la connexion du navigateur a été coupée).
        'busy' => (int) $conversation['busy_until'] > time(),
        'last_error' => $conversation['last_error'],
    ]);
}

function route_ai_conversation_delete(array $p): never
{
    $conversation = fpa_conversation(fpa_user(), $p[0]);
    q('DELETE FROM ai_conversations WHERE id = ?', [$conversation['id']]);
    sweep_orphan_files();
    json_out(['ok' => true]);
}

// ---------- Référentiels joints (REAC, REV…) ----------

function route_ai_document_create(array $p): never
{
    $conversation = fpa_conversation(fpa_user(), $p[0]);
    $file = uploads('file')[0] ?? throw new HttpError(400, 'Fichier manquant.');
    $isPdf = (bool) preg_match('/\.pdf$/i', $file['name']);
    if (!$isPdf && !preg_match('/\.(txt|md)$/i', $file['name'])) {
        throw new HttpError(400, 'Formats acceptés : PDF, TXT ou MD.');
    }
    if ($isPdf && file_get_contents($file['tmp'], false, null, 0, 5) !== '%PDF-') {
        throw new HttpError(400, 'Ce fichier n’est pas un PDF valide.');
    }
    if (!$isPdf && !mb_check_encoding(file_get_contents($file['tmp']), 'UTF-8')) {
        throw new HttpError(400, 'Ce fichier texte n’est pas encodé en UTF-8.');
    }
    if ($file['size'] > FPA_MAX_DOC_BYTES) {
        throw new HttpError(400, 'Document trop volumineux (12 Mo maximum).');
    }
    $total = (int) val('SELECT COALESCE(SUM(size), 0) FROM ai_documents WHERE conversation_id = ?', [$conversation['id']]);
    if ($total + $file['size'] > FPA_MAX_TOTAL_DOC_BYTES) {
        throw new HttpError(400, 'Trop de documents dans cette conversation (24 Mo au total).');
    }
    $kind = in_array(input()['kind'] ?? '', ['REAC', 'REV'], true) ? input()['kind'] : 'AUTRE';
    $id = insert('INSERT INTO ai_documents (conversation_id, kind, name, mime, size, stored_name) VALUES (?, ?, ?, ?, ?, ?)',
        [$conversation['id'], $kind, $file['name'], $isPdf ? 'application/pdf' : 'text/plain', $file['size'], store_upload($file)]);
    json_out(['document' => ['id' => $id, 'kind' => $kind, 'name' => $file['name'], 'size' => $file['size'], 'sent' => false]], 201);
}

function route_ai_document_delete(array $p): never
{
    $user = fpa_user();
    $doc = row('SELECT d.* FROM ai_documents d JOIN ai_conversations c ON c.id = d.conversation_id WHERE d.id = ? AND c.user_id = ?', [$p[0], $user['id']])
        ?? throw new HttpError(404, 'Document introuvable.');
    if ($doc['message_id'] !== null) {
        throw new HttpError(400, 'Ce document a déjà été transmis à l’assistant.');
    }
    q('DELETE FROM ai_documents WHERE id = ?', [$doc['id']]);
    sweep_orphan_files();
    json_out(['ok' => true]);
}

// ---------- Dialogue ----------

/** Remplace une référence de document par son contenu, à l'identique d'un appel à l'autre. */
function fpa_expand_block(array $block): array
{
    if (($block['type'] ?? '') === 'tool_use' && ($block['input'] ?? null) === []) {
        $block['input'] = new stdClass(); // un objet JSON vide ne doit pas être renvoyé comme une liste vide
    }
    if (($block['type'] ?? '') !== 'doc_ref') {
        return $block;
    }
    $doc = row('SELECT * FROM ai_documents WHERE id = ?', [$block['document_id']]);
    $bytes = $doc ? @file_get_contents(UPLOAD_DIR . '/' . $doc['stored_name']) : false;
    if ($bytes === false) {
        throw new HttpError(500, 'Un document joint à cette conversation est introuvable sur le serveur.');
    }
    return [
        'type' => 'document',
        'title' => FPA_KIND_LABELS[$doc['kind']] . ' — ' . $doc['name'],
        'source' => $doc['mime'] === 'application/pdf'
            ? ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]
            : ['type' => 'text', 'media_type' => 'text/plain', 'data' => $bytes],
    ];
}

/** Historique au format de l'API ; les messages utilisateur consécutifs (résultat d'outil puis texte) sont fusionnés. */
function fpa_build_messages(array $history): array
{
    $messages = [];
    foreach ($history as $entry) {
        $content = array_map('fpa_expand_block', $entry['content']);
        $last = count($messages) - 1;
        if ($last >= 0 && $messages[$last]['role'] === 'user' && $entry['role'] === 'user') {
            array_push($messages[$last]['content'], ...$content);
        } else {
            $messages[] = ['role' => $entry['role'], 'content' => $content];
        }
    }
    return $messages;
}

function fpa_friendly_error(Throwable $error): string
{
    return match (true) {
        $error instanceof HttpError => $error->getMessage(),
        $error instanceof AuthenticationException => 'Clé API Anthropic invalide. Vérifiez la configuration du serveur.',
        $error instanceof RateLimitException => 'L’assistant est très sollicité. Réessayez dans un instant.',
        $error instanceof BadRequestException => 'La demande a été refusée par le service d’IA : ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8'),
        $error instanceof APIConnectionException => 'Impossible de joindre le service d’IA. Vérifiez la connexion réseau du serveur.',
        $error instanceof APIException => 'Le service d’IA est momentanément indisponible. Réessayez dans un instant.',
        default => 'Une erreur est survenue pendant la réponse de l’assistant.',
    };
}

/** Envoie un événement au navigateur (une ligne JSON), sans attendre la fin de la réponse. */
function fpa_send(array $event): void
{
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    @ob_flush();
    flush();
}

/**
 * Envoi d'un message. La réponse est diffusée en continu ; si la connexion du navigateur est coupée
 * (délai de l'hébergeur), la rédaction se poursuit et le résultat est enregistré : le navigateur le
 * retrouve en rechargeant la conversation.
 */
function route_ai_message(array $p): never
{
    $user = fpa_user();
    $conversation = fpa_conversation($user, $p[0]);
    $conversationId = (int) $conversation['id'];
    if (!fpa_configured()) {
        throw new HttpError(503, 'L’assistant FPA n’est pas configuré : renseignez « anthropic_api_key » dans config.php.');
    }
    $text = str_in(input()['text'] ?? '', 20000, true, 'Message');
    // Une seule réponse à la fois par conversation (mise à jour atomique).
    if (!q('UPDATE ai_conversations SET busy_until = ?, last_error = ? WHERE id = ? AND busy_until < ?',
        [time() + FPA_BUSY_SECONDS, '', $conversationId, time()])->rowCount()) {
        throw new HttpError(409, 'Une réponse est déjà en cours pour cette conversation. Patientez un instant.');
    }

    $finished = false;
    $release = function (string $error = '') use ($conversationId, &$finished): void {
        if (!$finished) {
            $finished = true;
            db(true); // la connexion a pu expirer pendant la rédaction
            q('UPDATE ai_conversations SET busy_until = 0, last_error = ? WHERE id = ?', [mb_substr($error, 0, 500, 'UTF-8'), $conversationId]);
        }
    };
    // Arrêt inattendu du script (limite de l'hébergeur, erreur fatale) : la conversation est libérée.
    register_shutdown_function(fn () => $release('La réponse a été interrompue par le serveur. Réessayez, en demandant éventuellement le découpage module par module.'));

    try {
        $pending = rows('SELECT * FROM ai_documents WHERE conversation_id = ? AND message_id IS NULL ORDER BY id', [$conversationId]);
        $userContent = [...array_map(fn ($doc) => ['type' => 'doc_ref', 'document_id' => (int) $doc['id']], $pending), ['type' => 'text', 'text' => $text]];
        $history = array_map(fn ($message) => ['role' => $message['role'], 'content' => json_decode($message['content'], true) ?: []],
            rows('SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id', [$conversationId]));
        $messages = fpa_build_messages([...$history, ['role' => 'user', 'content' => $userContent]]);
        // Point de cache sur le dernier bloc : les référentiels et l'historique sont relus à tarif réduit au tour suivant.
        $lastMessage = count($messages) - 1;
        $lastBlock = count($messages[$lastMessage]['content']) - 1;
        $messages[$lastMessage]['content'][$lastBlock]['cache_control'] = ['type' => 'ephemeral'];

        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Accel-Buffering: no');
        fpa_send(['type' => 'status', 'text' => 'FPA réfléchit…']);

        require_once APP_ROOT . '/vendor/autoload.php';
        $options = [];
        if (class_exists(\GuzzleHttp\Client::class)) {
            // Pas de limite de durée globale : une proposition complète peut demander plusieurs minutes.
            $options['streamingTransporter'] = new \GuzzleHttp\Client(['stream' => true, 'connect_timeout' => 20, 'read_timeout' => 600, 'timeout' => 0]);
        }
        $client = new Client(apiKey: (string) config('anthropic_api_key'), baseUrl: config('anthropic_base_url'), requestOptions: $options);
        $fallbacks = config('fpa_fallbacks', 'on') !== 'off';
        // Si le modèle décline une demande, l'API la rejoue sur un modèle de repli (désactivable : fpa_fallbacks = off).
        $stream = $client->beta->messages->createStream(
            maxTokens: 64000,
            messages: $messages,
            model: (string) config('fpa_model', 'claude-opus-5-5'),
            system: FPA_SYSTEM_PROMPT,
            outputConfig: ['effort' => (string) config('fpa_effort', 'high')],
            tools: [fpa_proposal_tool()],
            betas: $fallbacks ? ['server-side-fallback-2026-07-01'] : null,
            fallbacks: $fallbacks ? 'default' : null,
        );

        $accumulator = MessageAccumulator::forBetaMessages();
        $toolJson = '';
        $seances = 0;
        foreach ($stream as $event) {
            $accumulator->accumulate($event);
            $wire = json_decode(json_encode($event), true);
            $type = $wire['type'] ?? '';
            if ($type === 'content_block_start' && ($wire['content_block']['type'] ?? '') === 'tool_use') {
                $toolJson = '';
                $seances = 0;
                fpa_send(['type' => 'status', 'text' => 'Construction du découpage pédagogique…']);
            } elseif ($type === 'content_block_delta' && ($wire['delta']['type'] ?? '') === 'text_delta') {
                fpa_send(['type' => 'text', 'text' => $wire['delta']['text']]);
            } elseif ($type === 'content_block_delta' && ($wire['delta']['type'] ?? '') === 'input_json_delta') {
                $toolJson .= $wire['delta']['partial_json'];
                $count = substr_count($toolJson, '"duree_heures"');
                if ($count > $seances) {
                    $seances = $count;
                    fpa_send(['type' => 'status', 'text' => "Construction du découpage pédagogique… $count séance" . ($count > 1 ? 's' : '')]);
                }
            }
        }
        $message = json_decode(json_encode($accumulator->message()), true);
        $content = $message['content'] ?? [];
        $stopReason = $message['stop_reason'] ?? null;
        $toolUses = array_values(array_filter($content, fn ($block) => ($block['type'] ?? '') === 'tool_use'));

        if ($stopReason === 'refusal') {
            throw new HttpError(422, 'L’assistant n’a pas pu traiter cette demande. Reformulez-la ou vérifiez les documents joints.');
        }
        if ($stopReason === 'max_tokens' && $toolUses) {
            throw new HttpError(422, 'Le découpage demandé est trop volumineux pour être généré en une fois. Demandez-le par CCP ou par bloc de modules.');
        }

        // Validation des propositions avant tout enregistrement : une entrée invalide est renvoyée au modèle comme erreur.
        $results = array_map(function (array $block) {
            try {
                return ['block' => $block, 'proposal' => fpa_normalize_proposal($block['input'] ?? null), 'error' => null];
            } catch (InvalidArgumentException $e) {
                return ['block' => $block, 'proposal' => null, 'error' => $e->getMessage()];
            }
        }, $toolUses);

        db(true); // la connexion a pu expirer pendant la rédaction
        $proposals = tx(function () use ($conversation, $conversationId, $userContent, $pending, $content, $results, $text) {
            $add = fn (string $role, array $blocks) => insert('INSERT INTO ai_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)',
                [$conversationId, $role, json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_iso()]);
            $userMessageId = $add('user', $userContent);
            foreach ($pending as $doc) {
                q('UPDATE ai_documents SET message_id = ? WHERE id = ?', [$userMessageId, $doc['id']]);
            }
            // Le contenu de l'assistant est conservé tel quel (blocs de réflexion compris) pour être renvoyé inchangé.
            $assistantId = $add('assistant', $content);
            $proposals = [];
            if ($results) {
                $toolResults = [];
                foreach ($results as $result) {
                    if ($result['error'] !== null) {
                        $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $result['block']['id'], 'is_error' => true, 'content' => 'Proposition invalide : ' . $result['error']];
                        continue;
                    }
                    $id = insert('INSERT INTO ai_proposals (conversation_id, message_id, data, created_at) VALUES (?, ?, ?, ?)',
                        [$conversationId, $assistantId, json_encode($result['proposal'], JSON_UNESCAPED_UNICODE), now_iso()]);
                    $proposals[] = ['id' => $id, 'course_id' => null, 'data' => $result['proposal']];
                    $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $result['block']['id'],
                        'content' => 'Proposition affichée au formateur. Il peut la valider pour créer le parcours sur la plateforme, ou demander des ajustements.'];
                }
                $add('user', $toolResults);
            }
            if ($conversation['title'] === 'Nouvelle conversation') {
                q('UPDATE ai_conversations SET title = ? WHERE id = ?', [mb_substr($text, 0, 80, 'UTF-8'), $conversationId]);
            }
            return $proposals;
        });

        $warning = '';
        foreach ($results as $result) {
            if ($result['error'] !== null) {
                $warning = "La proposition générée était incomplète ({$result['error']}). Demandez à l’assistant de la reprendre.";
            }
        }
        if ($stopReason === 'max_tokens') {
            $warning = 'La réponse a été interrompue car elle était trop longue.';
        }
        $release($warning);
        foreach ($proposals as $proposal) {
            fpa_send(['type' => 'proposal', 'proposal' => $proposal]);
        }
        if ($warning !== '') {
            fpa_send(['type' => 'error', 'message' => $warning]);
        }
        fpa_send(['type' => 'done']);
    } catch (Throwable $error) {
        if (!$error instanceof HttpError) {
            error_log('[AFPAREA][FPA] ' . $error);
        }
        $messageText = fpa_friendly_error($error);
        $release($messageText);
        if (!headers_sent()) {
            json_out(['error' => $messageText], $error instanceof HttpError ? $error->status : 502);
        }
        fpa_send(['type' => 'error', 'message' => $messageText]);
    }
    exit;
}

// ---------- Génération du parcours à partir d'une proposition validée ----------

function route_ai_generate(array $p): never
{
    $user = fpa_user();
    $proposalRow = row('SELECT p.* FROM ai_proposals p JOIN ai_conversations c ON c.id = p.conversation_id WHERE p.id = ? AND c.user_id = ?', [$p[0], $user['id']])
        ?? throw new HttpError(404, 'Proposition introuvable.');
    $proposal = json_decode($proposalRow['data'], true);
    $titreId = !empty(input()['titre_id']) ? int_in(input()['titre_id']) : null;
    if ($titreId && !val('SELECT 1 FROM titres WHERE id = ?', [$titreId])) {
        throw new HttpError(400, 'Titre professionnel introuvable.');
    }

    $courseId = tx(function () use ($user, $proposal, $titreId, $proposalRow) {
        $id = insert_course($user, ['title' => $proposal['titre'], 'summary' => $proposal['resume'], 'titre_id' => $titreId]);
        foreach ($proposal['modules'] as $module) {
            $moduleId = insert_node($id, null, 'module', ['title' => $module['titre'], 'description' => $module['description']]);
            foreach ($module['sequences'] as $sequence) {
                $sequenceId = insert_node($id, $moduleId, 'sequence', ['title' => $sequence['titre'], 'description' => $sequence['description']]);
                foreach ($sequence['seances'] as $seance) {
                    $seanceId = insert_node($id, $sequenceId, 'seance', [
                        'title' => $seance['titre'],
                        'description' => implode("\n", array_map(fn ($objectif) => "- $objectif", $seance['objectifs'])),
                        'duration_minutes' => (int) round($seance['duree_heures'] * 60),
                    ]);
                    foreach ($seance['activites'] as $activite) {
                        $data = $activite['type'] === 'depot' ? ['instructions' => $activite['contenu'], 'max_grade' => 20] : ['text' => $activite['contenu']];
                        insert_item($id, $seanceId, $activite['type'], $activite['titre'], $data);
                    }
                }
            }
        }
        q('UPDATE ai_proposals SET course_id = ? WHERE id = ?', [$id, $proposalRow['id']]);
        return $id;
    });
    json_out(['course_id' => $courseId], 201);
}
