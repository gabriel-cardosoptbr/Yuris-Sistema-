<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';

use App\Core\Database;
use App\Core\AccountContext;

// read_and_close: endpoint read-only (não escreve $_SESSION). Sem isto, o
// session_start() segura o lock de escrita da sessão durante o carregamento/polling
// do dashboard, serializando os AJAX e travando o sino de notificações em "Carregando…".
session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$uid       = $ctx->getUserId();
$tenantIds = $ctx->getAccessibleAccountIds('dashboard');
if (empty($tenantIds)) $tenantIds = [0]; // guard contra SQL "IN ()" inválido

// Filtro de Origem (?origin=__matriz__|__filiais__|<id>) — espelha a lógica
// do dashboard.php (server-side rendering) para os dados via API.
// Restringe ao conjunto acessível, com validação contra ids alheios.
$selected_origin = isset($_GET['origin']) ? trim((string)$_GET['origin']) : '';
if ($selected_origin !== '' && $ctx->isMatriz()) {
    try {
        // FIX (auditoria 2026-06-01): matriz_id e sempre NULL — deriva do conjunto
        // acessivel ($tenantIds via account_vinculos). Sem isso, ?origin retornava vazio.
        $accIds = $tenantIds;
        $ph_oa  = implode(',', array_fill(0, count($accIds), '?'));
        $stmt_oa = Database::getConnection()->prepare(
            "SELECT id, tipo FROM accounts
             WHERE id IN ($ph_oa) AND deleted_at IS NULL
               AND status IN ('active','trial','overdue')"
        );
        $stmt_oa->execute(array_map('intval', $accIds));
        $accs = $stmt_oa->fetchAll(\PDO::FETCH_ASSOC);
        $matrizIds  = array_map(fn($a) => (int)$a['id'], array_filter($accs, fn($a) => $a['tipo'] === 'matriz'));
        $filiaisIds = array_map(fn($a) => (int)$a['id'], array_filter($accs, fn($a) => $a['tipo'] === 'filial'));
        $allowed    = array_map(fn($a) => (int)$a['id'], $accs);
        if ($selected_origin === '__matriz__') {
            $tenantIds = $matrizIds ?: [0];
        } elseif ($selected_origin === '__filiais__') {
            $tenantIds = $filiaisIds ?: [0];
        } else {
            $sid = (int)$selected_origin;
            $tenantIds = (in_array($sid, $allowed, true) && $sid > 0) ? [$sid] : [0];
        }
    } catch (\Throwable $e) { /* mantém tenantIds original em caso de erro */ }
}

$pdo = Database::getConnection();

$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end   = isset($_GET['end'])   ? trim($_GET['end'])   : null;
if (empty($start) && !empty($_SESSION['dashboard_start'])) $start = $_SESSION['dashboard_start'];
if (empty($end)   && !empty($_SESSION['dashboard_end']))   $end   = $_SESSION['dashboard_end'];
function is_valid_date($s){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

// ── Tenant filter fragment (cards) ─────────────────────────────────────
$cardsTenantPh = [];
$tenantParams  = [];
foreach ($tenantIds as $i => $aid) {
    $k                 = "dacc_{$i}";
    $cardsTenantPh[]   = ":{$k}";
    $tenantParams[$k]  = (int) $aid;
}
$cardsTenant   = ' AND account_id IN (' . implode(',', $cardsTenantPh) . ')';
$columnsTenant = ' AND pc.account_id IN (' . implode(',', $cardsTenantPh) . ')';
$goalsTenant   = ' AND account_id IN (' . implode(',', $cardsTenantPh) . ')';

try {
    $closedWhere   = "WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'" . $cardsTenant;
    $forecastWhere = "WHERE deleted_at IS NULL AND data_prevista_fechamento IS NOT NULL AND data_prevista_fechamento > '0000-00-00'" . $cardsTenant;
    $params = $tenantParams;
    if ($start && is_valid_date($start)) {
        $closedWhere   .= ' AND data_fechamento >= :start';
        $forecastWhere .= ' AND data_prevista_fechamento >= :start';
        $params['start'] = $start;
    }
    if ($end && is_valid_date($end)) {
        $closedWhere   .= ' AND data_fechamento <= :end';
        $forecastWhere .= ' AND data_prevista_fechamento <= :end';
        $params['end'] = $end;
    }

    // Closed by month
    $sql = "SELECT DATE_FORMAT(data_fechamento, '%Y-%m') AS period,
        SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))) AS total,
        COUNT(*) AS count
        FROM cards $closedWhere GROUP BY period ORDER BY period ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $closed = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Forecast by month
    $sql = "SELECT DATE_FORMAT(data_prevista_fechamento, '%Y-%m') AS period, SUM(IFNULL(valor_proposta,0)) AS total, COUNT(*) AS count
        FROM cards $forecastWhere GROUP BY period ORDER BY period ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $forecast = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary by pipeline column (sem filtro de data; só de tenant)
    $sql = "SELECT pc.id, pc.nome, pc.cor, pc.ordem, COUNT(c.id) AS count,
        SUM(COALESCE(NULLIF(c.valor_fechado_final,0), NULLIF(c.valor_proposta,0), IFNULL(c.valor_estimado,0))) AS total_closed
        FROM pipeline_columns pc
        LEFT JOIN cards c ON c.coluna_id = pc.id AND c.deleted_at IS NULL AND c.account_id IN (" . implode(',', $cardsTenantPh) . ")
        WHERE 1=1 $columnsTenant
        GROUP BY pc.id ORDER BY pc.ordem ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($tenantParams);
    $by_column = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals
    // Quando há filtro de período aplicado, os KPIs respeitam o range:
    //   - Clientes Ativos / Contratos Fechados / Valor Fechado → data_fechamento BETWEEN
    //   - Receita Projetada / Oportunidades → data_prevista_fechamento BETWEEN
    // Sem filtro → totais absolutos do tenant (estado atual).
    $hasRange      = ($start && is_valid_date($start)) || ($end && is_valid_date($end));
    $closedRange   = '';
    $forecastRange = '';
    $rangeParams   = [];
    if ($start && is_valid_date($start)) {
        $closedRange   .= ' AND data_fechamento >= :start_kpi';
        $forecastRange .= ' AND data_prevista_fechamento >= :start_kpi';
        $rangeParams['start_kpi'] = $start;
    }
    if ($end && is_valid_date($end)) {
        $closedRange   .= ' AND data_fechamento <= :end_kpi';
        $forecastRange .= ' AND data_prevista_fechamento <= :end_kpi';
        $rangeParams['end_kpi'] = $end;
    }

    $total_cards = 0; $total_clients_active = 0; $total_opportunities_count = 0;
    $total_closed_count = 0; $total_closed_amount = 0.0; $total_projection_amount = 0.0;
    try {
        $t = $pdo->prepare("SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL $cardsTenant");
        $t->execute($tenantParams);
        $total_cards = (int)$t->fetchColumn();

        $t = $pdo->prepare("SELECT COUNT(DISTINCT cliente_nome) FROM cards WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00' $cardsTenant $closedRange");
        $t->execute($tenantParams + $rangeParams);
        $total_clients_active = (int)$t->fetchColumn();

        $lostColId = null;
        try {
            $t = $pdo->prepare("SELECT id FROM pipeline_columns WHERE slug = 'perdido' $columnsTenant LIMIT 1");
            // o $columnsTenant usa alias pc; substituímos por account_id direto
            $stmtLost = $pdo->prepare("SELECT id FROM pipeline_columns WHERE slug = 'perdido' AND account_id IN (" . implode(',', $cardsTenantPh) . ") LIMIT 1");
            $stmtLost->execute($tenantParams);
            $lostColId = $stmtLost->fetchColumn();
            if ($lostColId === false) $lostColId = null;
        } catch (Exception $_e) { $lostColId = null; }

        // Oportunidades: cards abertos. Com filtro, considera apenas os com forecast no período.
        $oppSql = "SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL AND (data_fechamento IS NULL OR data_fechamento = '0000-00-00') $cardsTenant";
        $oppParams = $tenantParams;
        if ($lostColId) {
            $oppSql .= ' AND (coluna_id IS NULL OR coluna_id != :lost)';
            $oppParams['lost'] = $lostColId;
        }
        if ($hasRange) {
            // exige forecast dentro do range (cards sem data_prevista ficam de fora quando há filtro)
            $oppSql .= " AND data_prevista_fechamento IS NOT NULL AND data_prevista_fechamento > '0000-00-00' $forecastRange";
            $oppParams = $oppParams + $rangeParams;
        }
        $t = $pdo->prepare($oppSql);
        $t->execute($oppParams);
        $total_opportunities_count = (int)$t->fetchColumn();

        $t = $pdo->prepare("SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00' $cardsTenant $closedRange");
        $t->execute($tenantParams + $rangeParams);
        $total_closed_count = (int)$t->fetchColumn();

        $t = $pdo->prepare("SELECT SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))) FROM cards WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00' $cardsTenant $closedRange");
        $t->execute($tenantParams + $rangeParams);
        $total_closed_amount = (float)$t->fetchColumn();

        $projSql = "SELECT SUM(IFNULL(valor_proposta,0)) FROM cards WHERE deleted_at IS NULL AND (data_fechamento IS NULL OR data_fechamento = '0000-00-00') $cardsTenant";
        $projParams = $tenantParams;
        if ($lostColId) $projSql .= ' AND (coluna_id IS NULL OR coluna_id != ' . (int)$lostColId . ')';
        if ($hasRange) {
            $projSql .= " AND data_prevista_fechamento IS NOT NULL AND data_prevista_fechamento > '0000-00-00' $forecastRange";
            $projParams = $projParams + $rangeParams;
        }
        $t = $pdo->prepare($projSql);
        $t->execute($projParams);
        $total_projection_amount = (float)$t->fetchColumn();
    } catch (Exception $_e) { /* ignore */ }

    // Goals by month (apenas do tenant)
    $goals = [];
    try {
        $gstmt = $pdo->prepare("SELECT referencia_mes AS period,
            SUM(CASE WHEN user_id = :uid THEN IFNULL(valor_meta,0) ELSE 0 END) AS total_user,
            SUM(CASE WHEN user_id IS NULL OR user_id = 0 THEN IFNULL(valor_meta,0) ELSE 0 END) AS total_global
            FROM goals WHERE 1=1 $goalsTenant GROUP BY referencia_mes ORDER BY referencia_mes ASC");
        $gstmt->execute(['uid' => $uid] + $tenantParams);
        foreach ($gstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $total = !empty($r['total_user']) ? $r['total_user'] : (!empty($r['total_global']) ? $r['total_global'] : 0);
            $goals[] = ['period' => $r['period'], 'total' => (float)$total];
        }
    } catch (Exception $ee) { $goals = []; }

    // Meta value (record mais recente do usuário no tenant; fallback registro mais recente do tenant)
    $meta_value = 0.0;
    try {
        $mst = $pdo->prepare("SELECT valor_meta FROM goals WHERE user_id = :uid $goalsTenant ORDER BY updated_at DESC, id DESC LIMIT 1");
        $mst->execute(['uid' => $uid] + $tenantParams);
        $mrow = $mst->fetch(PDO::FETCH_ASSOC);
        if ($mrow && (float)$mrow['valor_meta'] > 0) {
            $meta_value = (float)$mrow['valor_meta'];
        } else {
            $mst = $pdo->prepare("SELECT valor_meta FROM goals WHERE 1=1 $goalsTenant ORDER BY updated_at DESC, id DESC LIMIT 1");
            $mst->execute($tenantParams);
            $mrow = $mst->fetch(PDO::FETCH_ASSOC);
            if ($mrow && isset($mrow['valor_meta'])) $meta_value = (float)$mrow['valor_meta'];
        }
    } catch (Exception $ee) { $meta_value = 0.0; }

    echo json_encode([
        'closed_by_month'   => $closed,
        'forecast_by_month' => $forecast,
        'by_column'         => $by_column,
        'summary'           => [
            'total_cards'               => $total_cards,
            'total_clients_active'      => $total_clients_active,
            'total_opportunities_count' => $total_opportunities_count,
            'total_closed_count'        => $total_closed_count,
            'total_closed_amount'       => $total_closed_amount,
            'total_projection_amount'   => $total_projection_amount,
        ],
        'meta_value'        => (float)$meta_value,
        'goals_by_month'    => $goals,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
exit;
