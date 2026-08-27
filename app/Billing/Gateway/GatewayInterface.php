<?php
namespace App\Billing\Gateway;

/**
 * GatewayInterface — contrato para integração com gateways de pagamento.
 *
 * Adapters reais (StripeAdapter, MercadoPagoAdapter, PagarMeAdapter) devem
 * implementar esses métodos. O sistema chama via Gateway::driver('stripe').
 *
 * IMPORTANTE: nenhum adapter deve armazenar PAN/CVV. Trabalha apenas com
 * tokens fornecidos pelo SDK do gateway (Stripe.js, MercadoPago.js, etc).
 */
interface GatewayInterface
{
    /** Identificador único do gateway (slug). Ex: 'stripe', 'mercadopago'. */
    public function getName(): string;

    /**
     * Cria/recupera um customer no gateway pra esta account.
     * Retorna ID externo (ex: cus_XXX).
     */
    public function createCustomer(array $accountData): string;

    /**
     * Anexa um payment_method (token) ao customer.
     * Retorna ID externo do método.
     */
    public function attachPaymentMethod(string $customerId, string $token): string;

    /**
     * Cria uma assinatura recorrente.
     * Retorna ID externo da subscription.
     */
    public function createSubscription(array $params): string;

    /**
     * Cancela uma assinatura. Se $atPeriodEnd=true, mantém ativa até o fim do ciclo.
     */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): bool;

    /**
     * Cria uma fatura avulsa (one-off).
     */
    public function createInvoice(array $params): array;

    /**
     * Processa um evento de webhook (já validado/parsed).
     * Retorna array com ['type' => ..., 'data' => ..., 'event_id' => ...].
     */
    public function parseWebhookEvent(string $rawPayload, array $headers): array;

    /**
     * Valida assinatura do webhook (timing-safe).
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;
}
