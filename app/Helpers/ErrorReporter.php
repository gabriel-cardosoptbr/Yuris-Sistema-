<?php
namespace App\Helpers;

/**
 * ErrorReporter — padroniza tratamento de exceções em endpoints (LGPD P1).
 *
 * Antes deste helper, vários endpoints retornavam `$e->getMessage()` direto
 * no JSON. Isso vazava nomes de tabela, colunas, paths e stack traces para
 * o cliente — útil para atacante mapear o schema e escalar para extração
 * de dados pessoais (Art. 46 LGPD).
 *
 * Comportamento:
 *   • Em DEV (APP_ENV != production) — devolve mensagem real (debug fácil)
 *   • Em PROD                         — devolve mensagem genérica + correlation_id
 *
 * Em ambos os casos: log COMPLETO em error_log (com correlation_id) pra
 * troubleshooting via suporte.
 *
 * USO:
 *   try {
 *     // ... código que pode lançar
 *   } catch (\Throwable $e) {
 *     \App\Helpers\ErrorReporter::handle($e);  // termina request
 *   }
 *
 *   // Variante: customizar mensagem pública e status
 *   ErrorReporter::handle($e, 500, 'Falha ao processar pagamento.');
 */
final class ErrorReporter
{
    public static function handle(\Throwable $e, int $httpCode = 500, ?string $publicMessage = null): never
    {
        $correlationId = bin2hex(random_bytes(6));   // 12 hex = curto e único o suficiente

        // Sempre loga internamente — sem expor ao cliente
        error_log(sprintf(
            '[ErrorReporter cid=%s] %s in %s:%d — %s',
            $correlationId,
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        ));

        // Modo dev: devolve detalhes (mensagem + cid)
        if (!class_exists('App\\Helpers\\EnvLoader')) {
            require_once __DIR__ . '/EnvLoader.php';
        }
        $env = strtolower(EnvLoader::get('APP_ENV', 'development'));
        $isProd = in_array($env, ['production', 'prod'], true);

        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        if ($isProd) {
            // Mensagem genérica + cid para suporte rastrear
            $body = [
                'ok'             => false,
                'error'          => $publicMessage ?: 'Erro interno. Contate o suporte com o código abaixo.',
                'correlation_id' => $correlationId,
            ];
        } else {
            // Dev: detalhe completo pra facilitar debug local
            $body = [
                'ok'             => false,
                'error'          => $publicMessage ?: $e->getMessage(),
                'correlation_id' => $correlationId,
                'debug' => [
                    'class' => get_class($e),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ],
            ];
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Variante que NÃO termina request — útil quando o caller quer continuar
     * fluxo após logar (ex: try/catch dentro de loop). Retorna o correlation_id.
     */
    public static function log(\Throwable $e, ?string $context = null): string
    {
        $cid = bin2hex(random_bytes(6));
        error_log(sprintf(
            '[ErrorReporter cid=%s%s] %s in %s:%d — %s',
            $cid,
            $context ? " ctx={$context}" : '',
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        ));
        return $cid;
    }
}
