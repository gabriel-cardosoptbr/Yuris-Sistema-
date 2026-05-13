<?php
require_once __DIR__ . '/../../app/Models/Database.php';
header('Content-Type: application/json; charset=utf-8');

// Executa query segura retornando array vazio em caso de erro (evita 500 na página inteira)
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

// Executa query segura retornando escalar; usa ?: portanto retorna $default quando o valor é 0 (ver bug conhecido)
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

    $active = (int) safeScalar($pdo,
        "SELECT COUNT(*) FROM processos WHERE deleted_at IS NULL AND status = 'ativo'"
    );

    // by_lawyer: try with JOIN first, fall back to without join
    $by_lawyer = safeQuery($pdo,
        "SELECT p.responsavel_user_id AS user_id,
                COALESCE(u.nome, CONCAT('Responsável #', COALESCE(p.responsavel_user_id,'S/N'))) AS nome,
                COUNT(*) AS total
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.responsavel_user_id IS NOT NULL
           AND p.responsavel_user_id > 0
         GROUP BY p.responsavel_user_id
         ORDER BY total DESC
         LIMIT 100"
    );
    if (empty($by_lawyer)) {
        $by_lawyer = safeQuery($pdo,
            "SELECT responsavel_user_id AS user_id,
                    CONCAT('Responsável #', COALESCE(responsavel_user_id,'S/N')) AS nome,
                    COUNT(*) AS total
             FROM processos
             WHERE deleted_at IS NULL
               AND responsavel_user_id IS NOT NULL
               AND responsavel_user_id > 0
             GROUP BY responsavel_user_id
             ORDER BY total DESC
             LIMIT 100"
        );
    }

    $week_deadlines = safeQuery($pdo,
        "SELECT id, numero, cliente_nome, proximo_prazo, responsavel_user_id
         FROM processos
         WHERE deleted_at IS NULL
           AND proximo_prazo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         ORDER BY proximo_prazo ASC LIMIT 200"
    );

    $urgent = safeQuery($pdo,
        "SELECT id, numero, cliente_nome, proximo_prazo
         FROM processos
         WHERE deleted_at IS NULL
           AND proximo_prazo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
         ORDER BY proximo_prazo ASC LIMIT 200"
    );

    // Processos sem atualização há mais de 30 dias — ordenados do mais antigo para o mais recente
    $stale = safeQuery($pdo,
        "SELECT id, numero, cliente_nome, ultima_movimentacao
         FROM processos
         WHERE deleted_at IS NULL
           AND (ultima_movimentacao IS NULL OR ultima_movimentacao < DATE_SUB(NOW(), INTERVAL 30 DAY))
         ORDER BY COALESCE(ultima_movimentacao,'1970-01-01') ASC LIMIT 200"
    );

    // deadlines with responsavel name — these use JOIN, wrapped safely
    $deadlines_today = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL AND p.proximo_prazo = CURDATE()
         ORDER BY p.proximo_prazo ASC LIMIT 200"
    );

    $deadlines_7 = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.proximo_prazo > CURDATE()
           AND p.proximo_prazo <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         ORDER BY p.proximo_prazo ASC LIMIT 200"
    );

    $deadlines_15 = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.proximo_prazo > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           AND p.proximo_prazo <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
         ORDER BY p.proximo_prazo ASC LIMIT 200"
    );

    $deadlines_30 = safeQuery($pdo,
        "SELECT p.id, p.numero, p.cliente_nome, p.proximo_prazo,
                COALESCE(u.nome, CONCAT('ID:',COALESCE(p.responsavel_user_id,'—'))) AS responsavel
         FROM processos p
         LEFT JOIN users u ON u.id = p.responsavel_user_id
         WHERE p.deleted_at IS NULL
           AND p.proximo_prazo > DATE_ADD(CURDATE(), INTERVAL 15 DAY)
           AND p.proximo_prazo <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY p.proximo_prazo ASC LIMIT 200"
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
