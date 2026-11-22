<?php
declare(strict_types=1);

/** Panier stocké en session : [produit_id => quantité]. */
function cart_raw(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_set(int $id, int $qty): void
{
    $qty = max(0, min(99, $qty));
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

/** Lignes du panier avec prix à jour depuis la base. */
function cart_lines(): array
{
    $raw = cart_raw();
    if (!$raw) {
        return [];
    }
    $ids = array_map('intval', array_keys($raw));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM produits WHERE actif = 1 AND id IN ($in)");
    $st->execute($ids);
    $lines = [];
    foreach ($st->fetchAll() as $p) {
        $q = (int) $raw[$p['id']];
        $lines[] = [
            'produit'  => $p,
            'quantite' => $q,
            'total'    => $q * (int) $p['prix_cents'],
        ];
    }
    // Retire les produits devenus indisponibles
    $valid = array_column(array_column($lines, 'produit'), 'id');
    foreach ($ids as $id) {
        if (!in_array($id, array_map('intval', $valid), true)) {
            unset($_SESSION['cart'][$id]);
        }
    }
    return $lines;
}

function cart_subtotal(array $lines): int
{
    return array_sum(array_column($lines, 'total'));
}

function delivery_fee(int $subtotal, string $mode): int
{
    if ($mode !== 'livraison') {
        return 0;
    }
    $free = (int) cfg('free_delivery_from_cents', 0);
    if ($free > 0 && $subtotal >= $free) {
        return 0;
    }
    return (int) cfg('delivery_fee_cents', 0);
}
