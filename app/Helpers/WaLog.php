<?php
namespace App\Helpers;

/**
 * WaLog — log de UMA linha, em JSON, com chaves padronizadas, para o modulo WhatsApp/Agente
 * (Onda 4 / 4D). Substitui, aos poucos, os error_log ad-hoc por linhas consultaveis (grep no
 * docker vira busca estruturada). NAO derruba o fluxo (so escreve no error_log).
 *
 * REGRA F4: PII nunca em claro. line() mascara automaticamente qualquer sequencia de 5+ digitos
 * nos valores string do contexto (telefone/jid). Ainda assim, prefira passar jid ja mascarado.
 *
 * Uso: WaLog::line('webhook_silent', ['instance' => $id, 'last' => $ts], 'warn');
 * Saida: [wa] {"t":"webhook_silent","lvl":"warn","instance":12,"last":"..."}
 */
final class WaLog
{
    public static function line(string $code, array $ctx = [], string $level = 'info'): void
    {
        foreach ($ctx as $k => $v) {
            if (is_string($v)) $ctx[$k] = preg_replace('/\d{5,}/', '*****', $v);
        }
        $payload = array_merge(['t' => substr($code, 0, 40), 'lvl' => $level], $ctx);
        error_log('[wa] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
