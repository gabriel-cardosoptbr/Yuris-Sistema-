<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/DREAccount.php';

use App\Models\DREAccount;
use App\Models\Database;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $acc = DREAccount::find((int)$_GET['id']);
        echo json_encode(['data' => $acc]);
        exit;
    }
    $list = DREAccount::listAll();
    $summary = DREAccount::summary();
    $pdo = Database::getConnection();

    function dre_is_valid_date($s){ return $s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

    // date range filter (start/end take precedence over legacy closed_month/closed_until)
    $rangeStart = isset($_GET['start']) ? trim($_GET['start']) : null;
    $rangeEnd   = isset($_GET['end'])   ? trim($_GET['end'])   : null;
    $hasRange   = dre_is_valid_date($rangeStart) || dre_is_valid_date($rangeEnd);

    // card revenue filter
    $closed_total = 0.0;
    if ($hasRange) {
        $cardWhere = "WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'";
        $cardParams = [];
        if (dre_is_valid_date($rangeStart)) { $cardWhere .= ' AND data_fechamento >= :start'; $cardParams['start'] = $rangeStart; }
        if (dre_is_valid_date($rangeEnd))   { $cardWhere .= ' AND data_fechamento <= :end';   $cardParams['end']   = $rangeEnd;   }
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as t FROM cards $cardWhere");
        $st->execute($cardParams);
        $closed_total = (float)($st->fetchColumn() ?? 0);
    } else if (isset($_GET['closed_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['closed_month'])) {
        $monthFirst = $_GET['closed_month'] . '-01';
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as t FROM cards WHERE deleted_at IS NULL AND (status = 'fechado' OR (data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00')) AND (data_fechamento IS NOT NULL AND data_fechamento <= LAST_DAY(:monthFirst))");
        $st->execute([':monthFirst' => $monthFirst]);
        $closed_total = (float)($st->fetchColumn() ?? 0);
    } else if (isset($_GET['closed_until'])) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as t FROM cards WHERE deleted_at IS NULL AND (status = 'fechado' OR (data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00')) AND (data_fechamento IS NOT NULL AND data_fechamento <= :closedUntil)");
        $st->execute([':closedUntil' => $_GET['closed_until']]);
        $closed_total = (float)($st->fetchColumn() ?? 0);
    } else {
        $st = $pdo->query("SELECT COALESCE(SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))),0) as t FROM cards WHERE deleted_at IS NULL AND (status = 'fechado' OR (data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'))");
        $closed_total = (float)($st->fetchColumn() ?? 0);
    }

    // DRE accounts — when range provided, filter by data_referencia (NULL entries are always included as recurring)
    $dreReceita = (float)($summary['receita'] ?? 0);
    $dreDespesa = (float)($summary['despesa'] ?? 0);
    if ($hasRange) {
        $dreWhere  = "WHERE ativo = 1";
        $dreParams = [];
        if (dre_is_valid_date($rangeStart)) { $dreWhere .= " AND (data_referencia IS NULL OR data_referencia >= :start)"; $dreParams['start'] = $rangeStart; }
        if (dre_is_valid_date($rangeEnd))   { $dreWhere .= " AND (data_referencia IS NULL OR data_referencia <= :end)";   $dreParams['end']   = $rangeEnd;   }
        $st = $pdo->prepare("SELECT tipo, COALESCE(SUM(valor_fixo),0) as total FROM dre_accounts $dreWhere GROUP BY tipo");
        $st->execute($dreParams);
        $dreReceita = $dreDespesa = 0.0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['tipo'] === 'receita') $dreReceita = (float)$r['total'];
            if ($r['tipo'] === 'despesa') $dreDespesa = (float)$r['total'];
        }
    }

    // combined totals
    $combined_receita  = $dreReceita + $closed_total;
    $combined_despesa  = $dreDespesa;
    $combined_resultado = $combined_receita - $combined_despesa;
    $combined_margem   = $combined_receita > 0 ? round(($combined_resultado / $combined_receita) * 100, 1) : 0;

    echo json_encode([
        'data' => $list, 'summary' => $summary, 'closed_total' => $closed_total,
        'combined' => [
            'receita'  => $combined_receita,
            'despesa'  => $combined_despesa,
            'resultado'=> $combined_resultado,
            'margem'   => $combined_margem,
        ]
    ]);
    exit;
}

// CSRF check for state changes
if (in_array($method, ['POST','PUT','PATCH','DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error'=>'Invalid CSRF token']);
        exit;
    }
}

if ($method === 'POST') {
    if (empty($input['nome'])) { http_response_code(400); echo json_encode(['error'=>'Missing nome']); exit; }
    $id = DREAccount::create($input);
    echo json_encode(['success'=>true,'id'=>$id]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $ok = DREAccount::update((int)$input['id'], $input);
    echo json_encode(['success'=>(bool)$ok]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $ok = DREAccount::softDelete((int)$id);
    echo json_encode(['success'=>(bool)$ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
