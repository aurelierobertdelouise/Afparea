<?php
declare(strict_types=1);

/** Échappement HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL interne : url('panier') => index.php?p=panier */
function url(string $page = '', array $params = []): string
{
    $base = rtrim((string) cfg('base_url', ''), '/');
    $query = $page !== '' ? array_merge(['p' => $page], $params) : $params;
    return $base . '/index.php' . ($query ? '?' . http_build_query($query) : '');
}

/** URL d'un fichier statique. */
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

function prix(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

/** Convertit « 1,20 » ou « 1.2 » en centimes. */
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
        redirect($_SERVER['HTTP_REFERER'] ?? url());
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

// ---- Admin ----------------------------------------------------------------
function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect(url('admin-login'));
    }
}

// ---- Données ----------------------------------------------------------------
function categories(): array
{
    return db()->query('SELECT * FROM categories ORDER BY ordre, nom')->fetchAll();
}

function categorie_by_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM categories WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function produits(?int $categorieId = null, bool $actifsSeulement = true): array
{
    $sql = 'SELECT p.*, c.nom AS categorie, c.slug AS categorie_slug
            FROM produits p JOIN categories c ON c.id = p.categorie_id WHERE 1=1';
    $args = [];
    if ($categorieId !== null) {
        $sql .= ' AND p.categorie_id = ?';
        $args[] = $categorieId;
    }
    if ($actifsSeulement) {
        $sql .= ' AND p.actif = 1';
    }
    $sql .= ' ORDER BY c.ordre, p.ordre, p.nom';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function produit(int $id): ?array
{
    $st = db()->prepare('SELECT p.*, c.nom AS categorie, c.slug AS categorie_slug FROM produits p JOIN categories c ON c.id = p.categorie_id WHERE p.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function statuts(): array
{
    return [
        'nouvelle'      => 'Nouvelle',
        'preparation'   => 'En préparation',
        'prete'         => 'Prête',
        'en_livraison'  => 'En livraison',
        'terminee'      => 'Terminée',
        'annulee'       => 'Annulée',
    ];
}

function statut_label(string $s): string
{
    return statuts()[$s] ?? $s;
}

function mode_label(string $m): string
{
    return $m === 'livraison' ? 'Livraison' : 'Click & Collect';
}

/** Liste des dates proposées à la commande. */
function dates_disponibles(): array
{
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $out = [];
    $d = new DateTimeImmutable('today');
    $d = $d->modify('+' . (int) cfg('lead_days', 0) . ' day');
    for ($i = 0; $i < (int) cfg('max_days', 14); $i++) {
        $day = $d->modify("+$i day");
        $label = ucfirst($jours[(int) $day->format('w')]) . ' ' . $day->format('j') . ' ' . $mois[(int) $day->format('n')];
        if ($i === 0 && (int) cfg('lead_days', 0) === 0) {
            $label = "Aujourd'hui ($label)";
        }
        $out[$day->format('Y-m-d')] = $label;
    }
    return $out;
}

function date_fr(string $ymd): string
{
    return dates_disponibles()[$ymd] ?? DateTime::createFromFormat('Y-m-d', $ymd)->format('d/m/Y');
}

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
    ][$name] ?? '';
    return '<svg class="ico" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/** Carte produit réutilisable. */
function product_card(array $p): string
{
    ob_start(); ?>
    <article class="card">
        <a class="card-img" href="<?= e(url('produit', ['id' => $p['id']])) ?>">
            <img src="<?= e(asset($p['image'] ?: 'assets/img/logo-afpar.png')) ?>" alt="<?= e($p['nom']) ?>" loading="lazy" width="600" height="450">
            <span class="tag"><?= e($p['nom']) ?></span>
        </a>
        <div class="card-body">
            <h3><a href="<?= e(url('produit', ['id' => $p['id']])) ?>"><?= e($p['nom']) ?></a></h3>
            <p class="muted"><?= e($p['description']) ?></p>
            <div class="card-foot">
                <span class="price"><?= prix((int) $p['prix_cents']) ?></span>
                <form method="post" action="<?= e(url('panier-ajouter')) ?>" data-add-to-cart>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="btn btn-green btn-sm" type="submit"><?= icon('cart') ?> Ajouter</button>
                </form>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}
