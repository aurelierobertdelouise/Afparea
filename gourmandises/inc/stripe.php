<?php
declare(strict_types=1);

/*
 * Paiement Stripe Checkout (CDC §5.4 / §6.4).
 * La saisie de la carte se fait exclusivement sur la page hébergée par Stripe
 * (certifiée PCI-DSS) : aucun numéro de carte ne transite par ce serveur.
 * Apple Pay / Google Pay sont proposés automatiquement par Stripe Checkout
 * lorsqu'ils sont activés dans le tableau de bord Stripe.
 */

function stripe_enabled(): bool
{
    return (string) cfg('stripe_secret_key', '') !== '';
}

function stripe_request(string $method, string $path, array $params = []): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => cfg('stripe_secret_key') . ':',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif ($params) {
        $opts[CURLOPT_URL] = 'https://api.stripe.com/v1/' . ltrim($path, '/') . '?' . http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Stripe injoignable : ' . $err);
    }
    $data = json_decode((string) $raw, true) ?: [];
    if ($code >= 400) {
        throw new RuntimeException('Erreur Stripe : ' . ($data['error']['message'] ?? $code));
    }
    return $data;
}

/** Crée une session Checkout et retourne son URL. */
function stripe_checkout(array $cmd, array $lignes): string
{
    $params = [
        'mode' => 'payment',
        'customer_email' => $cmd['email'],
        'client_reference_id' => $cmd['reference'],
        'metadata' => ['reference' => $cmd['reference'], 'commande_id' => $cmd['id']],
        'payment_intent_data' => ['description' => 'Gourmandises AFPAR ' . $cmd['reference'], 'metadata' => ['reference' => $cmd['reference']]],
        'success_url' => rtrim((string) cfg('app_url'), '/') . url('paiement-retour', ['ref' => $cmd['reference']]) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => rtrim((string) cfg('app_url'), '/') . url('paiement-annule', ['ref' => $cmd['reference']]),
        'expires_at'  => time() + 60 * max(30, (int) cfg('pending_expiry_min', 30)),
        'locale' => 'fr',
        'line_items' => [],
    ];
    foreach ($lignes as $l) {
        $params['line_items'][] = [
            'quantity' => (int) $l['quantite'],
            'price_data' => [
                'currency' => cfg('currency', 'eur'),
                'unit_amount' => (int) $l['prix_cents'],
                'product_data' => ['name' => $l['nom']],
            ],
        ];
    }
    $s = stripe_request('POST', 'checkout/sessions', $params);
    db()->prepare('UPDATE commandes SET stripe_session = ? WHERE id = ?')->execute([$s['id'], $cmd['id']]);
    return $s['url'];
}

/** Vérifie auprès de Stripe qu'une session est payée et marque la commande « Payée ». */
function stripe_confirm_session(string $sessionId): ?array
{
    $s = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
    if (($s['payment_status'] ?? '') !== 'paid') {
        return null;
    }
    $ref = (string) ($s['client_reference_id'] ?? '');
    return mark_order_paid($ref, $sessionId, (string) ($s['payment_intent'] ?? ''));
}

/** Vérification de la signature d'un webhook Stripe (en-tête Stripe-Signature). */
function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $header) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[$k][] = $v;
    }
    $t = (int) ($parts['t'][0] ?? 0);
    if (!$t || abs(time() - $t) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}
