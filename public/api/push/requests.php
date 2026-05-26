<?php
/**
 * push/requests.php — solicitações de monitoramento (Etapa 9).
 *
 * Fluxo D4: "advogado solicita, admin aprova".
 *
 * GET    /api/push/requests.php?scope=mine|pending|all&status=...
 *   - mine    (default p/ requester): só do user logado
 *   - pending (default p/ admin):     só pending da conta
 *   - all     (admin):                tudo da conta
 *
 * POST   /api/push/requests.php
 *   Body: {_csrf, tipo_monitoramento, valor_monitorado, uf?,
 *          nome_complementar?, source_id?, justificativa?}
 *   - Qualquer user logado pode solicitar (canRequestMonitor)
 *   - 402 se a conta não tem cota disponível (D4 — pending reserva)
 *   - 409 se já existir pending duplicado (mesmo tipo+valor+uf)
 *
 * PATCH  /api/push/requests.php?id=X
 *   Body: {_csrf, action: 'approve' | 'deny' | 'cancel', motivo?}
 *   - approve / deny: só admin (canApproveRequest)
 *   - cancel: solicitante mesmo (enquanto pending)
 *   - approve cria push_monitor com origem_criacao='request_approved'
 *     e preenche monitor_requests.resulting_monitor_id
 *
 * Notifica via account_notifications:
 *   - tipo=monitor_request_created (target: todos owner/admin/super_admin da conta)
 *   - tipo=monitor_request_resolved (target: solicitante)
 *
 * @since 2026-05-26 (Etapa 9 add-on Monitoramentos)
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/PushMonitor.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
require_once __DIR__ . '/../../../app/Helpers/MonitorQuota.php';
require_once __DIR__ . '/../../../app/Helpers/MonitorAudit.php';
require_once __DIR__ . '/../../../app/Helpers/MonitorPermission.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MonitorQuota;
use App\Helpers\MonitorAudit;
use App\Helpers\MonitorPermission;
use App\Models\Database;
use App\Models\PushMonitor;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$method  = $_SERVER['REQUEST_METHOD'];
$payload = [];
if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?? [];
    $tokenIn = $payload['_csrf'] ?? ($_GET['_csrf'] ?? '');
    if (!$tokenIn || $tokenIn !== $csrfSession) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
        exit;
    }
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $userId    = $ctx->getUserId();
    $pdo       = Database::getConnection();

    switch ($method) {
        case 'GET':
            handleGet($pdo, $ctx);
            break;
        case 'POST':
            handlePost($pdo, $ctx, $payload);
            break;
        case 'PATCH':
            $id = (int) ($_GET['id'] ?? $payload['id'] ?? 0);
            handlePatch($pdo, $ctx, $payload, $id);
            break;
        default:
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Método não suportado']);
    }
} catch (\Throwable $e) {
    \App\Helpers\ErrorReporter::handle($e);
}

// ───────────────────────────────────────────────────────────────────────
// HANDLERS
// ───────────────────────────────────────────────────────────────────────

function handleGet(PDO $pdo, AccountContext $ctx): void
{
    $accountId = $ctx->getAccountId();
    $userId    = $ctx->getUserId();
    $scope     = (string) ($_GET['scope'] ?? '');
    $status    = (string) ($_GET['status'] ?? '');

    $isAdmin = $ctx->isSuperAdmin() || $ctx->isOwnerOrAdmin();

    // Default scope:
    //   admin → pending (caixa de entrada)
    //   user  → mine
    if ($scope === '') $scope = $isAdmin ? 'pending' : 'mine';

    $where  = ['mr.account_id = :acc'];
    $params = ['acc' => $accountId];

    if ($scope === 'mine') {
        $where[]         = 'mr.requesting_user_id = :uid';
        $params['uid']   = $userId;
    } elseif ($scope === 'pending') {
        // pending caixa de entrada do admin
        if (!$isAdmin) {
            // user comum não pode listar pending de outros
            $where[]       = 'mr.requesting_user_id = :uid';
            $params['uid'] = $userId;
        }
        $where[]            = "mr.status = 'pending'";
    } elseif ($scope === 'all') {
        if (!$isAdmin) {
            // user comum sem all → restringe aos próprios
            $where[]       = 'mr.requesting_user_id = :uid';
            $params['uid'] = $userId;
        }
    }

    if (in_array($status, ['pending','approved','denied','canceled'], true)) {
        $where[]            = 'mr.status = :st';
        $params['st']       = $status;
    }

    $sql =
        "SELECT mr.*, u.nome AS requesting_user_nome,
                ua.nome AS approved_by_nome
           FROM monitor_requests mr
           LEFT JOIN users u  ON u.id  = mr.requesting_user_id
           LEFT JOIN users ua ON ua.id = mr.approved_by
          WHERE " . implode(' AND ', $where) . "
          ORDER BY (mr.status = 'pending') DESC, mr.created_at DESC
          LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Status atual do solicitante (pra UI saber se pode "Solicitar de novo")
    $can = [
        'request' => MonitorPermission::canRequestMonitor($ctx),
        'approve' => MonitorPermission::canApproveRequest($ctx),
        'create'  => MonitorPermission::canCreate($ctx),
    ];

    // Resumo: contadores por status
    $counts = ['pending' => 0, 'approved' => 0, 'denied' => 0, 'canceled' => 0];
    foreach ($rows as $r) {
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
    }

    ApiResponse::ok([
        'requests' => $rows,
        'counts'   => $counts,
        'scope'    => $scope,
        'can'      => $can,
        'quota'    => MonitorQuota::getQuotaStatus($accountId),
    ]);
}

function handlePost(PDO $pdo, AccountContext $ctx, array $payload): void
{
    $accountId = $ctx->getAccountId();
    $userId    = $ctx->getUserId();

    MonitorPermission::assertCanRequestMonitor($ctx);

    $tipo  = trim((string) ($payload['tipo_monitoramento'] ?? 'oab'));
    $valor = trim((string) ($payload['valor_monitorado']   ?? ''));
    $uf    = strtoupper(preg_replace('/[^A-Z]/i', '', (string) ($payload['uf'] ?? '')));
    $nome  = trim((string) ($payload['nome_complementar'] ?? ''));
    $just  = trim((string) ($payload['justificativa']     ?? ''));
    $src   = (string) ($payload['source_id'] ?? 'djen');

    if (!in_array($tipo, ['oab','processo','nome','customizado'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'tipo_monitoramento inválido']);
        exit;
    }
    if ($valor === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'valor_monitorado obrigatório']);
        exit;
    }
    if ($tipo === 'oab' && $uf === '' && preg_match('/^([A-Z]{2})(\d+)$/i', $valor, $m)) {
        $uf    = strtoupper($m[1]);
        $valor = ltrim($m[2], '0');
    }

    // Cota disponível? (D4 — pending também consome)
    $limit = MonitorQuota::getEffectiveLimit($accountId);
    $used  = MonitorQuota::getCurrentUsage($accountId);
    if ($limit <= 0) {
        http_response_code(402);
        echo json_encode([
            'ok'    => false,
            'error' => 'Conta não tem monitoramentos contratados. Solicite ao gestor da conta.',
            'code'  => 'sem_cota_contratada',
        ]);
        exit;
    }
    if ($used >= $limit) {
        http_response_code(402);
        echo json_encode([
            'ok'    => false,
            'error' => "Cota esgotada ({$used}/{$limit}). Não há vagas mesmo pra reservar — aguarde um cancelamento ou contrate mais.",
            'code'  => 'cota_esgotada',
        ]);
        exit;
    }

    // Duplicata?
    $dup = $pdo->prepare(
        "SELECT id FROM monitor_requests
          WHERE account_id = :acc
            AND requesting_user_id = :uid
            AND status = 'pending'
            AND tipo_monitoramento = :tipo
            AND valor_monitorado = :val
            AND (uf <=> :uf)
          LIMIT 1"
    );
    $dup->execute([
        'acc'  => $accountId,
        'uid'  => $userId,
        'tipo' => $tipo,
        'val'  => $valor,
        'uf'   => $uf ?: null,
    ]);
    if ($existing = $dup->fetchColumn()) {
        http_response_code(409);
        echo json_encode([
            'ok'    => false,
            'error' => 'Você já tem uma solicitação pendente igual.',
            'code'  => 'duplicate_pending',
            'existing_id' => (int) $existing,
        ]);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO monitor_requests
           (account_id, requesting_user_id, tipo_monitoramento,
            valor_monitorado, uf, nome_complementar, source_id,
            justificativa, status, created_at, updated_at)
         VALUES
           (:acc, :uid, :tipo, :val, :uf, :nome, :src, :just,
            'pending', NOW(), NOW())"
    )->execute([
        'acc'  => $accountId,
        'uid'  => $userId,
        'tipo' => $tipo,
        'val'  => $valor,
        'uf'   => $uf ?: null,
        'nome' => $nome !== '' ? $nome : null,
        'src'  => $src,
        'just' => $just !== '' ? $just : null,
    ]);
    $reqId = (int) $pdo->lastInsertId();

    MonitorAudit::log(
        'request_created',
        $accountId,
        null,
        "Solicitação #{$reqId}: {$tipo} {$valor}" . ($uf ? "/{$uf}" : ''),
        null,
        ['request_id' => $reqId, 'tipo' => $tipo, 'valor' => $valor, 'uf' => $uf]
    );

    // Notifica admins da conta
    notifyAdmins(
        $pdo, $accountId,
        'monitor_request_created',
        'Nova solicitação de monitoramento',
        "Solicitação #{$reqId} aguardando aprovação ({$tipo}: {$valor}).",
        ['request_id' => $reqId, 'tipo' => $tipo, 'valor' => $valor, 'uf' => $uf]
    );

    ApiResponse::ok([
        'id'     => $reqId,
        'status' => 'pending',
    ]);
}

function handlePatch(PDO $pdo, AccountContext $ctx, array $payload, int $id): void
{
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id obrigatório']);
        exit;
    }
    $action    = (string) ($payload['action'] ?? '');
    $motivo    = trim((string) ($payload['motivo'] ?? ''));
    $accountId = $ctx->getAccountId();
    $userId    = $ctx->getUserId();

    // Carrega request
    $stmt = $pdo->prepare(
        "SELECT * FROM monitor_requests
          WHERE id = :id AND account_id = :acc LIMIT 1"
    );
    $stmt->execute(['id' => $id, 'acc' => $accountId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Solicitação não encontrada']);
        exit;
    }
    if ($req['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => "Solicitação já está '{$req['status']}', não pode mais ser alterada.",
        ]);
        exit;
    }

    // Permissões
    if ($action === 'cancel') {
        if ((int) $req['requesting_user_id'] !== $userId && !$ctx->isOwnerOrAdmin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Só o solicitante (ou admin) pode cancelar.']);
            exit;
        }
    } elseif (in_array($action, ['approve','deny'], true)) {
        MonitorPermission::assertCanApproveRequest($ctx);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'action deve ser approve|deny|cancel']);
        exit;
    }

    if ($action === 'cancel') {
        $pdo->prepare(
            "UPDATE monitor_requests
                SET status     = 'canceled',
                    motivo_recusa = :mot,
                    updated_at = NOW()
              WHERE id = :id"
        )->execute(['id' => $id, 'mot' => $motivo !== '' ? $motivo : null]);

        MonitorAudit::log(
            'request_denied',  // canceled não tem enum próprio — reaproveita denied
            $accountId,
            null,
            "Solicitação #{$id} cancelada pelo solicitante",
            ['status' => 'pending'],
            ['status' => 'canceled', 'motivo' => $motivo ?: null]
        );

        ApiResponse::ok(['id' => $id, 'status' => 'canceled']);
    }

    if ($action === 'deny') {
        $pdo->prepare(
            "UPDATE monitor_requests
                SET status        = 'denied',
                    denied_at     = NOW(),
                    approved_by   = :uid,
                    motivo_recusa = :mot,
                    updated_at    = NOW()
              WHERE id = :id"
        )->execute([
            'id'  => $id,
            'uid' => $userId,
            'mot' => $motivo !== '' ? $motivo : null,
        ]);

        MonitorAudit::log(
            'request_denied',
            $accountId,
            null,
            "Solicitação #{$id} recusada" . ($motivo ? " — {$motivo}" : ''),
            ['status' => 'pending'],
            ['status' => 'denied', 'motivo' => $motivo ?: null]
        );

        // Notifica solicitante
        notifyUser(
            $pdo,
            (int) $req['requesting_user_id'],
            $accountId,
            'monitor_request_resolved',
            'Solicitação de monitor recusada',
            "Sua solicitação #{$id} foi recusada" . ($motivo ? ": {$motivo}" : '.'),
            ['request_id' => $id, 'status' => 'denied']
        );

        ApiResponse::ok(['id' => $id, 'status' => 'denied']);
    }

    // ── approve ───────────────────────────────────────────────────
    // Cria push_monitor de verdade, atualiza request.
    if ($action === 'approve') {
        // Revalida cota — eventual race entre solicitação e aprovação
        $limit = MonitorQuota::getEffectiveLimit($accountId);
        $used  = MonitorQuota::getCurrentUsage($accountId);
        // Tira o próprio pending da conta (vai virar approved + monitor)
        $usedEffective = max(0, $used - 1);
        if ($usedEffective >= $limit) {
            http_response_code(402);
            echo json_encode([
                'ok'    => false,
                'error' => "Cota esgotada na hora da aprovação ({$usedEffective}/{$limit}). Cancele outro ou aguarde.",
                'code'  => 'cota_esgotada',
            ]);
            exit;
        }

        $tipo  = $req['tipo_monitoramento'];
        $valor = $req['valor_monitorado'];
        $uf    = $req['uf'];
        $src   = $req['source_id'] ?: 'djen';

        try {
            $pdo->beginTransaction();

            $monitorId = PushMonitor::create([
                'account_id'         => $accountId,
                'source_id'          => $src,
                'tipo_monitoramento' => $tipo,
                'valor_monitorado'   => $valor,
                'uf'                 => $uf ?: null,
                'tribunal'           => null,
                'status'             => 'ativo',
                'prioridade'         => 5,
                'intervalo_minutos'  => 120,
                'proxima_consulta_em'=> date('Y-m-d H:i:s'),
                'created_by'         => $userId,
            ]);

            // PushMonitor::create não conhece origem_criacao/assigned — UPDATE manual
            // (campos da migration 076). Origem='request_approved' marca claramente
            // que veio da aprovação dessa request — útil pra rastrear ROI.
            $pdo->prepare(
                "UPDATE push_monitors
                    SET origem_criacao   = 'request_approved',
                        assigned_user_id = :uid,
                        consome_cota     = 1
                  WHERE id = :id AND account_id = :acc"
            )->execute([
                'uid' => (int) $req['requesting_user_id'],
                'id'  => $monitorId,
                'acc' => $accountId,
            ]);

            $pdo->prepare(
                "UPDATE monitor_requests
                    SET status                = 'approved',
                        approved_by           = :uid,
                        approved_at           = NOW(),
                        resulting_monitor_id  = :mid,
                        motivo_recusa         = NULL,
                        updated_at            = NOW()
                  WHERE id = :id"
            )->execute([
                'uid' => $userId,
                'mid' => $monitorId,
                'id'  => $id,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        MonitorAudit::log(
            'request_approved',
            $accountId,
            (int) $monitorId,
            "Solicitação #{$id} aprovada — monitor #{$monitorId} criado",
            ['status' => 'pending'],
            ['status' => 'approved', 'resulting_monitor_id' => $monitorId]
        );

        notifyUser(
            $pdo,
            (int) $req['requesting_user_id'],
            $accountId,
            'monitor_request_resolved',
            'Solicitação de monitor aprovada',
            "Sua solicitação #{$id} foi aprovada — o monitor está ativo.",
            ['request_id' => $id, 'status' => 'approved', 'monitor_id' => $monitorId]
        );

        ApiResponse::ok([
            'id'         => $id,
            'status'     => 'approved',
            'monitor_id' => $monitorId,
        ]);
    }
}

// ───────────────────────────────────────────────────────────────────────
// NOTIFICATIONS (fail-soft)
// ───────────────────────────────────────────────────────────────────────

function notifyUser(PDO $pdo, int $userId, int $accountId, string $tipo, string $titulo, string $msg, array $payload = []): void
{
    try {
        $pdo->prepare(
            "INSERT INTO account_notifications
               (account_id, user_id, tipo, titulo, mensagem, lida, payload, created_at)
             VALUES
               (:acc, :uid, :tipo, :tit, :msg, 0, :p, NOW())"
        )->execute([
            'acc'  => $accountId,
            'uid'  => $userId,
            'tipo' => $tipo,
            'tit'  => $titulo,
            'msg'  => $msg,
            'p'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('[push/requests.notifyUser] ' . $e->getMessage());
    }
}

/** Notifica todos owners/admins da conta. */
function notifyAdmins(PDO $pdo, int $accountId, string $tipo, string $titulo, string $msg, array $payload = []): void
{
    try {
        // Owners/admins não-deletados na conta
        $stmt = $pdo->prepare(
            "SELECT u.id FROM users u
              WHERE u.account_id = :acc
                AND u.deleted_at IS NULL
                AND (u.perfil = 'admin' OR u.role IN ('owner','admin'))"
        );
        $stmt->execute(['acc' => $accountId]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Adiciona super_admins ativos (visão global)
        $stmt = $pdo->query(
            "SELECT user_id FROM super_admins WHERE ativo = 1"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if (!in_array((int) $uid, array_map('intval', $userIds), true)) {
                $userIds[] = (int) $uid;
            }
        }

        // Insert em lote (1 row por destinatário)
        $ins = $pdo->prepare(
            "INSERT INTO account_notifications
               (account_id, user_id, tipo, titulo, mensagem, lida, payload, created_at)
             VALUES
               (:acc, :uid, :tipo, :tit, :msg, 0, :p, NOW())"
        );
        foreach ($userIds as $uid) {
            $ins->execute([
                'acc'  => $accountId,
                'uid'  => (int) $uid,
                'tipo' => $tipo,
                'tit'  => $titulo,
                'msg'  => $msg,
                'p'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        }
    } catch (\Throwable $e) {
        error_log('[push/requests.notifyAdmins] ' . $e->getMessage());
    }
}
