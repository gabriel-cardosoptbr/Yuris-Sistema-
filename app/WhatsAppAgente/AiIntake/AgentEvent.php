<?php
namespace App\WhatsAppAgente\AiIntake;

/**
 * AgentEvent — log estruturado do agente de IA em ai_agent_events (Onda 4 / 4D).
 *
 * Substitui, aos poucos, os error_log ad-hoc truncados a 240 chars do IntakeEngine
 * por eventos consultaveis (por conta, por codigo, no tempo) que alimentam o card
 * "Saude WhatsApp" no Painel Master e o alerta de webhook silencioso.
 *
 * REGRAS:
 *  - Best-effort: telemetria NUNCA derruba o fluxo. Qualquer falha e engolida.
 *  - $detail e JSON e NAO deve conter PII crua. Mascare telefone/jid ANTES de logar
 *    (padrao F4: preg_replace('/\d{5,}/', '*****', ...)).
 *  - $code e um slug curto e estavel (<=40 chars): provider_error, price_fallback,
 *    superseded, circuit_breaker, paused, resumed, webhook_silent, ...
 */
final class AgentEvent
{
    /** Codigos conhecidos (documentacao viva; nao e allowlist rigida). */
    public const CODES = [
        'provider_error', 'price_fallback', 'superseded', 'circuit_breaker',
        'paused', 'resumed', 'webhook_silent', 'llm_ok', 'handoff',
    ];

    public static function log(
        \PDO $pdo,
        string $code,
        array $detail = [],
        string $level = 'info',
        ?int $accountId = null,
        ?int $sessionId = null,
        ?int $instanceId = null
    ): void {
        try {
            $st = $pdo->prepare(
                "INSERT INTO ai_agent_events
                    (account_id, session_id, instance_id, level, code, detail, created_at)
                 VALUES (:account_id, :session_id, :instance_id, :level, :code, :detail, :created_at)"
            );
            $st->execute([
                ':account_id'  => $accountId,
                ':session_id'  => $sessionId,
                ':instance_id' => $instanceId,
                ':level'       => in_array($level, ['info', 'warn', 'error'], true) ? $level : 'info',
                ':code'        => substr($code, 0, 40),
                ':detail'      => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ':created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $_) {
            // Best-effort: a tabela pode nao existir (migration 107 nao aplicada) ou o
            // banco estar indisponivel. Em nenhum caso a telemetria pode quebrar o agente.
        }
    }
}
