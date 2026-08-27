<?php
namespace App\Integracoes;

/**
 * WebhookRetryPolicy — backoff exponencial para retries de webhook.
 *
 * Sequencia padrao: 60s, 300s (5min), 1800s (30min).
 * tentativa eh o numero da tentativa que acabou de FALHAR (1-based).
 * O proximo scheduled_retry_at = NOW() + delay[tentativa - 1].
 */
class WebhookRetryPolicy
{
    /** Delays (segundos) por indice. Apos esgotar, marca 'failed'. */
    private const DELAYS = [60, 300, 1800];

    /**
     * Retorna DATETIME (Y-m-d H:i:s) do proximo agendamento para retry.
     * $tentativa = tentativa que acabou de falhar (1 = 1a, 2 = 2a, ...).
     */
    public static function nextAttemptAt(int $tentativa): string
    {
        $idx   = max(0, min(count(self::DELAYS) - 1, $tentativa - 1));
        $delay = self::DELAYS[$idx];
        return date('Y-m-d H:i:s', time() + $delay);
    }

    /** True se ainda ha tentativas pendentes considerando max_retries do endpoint. */
    public static function canRetry(int $tentativa, int $maxRetries): bool
    {
        return $tentativa < max(1, $maxRetries);
    }
}
