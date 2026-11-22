<?php
declare(strict_types=1);

/** Échappement HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL interne : url('panier') => /index.php?p=panier */
function url(string $page = '', array $params = []): string
{
    $base = rtrim((string) cfg('base_url', ''), '/');
    $query = $page !== '' ? array_merge(['p' => $page], $params) : $params;
    return $base . '/index.php' . ($query ? '?' . http_build_query($query) : '');
}

/** URL absolue (QR codes, e-mails). */
function abs_url(string $page = '', array $params = []): string
{
    return rtrim((string) cfg('app_url', ''), '/') . url($page, $params);
}

/** URL d'un fichier statique, avec version pour le cache. */
function asset(string $path): string
{
    $base = rtrim((string) cfg('base_url', ''), '/');
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return $base . '/' . ltrim($path, '/') . $v;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** Redirection vers une URL interne uniquement (anti open-redirect). */
function safe_back(?string $to, string $fallback): string
{
    if ($to && str_starts_with($to, '/') && !str_starts_with($to, '//')) {
        return $to;
    }
    return $fallback;
}

function prix(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function to_cents(string $value): int
{
    $value = str_replace([' ', '€', ','], ['', '', '.'], trim($value));
    return (int) round(((float) $value) * 100);
}

// ---- Messages flash -------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- CSRF -----------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        flash('error', 'Votre session a expiré, merci de recommencer.');
        redirect(url());
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

// ---- Données de référence ----------------------------------------------------
function categories(): array
{
    static $c = null;
    return $c ??= db()->query('SELECT * FROM categories ORDER BY ordre, nom')->fetchAll();
}

function centres(bool $actifs = true): array
{
    return db()->query('SELECT * FROM centres' . ($actifs ? ' WHERE actif = 1' : '') . ' ORDER BY ordre, nom')->fetchAll();
}

function centre(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM centres WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function produits(?int $categorieId = null, bool $actifsSeulement = true): array
{
    $sql = 'SELECT p.*, c.nom AS categorie, c.slug AS categorie_slug FROM produits p JOIN categories c ON c.id = p.categorie_id WHERE 1=1';
    $args = [];
    if ($categorieId !== null) {
        $sql .= ' AND p.categorie_id = ?';
        $args[] = $categorieId;
    }
    if ($actifsSeulement) {
        $sql .= ' AND p.actif = 1';
    }
    $st = db()->prepare($sql . ' ORDER BY c.ordre, p.ordre, p.nom');
    $st->execute($args);
    return $st->fetchAll();
}

function produit(int $id): ?array
{
    $st = db()->prepare('SELECT p.*, c.nom AS categorie, c.slug AS categorie_slug, c.quota FROM produits p JOIN categories c ON c.id = p.categorie_id WHERE p.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ---- Rendu --------------------------------------------------------------------
function render(string $view, array $vars = [], string $layout = 'layout'): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/pages/' . $view . '.php';
    $content = ob_get_clean();
    require APP_ROOT . '/inc/' . $layout . '.php';
}

function not_found(): void
{
    http_response_code(404);
    render('404', ['title' => 'Page introuvable']);
    exit;
}

/** Export CSV compatible Excel (UTF-8 avec BOM, séparateur « ; »). */
function send_csv(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';');
    foreach ($rows as $r) {
        // Neutralise l'injection de formules dans Excel
        $r = array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r);
        fputcsv($out, $r, ';');
    }
    fclose($out);
    exit;
}

/** Pictogrammes SVG en ligne (style trait, comme sur les affiches). */
function icon(string $name): string
{
    $p = [
        'truck'     => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
        'shop'      => '<path d="M4 10v10h16V10M3 10l2-6h14l2 6zM3 10c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3M10 20v-5h4v5"/>',
        'lock'      => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M9.5 15.5l2 2 3.5-3.5"/>',
        'home'      => '<path d="M3 11l9-7 9 7M5 10v10h5v-6h4v6h5V10"/>',
        'bread'     => '<path d="M4 11a4 4 0 0 1 0-6h14a4 4 0 0 1 2 7.5V19a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-6.5A4 4 0 0 1 4 11z"/>',
        'croissant' => '<path d="M4 15c2-6 6-9 8-9s6 3 8 9c-2 1-4 0-5-1l-3 3-3-3c-1 1-3 2-5 1z"/><path d="M12 6v11M8.5 8.5 9 14m6.5-5.5L15 14"/>',
        'cart'      => '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="9" cy="20.5" r="1"/><circle cx="18" cy="20.5" r="1"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin'       => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'check'     => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'trash'     => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'list'      => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        'card'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'alert'     => '<path d="M12 3 2 20h20L12 3zM12 10v4M12 17h.01"/>',
        'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'box'       => '<path d="M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10"/>',
        'logout'    => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'download'  => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'print'     => '<path d="M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z"/>',
    ][$name] ?? '';
    return '<svg class="ico" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/** Carte produit (catalogue). */
function product_card(array $p, ?string $dateRef = null): string
{
    $restant = $dateRef ? stock_restant($p, $dateRef) : ($p['disponible'] ? null : 0);
    $epuise = $restant === 0;
    ob_start(); ?>
    <article class="card <?= $epuise ? 'is-out' : '' ?>" data-cat="<?= e($p['categorie_slug']) ?>">
        <a class="card-img" href="<?= e(url('produit', ['id' => $p['id']])) ?>">
            <img src="<?= e(asset($p['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="<?= e($p['nom']) ?>" loading="lazy" width="600" height="450">
            <span class="tag"><?= e($p['nom']) ?></span>
            <span class="stock <?= $epuise ? 'stock-out' : 'stock-in' ?>"><?= $epuise ? 'Épuisé' : ($restant !== null && $restant <= 10 ? 'Plus que ' . $restant : 'En stock') ?></span>
        </a>
        <div class="card-body">
            <h3><a href="<?= e(url('produit', ['id' => $p['id']])) ?>"><?= e($p['nom']) ?></a></h3>
            <p class="muted"><?= e($p['description']) ?></p>
            <?php if ($p['allergenes']): ?><p class="allerg"><strong>Allergènes :</strong> <?= e($p['allergenes']) ?></p><?php endif; ?>
            <div class="card-foot">
                <span class="price"><?= prix((int) $p['prix_cents']) ?></span>
                <?php if ($epuise): ?>
                    <span class="btn btn-sm btn-disabled">Épuisé</span>
                <?php else: ?>
                <form method="post" action="<?= e(url('panier-ajouter')) ?>" data-add-to-cart>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="btn btn-green btn-sm" type="submit"><?= icon('cart') ?> Ajouter</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}
