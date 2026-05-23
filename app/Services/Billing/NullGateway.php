<?php
namespace App\Services\Billing;

/**
 * NullGateway — adapter "noop" usado em desenvolvimento e como fallback
 * quando nenhum gateway real foi configurado.
 *
 * Permite testar o fluxo de assinaturas/invoices localmente sem depender
 * de credenciais Stripe/MP. Eventos de webhook são aceitos sem validação.
 *
 * SUBSTITUIR EM PROD por adapter real (StripeAdapter / MercadoPagoAdapter).
 */
class NullGateway implements GatewayInterface
{
    public function getName(): string { return 'null'; }

    public function createCustomer(array $accountData): string
    {
        return 'null_cus_' . bin2hex(random_bytes(6));
    }

    public function attachPaymentMethod(string $customerId, string $token): string
    {
        return 'null_pm_' . bin2hex(random_bytes(6));
    }

    public function createSubscription(array $params): string
    {
        return 'null_sub_' . bin2hex(random_bytes(6));
    }

    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): bool
    {
        return true;
    }

    public function createInvoice(array $params): array
    {
        return [
            'id'     => 'null_inv_' . bin2hex(random_bytes(6)),
            'status' => 'paid',
            'url'    => null,
        ];
    }

    public function parseWebhookEvent(string $rawPayload, array $headers): array
    {
        $data = json_decode($rawPayload, true) ?: [];
        return [
            'event_id' => $data['id']   ?? ('null_evt_' . bin2hex(random_bytes(6))),
            'type'     => $data['type'] ?? 'unknown',
            'data'     => $data['data'] ?? [],
        ];
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        return true; // dev mode — sempre aceita
    }
}
