<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Financeiro/DREAccount.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Financeiro\DREAccount;
use App\Core\Database;
use App\Core\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
// Escopo CONSOLIDADO: a matriz vê o DRE das filiais/advogados vinculados (igual ao dashboard
// e ao render server-side de financas.php). Antes esta API hardcodava [$accountId], o que fazia
// os KPIs da matriz ENCOLHEREM no load do JS (consolidado no 1º paint → só-a-matriz depois).
$tenantIds = $ctx->getAccessibleAccountIds('financas');
if (empty($tenantIds)) $tenantIds = [$accountId]; // guard: evita SQL "IN ()" inválido

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $acc = DREAccount::find((int)$_GET['id'], $tenantIds);
        echo json_encode(['data' => $acc]);
        exit;
    }

    $list    = DREAccount::listAll(['account_ids' => $tenantIds]);
    $summary = DREAccount::summary(['account_ids' => $tenantIds]);
    $pdo     = Database::getConnection();

    function dre_is_valid_date($s){ return $s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

    // Tenant clause (cards) — recursos compartilhados via resource_shares também contam
    $cardPh = [];
    $tenantParams = [];
    foreach ($tenantIds as $i => $aid) {
        $k = "dacc_{$i}";
        $cardPh[] = ":{$k}";
        $tenantParams[$k] = (int)$aid;
    }
    $cardTenant = ' AND account_id IN (' . implode(',', $cardPh) . ')';

    // date range
    $rangeStart = isset($_GET['start']) ? trim($_GET['start']) : null;
    $rangeEnd   = isset($_GET['end'])   ? trim($_GET['end'])   : null;
    $hasRange   = dre_is_valid_date($rangeStart) || dre_is_valid_date($rangeEnd);

    $closed_total = 0.0;
    if ($hasRange) {
        $cardWhere = "WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'" . $cardTenant;
        $cardParams = $tenantParams;
        if (dre_is_valid_date($rangeStart)) { $cardWhere .= ' AND data_fechamento >= :start'; $cardParams['start'] = $rangeStart; }
        if (dre_is_valid_date($rangeEnd))   { $cardWhere .= ' AND data_fechamento <= :end';   $cardParams['end']   = $rangeEnd;   }
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as t FROM cards $cardWhere");
        $st->execute($cardParams);
        $closed_total = (float)($st->fetchColumn() ?? 0);
    } elseif (isset($_GET['closed_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['closed_month'])) {
        $monthFirst = $_GET['closed_month'] . '-01';
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as t FROM cards WHERE deleted_at IS NULL AND (status = 'fechado' OR (data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00')) AND (data_fechamento IS NOT NULL AND data_fechamento <= LAST_DAY(:monthFirst))" . $cardTenant);
        $st->execute([':monthFirst' => $monthFirst] + $tenantParams);
        $closed_total = (float)($st->fetchColumn() ?? 0);
    } else {
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as t FROM cards WHERE deleted_at IS NULL AND (status = 'fechado' OR (data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'))" . $cardTenant);
        $st->execute($tenantParams);
        $closed_total = (float)($st->fetchColumn() ?? 0);
    }

    $dreReceita = (float)($summary['receita'] ?? 0);
    $dreDespesa = (float)($summary['despesa'] ?? 0);
    if ($hasRange) {
        // Lógica de recorrência:
        //   FIXA   → vigora a partir de data_referencia (NULL = desde sempre).
        //            Aparece em qualquer mês >= data_referencia.
        //   AVULSA → mês específico. Aparece SÓ dentro do range exato.
        $dreWhere  = "WHERE ativo = 1" . $cardTenant;
        $dreParams = $tenantParams;

        $hasEnd   = dre_is_valid_date($rangeEnd);
        $hasStart = dre_is_valid_date($rangeStart);

        if ($hasEnd && $hasStart) {
            $dreParams['start'] = $rangeStart;
            $dreParams['end']   = $rangeEnd;
            $dreWhere .= "
                AND (
                  -- FIXA recorrente: aparece se já existia no fim do período
                  (recorrencia = 'fixa' AND (data_referencia IS NULL OR data_referencia <= :end))
                  OR
                  -- AVULSA pontual: data exata dentro do range
                  (recorrencia <> 'fixa' AND data_referencia BETWEEN :start AND :end)
                )";
        } elseif ($hasEnd) {
            $dreParams['end'] = $rangeEnd;
            $dreWhere .= "
                AND (
                  (recorrencia = 'fixa' AND (data_referencia IS NULL OR data_referencia <= :end))
                  OR
                  (recorrencia <> 'fixa' AND data_referencia <= :end)
                )";
        } elseif ($hasStart) {
            $dreParams['start'] = $rangeStart;
            $dreWhere .= "
                AND (
                  recorrencia = 'fixa'
                  OR (recorrencia <> 'fixa' AND data_referencia >= :start)
                )";
        }

        $st = $pdo->prepare("SELECT tipo, COALESCE(SUM(valor_fixo),0) as total FROM dre_accounts $dreWhere GROUP BY tipo");
        $st->execute($dreParams);
        $dreReceita = $dreDespesa = 0.0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['tipo'] === 'receita') $dreReceita = (float)$r['total'];
            if ($r['tipo'] === 'despesa') $dreDespesa = (float)$r['total'];
        }
    }

    $combined_receita   = $dreReceita + $closed_total;
    $combined_despesa   = $dreDespesa;
    $combined_resultado = $combined_receita - $combined_despesa;
    $combined_margem    = $combined_receita > 0 ? round(($combined_resultado / $combined_receita) * 100, 1) : 0;

    echo json_encode([
        'data' => $list, 'summary' => $summary, 'closed_total' => $closed_total,
        'combined' => [
            'receita'   => $combined_receita,
            'despesa'   => $combined_despesa,
            'resultado' => $combined_resultado,
            'margem'    => $combined_margem,
        ]
    ]);
    exit;
}

// CSRF
if (in_array($method, ['POST','PUT','PATCH','DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

if ($method === 'POST') {
    if (empty($input['nome'])) { http_response_code(400); echo json_encode(['error' => 'Missing nome']); exit; }
    $input['account_id'] = $accountId;     // injeta tenant (NUNCA do body)
    $id = DREAccount::create($input);
    \App\Master\Account::audit($accountId, 'dre.created', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'dre_account',
        'entidade_id' => (int)$id,
        'detalhes'    => [
            'nome'           => $input['nome'] ?? null,
            'tipo'           => $input['tipo'] ?? null,
            'valor_fixo'     => $input['valor_fixo'] ?? null,
            'recorrencia'    => $input['recorrencia'] ?? null,
            'data_referencia'=> $input['data_referencia'] ?? null,
        ],
    ]);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $dreId      = (int)$input['id'];
    $dadosAntes = DREAccount::find($dreId, $tenantIds);
    $ok = DREAccount::update($dreId, $input, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Master\Account::audit($accountId, 'dre.updated', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'dre_account',
        'entidade_id' => $dreId,
        'dados_antes' => $dadosAntes,
        'detalhes'    => $input,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $dreId      = (int)$id;
    $dadosAntes = DREAccount::find($dreId, $tenantIds);
    $ok = DREAccount::softDelete($dreId, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Master\Account::audit($accountId, 'dre.deleted', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'dre_account',
        'entidade_id' => $dreId,
        'dados_antes' => $dadosAntes,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
