<?php
/**
 * PlanFeature — enforcement dos limites e módulos por plano.
 *
 * Segue o mesmo molde de MonitorQuota/MonitorPermission (os únicos guards que
 * já rodavam em produção):
 *   • par duplo canX()/assertX() — o bool alimenta a UI, o assert protege o endpoint
 *   • assert emite 402 (cota/plano) ou 403 (permissão) + exit, sem exceção
 *   • TODO acesso a banco em try/catch com fail-soft: se a migration não rodou,
 *     ou o billing está incompleto, o sistema LIBERA em vez de travar
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ CHAVE MESTRA DE SEGURANÇA: app_settings.plan_enforcement_enabled         │
 * │                                                                          │
 * │ Vem DESLIGADA (default '0'). Com ela desligada os asserts NÃO bloqueiam: │
 * │ apenas gravam no error_log o que teriam bloqueado.                      │
 * │                                                                          │
 * │ Por quê: contas antigas vivem em planos legados cujas features foram     │
 * │ semeadas em 2026 (ex.: 'basico' tem webhooks=0). Ligar o enforcement no  │
 * │ mesmo deploy que sobe o código tiraria acesso de cliente pagante sem     │
 * │ aviso. O caminho seguro é: sobe o código -> observa o log por alguns     │
 * │ dias -> ajusta os planos das contas -> só então liga a chave.            │
 * │                                                                          │
 * │ Para ligar:                                                              │
 * │   INSERT INTO app_settings (config_key, config_value)                    │
 * │   VALUES ('plan_enforcement_enabled','1')                                │
 * │   ON DUPLICATE KEY UPDATE config_value='1';                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Semântica herdada de BillingGuard::getLimit():
 *   null  → ilimitado
 *   false → sem infra de billing  → LIBERA
 *   int   → limite (0 = desabilitado)
 */

namespace App\Billing;

require_once __DIR__ . '/BillingGuard.php';
require_once __DIR__ . '/../Core/Database.php';

use App\Core\Database;

final class PlanFeature
{
    /** Chave da trava mestra em app_settings. */
    public const ENFORCEMENT_KEY = 'plan_enforcement_enabled';

    /* Feature keys conhecidas (espelham a migration 110). */
    public const F_MAX_USERS   = 'max_users';
    public const F_MAX_FILIAIS = 'max_filiais';
    public const F_CHAT_INTERNO = 'chat_interno';
    public const F_WEBHOOKS     = 'webhooks';
    public const F_AASP         = 'aasp_enabled';
    public const F_PLANEJAMENTO = 'planejamento';
    public const F_ADVOGADOS    = 'advogados_associados';
    public const F_WHATSAPP     = 'whatsapp_enabled';
    public const F_TRIAGENS     = 'ai.triagens_mes';

    /** Rótulo amigável por feature, para a mensagem de erro. */
    private const LABELS = [
        self::F_MAX_USERS    => 'usuários',
        self::F_MAX_FILIAIS  => 'filiais',
        self::F_CHAT_INTERNO => 'Chat Interno',
        self::F_WEBHOOKS     => 'Webhooks e automações',
        self::F_AASP         => 'integração AASP',
        self::F_PLANEJAMENTO => 'Planejamento Comercial',
        self::F_ADVOGADOS    => 'advogados associados',
        self::F_WHATSAPP     => 'WhatsApp',
        self::F_TRIAGENS     => 'triagens do agente de IA',
    ];

    /** Cache por request (evita reler app_settings a cada checagem). */
    private static ?bool $enforcementCache = null;

    // ─────────────────────────────────────────────────────────────────────────
    // Trava mestra
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * O enforcement está ligado? Default: NÃO (modo observação).
     * Fail-soft: qualquer erro de leitura devolve false (não bloqueia).
     */
    public static function enforcementEnabled(): bool
    {
        if (self::$enforcementCache !== null) return self::$enforcementCache;
        try {
            $pdo = Database::getConnection();
            $st = $pdo->prepare('SELECT config_value FROM app_settings WHERE config_key = ? LIMIT 1');
            $st->execute([self::ENFORCEMENT_KEY]);
            $v = $st->fetchColumn();
            self::$enforcementCache = ($v !== false && trim((string)$v) === '1');
        } catch (\Throwable $_e) {
            self::$enforcementCache = false;
        }
        return self::$enforcementCache;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consulta (bool) — use para esconder/desabilitar UI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A feature está liberada para a conta?
     * Sem linha em plan_features → true (fail-soft, mesma regra do BillingGuard).
     */
    public static function isEnabled(int $accountId, string $featureKey): bool
    {
        if ($accountId <= 0) return true;
        try {
            return BillingGuard::isFeatureEnabled($accountId, $featureKey);
        } catch (\Throwable $_e) {
            return true;
        }
    }

    /** Limite numérico da feature. null = ilimitado, false = sem infra. */
    public static function getLimit(int $accountId, string $featureKey): int|null|false
    {
        try {
            return BillingGuard::getLimit($accountId, $featureKey);
        } catch (\Throwable $_e) {
            return false;
        }
    }

    /**
     * Quantos ainda cabem? PHP_INT_MAX quando ilimitado ou sem infra.
     * Nunca negativo.
     */
    public static function remaining(int $accountId, string $featureKey, int $used): int
    {
        $limit = self::getLimit($accountId, $featureKey);
        if ($limit === false || $limit === null) return PHP_INT_MAX;
        return max(0, $limit - $used);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enforcement (assert) — protege o endpoint
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Aborta com 402 se o módulo não faz parte do plano da conta.
     * Com a trava mestra desligada, apenas registra no log.
     */
    public static function assertEnabled(int $accountId, string $featureKey, ?string $label = null): void
    {
        if (self::isEnabled($accountId, $featureKey)) return;

        $label = $label ?? (self::LABELS[$featureKey] ?? $featureKey);
        if (!self::enforcementEnabled()) {
            self::observe($accountId, $featureKey, "modulo '$label' fora do plano");
            return;
        }
        self::deny(
            'FEATURE_NOT_IN_PLAN',
            "O recurso \"$label\" não está incluído no plano atual da sua conta.",
            ['feature' => $featureKey]
        );
    }

    /**
     * Aborta com 402 se criar mais um item estourar o limite da feature.
     * $used = quantos já existem hoje.
     */
    public static function assertUnderLimit(int $accountId, string $featureKey, int $used, ?string $label = null): void
    {
        $limit = self::getLimit($accountId, $featureKey);
        if ($limit === false || $limit === null) return;  // sem infra ou ilimitado

        if ($used < $limit) return;

        $label = $label ?? (self::LABELS[$featureKey] ?? $featureKey);
        if (!self::enforcementEnabled()) {
            self::observe($accountId, $featureKey, "limite de $label atingido (usado=$used, limite=$limit)");
            return;
        }
        $msg = $limit <= 0
            ? "O plano atual da sua conta não inclui $label."
            : "Limite de $label do plano atingido ($used de $limit). Amplie o plano ou contrate adicionais.";
        self::deny('PLAN_LIMIT_EXCEEDED', $msg, [
            'feature' => $featureKey,
            'limit'   => $limit,
            'used'    => $used,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Atalhos por recurso
    // ─────────────────────────────────────────────────────────────────────────

    /** Usuários ativos da conta (respeita deleted_at). */
    public static function countUsers(int $accountId): int
    {
        try {
            return BillingGuard::getCurrentCount($accountId, 'users');
        } catch (\Throwable $_e) {
            return 0;
        }
    }

    /** Aborta se a conta já bateu o teto de usuários do plano. */
    public static function assertCanAddUser(int $accountId): void
    {
        self::assertUnderLimit($accountId, self::F_MAX_USERS, self::countUsers($accountId), 'usuários');
    }

    /**
     * Triagens do agente de IA consumidas no mês corrente (1 sessão = 1 triagem).
     * Fail-soft: erro de leitura devolve 0 (não bloqueia atendimento).
     */
    public static function countTriagensMes(int $accountId): int
    {
        try {
            $pdo = Database::getConnection();
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM ai_intake_sessions
                  WHERE account_id = ?
                    AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            $st->execute([$accountId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $_e) {
            return 0;
        }
    }

    /**
     * Ainda há franquia de triagem de IA neste mês?
     *
     * NÃO usa assert/402 de propósito: o webhook do WhatsApp não pode devolver
     * erro HTTP para a Evolution por causa de cota. Quem chama decide o que
     * fazer (o IntakeEngine passa o atendimento para um humano).
     */
    public static function hasTriagemDisponivel(int $accountId): bool
    {
        $limit = self::getLimit($accountId, self::F_TRIAGENS);
        if ($limit === false || $limit === null) return true;
        if (!self::enforcementEnabled()) {
            if (self::countTriagensMes($accountId) >= $limit) {
                self::observe($accountId, self::F_TRIAGENS, "franquia de triagens esgotada (limite=$limit)");
            }
            return true;
        }
        return self::countTriagensMes($accountId) < $limit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internos
    // ─────────────────────────────────────────────────────────────────────────

    /** Modo observação: registra o que teria sido bloqueado. */
    private static function observe(int $accountId, string $featureKey, string $motivo): void
    {
        error_log("[PlanFeature][observacao] account=$accountId feature=$featureKey :: $motivo "
                . "(bloqueio NÃO aplicado — " . self::ENFORCEMENT_KEY . " desligado)");
    }

    /** 402 + JSON + exit. Mesmo formato de MonitorQuota::deny(). */
    private static function deny(string $code, string $msg, array $extra = []): never
    {
        error_log("[PlanFeature][bloqueio] $code :: $msg " . json_encode($extra));
        if (!headers_sent()) {
            http_response_code(402);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array_merge([
            'ok'    => false,
            'error' => $msg,
            'code'  => $code,
        ], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Variante para TELA (não API): mostra página de bloqueio em vez de JSON.
     * Use no topo dos .php de página, depois do assertAccountActive().
     */
    public static function assertEnabledPage(int $accountId, string $featureKey, ?string $label = null): void
    {
        if (self::isEnabled($accountId, $featureKey)) return;

        $label = $label ?? (self::LABELS[$featureKey] ?? $featureKey);
        if (!self::enforcementEnabled()) {
            self::observe($accountId, $featureKey, "tela '$label' fora do plano");
            return;
        }

        http_response_code(402);
        $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Recurso não incluído no plano</title>'
           . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0b1220;color:#e6edf7;'
           . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px}'
           . '.b{max-width:460px;text-align:center;background:#111c2f;border:1px solid #22344f;border-radius:16px;padding:38px 32px}'
           . 'h1{font-size:1.25rem;margin:0 0 12px}p{color:#93a4bd;line-height:1.6;font-size:.94rem;margin:0 0 24px}'
           . 'a{display:inline-block;background:#1E5299;color:#fff;text-decoration:none;padding:11px 22px;border-radius:9px;font-weight:600}'
           . '</style></head><body><div class="b">'
           . '<h1>' . $safe . ' não está no seu plano</h1>'
           . '<p>Este recurso faz parte de um plano superior. Fale com a nossa equipe para liberar o acesso.</p>'
           . '<a href="/dashboard.php">Voltar ao início</a>'
           . '</div></body></html>';
        exit;
    }
}
