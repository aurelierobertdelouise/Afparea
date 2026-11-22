<?php
declare(strict_types=1);

/*
 * Webhook Stripe : confirme les paiements même si le salarié ferme son navigateur
 * avant le retour sur le site. À déclarer dans Stripe > Développeurs > Webhooks :
 *   URL      : https://<votre-domaine>/stripe-webhook.php
 *   Événements : checkout.session.completed, checkout.session.async_payment_succeeded,
 *                checkout.session.expired
 */

require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json');

$secret = (string) cfg('stripe_webhook_secret', '');
$payload = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $secret === '' || !stripe_verify_signature($payload, $sig, $secret)) {
    http_response_code(400);
    echo json_encode(['error' => 'signature invalide']);
    exit;
}

$event = json_decode($payload, true) ?: [];
$obj = $event['data']['object'] ?? [];
$ref = (string) ($obj['client_reference_id'] ?? ($obj['metadata']['reference'] ?? ''));

try {
    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            if (($obj['payment_status'] ?? '') === 'paid' && $ref !== '') {
                mark_order_paid($ref, (string) $obj['id'], (string) ($obj['payment_intent'] ?? ''));
            }
            break;
        case 'checkout.session.expired':
            if ($ref !== '') {
                db()->prepare("UPDATE commandes SET statut = 'annulee' WHERE reference = ? AND statut = 'en_attente'")->execute([$ref]);
            }
            break;
    }
} catch (Throwable $e) {
    error_log('[gourmandises] webhook : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'traitement']);
    exit;
}

echo json_encode(['received' => true]);
