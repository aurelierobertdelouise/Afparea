<?php
declare(strict_types=1);

/** Panier stocké en session : [produit_id => quantité]. */
function cart_raw(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_set(int $id, int $qty): void
{
    $qty = max(0, min(50, $qty));
    if ($qty === 0) {
        unset($_SESSION['cart'][$id]);
    } else {
        $_SESSION['cart'][$id] = $qty;
    }
}

function cart_add(int $id, int $qty = 1): void
{
    cart_set($id, (cart_raw()[$id] ?? 0) + $qty);
}

function cart_clear(): void
{
    unset($_SESSION['cart']);
}

function cart_count(): int
{
    return array_sum(cart_raw());
}

/** Lignes du panier avec prix à jour depuis la base (produits retirés supprimés). */
function cart_lines(): array
{
    $raw = cart_raw();
    if (!$raw) {
        return [];
    }
    $ids = array_map('intval', array_keys($raw));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT p.*, c.nom AS categorie, c.slug AS categorie_slug FROM produits p JOIN categories c ON c.id = p.categorie_id
        WHERE p.actif = 1 AND p.id IN ($in) ORDER BY c.ordre, p.ordre");
    $st->execute($ids);
    $lines = [];
    $seen = [];
    foreach ($st->fetchAll() as $p) {
        $q = (int) $raw[$p['id']];
        $seen[] = (int) $p['id'];
        $lines[] = ['produit' => $p, 'quantite' => $q, 'total' => $q * (int) $p['prix_cents']];
    }
    foreach (array_diff($ids, $seen) as $gone) {
        unset($_SESSION['cart'][$gone]);
    }
    return $lines;
}

function cart_total(array $lines): int
{
    return array_sum(array_column($lines, 'total'));
}
