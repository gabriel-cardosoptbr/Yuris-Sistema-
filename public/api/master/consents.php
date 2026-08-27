<?php
/**
 * /api/master/consents.php — Painel Master: aceites de termos LGPD.
 *
 * Lista entradas de lgpd_consents (foco em finalidade=termos_uso_login, mas
 * suporta filtro). Acesso restrito: super_admin com master_mode.
 *
 * Endpoints:
 *   GET                                  → lista com filtros (q, finalidade, status, limit)
 *   GET ?counts=1                        → contagens para badge ({total_ativos, hoje, ultimos_7d})
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only']);
    exit;
}

try {
    $pdo = Database::getConnection();

    if (!empty($_GET['counts'])) {
        $st = $pdo->query(
            "SELECT
               SUM(status='ativo')                                                       AS total_ativos,
               SUM(status='ativo' AND DATE(concedido_em)=CURDATE())                      AS hoje,
               SUM(status='ativo' AND concedido_em >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS ultimos_7d
             FROM lgpd_consents
             WHERE finalidade='termos_uso_login'"
        );
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        ApiResponse::ok([
            'total_ativos' => (int)($row['total_ativos'] ?? 0),
            'hoje'         => (int)($row['hoje']         ?? 0),
            'ultimos_7d'   => (int)($row['ultimos_7d']   ?? 0),
        ]);
    }

    // Filtros
    $finalidade = trim((string)($_GET['finalidade'] ?? 'termos_uso_login'));
    $statusFlt  = trim((string)($_GET['status']     ?? 'ativo'));
    $q          = trim((string)($_GET['q']          ?? ''));
    $limit      = max(1, min(200, (int)($_GET['limit'] ?? 100)));

    $where  = [];
    $params = [];
    if ($finalidade !== '') { $where[] = 'lc.finalidade = :fin'; $params['fin'] = $finalidade; }
    if ($statusFlt  !== '') { $where[] = 'lc.status     = :st';  $params['st']  = $statusFlt;  }
    if ($q !== '') {
        // Busca por e-mail (titular_email anônimo OU users.login após login) ou nome
        $where[] = '(lc.titular_email LIKE :q OR u.login LIKE :q OR u.nome LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT
              lc.id, lc.finalidade, lc.base_legal, lc.status, lc.fonte,
              lc.concedido_em, lc.revogado_em, lc.ip, lc.user_agent,
              COALESCE(u.login, lc.titular_email) AS email,
              u.nome AS user_nome,
              a.nome AS account_nome
            FROM lgpd_consents lc
            LEFT JOIN users    u ON u.id = lc.user_id
            LEFT JOIN accounts a ON a.id = COALESCE(lc.account_id, u.account_id)
            $whereSql
            ORDER BY lc.concedido_em DESC
            LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

    ApiResponse::ok([
        'total' => count($rows),
        'items' => $rows,
    ]);
} catch (\Throwable $e) {
    // Rule 4: nunca vazar $e->getMessage() em prod — loga server-side e
    // devolve mensagem genérica (mesmo padrão de error_log dos outros endpoints master).
    error_log('[master/consents] ' . $e->getMessage());
    ApiResponse::serverError('Falha ao listar aceites de termos.');
}
