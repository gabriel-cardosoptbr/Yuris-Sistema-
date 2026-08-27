<?php
namespace App\Billing\Gateway;

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

    /**
     * Validação de webhook — FAIL-CLOSED por padrão (LGPD P0).
     *
     * Antes da correção (Fase 0 LGPD), retornava `true` incondicionalmente, o
     * que permitia atacante forjar eventos (`invoice.paid`, `subscription.active`)
     * sem credenciais. Agora:
     *   • produção (APP_ENV=production) → SEMPRE nega. Use adapter real
     *     (StripeAdapter, MercadoPagoAdapter, etc.) em prod.
     *   • dev/local → aceita SOMENTE requisições de loopback (127.0.0.1, ::1).
     *
     * @return bool true se aceita, false caso contrário.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $env = strtolower(\App\Core\EnvLoader::get('APP_ENV', 'development'));
        if ($env === 'production' || $env === 'prod') {
            // Em prod, NullGateway não é confiável — exija driver real.
            error_log('[NullGateway] Tentativa de webhook em produção com gateway nulo — negado.');
            return false;
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $loopback = in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);
        if (!$loopback) {
            error_log("[NullGateway] Webhook recusado: REMOTE_ADDR={$remote} não é loopback.");
            return false;
        }
        return true;
    }
}
