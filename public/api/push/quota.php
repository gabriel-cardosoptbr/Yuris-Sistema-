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
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MonitorQuota.php';
require_once __DIR__ . '/../../../app/Helpers/BillingGuard.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MonitorQuota;
use App\Helpers\BillingGuard;

session_start(['read_and_close' => true]);

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

$status = MonitorQuota::getQuotaStatus($accountId);
$status['plan_base']    = BillingGuard::getBaseLimit($accountId, 'monitors.limit');
$status['override_sum'] = BillingGuard::getOverrideSum($accountId, 'monitors.limit');

ApiResponse::ok(['status' => $status]);
