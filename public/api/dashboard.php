<?php
require_once __DIR__ . '/../../app/Models/Database.php';

use App\Models\Database;

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = Database::getConnection();

// optional date range filtering: expect YYYY-MM-DD in GET params 'start' and 'end'
$start = isset($_GET['start']) ? trim($_GET['start']) : null;
$end = isset($_GET['end']) ? trim($_GET['end']) : null;

// fallback to session-stored global dashboard period when params not provided
if (empty($start) && !empty($_SESSION['dashboard_start'])) {
    $start = $_SESSION['dashboard_start'];
}
if (empty($end) && !empty($_SESSION['dashboard_end'])) {
    $end = $_SESSION['dashboard_end'];
}

// validate simple date format (YYYY-MM-DD)
function is_valid_date($s){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

try {
        // exclude zero/invalid dates (MySQL '0000-00-00') when considering closed/forecast
        $closedWhere = "WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'";
        $forecastWhere = "WHERE deleted_at IS NULL AND data_prevista_fechamento IS NOT NULL AND data_prevista_fechamento > '0000-00-00'";
    $params = [];
    if ($start && is_valid_date($start)) {
        $closedWhere .= ' AND data_fechamento >= :start';
        $forecastWhere .= ' AND data_prevista_fechamento >= :start';
        $params['start'] = $start;
    }
    if ($end && is_valid_date($end)) {
        $closedWhere .= ' AND data_fechamento <= :end';
        $forecastWhere .= ' AND data_prevista_fechamento <= :end';
        $params['end'] = $end;
    }

    // Closed by month (data_fechamento) — use best available value per card
    $sql = "SELECT DATE_FORMAT(data_fechamento, '%Y-%m') AS period,
        SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))) AS total,
        COUNT(*) AS count
        FROM cards
        $closedWhere
        GROUP BY period
        ORDER BY period ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $closed = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Forecast by month (data_prevista_fechamento)
    $sql = "SELECT DATE_FORMAT(data_prevista_fechamento, '%Y-%m') AS period, SUM(IFNULL(valor_proposta,0)) AS total, COUNT(*) AS count
        FROM cards
        $forecastWhere
        GROUP BY period
        ORDER BY period ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $forecast = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary by pipeline column (not filtered by date range)
    $stmt = $pdo->prepare("SELECT pc.id, pc.nome, pc.cor, pc.ordem, COUNT(c.id) AS count,
        SUM(COALESCE(NULLIF(c.valor_fechado_final,0), NULLIF(c.valor_proposta,0), IFNULL(c.valor_estimado,0))) AS total_closed
        FROM pipeline_columns pc
        LEFT JOIN cards c ON c.coluna_id = pc.id AND c.deleted_at IS NULL
        GROUP BY pc.id
        ORDER BY pc.ordem ASC");
    $stmt->execute();
    $by_column = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // totals / summary - compute according to rules from prospeccao.php
    $total_cards = 0;
    $total_clients_active = 0; // distinct clients with data_fechamento
    $total_opportunities_count = 0; // cards not in 'perdido' and without data_fechamento
    $total_closed_count = 0; // cards with data_fechamento
    $total_closed_amount = 0.0; // sum valor_fechado_final for closed
    $total_projection_amount = 0.0; // sum valor_proposta for open opportunities (not perdido and no data_fechamento)
    try {
        // total cards
        $tstmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM cards WHERE deleted_at IS NULL');
        $tstmt->execute();
        $total_cards = (int)$tstmt->fetchColumn();

        // total distinct clients with data_fechamento
            $cstmt = $pdo->prepare("SELECT COUNT(DISTINCT cliente_nome) AS cnt FROM cards WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'");
        $cstmt->execute();
        $total_clients_active = (int)$cstmt->fetchColumn();

        // find 'perdido' column id if exists
        $lostColId = null;
        try {
            $colstmt = $pdo->prepare("SELECT id FROM pipeline_columns WHERE slug = 'perdido' LIMIT 1");
            $colstmt->execute();
            $lostColId = $colstmt->fetchColumn();
            if ($lostColId === false) $lostColId = null;
        } catch (Exception $_e) { $lostColId = null; }

        // opportunities: not in perdido and no data_fechamento (treat '0000-00-00' as no date)
        $oppSql = "SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL AND (data_fechamento IS NULL OR data_fechamento = '0000-00-00')";
        $oppParams = [];
        if ($lostColId) {
            $oppSql .= ' AND (coluna_id IS NULL OR coluna_id != :lost)';
            $oppParams['lost'] = $lostColId;
        }
        $opstmt = $pdo->prepare($oppSql);
        $opstmt->execute($oppParams);
        $total_opportunities_count = (int)$opstmt->fetchColumn();

        // closed count and amount — use best available value per card
        $closedCntStmt = $pdo->prepare("SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'");
        $closedCntStmt->execute();
        $total_closed_count = (int)$closedCntStmt->fetchColumn();
        $sumStmt = $pdo->prepare("SELECT SUM(COALESCE(NULLIF(valor_fechado_final,0), NULLIF(valor_proposta,0), IFNULL(valor_estimado,0))) AS total_closed_amount FROM cards WHERE deleted_at IS NULL AND data_fechamento IS NOT NULL AND data_fechamento > '0000-00-00'");
        $sumStmt->execute();
        $total_closed_amount = (float)$sumStmt->fetchColumn();

        // projection amount: sum valor_proposta for open opportunities (same filter as opportunities)
        $projSql = "SELECT SUM(IFNULL(valor_proposta,0)) FROM cards WHERE deleted_at IS NULL AND (data_fechamento IS NULL OR data_fechamento = '0000-00-00')";
        if ($lostColId) $projSql .= ' AND (coluna_id IS NULL OR coluna_id != ' . (int)$lostColId . ')';
        $projStmt = $pdo->prepare($projSql);
        $projStmt->execute();
        $total_projection_amount = (float)$projStmt->fetchColumn();
    } catch (Exception $_e) {
        // ignore and keep defaults
    }

    // Goals by month (referencia_mes stored as 'YYYY-MM')
    // IMPORTANT: goals are manual parameters and must NOT be filtered by the
    // dashboard date range. Return user's goals per month (fallback to global)
    // across all months so the front-end can treat them as isolated inputs.
    $goals = [];
    try {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $gstmt = $pdo->prepare("SELECT referencia_mes AS period,
            SUM(CASE WHEN user_id = :uid THEN IFNULL(valor_meta,0) ELSE 0 END) AS total_user,
            SUM(CASE WHEN user_id IS NULL OR user_id = 0 THEN IFNULL(valor_meta,0) ELSE 0 END) AS total_global
            FROM goals GROUP BY referencia_mes ORDER BY referencia_mes ASC");
        $gstmt->execute(['uid' => $uid]);
        $rows = $gstmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $total = 0;
            if (!empty($r['total_user'])) $total = $r['total_user'];
            elseif (!empty($r['total_global'])) $total = $r['total_global'];
            $goals[] = ['period' => $r['period'], 'total' => (float)$total];
        }
    } catch (Exception $ee) {
        $goals = [];
    }

    // meta value: pega o registro mais recente do usuário (qualquer tipo/período)
    $meta_value = 0.0;
    try {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        // qualquer record do usuário, ordenado por updated_at DESC para pegar o mais recente
        $mst = $pdo->prepare("SELECT valor_meta FROM goals WHERE user_id = :uid ORDER BY updated_at DESC, id DESC LIMIT 1");
        $mst->execute(['uid' => $uid]);
        $mrow = $mst->fetch(PDO::FETCH_ASSOC);
        if ($mrow && isset($mrow['valor_meta']) && (float)$mrow['valor_meta'] > 0) {
            $meta_value = (float)$mrow['valor_meta'];
        } else {
            // fallback: qualquer record global
            $mst = $pdo->prepare("SELECT valor_meta FROM goals ORDER BY updated_at DESC, id DESC LIMIT 1");
            $mst->execute();
            $mrow = $mst->fetch(PDO::FETCH_ASSOC);
            if ($mrow && isset($mrow['valor_meta'])) $meta_value = (float)$mrow['valor_meta'];
        }
    } catch (Exception $ee) {
        $meta_value = 0.0;
    }

    echo json_encode([
        'closed_by_month' => $closed,
        'forecast_by_month' => $forecast,
        'by_column' => $by_column,
        'summary' => [
            'total_cards' => $total_cards,
            'total_clients_active' => $total_clients_active,
            'total_opportunities_count' => $total_opportunities_count,
            'total_closed_count' => $total_closed_count,
            'total_closed_amount' => $total_closed_amount,
            'total_projection_amount' => $total_projection_amount
        ],
        // meta_value: single isolated meta (prefer user, fallback global)
        'meta_value' => (isset($meta_value) ? (float)$meta_value : 0),
        'goals_by_month' => $goals
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}

exit;
