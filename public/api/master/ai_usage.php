<?php
/**
 * /api/master/ai_usage.php — Painel Master: visão de CONTAS x AGENTE de IA.
 *
 * Mostra TODAS as contas (com agente ou não), em qual canal, se o agente está LIGADO
 * (agent_configs.enabled), quantas áreas de triagem, e o CONSUMO de tokens/custo
 * (mês/total) lido de ai_usage_log. Pura leitura/relatório.
 *
 * Ação extra (leitura ao vivo, read-only): GET ?action=webhook&instance_id=ID
 *   -> { ok, data:{ instance_id, dest:'yuris'|'n8n'|'outro'|'none'|'noconfig'|'erro', url } }
 *   Consulta a Evolution (GET /webhook/find) pra mostrar PRA ONDE cada canal entrega
 *   (Yuris ou externo). O token na URL volta MASCARADO (nunca expõe a chave do tenant).
 *
 * Acesso: super_admin em sessão master_mode (somente leitura — sem CSRF/escrita).
 *
 *   GET -> { ok, data: { summary:{...}, accounts:[ {...} ] } }
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo = \App\Core\Database::getConnection();

/* ─────────────────────────────────────────────────────────────────────────
 * AÇÃO: webhook (leitura ao vivo do destino do webhook de UMA instância).
 * READ-ONLY: usa GET /webhook/find na Evolution. NÃO altera nada.
 * ──────────────────────────────────────────────────────────────────────── */
if (($_GET['action'] ?? '') === 'webhook') {
    require_once __DIR__ . '/../../../app/WhatsAppAgente/WhatsAppInstance.php';
    require_once __DIR__ . '/../../../app/WhatsAppAgente/EvolutionApiService.php';

    $iid = (int)($_GET['instance_id'] ?? 0);
    if ($iid <= 0) ApiResponse::badRequest('instance_id obrigatório.');

    $wi   = new \WhatsAppInstance();
    $inst = $wi->find($iid);                       // super admin: sem escopo de conta
    if (!$inst) ApiResponse::badRequest('Instância não encontrada.');

    $aid  = (int)$inst['account_id'];
    $cfg  = $wi->getSettings($aid);
    $base = $cfg['evolution_base_url'] ?? '';
    $key  = $cfg['evolution_api_key']  ?? '';
    $name = $cfg['evolution_instance'] ?? ($inst['instance_name'] ?? '');
    if ($base === '' || $key === '' || $name === '') {
        ApiResponse::ok(['instance_id' => $iid, 'dest' => 'noconfig', 'url' => null]);
    }

    try {
        $svc = new \EvolutionApiService($cfg);
        $svc->setTimeout(8);
        $resp = $svc->getWebhook($name);
        $url  = $resp['url'] ?? $resp['webhook']['url'] ?? ($resp['data']['url'] ?? null);
        if ($url === null || $url === '') {
            ApiResponse::ok(['instance_id' => $iid, 'dest' => 'none', 'url' => null]);
        }
        $dest = (stripos($url, 'yuris') !== false && stripos($url, 'webhook') !== false) ? 'yuris'
              : ((stripos($url, 'automacao.inovaize.com') !== false || stripos($url, 'n8n') !== false) ? 'n8n' : 'outro');
        // Mascara token/apikey na URL antes de devolver (nunca expõe a chave do tenant).
        $masked = preg_replace('/((?:token|apikey)=)[^&]+/i', '$1***', (string)$url);
        ApiResponse::ok(['instance_id' => $iid, 'dest' => $dest, 'url' => $masked]);
    } catch (\Throwable $e) {
        ApiResponse::ok(['instance_id' => $iid, 'dest' => 'erro', 'url' => null]);
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * RELATÓRIO PRINCIPAL (todas as contas).
 * ──────────────────────────────────────────────────────────────────────── */
$monthStart = date('Y-m-01 00:00:00');
$todayStart = date('Y-m-d 00:00:00');

$mk = function (int $id, string $nome = '', string $tipo = '', $status = null) {
    return [
        'account_id'      => $id,
        'account_nome'    => $nome !== '' ? $nome : ('Conta #' . $id),
        'tipo'            => $tipo,
        'account_status'  => $status,
        'has_agent'       => 0,
        'enabled'         => 0,
        'channels'        => 0,
        'channels_detail' => [],
        'areas'           => 0,
        'calls'           => 0,
        'tok_today'       => 0, 'tok_month' => 0, 'tok_total' => 0,
        'inp_month'       => 0, 'cch_month' => 0,
        'cost_month'      => 0.0, 'cost_total' => 0.0,
        'last_at'         => null,
    ];
};

/* 1) TODAS as contas. */
$acc = [];
foreach ($pdo->query("SELECT id, nome, tipo, status FROM accounts ORDER BY id") as $r) {
    $id = (int)$r['id'];
    $acc[$id] = $mk($id, (string)($r['nome'] ?? ''), (string)($r['tipo'] ?? ''), $r['status'] ?? null);
}

/* 2) Áreas de triagem habilitadas por conta (best-effort). */
try {
    foreach ($pdo->query("SELECT account_id, COUNT(*) c FROM ai_intake_areas WHERE enabled = 1 GROUP BY account_id") as $r) {
        $id = (int)$r['account_id'];
        if (isset($acc[$id])) $acc[$id]['areas'] = (int)$r['c'];
    }
} catch (\Throwable $_) { /* tabela ausente: ignora */ }

/* 3) agent_configs: estado por instância + flag por conta. */
$agByInst = [];   // instance_id => [agent_id, enabled]
foreach ($pdo->query("SELECT id, account_id, whatsapp_instance_id, enabled FROM agent_configs") as $g) {
    $iid = (int)$g['whatsapp_instance_id'];
    if ($iid > 0) $agByInst[$iid] = ['agent_id' => (int)$g['id'], 'enabled' => (int)$g['enabled']];
    $aid = (int)$g['account_id'];
    if (isset($acc[$aid])) {
        $acc[$aid]['has_agent'] = 1;
        if ((int)$g['enabled'] === 1) $acc[$aid]['enabled'] = 1;
    }
}

/* 4) Instâncias (canais) por conta. */
foreach ($pdo->query("SELECT id, account_id, instance_name, display_name, status FROM whatsapp_instances ORDER BY account_id, id") as $w) {
    $aid = (int)$w['account_id'];
    if (!isset($acc[$aid])) continue;            // instância órfã (conta inexistente): ignora
    $iid = (int)$w['id'];
    $ag  = $agByInst[$iid] ?? null;
    $acc[$aid]['channels_detail'][] = [
        'agent_id'    => $ag['agent_id'] ?? 0,
        'instance_id' => $iid,
        'name'        => ($w['instance_name'] !== '' && $w['instance_name'] !== null) ? $w['instance_name'] : ($w['display_name'] ?: ('#' . $iid)),
        'status'      => $w['status'] ?? '',
        'enabled'     => $ag['enabled'] ?? 0,
        'has_agent'   => $ag ? 1 : 0,
    ];
    $acc[$aid]['channels']++;
}

/* 5) Consumo (tokens/custo) por conta, de ai_usage_log. */
$useStmt = $pdo->prepare("
    SELECT ul.account_id,
           COUNT(*)                           AS calls,
           COALESCE(SUM(ul.total_tokens),0)   AS tok_total,
           COALESCE(SUM(ul.estimated_cost),0) AS cost_total,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ms1 THEN ul.total_tokens   ELSE 0 END),0) AS tok_month,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ms2 THEN ul.estimated_cost ELSE 0 END),0) AS cost_month,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ts1 THEN ul.total_tokens   ELSE 0 END),0) AS tok_today,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ms3 THEN ul.input_tokens        ELSE 0 END),0) AS inp_month,
           COALESCE(SUM(CASE WHEN ul.created_at >= :ms4 THEN ul.cached_input_tokens ELSE 0 END),0) AS cch_month,
           MAX(ul.created_at) AS last_at
      FROM ai_usage_log ul
     GROUP BY ul.account_id
");
$useStmt->execute([':ms1' => $monthStart, ':ms2' => $monthStart, ':ms3' => $monthStart, ':ms4' => $monthStart, ':ts1' => $todayStart]);
foreach ($useStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $id = (int)$r['account_id'];
    if (!isset($acc[$id])) $acc[$id] = $mk($id);
    $acc[$id]['calls']      = (int)$r['calls'];
    $acc[$id]['tok_today']  = (int)$r['tok_today'];
    $acc[$id]['tok_month']  = (int)$r['tok_month'];
    $acc[$id]['tok_total']  = (int)$r['tok_total'];
    $acc[$id]['inp_month']  = (int)$r['inp_month'];
    $acc[$id]['cch_month']  = (int)$r['cch_month'];
    $acc[$id]['cost_month'] = (float)$r['cost_month'];
    $acc[$id]['cost_total'] = (float)$r['cost_total'];
    $acc[$id]['last_at']    = $r['last_at'];
}

$accounts = array_values($acc);
/* Ordena: ligados primeiro, depois com agente, depois consumo do mês desc, depois nome. */
usort($accounts, function ($a, $b) {
    if ($a['enabled']   !== $b['enabled'])   return $b['enabled']   <=> $a['enabled'];
    if ($a['has_agent'] !== $b['has_agent']) return $b['has_agent'] <=> $a['has_agent'];
    if ($a['tok_month'] !== $b['tok_month']) return $b['tok_month'] <=> $a['tok_month'];
    return strcasecmp((string)$a['account_nome'], (string)$b['account_nome']);
});

$summary = [
    'contas_total'      => count($accounts),
    'contas_com_agente' => count(array_filter($accounts, fn($x) => $x['has_agent'] === 1)),
    'contas_ligadas'    => count(array_filter($accounts, fn($x) => $x['enabled'] === 1)),
    'tokens_today'      => array_sum(array_column($accounts, 'tok_today')),
    'tokens_month'      => array_sum(array_column($accounts, 'tok_month')),
    'tokens_total'      => array_sum(array_column($accounts, 'tok_total')),
    'cost_month'        => array_sum(array_column($accounts, 'cost_month')),
    'cost_total'        => array_sum(array_column($accounts, 'cost_total')),
    'input_month'       => array_sum(array_column($accounts, 'inp_month')),
    'cached_month'      => array_sum(array_column($accounts, 'cch_month')),
    'month_label'       => date('m/Y'),
];
// Taxa de cache do mes = tokens de entrada servidos do cache / total de entrada (0..1).
$summary['cache_ratio_month'] = $summary['input_month'] > 0
    ? round($summary['cached_month'] / $summary['input_month'], 4) : 0.0;

ApiResponse::ok(['summary' => $summary, 'accounts' => $accounts]);
