<?php
/**
 * push/quota.php — endpoint de leitura do status de cota de monitoramento
 * pra UI do tenant (modal Monitoramento em /intimacoes.php).
 *
 * GET /api/push/quota.php
 *   → {ok:true, status: {effective_limit, current_usage, available, has_quota,
 *                        plan_base, override_sum, percent_used, ...}}
 *
 * Multi-tenant: AccountContext::fromSession() — só dados da própria conta.
 * Endpoint READ-only — sem CSRF check.
 *
 * @since 2026-05-26 (Etapa 5 add-on Monitoramentos)
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Processos\MonitorQuota;
use App\Billing\BillingGuard;

session_start(['read_and_close' => true]);

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

$status = MonitorQuota::getQuotaStatus($accountId);
$status['plan_base']    = BillingGuard::getBaseLimit($accountId, 'monitors.limit');
$status['override_sum'] = BillingGuard::getOverrideSum($accountId, 'monitors.limit');

// Single source of truth — nunca cachear. Mudanças no Painel Master
// (alocação, override, grant) refletem imediatamente no badge do cliente.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

ApiResponse::ok(['status' => $status]);
