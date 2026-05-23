<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$tenantIds = $ctx->getAccessibleAccountIds('juridico');
if (empty($tenantIds)) $tenantIds = [0]; // guard contra SQL "IN ()" inválido

// Constrói cláusula tenant uma vez
$ph = []; $tenantParams = [];
foreach ($tenantIds as $i => $aid) {
    $k = "jacc_{$i}";
    $ph[] = ":{$k}";
    $tenantParams[$k] = (int) $aid;
}
$tProc = ' AND p.account_id IN (' . implode(',', $ph) . ')';
$tBare = ' AND account_id IN ('   . implode(',', $ph) . ')';

function safeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('juridico_metrics safeQuery error: ' . $e->getMessage());
        return [];
    }
}
function safeScalar($pdo, $sql, $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: $default;
    } catch (Exception $e) {
        error_log('juridico_metrics safeScalar error: ' . $e->getMessage());
        return $default;
    }
}

try {
    $pdo = App\Models\Database::getConnection();

    // "Processos Ativos" = NÃO encerrado/arquivado.
    // Inclui status='ativo', 'concluido', 'suspenso', NULL.
    // Consistente com computeJurKPIs (dashboard.js), updateKPIs (processos.js)
    // e computeStrategicKPIs (juridico.js).
    $active = (int) safeScalar($pdo,
        "SELECT COUNT(*) FROM processos
         WHERE deleted_at IS NULL
           AND (status IS NULL OR status NOT IN ('encerrado','arquivado'))
           $tBare",
        $tenantParams
    );

    $by_lawyer = safeQuery($pdo,
        "SELECT p.responsavel_user_id AS user_id,
                COALESCE(u.nome, CONCAT('Responsável #', COALESCE(p.responsavel_user_id,'S/N'))) AS nome,
                COUNT(*) AS total
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.responsavel_user_id IS NOT NULL
           AND p.responsavel_user_id > 0
           $tProc
         GROUP BY p.responsavel_user_id
         ORDER BY total DESC
         LIMIT 100",
        $tenantParams
    );

    $week_deadlines = safeQuery($pdo,
        "SELECT id, numero, cliente_nome, proximo_prazo, responsavel_user_id
         FROM processos
         WHERE deleted_at IS NULL
           AND proximo_prazo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           $tBare
         ORDER BY proximo_prazo ASC LIMIT 200",
        $tenantParams
    );

    $urgent = safeQuery($pdo,
        "SELECT id, numero, cliente_nome, proximo_prazo
         FROM processos
         WHERE deleted_at IS NULL
           AND proximo_prazo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
           $tBare
         ORDER BY proximo_prazo ASC LIMIT 200",
        $tenantParams
    );

    $stale = safeQuery($pdo,
        "SELECT id, numero, cliente_nome, ultima_movimentacao
         FROM processos
         WHERE deleted_at IS NULL
           AND (ultima_movimentacao IS NULL OR ultima_movimentacao < DATE_SUB(NOW(), INTERVAL 30 DAY))
           $tBare
         ORDER BY COALESCE(ultima_movimentacao,'1970-01-01') ASC LIMIT 200",
        $tenantParams
    );

    $deadlines_today = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL AND p.proximo_prazo = CURDATE()
           $tProc
         ORDER BY p.proximo_prazo ASC LIMIT 200",
        $tenantParams
    );

    $deadlines_7 = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.proximo_prazo > CURDATE()
           AND p.proximo_prazo <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           $tProc
         ORDER BY p.proximo_prazo ASC LIMIT 200",
        $tenantParams
    );

    $deadlines_15 = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.proximo_prazo > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           AND p.proximo_prazo <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
           $tProc
         ORDER BY p.proximo_prazo ASC LIMIT 200",
        $tenantParams
    );

    $deadlines_30 = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.proximo_prazo > DATE_ADD(CURDATE(), INTERVAL 15 DAY)
           AND p.proximo_prazo <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           $tProc
         ORDER BY p.proximo_prazo ASC LIMIT 200",
        $tenantParams
    );

    echo json_encode(['success' => true, 'data' => [
        'active_count'    => $active,
        'by_lawyer'       => $by_lawyer,
        'deadlines_week'  => $week_deadlines,
        'hearings_month'  => [],
        'urgent'          => $urgent,
        'no_update'       => $stale,
        'deadlines_today' => $deadlines_today,
        'deadlines_7'     => $deadlines_7,
        'deadlines_15'    => $deadlines_15,
        'deadlines_30'    => $deadlines_30,
    ]], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
