<?php
namespace App\Services\Billing;

/**
 * Gateway — factory/router que retorna o adapter ativo.
 *
 * Driver é definido em .env:
 *   BILLING_GATEWAY=null         (dev local, default)
 *   BILLING_GATEWAY=stripe       (em prod, requer StripeAdapter)
 *   BILLING_GATEWAY=mercadopago  (em prod, requer MercadoPagoAdapter)
 *
 * Uso:
 *   $gw = Gateway::driver();   // retorna o adapter ativo
 *   $gw->createSubscription([...]);
 *
 *   $gw = Gateway::driver('stripe');  // força um driver específico
 */
final class Gateway
{
    private static array $instances = [];

    public static function driver(?string $name = null): GatewayInterface
    {
        require_once __DIR__ . '/GatewayInterface.php';
        require_once __DIR__ . '/NullGateway.php';

        $name = $name ?: \App\Helpers\EnvLoader::get('BILLING_GATEWAY', 'null');
        if (isset(self::$instances[$name])) return self::$instances[$name];

        $adapter = match (strtolower($name)) {
            'null','noop','dev' => new NullGateway(),
            // Adicionar aqui quando criar:
            // 'stripe'        => new StripeAdapter(),
            // 'mercadopago'   => new MercadoPagoAdapter(),
            default             => new NullGateway(),
        };

        return self::$instances[$name] = $adapter;
    }

    public static function reset(): void { self::$instances = []; }
}
