<?php
/**
 * push/permissions.php — toggle de permissões do add-on Monitoramentos.
 *
 * Por enquanto só lida com a flag `advogado_pode_criar_monitor`. Pode crescer
 * conforme novas flags surgirem.
 *
 * GET /api/push/permissions.php
 *   → {ok, advogado_pode_criar_monitor: bool}
 *
 * POST /api/push/permissions.php
 *   Body: {_csrf, advogado_pode_criar_monitor: bool}
 *   → {ok, advogado_pode_criar_monitor: bool}
 *
 * Quem pode mudar: owner/admin da própria conta (multi-tenant strict).
 *
 * @since 2026-05-26 (Etapa 8 add-on Monitoramentos)
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Processos\MonitorPermission;
use App\Processos\MonitorAudit;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?? [];
    $tokenIn = $payload['_csrf'] ?? '';
    if (!$tokenIn || $tokenIn !== $csrfSession) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
        exit;
    }
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();

    if ($method === 'GET') {
        ApiResponse::ok([
            'advogado_pode_criar_monitor' => MonitorPermission::isAdvogadoAllowedToCreate($accountId),
        ]);
    }

    if ($method === 'POST') {
        if (!$ctx->isSuperAdmin() && !$ctx->isOwnerOrAdmin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sem permissão']);
            exit;
        }

        if (!array_key_exists('advogado_pode_criar_monitor', $payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'advogado_pode_criar_monitor obrigatório']);
            exit;
        }

        $novo  = !empty($payload['advogado_pode_criar_monitor']);
        $atual = MonitorPermission::isAdvogadoAllowedToCreate($accountId);

        if ($novo === $atual) {
            ApiResponse::ok([
                'advogado_pode_criar_monitor' => $novo,
                'unchanged' => true,
            ]);
        }

        $ok = MonitorPermission::setAdvogadoAllowedToCreate($accountId, $novo);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Falha ao salvar flag']);
            exit;
        }

        MonitorAudit::log(
            'permission_changed',
            $accountId,
            null,
            "Flag advogado_pode_criar_monitor mudada de "
            . ($atual ? 'true' : 'false') . " → "
            . ($novo  ? 'true' : 'false'),
            ['advogado_pode_criar_monitor' => $atual],
            ['advogado_pode_criar_monitor' => $novo]
        );

        ApiResponse::ok(['advogado_pode_criar_monitor' => $novo]);
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não suportado']);
} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
