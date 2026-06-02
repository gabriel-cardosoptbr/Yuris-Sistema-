<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

// read_and_close: endpoint read-only (não escreve $_SESSION). Libera o lock de
// escrita na hora — evita serializar os AJAX do dashboard e travar o sino.
session_start(['read_and_close' => true]);
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

    // Nota (auditoria #4): este endpoint devolve SOMENTE o que os consumidores
    // realmente usam — by_lawyer (gráfico de carga por advogado em
    // juridico_charts.js), no_update (card "sem movimentação" em juridico.js) e
    // as faixas de prazo deadlines_today/7/15/30 (Dashboard + Jurídico).
    // Campos antes calculados e descartados (active_count, deadlines_week,
    // urgent, hearings_month) foram removidos: o Dashboard recomputa "Processos
    // Ativos"/"urgentes" client-side via computeJurKPIs(processes) e ninguém lia
    // esses campos. Evita trabalho de servidor desperdiçado.
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
        'by_lawyer'       => $by_lawyer,   // gráfico carga por advogado (juridico_charts.js)
        'no_update'       => $stale,       // card "sem movimentação 30d+" (juridico.js)
        'deadlines_today' => $deadlines_today,
        'deadlines_7'     => $deadlines_7,
        'deadlines_15'    => $deadlines_15,
        'deadlines_30'    => $deadlines_30,
    ]], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    // P1 LGPD (rule #4): nunca vaza $e->getMessage() em prod — loga server-side
    // e devolve mensagem genérica via ErrorReporter (padrão do projeto).
    require_once __DIR__ . '/../../app/Helpers/ErrorReporter.php';
    \App\Helpers\ErrorReporter::handle($e);
}
