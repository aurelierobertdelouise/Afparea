<?php

declare(strict_types=1);

/** Socle : configuration, base de données, erreurs, lecture et validation des entrées. */

const APP_ROOT = __DIR__ . '/..';
const UPLOAD_DIR = APP_ROOT . '/data/uploads';

class HttpError extends Exception
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}

/** Configuration : config.php, surchargée par les variables d'environnement AFPAREA_* (tests). */
function config(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $file = APP_ROOT . '/config.php';
        $config = is_file($file) ? (array) require $file : [];
    }
    $env = getenv('AFPAREA_' . strtoupper($key));
    if ($env !== false && $env !== '') {
        return $env;
    }
    $value = $config[$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

function check_requirements(): void
{
    if (PHP_VERSION_ID < 80100) {
        throw new HttpError(500, 'AFPAREA nécessite PHP 8.1 ou plus récent. Changez la version de PHP dans le panneau de votre hébergement.');
    }
    $missing = array_filter(['pdo_mysql', 'mbstring', 'fileinfo', 'zip', 'curl', 'simplexml'], fn ($ext) => !extension_loaded($ext));
    if ($missing) {
        throw new HttpError(500, 'Extensions PHP manquantes : ' . implode(', ', $missing) . '. Activez-les dans le panneau de votre hébergement.');
    }
    if (config('db_name') === null) {
        throw new HttpError(500, 'Configuration absente : copiez config.sample.php en config.php et renseignez l’accès à la base de données.');
    }
}

/** Connexion à la base. $reconnect rouvre la connexion (après une longue attente, le serveur MySQL peut l'avoir fermée). */
function db(bool $reconnect = false): PDO
{
    static $pdo = null;
    if ($reconnect) {
        $pdo = null;
    }
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db_host', 'localhost'), (int) config('db_port', 3306), config('db_name'));
        try {
            $pdo = new PDO($dsn, (string) config('db_user'), (string) config('db_password', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('[AFPAREA] connexion base : ' . $e->getMessage());
            throw new HttpError(500, 'Connexion à la base de données impossible. Vérifiez les paramètres de config.php.');
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    foreach (array_values($params) as $index => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : (is_bool($value) ? PDO::PARAM_BOOL : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $statement->bindValue($index + 1, is_float($value) ? (string) $value : $value, $type);
    }
    $statement->execute();
    return $statement;
}

function row(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = []): mixed
{
    $value = q($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

function col(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function insert(string $sql, array $params = []): int
{
    q($sql, $params);
    return (int) db()->lastInsertId();
}

/** Exécute la fonction dans une transaction (annulée si une exception survient). */
function tx(callable $fn): mixed
{
    db()->beginTransaction();
    try {
        $result = $fn();
        db()->commit();
        return $result;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/** Date et heure courantes, au format ISO UTC (comparable comme une chaîne). */
function now_iso(?int $timestamp = null): string
{
    return gmdate('Y-m-d\TH:i:s', $timestamp ?? time()) . '.000Z';
}

// ---------- Réponses ----------

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ---------- Entrées ----------

/** Corps de la requête : JSON, ou champs de formulaire (envois de fichiers). */
function input(): array
{
    static $input = null;
    if ($input === null) {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
            if (strlen($raw) > 2 * 1024 * 1024) {
                throw new HttpError(400, 'Requête invalide.');
            }
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new HttpError(400, 'Requête invalide.');
            }
            $input = $decoded;
        } else {
            $input = $_POST;
        }
    }
    return $input;
}

function text_len(string $value): int
{
    return mb_strlen($value, 'UTF-8');
}

function str_in(mixed $value, int $max = 500, bool $required = false, string $label = 'Champ'): string
{
    $text = is_string($value) ? trim($value) : '';
    if (!mb_check_encoding($text, 'UTF-8')) {
        throw new HttpError(400, "$label invalide.");
    }
    if ($required && $text === '') {
        throw new HttpError(400, "$label obligatoire.");
    }
    if (text_len($text) > $max) {
        throw new HttpError(400, "$label trop long ($max caractères maximum).");
    }
    return $text;
}

function int_in(mixed $value, string $label = 'Identifiant'): int
{
    if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,15}$/', $value))) {
        return (int) $value;
    }
    throw new HttpError(400, "$label invalide.");
}

/** Date ISO (ou AAAA-MM-JJ) → ISO UTC, ou null ; refuse les valeurs invalides. */
function iso_in(mixed $value, string $label = 'Date'): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
        throw new HttpError(400, "$label invalide.");
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Exception) {
        throw new HttpError(400, "$label invalide.");
    }
    return now_iso($date->getTimestamp());
}

function bool_in(mixed $value): bool
{
    return !($value === false || $value === 0 || $value === '0' || $value === '' || $value === null || $value === 'false');
}

const EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u';

function url_in(mixed $value): string
{
    $text = str_in($value, 2000, true, 'Adresse');
    $scheme = strtolower((string) parse_url($text, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || !parse_url($text, PHP_URL_HOST)) {
        throw new HttpError(400, 'Adresse web invalide (elle doit commencer par https://).');
    }
    return $text;
}

// ---------- Fichiers envoyés ----------

function ini_bytes(string $key): int
{
    $value = trim((string) ini_get($key));
    $number = (float) $value;
    return (int) match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

function max_upload_bytes(): int
{
    $limits = array_filter([ini_bytes('upload_max_filesize'), ini_bytes('post_max_size'), (int) config('max_upload_mb', 50) * 1024 * 1024]);
    return (int) min($limits);
}

/**
 * Fichiers reçus dans un champ (simple ou multiple « champ[] »).
 * @return list<array{tmp:string,name:string,size:int,mime:string}>
 */
function uploads(string $field): array
{
    $limit = round(max_upload_bytes() / 1024 / 1024);
    // Quand l'envoi dépasse post_max_size, PHP vide $_POST et $_FILES sans autre signal.
    if (!$_FILES && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
        throw new HttpError(400, "Envoi trop volumineux ($limit Mo maximum).");
    }
    if (!isset($_FILES[$field])) {
        return [];
    }
    $entry = $_FILES[$field];
    $count = is_array($entry['name']) ? count($entry['name']) : 1;
    $files = [];
    for ($i = 0; $i < $count; $i++) {
        $get = fn (string $key) => is_array($entry[$key]) ? $entry[$key][$i] : $entry[$key];
        $error = $get('error');
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError(400, "Fichier trop volumineux ($limit Mo maximum).");
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($get('tmp_name'))) {
            throw new HttpError(400, 'L’envoi du fichier a échoué. Réessayez.');
        }
        if ($get('size') > max_upload_bytes()) {
            throw new HttpError(400, "Fichier trop volumineux ($limit Mo maximum).");
        }
        $name = mb_substr(basename(str_replace('\\', '/', (string) $get('name'))), 0, 200, 'UTF-8');
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = 'fichier';
        }
        $files[] = [
            'tmp' => $get('tmp_name'),
            'name' => $name !== '' ? $name : 'fichier',
            'size' => (int) $get('size'),
            // Le type est détecté d'après le contenu, pas d'après la déclaration du navigateur.
            'mime' => (new finfo(FILEINFO_MIME_TYPE))->file($get('tmp_name')) ?: 'application/octet-stream',
        ];
    }
    if (count($files) > 20) {
        throw new HttpError(400, 'Trop de fichiers à la fois (20 maximum).');
    }
    return $files;
}

/** Déplace un fichier reçu dans le dossier de stockage, sous un nom aléatoire. */
function store_upload(array $file): string
{
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true)) {
        throw new HttpError(500, 'Le dossier data/uploads n’est pas accessible en écriture.');
    }
    $stored = bin2hex(random_bytes(20));
    if (!move_uploaded_file($file['tmp'], UPLOAD_DIR . '/' . $stored)) {
        throw new HttpError(500, 'Le dossier data/uploads n’est pas accessible en écriture.');
    }
    return $stored;
}

/** Supprime du disque les fichiers qui ne sont plus référencés en base (après suppressions en cascade). */
function sweep_orphan_files(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        return;
    }
    $known = array_flip(array_merge(col('SELECT stored_name FROM files'), col('SELECT stored_name FROM ai_documents')));
    foreach (scandir(UPLOAD_DIR) as $name) {
        // Les fichiers tout juste reçus (moins d'une minute) peuvent ne pas encore être enregistrés en base.
        if (preg_match('/^[0-9a-f]{40}$/', $name) && !isset($known[$name]) && filemtime(UPLOAD_DIR . '/' . $name) < time() - 60) {
            @unlink(UPLOAD_DIR . '/' . $name);
        }
    }
}
