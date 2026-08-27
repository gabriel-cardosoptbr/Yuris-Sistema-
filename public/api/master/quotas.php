<?php
/**
 * Painel Master — gestão de cotas (account_quota_overrides).
 * Acesso: super_admin (apenas).
 *
 * Modelo comercial (D1-D10 aprovados): plano não inclui monitoramento.
 * Master libera quantidade contratada por tenant via grants/purchases
 * armazenados em account_quota_overrides.
 *
 *   GET    /api/master/quotas.php?account_id=X       → status + overrides ativos
 *   POST   /api/master/quotas.php                    → cria override
 *   DELETE /api/master/quotas.php?id=Y               → revoga override
 *
 * @since 2026-05-26 (Etapa 6 do add-on Monitoramentos)
 */

require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';
require_once __DIR__ . '/../../../app/Processos/MonitorAudit.php';
require_once __DIR__ . '/../../../app/Processos/MonitorQuota.php';
require_once __DIR__ . '/../../../app/Billing/BillingGuard.php';
require_once __DIR__ . '/../../../app/Core/TenantGuard.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Processos\MonitorAudit;
use App\Processos\MonitorQuota;
use App\Billing\BillingGuard;
use App\Core\TenantGuard;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF + same-origin nos métodos de escrita (helper já existente)
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    TenantGuard::requireSameOriginOrCsrf();
}

// Resolve super_admin_id (pra auditoria + foreign-key)
// Fonte canônica: $ctx->getUserId() — consistente com assertSuperAdmin().
// (Antes lia $_SESSION['user_id'] cru, o que podia divergir do contexto se
// algo intermediário tocasse na sessão. Cinto-e-suspensórios.)
$ctxUid = $ctx->getUserId();
$saStmt = $pdo->prepare('SELECT id FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1');
$saStmt->execute(['uid' => $ctxUid]);
$superAdminId = (int) ($saStmt->fetchColumn() ?: 0);
if ($superAdminId <= 0) {
    // Fallback: tenta o user_id direto da sessão (se diferir do contexto)
    $sessUid = (int) ($_SESSION['user_id'] ?? 0);
    if ($sessUid > 0 && $sessUid !== $ctxUid) {
        $saStmt->execute(['uid' => $sessUid]);
        $superAdminId = (int) ($saStmt->fetchColumn() ?: 0);
    }
}
if ($superAdminId <= 0) {
    // Diagnóstico: o assertSuperAdmin passou (página master loaded) mas a
    // tabela super_admins não tem registro ativo pra esse user_id. Logamos
    // pra rastrear casos raros (sessão antiga, registro desativado, etc.).
    error_log(sprintf(
        '[quotas.php] super_admin não encontrado: ctxUid=%d sessUid=%d is_super_admin=%s',
        $ctxUid,
        (int)($_SESSION['user_id'] ?? 0),
        ($_SESSION['is_super_admin'] ?? 'unset') ? '1' : '0'
    ));
    ApiResponse::forbidden('super_admin não encontrado/ativo (ctxUid=' . $ctxUid . ')');
}

// ──────────────────────────────────────────────────────────────────────
// GET — status da cota + overrides ativos
// ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Single source of truth: nunca cachear. Mudanças em outras abas
    // do Master (ou no cliente) precisam refletir imediatamente.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $accountId = (int) ($_GET['account_id'] ?? 0);
    if ($accountId <= 0) ApiResponse::badRequest('account_id obrigatório');

    // Confirma que a conta existe
    $accStmt = $pdo->prepare('SELECT id, nome, tipo, matriz_id FROM accounts WHERE id = :id LIMIT 1');
    $accStmt->execute(['id' => $accountId]);
    $acc = $accStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$acc) ApiResponse::notFound('Conta não encontrada');

    // Status agregado (limit/used/available + breakdown)
    $status = MonitorQuota::getQuotaStatus($accountId);

    // Breakdown plan vs override (pra UI mostrar separado)
    $status['plan_base']    = BillingGuard::getBaseLimit($accountId, 'monitors.limit');
    $status['override_sum'] = BillingGuard::getOverrideSum($accountId, 'monitors.limit');

    // Lista de overrides ativos (todos os ativos da conta pra essa feature)
    $stmt = $pdo->prepare(
        "SELECT aqo.id, aqo.limit_value, aqo.source, aqo.expires_at,
                aqo.unit_price_cents, aqo.billing_cycle, aqo.contract_ref,
                aqo.gateway_subscription_id, aqo.observacoes,
                aqo.created_at, aqo.set_by_super_admin_id,
                u.nome AS criado_por_nome
           FROM account_quota_overrides aqo
           LEFT JOIN super_admins sa ON sa.id = aqo.set_by_super_admin_id
           LEFT JOIN users u        ON u.id  = sa.user_id
          WHERE aqo.account_id  = :aid
            AND aqo.feature_key = 'monitors.limit'
            AND aqo.revoked_at  IS NULL
            AND (aqo.expires_at IS NULL OR aqo.expires_at > NOW())
          ORDER BY aqo.created_at DESC"
    );
    $stmt->execute(['aid' => $accountId]);
    $status['overrides'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Lista de overrides JÁ REVOGADOS (pro histórico)
    $stmt = $pdo->prepare(
        "SELECT aqo.id, aqo.limit_value, aqo.source, aqo.observacoes,
                aqo.created_at, aqo.revoked_at,
                u.nome AS criado_por_nome
           FROM account_quota_overrides aqo
           LEFT JOIN super_admins sa ON sa.id = aqo.set_by_super_admin_id
           LEFT JOIN users u        ON u.id  = sa.user_id
          WHERE aqo.account_id  = :aid
            AND aqo.feature_key = 'monitors.limit'
            AND aqo.revoked_at  IS NOT NULL
          ORDER BY aqo.revoked_at DESC
          LIMIT 20"
    );
    $stmt->execute(['aid' => $accountId]);
    $status['overrides_revogados'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $status['account'] = $acc;
    ApiResponse::ok($status);
}

// ──────────────────────────────────────────────────────────────────────
// POST — criar override (grant ou purchase) OU set_total (atalho UX)
// ──────────────────────────────────────────────────────────────────────
//
// 2 modos:
//  (a) Delta explícito (modo original):
//      {account_id, limit_value, source: 'master_grant'|'purchase', ...}
//      Insere 1 row em account_quota_overrides com a quantidade.
//
//  (b) set_total (modo UX inline — adicionado 2026-05-26):
//      {account_id, set_total: N}
//      Calcula delta = N - effective_limit_atual.
//        - delta > 0: cria grant master_grant com a diferença
//        - delta < 0: revoga overrides ativos até liberar a diferença
//                      (FIFO — mais antigos primeiro pra preservar
//                       purchases recentes que importam comercialmente)
//        - delta == 0: no-op
//      Útil pra "Editar contratado" inline no Master UI.
//
if ($method === 'POST') {
    $accountId  = (int) ($input['account_id'] ?? 0);
    if ($accountId <= 0) ApiResponse::badRequest('account_id obrigatório');

    // Conta tem de existir
    $accStmt = $pdo->prepare('SELECT id, nome FROM accounts WHERE id = :id LIMIT 1');
    $accStmt->execute(['id' => $accountId]);
    $acc = $accStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$acc) ApiResponse::notFound('Conta não encontrada');

    // ── Modo (b): set_total ────────────────────────────────────────
    if (isset($input['set_total'])) {
        $newTotal = (int) $input['set_total'];
        if ($newTotal < 0) ApiResponse::badRequest('set_total não pode ser negativo');

        $statusAntes  = MonitorQuota::getQuotaStatus($accountId);
        $atual        = $statusAntes['effective_limit'];
        $delta        = $newTotal - $atual;

        if ($delta === 0) {
            ApiResponse::ok(['message' => 'Nada mudou', 'status' => $statusAntes]);
        }

        try {
            $pdo->beginTransaction();

            if ($delta > 0) {
                // Adicionar — cria 1 grant com o delta
                $stmt = $pdo->prepare(
                    "INSERT INTO account_quota_overrides
                        (account_id, feature_key, limit_value, source,
                         set_by_super_admin_id, observacoes)
                     VALUES
                        (:aid, 'monitors.limit', :lv, 'master_grant',
                         :sa, :obs)"
                );
                $stmt->execute([
                    'aid' => $accountId,
                    'lv'  => $delta,
                    'sa'  => $superAdminId,
                    'obs' => 'Ajuste via editar (+' . $delta . ')',
                ]);
                $newOverrideId = (int) $pdo->lastInsertId();
                $opDesc = "+{$delta} (ajuste inline)";
            } else {
                // Reduzir — revoga overrides do mais antigo até cobrir |delta|
                $aRevogar = abs($delta);
                $stmt = $pdo->prepare(
                    "SELECT id, limit_value FROM account_quota_overrides
                       WHERE account_id = :aid AND feature_key = 'monitors.limit'
                         AND revoked_at IS NULL
                         AND (expires_at IS NULL OR expires_at > NOW())
                       ORDER BY created_at ASC, id ASC"
                );
                $stmt->execute(['aid' => $accountId]);
                $candidatos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $sobra = $aRevogar;
                $revogados = [];
                foreach ($candidatos as $ovr) {
                    if ($sobra <= 0) break;
                    $val = (int) $ovr['limit_value'];

                    if ($val <= $sobra) {
                        // Revoga inteiro
                        $upd = $pdo->prepare(
                            "UPDATE account_quota_overrides
                                SET revoked_at = NOW(),
                                    revoked_by_super_admin_id = :sa
                              WHERE id = :id"
                        );
                        $upd->execute(['sa' => $superAdminId, 'id' => $ovr['id']]);
                        $revogados[] = (int) $ovr['id'];
                        $sobra -= $val;
                    } else {
                        // Split: revoga o original e cria um novo com val-sobra
                        $upd = $pdo->prepare(
                            "UPDATE account_quota_overrides
                                SET revoked_at = NOW(),
                                    revoked_by_super_admin_id = :sa
                              WHERE id = :id"
                        );
                        $upd->execute(['sa' => $superAdminId, 'id' => $ovr['id']]);
                        $revogados[] = (int) $ovr['id'];

                        $resto = $val - $sobra;
                        $ins = $pdo->prepare(
                            "INSERT INTO account_quota_overrides
                                (account_id, feature_key, limit_value, source,
                                 set_by_super_admin_id, observacoes)
                             VALUES
                                (:aid, 'monitors.limit', :lv, 'master_grant',
                                 :sa, :obs)"
                        );
                        $ins->execute([
                            'aid' => $accountId,
                            'lv'  => $resto,
                            'sa'  => $superAdminId,
                            'obs' => "Saldo de override #{$ovr['id']} após reducao inline",
                        ]);
                        $sobra = 0;
                    }
                }
                $opDesc = "-{$aRevogar} (ajuste inline)";
            }

            $statusDepois = MonitorQuota::getQuotaStatus($accountId);
            MasterAudit::log(
                'monitor.quota.set_total', 'account', $accountId,
                "Editar inline: {$atual} → {$newTotal} ({$opDesc})",
                ['antes' => $atual, 'depois' => $newTotal, 'delta' => $delta]
            );
            MonitorAudit::log(
                $delta > 0 ? 'quota_granted' : 'quota_reduced',
                $accountId,
                null,
                "Master editou cota: {$atual} → {$newTotal}",
                ['limit_efetivo' => $atual],
                ['limit_efetivo' => $newTotal, 'delta' => $delta]
            );

            $pdo->commit();
            ApiResponse::ok([
                'message' => "Cota atualizada: {$atual} → {$newTotal}",
                'status'  => $statusDepois,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ApiResponse::serverError('Erro ao ajustar total: ' . $e->getMessage());
        }
    }

    // ── Modo (a): delta explícito (original) ────────────────────────
    $limitValue = (int) ($input['limit_value'] ?? $input['qtd'] ?? 0);
    $source     = trim($input['source'] ?? 'master_grant');

    if ($limitValue <= 0) ApiResponse::badRequest('limit_value/qtd deve ser maior que zero');

    $sourceAllowed = ['master_grant', 'purchase', 'trial', 'promo'];
    if (!in_array($source, $sourceAllowed, true)) {
        ApiResponse::badRequest("source inválido. Aceitos: " . implode(', ', $sourceAllowed));
    }

    // Campos opcionais (prep gateway — todos NULL por default)
    $expiresAt           = !empty($input['expires_at']) ? $input['expires_at'] : null;
    $unitPriceCents      = isset($input['unit_price_cents']) && $input['unit_price_cents'] !== ''
        ? (int) $input['unit_price_cents'] : null;
    $billingCycle        = !empty($input['billing_cycle']) ? $input['billing_cycle'] : null;
    $contractRef         = !empty($input['contract_ref']) ? trim($input['contract_ref']) : null;
    $observacoes         = !empty($input['observacoes']) ? trim($input['observacoes']) : null;

    if ($billingCycle !== null) {
        $cycleAllowed = ['monthly', 'quarterly', 'yearly', 'one_off'];
        if (!in_array($billingCycle, $cycleAllowed, true)) {
            ApiResponse::badRequest('billing_cycle inválido. Aceitos: ' . implode(', ', $cycleAllowed));
        }
    }

    try {
        $pdo->beginTransaction();

        // Estado anterior (pra audit)
        $statusAntes = MonitorQuota::getQuotaStatus($accountId);

        $stmt = $pdo->prepare(
            "INSERT INTO account_quota_overrides
                (account_id, feature_key, limit_value, source,
                 expires_at, set_by_super_admin_id,
                 unit_price_cents, billing_cycle, contract_ref,
                 observacoes)
             VALUES
                (:aid, 'monitors.limit', :lv, :src,
                 :exp, :sa,
                 :upc, :bc, :ref,
                 :obs)"
        );
        $stmt->execute([
            'aid' => $accountId,
            'lv'  => $limitValue,
            'src' => $source,
            'exp' => $expiresAt,
            'sa'  => $superAdminId,
            'upc' => $unitPriceCents,
            'bc'  => $billingCycle,
            'ref' => $contractRef,
            'obs' => $observacoes,
        ]);
        $overrideId = (int) $pdo->lastInsertId();

        // Audit dual (master + monitor) — ambos imutáveis
        $statusDepois = MonitorQuota::getQuotaStatus($accountId);
        $masterDesc   = "+{$limitValue} monitor(s) — source={$source}";
        if ($contractRef)         $masterDesc .= " — contrato={$contractRef}";
        if ($expiresAt)           $masterDesc .= " — expira={$expiresAt}";
        MasterAudit::log(
            'monitor.quota.grant', 'account', $accountId, $masterDesc,
            ['override_id' => $overrideId, 'limit_value' => $limitValue, 'source' => $source]
        );
        MonitorAudit::log(
            'quota_granted',
            $accountId,
            null,
            "Master {$source}: +{$limitValue} monitor(s)",
            ['limit_efetivo' => $statusAntes['effective_limit']],
            [
                'limit_efetivo' => $statusDepois['effective_limit'],
                'override_id'   => $overrideId,
                'source'        => $source,
            ]
        );

        $pdo->commit();
        ApiResponse::ok([
            'override_id' => $overrideId,
            'status'      => $statusDepois,
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ApiResponse::serverError('Erro ao criar override: ' . $e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────────────────
// DELETE — revogar override (soft via revoked_at)
// ──────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $overrideId = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if ($overrideId <= 0) ApiResponse::badRequest('id obrigatório');

    // Carrega override (pra audit + descobrir account_id)
    $stmt = $pdo->prepare(
        'SELECT id, account_id, limit_value, source, revoked_at, observacoes
           FROM account_quota_overrides WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $overrideId]);
    $ovr = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$ovr)                       ApiResponse::notFound('Override não encontrado');
    if ($ovr['revoked_at'] !== null) ApiResponse::badRequest('Override já estava revogado');

    try {
        $pdo->beginTransaction();

        $statusAntes = MonitorQuota::getQuotaStatus((int) $ovr['account_id']);

        $upd = $pdo->prepare(
            'UPDATE account_quota_overrides
                SET revoked_at                 = NOW(),
                    revoked_by_super_admin_id  = :sa
              WHERE id = :id'
        );
        $upd->execute(['sa' => $superAdminId, 'id' => $overrideId]);

        $statusDepois = MonitorQuota::getQuotaStatus((int) $ovr['account_id']);

        MasterAudit::log(
            'monitor.quota.revoke', 'account', (int) $ovr['account_id'],
            "Revogou override #{$overrideId} (-{$ovr['limit_value']} monitor(s))",
            ['override_id' => $overrideId, 'source' => $ovr['source']]
        );
        MonitorAudit::log(
            'quota_revoked',
            (int) $ovr['account_id'],
            null,
            "Master revogou override #{$overrideId} (-{$ovr['limit_value']})",
            ['limit_efetivo' => $statusAntes['effective_limit']],
            ['limit_efetivo' => $statusDepois['effective_limit'], 'override_id' => $overrideId]
        );

        $pdo->commit();
        ApiResponse::ok([
            'revoked'     => true,
            'override_id' => $overrideId,
            'status'      => $statusDepois,
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ApiResponse::serverError('Erro ao revogar: ' . $e->getMessage());
    }
}

ApiResponse::badRequest('Método não suportado');
