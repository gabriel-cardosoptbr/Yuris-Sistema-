<?php
/**
 * Webhook receiver universal de gateways de pagamento.
 * Suporta múltiplos drivers via ?gateway=stripe|mercadopago|null
 *
 * Recebe POST com payload bruto, valida assinatura, deduplica via
 * gateway_events_received (idempotency), e processa eventos relevantes
 * (charge.succeeded, invoice.payment_failed, customer.subscription.updated, etc.)
 *
 * SEM AUTENTICAÇÃO DE USUÁRIO — a auth é via signature do gateway.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\ApiResponse;
use App\Core\Database;
use App\Billing\Gateway\Gateway;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ApiResponse::methodNotAllowed();

$gatewayName = $_GET['gateway'] ?? 'null';
$gateway     = Gateway::driver($gatewayName);

// 1. Lê payload bruto
$raw     = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

// 2. Valida assinatura
if (!$gateway->verifyWebhookSignature($raw, $headers)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// 3. Parse
try {
    $event = $gateway->parseWebhookEvent($raw, $headers);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not parse event: ' . $e->getMessage()]);
    exit;
}

// 4. Idempotency — guarda o event_id; se já recebido, retorna 200 OK sem reprocessar
$pdo = Database::getConnection();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO gateway_events_received (gateway, event_id, event_type, payload, processed, received_at)
         VALUES (:g, :eid, :et, :pl, 0, NOW())'
    );
    $stmt->execute([
        'g'   => $gateway->getName(),
        'eid' => $event['event_id'],
        'et'  => $event['type'],
        'pl'  => $raw,
    ]);
    $rowId = (int) $pdo->lastInsertId();
} catch (\PDOException $e) {
    // Duplicate key (já recebido) → 200 OK sem reprocessar
    if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit;
    }
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

// 5. Processa o evento
try {
    switch ($event['type']) {
        case 'invoice.payment_succeeded':
            $invId = $event['data']['invoice_id'] ?? null;
            if ($invId) {
                $pdo->prepare(
                    "UPDATE invoices SET status='paid', paid_at=NOW() WHERE gateway_invoice_id = :id"
                )->execute(['id' => $invId]);
            }
            break;
        case 'invoice.payment_failed':
            $invId = $event['data']['invoice_id'] ?? null;
            if ($invId) {
                $pdo->prepare(
                    "UPDATE invoices SET status='uncollectible' WHERE gateway_invoice_id = :id"
                )->execute(['id' => $invId]);
            }
            break;
        case 'customer.subscription.updated':
            $subId  = $event['data']['subscription_id'] ?? null;
            $status = $event['data']['status'] ?? null;
            if ($subId && $status) {
                $pdo->prepare(
                    "UPDATE subscriptions SET status = :s WHERE gateway_subscription_id = :id"
                )->execute(['id' => $subId, 's' => $status]);
            }
            break;
        case 'customer.subscription.deleted':
            $subId = $event['data']['subscription_id'] ?? null;
            if ($subId) {
                $pdo->prepare(
                    "UPDATE subscriptions SET status='canceled', canceled_at=NOW() WHERE gateway_subscription_id = :id"
                )->execute(['id' => $subId]);
            }
            break;
        default:
            // tipo desconhecido — ainda assim marca como processed
            break;
    }
    $pdo->prepare(
        'UPDATE gateway_events_received SET processed=1, processed_at=NOW() WHERE id = :id'
    )->execute(['id' => $rowId]);

    echo json_encode(['ok' => true, 'processed' => true, 'event_type' => $event['type']]);
} catch (\Throwable $e) {
    $pdo->prepare(
        'UPDATE gateway_events_received SET error = :e WHERE id = :id'
    )->execute(['e' => $e->getMessage(), 'id' => $rowId]);
    http_response_code(500);
    echo json_encode(['error' => 'Processing failed: ' . $e->getMessage()]);
}
