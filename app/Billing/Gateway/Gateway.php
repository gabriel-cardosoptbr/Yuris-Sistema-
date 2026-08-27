<?php
namespace App\Billing\Gateway;

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

        $name = $name ?: \App\Core\EnvLoader::get('BILLING_GATEWAY', 'null');
        $nameLower = strtolower($name);
        if (isset(self::$instances[$nameLower])) return self::$instances[$nameLower];

        // P0 LGPD: NullGateway em produção é vulnerabilidade — bloqueia bootstrap.
        // Em prod exige BILLING_GATEWAY=stripe|mercadopago|... configurado explicitamente.
        $env = strtolower(\App\Core\EnvLoader::get('APP_ENV', 'development'));
        $isProd = in_array($env, ['production', 'prod'], true);
        $isNull = in_array($nameLower, ['null','noop','dev',''], true);
        if ($isProd && $isNull) {
            throw new \RuntimeException(
                'BILLING_GATEWAY não pode ser "null" em produção. ' .
                'Configure um adapter real (stripe/mercadopago/asaas) no .env.'
            );
        }

        $adapter = match ($nameLower) {
            'null','noop','dev' => new NullGateway(),
            // Adicionar aqui quando criar:
            // 'stripe'        => new StripeAdapter(),
            // 'mercadopago'   => new MercadoPagoAdapter(),
            default             => new NullGateway(),
        };

        return self::$instances[$nameLower] = $adapter;
    }

    public static function reset(): void { self::$instances = []; }
}
