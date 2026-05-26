<?php
/**
 * push/allocations.php — alocação de cota matriz→filial (add-on Monitoramentos).
 *
 * GET    /api/push/allocations.php
 *        → {ok, parent: {account_id, own_limit, ...}, filiais:[...], allocations:[...]}
 *        - own_limit = cota da matriz
 *        - allocated_total = soma das allocations ATIVAS
 *        - pool_disponivel = own_limit - allocated_total
 *        - filiais[]: cada filial vinculada com info da alocação atual
 *        - allocations[]: registros ativos OU revogados (limite 50)
 *
 * POST   /api/push/allocations.php
 *        Body: {_csrf, target_account_id, allocated, observacoes?}
 *        → cria allocation. Erros: 400 (validação), 402 (pool insuficiente),
 *          403 (sem permissão), 409 (já existe ativa pra esse target).
 *
 * PATCH  /api/push/allocations.php?id=X
 *        Body: {_csrf, allocated, observacoes?}
 *        → ajusta allocated da row (pode aumentar/diminuir).
 *
 * DELETE /api/push/allocations.php?id=X
 *        Body: {_csrf}
 *        → soft revoke (status='revoked', revoked_at/by).
 *
 * REGRAS DE NEGÓCIO:
 *  - Quem chama precisa ser admin/owner da MATRIZ (canManageQuotaAllocations).
 *  - target precisa ser filial vinculada via account_vinculos
 *    (status='active' AND sync_enabled=1 AND sync_monitoramentos=1).
 *  - pool_disponivel = own_limit - SUM(allocations ativas excluindo a row em edit).
 *  - Não pode alocar mais do que o pool disponível.
 *  - Pode reduzir abaixo do que filial já tá usando? SIM (mas a filial não
 *    consegue criar novos; existentes continuam até cancelar).
 *
 * AUDIT: MonitorAudit::log('quota_allocated' | 'quota_revoked', ...)
 *
 * @since 2026-05-26 (Etapa 8 add-on Monitoramentos)
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
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

// CSRF check pra mutating methods
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

    // Permissão: só admin da matriz pode mexer aqui (exceto GET — também
    // libera pra owner/admin pra ver o estado do próprio pool).
    if ($method !== 'GET') {
        MonitorPermission::assertCanManageQuotaAllocations($ctx);
    } else {
        // GET — basta ser owner/admin (não exige matriz, pra filiais
        // verem que cota receberam)
        if (!$ctx->isSuperAdmin() && !$ctx->isOwnerOrAdmin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sem permissão']);
            exit;
        }
    }

    switch ($method) {
        case 'GET':
            handleGet($pdo, $ctx);
            break;

        case 'POST':
            handlePost($pdo, $ctx, $payload, $userId);
            break;

        case 'PATCH':
            $id = (int) ($_GET['id'] ?? $payload['id'] ?? 0);
            handlePatch($pdo, $ctx, $payload, $userId, $id);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? $payload['id'] ?? 0);
            handleDelete($pdo, $ctx, $userId, $id);
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

    // Status da matriz (sempre olhamos do ponto de vista da própria conta
    // — quem chama GET é a matriz; filial vê seu próprio status via
    // /api/push/quota.php).
    $own        = MonitorQuota::getOwnLimit($accountId);
    $allocSum   = sumActiveAllocations($pdo, $accountId);
    $usedMatriz = MonitorQuota::getCurrentUsage($accountId);

    // Lista filiais vinculadas (status=active, sync_monitoramentos=1)
    $filiais = listFiliaisVinculadas($pdo, $accountId);

    // Mescla com allocations existentes
    $allocsByFilial = fetchActiveAllocationsByTarget($pdo, $accountId);
    foreach ($filiais as &$f) {
        $alloc = $allocsByFilial[$f['account_id']] ?? null;
        $f['allocation_id']  = $alloc['id']         ?? null;
        $f['allocated']      = $alloc['allocated']  ?? 0;
        $f['has_allocation'] = $alloc !== null;
        $f['current_usage']  = MonitorQuota::getCurrentUsage((int) $f['account_id']);
    }
    unset($f);

    // Histórico de allocations (ativas + revogadas recentes)
    $history = fetchAllocationHistory($pdo, $accountId, 50);

    ApiResponse::ok([
        'parent' => [
            'account_id'        => $accountId,
            'own_limit'         => $own,
            'allocated_total'   => $allocSum,
            'pool_disponivel'   => max(0, $own - $allocSum),
            'matriz_usage'      => $usedMatriz,
        ],
        'filiais'      => $filiais,
        'allocations'  => $history,
    ]);
}

function handlePost(PDO $pdo, AccountContext $ctx, array $payload, int $userId): void
{
    $parentId  = $ctx->getAccountId();
    $targetAcc = (int) ($payload['target_account_id'] ?? 0);
    $allocated = (int) ($payload['allocated'] ?? 0);
    $obs       = trim((string) ($payload['observacoes'] ?? ''));

    if ($targetAcc <= 0 || $allocated <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'target_account_id e allocated > 0 obrigatórios']);
        exit;
    }

    // Valida vínculo
    if (!isFilialVinculada($pdo, $parentId, $targetAcc)) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => 'Filial não está vinculada à matriz ou sync_monitoramentos desligado',
            'code'  => 'invalid_target',
        ]);
        exit;
    }

    // Já tem ativa pra esse target?
    $stmt = $pdo->prepare(
        "SELECT id FROM monitor_quota_allocations
          WHERE parent_account_id = :pid
            AND target_account_id = :tid
            AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['pid' => $parentId, 'tid' => $targetAcc]);
    if ($stmt->fetchColumn()) {
        http_response_code(409);
        echo json_encode([
            'ok'    => false,
            'error' => 'Já existe alocação ativa pra essa filial. Use PATCH pra ajustar.',
            'code'  => 'allocation_exists',
        ]);
        exit;
    }

    // Pool disponível?
    $own       = MonitorQuota::getOwnLimit($parentId);
    $allocSum  = sumActiveAllocations($pdo, $parentId);
    $disponivel = $own - $allocSum;

    if ($allocated > $disponivel) {
        http_response_code(402);
        echo json_encode([
            'ok'    => false,
            'error' => "Pool insuficiente: disponível {$disponivel}, tentou alocar {$allocated}.",
            'code'  => 'pool_insuficiente',
            'pool_disponivel' => $disponivel,
        ]);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO monitor_quota_allocations
           (parent_account_id, target_account_id, target_user_id,
            allocated, status, created_by, observacoes, created_at, updated_at)
         VALUES
           (:pid, :tid, NULL, :alloc, 'active', :uid, :obs, NOW(), NOW())"
    )->execute([
        'pid'   => $parentId,
        'tid'   => $targetAcc,
        'alloc' => $allocated,
        'uid'   => $userId,
        'obs'   => $obs !== '' ? $obs : null,
    ]);
    $id = (int) $pdo->lastInsertId();

    MonitorAudit::log(
        'quota_allocated',
        $parentId,
        null,
        "Alocou {$allocated} monitor(s) pra filial #{$targetAcc}",
        null,
        [
            'allocation_id'     => $id,
            'target_account_id' => $targetAcc,
            'allocated'         => $allocated,
            'observacoes'       => $obs ?: null,
        ]
    );

    // Reflete também na filial (account_id afetado=target)
    MonitorAudit::log(
        'quota_allocated',
        $targetAcc,
        null,
        "Recebeu alocação de {$allocated} monitor(s) da matriz #{$parentId}",
        null,
        [
            'allocation_id'     => $id,
            'parent_account_id' => $parentId,
            'allocated'         => $allocated,
        ]
    );

    ApiResponse::ok(['id' => $id, 'allocated' => $allocated]);
}

function handlePatch(PDO $pdo, AccountContext $ctx, array $payload, int $userId, int $id): void
{
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id obrigatório']);
        exit;
    }
    if (!isset($payload['allocated'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'allocated obrigatório']);
        exit;
    }

    $parentId  = $ctx->getAccountId();
    $newAlloc  = (int) $payload['allocated'];
    $obs       = isset($payload['observacoes']) ? trim((string) $payload['observacoes']) : null;

    if ($newAlloc <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'allocated deve ser > 0 (use DELETE pra revogar)']);
        exit;
    }

    // Carrega row atual (e valida tenant)
    $stmt = $pdo->prepare(
        "SELECT id, target_account_id, allocated, status
           FROM monitor_quota_allocations
          WHERE id = :id AND parent_account_id = :pid LIMIT 1"
    );
    $stmt->execute(['id' => $id, 'pid' => $parentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Alocação não encontrada']);
        exit;
    }
    if ($row['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Alocação não está ativa']);
        exit;
    }

    $oldAlloc = (int) $row['allocated'];
    if ($oldAlloc === $newAlloc && $obs === null) {
        ApiResponse::ok(['id' => $id, 'allocated' => $oldAlloc, 'unchanged' => true]);
        return;
    }

    // Pool disponível desconsiderando ESTA row
    $own        = MonitorQuota::getOwnLimit($parentId);
    $allocSum   = sumActiveAllocations($pdo, $parentId, excludeId: $id);
    $disponivel = $own - $allocSum;

    if ($newAlloc > $disponivel) {
        http_response_code(402);
        echo json_encode([
            'ok'    => false,
            'error' => "Pool insuficiente: disponível {$disponivel}, tentou alocar {$newAlloc}.",
            'code'  => 'pool_insuficiente',
            'pool_disponivel' => $disponivel,
        ]);
        exit;
    }

    $sets   = ['allocated = :alloc', 'updated_at = NOW()'];
    $params = ['id' => $id, 'pid' => $parentId, 'alloc' => $newAlloc];

    if ($obs !== null) {
        $sets[]      = 'observacoes = :obs';
        $params['obs'] = $obs !== '' ? $obs : null;
    }

    $upd = $pdo->prepare(
        "UPDATE monitor_quota_allocations
            SET " . implode(', ', $sets) . "
          WHERE id = :id AND parent_account_id = :pid"
    );
    $upd->execute($params);

    $delta = $newAlloc - $oldAlloc;
    $verbo = $delta > 0 ? 'aumentou' : 'reduziu';
    MonitorAudit::log(
        'quota_allocated',
        $parentId,
        null,
        "Re-alocação #{$id}: {$verbo} de {$oldAlloc} → {$newAlloc} (filial #{$row['target_account_id']})",
        ['allocated' => $oldAlloc],
        ['allocated' => $newAlloc, 'delta' => $delta]
    );

    ApiResponse::ok(['id' => $id, 'allocated' => $newAlloc, 'previous' => $oldAlloc]);
}

function handleDelete(PDO $pdo, AccountContext $ctx, int $userId, int $id): void
{
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id obrigatório']);
        exit;
    }
    $parentId = $ctx->getAccountId();

    $stmt = $pdo->prepare(
        "SELECT id, target_account_id, allocated, status
           FROM monitor_quota_allocations
          WHERE id = :id AND parent_account_id = :pid LIMIT 1"
    );
    $stmt->execute(['id' => $id, 'pid' => $parentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Alocação não encontrada']);
        exit;
    }
    if ($row['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Alocação já revogada']);
        exit;
    }

    $upd = $pdo->prepare(
        "UPDATE monitor_quota_allocations
            SET status     = 'revoked',
                revoked_at = NOW(),
                revoked_by = :uid,
                updated_at = NOW()
          WHERE id = :id AND parent_account_id = :pid"
    );
    $upd->execute(['id' => $id, 'pid' => $parentId, 'uid' => $userId]);

    $targetAcc = (int) $row['target_account_id'];
    $alloc     = (int) $row['allocated'];

    MonitorAudit::log(
        'quota_revoked',
        $parentId,
        null,
        "Revogou alocação #{$id} (filial #{$targetAcc}, {$alloc} monitor(s))",
        ['allocated' => $alloc, 'status' => 'active'],
        ['status' => 'revoked']
    );
    MonitorAudit::log(
        'quota_revoked',
        $targetAcc,
        null,
        "Matriz revogou alocação de {$alloc} monitor(s)",
        null,
        ['allocation_id' => $id, 'parent_account_id' => $parentId]
    );

    ApiResponse::ok(['id' => $id, 'revoked' => true]);
}

// ───────────────────────────────────────────────────────────────────────
// HELPERS DE QUERY
// ───────────────────────────────────────────────────────────────────────

/**
 * Soma allocations ativas da matriz. Opcionalmente exclui uma row (pra
 * recálculo durante PATCH).
 */
function sumActiveAllocations(PDO $pdo, int $parentId, ?int $excludeId = null): int
{
    $sql = "SELECT COALESCE(SUM(allocated), 0)
              FROM monitor_quota_allocations
             WHERE parent_account_id = :pid
               AND status = 'active'";
    $params = ['pid' => $parentId];
    if ($excludeId !== null) {
        $sql .= " AND id <> :xid";
        $params['xid'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Lista filiais vinculadas à matriz com sync_monitoramentos=1.
 */
function listFiliaisVinculadas(PDO $pdo, int $matrizId): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id        AS account_id,
                a.nome      AS nome,
                a.tipo      AS tipo,
                a.status    AS status,
                av.sync_monitoramentos
           FROM account_vinculos av
           JOIN accounts a ON a.id = av.filial_account_id
          WHERE av.matriz_account_id = :mid
            AND av.status            = 'active'
            AND av.sync_enabled      = 1
            AND av.sync_monitoramentos = 1
          ORDER BY a.nome"
    );
    $stmt->execute(['mid' => $matrizId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Retorna allocations ativas indexadas pelo target_account_id.
 */
function fetchActiveAllocationsByTarget(PDO $pdo, int $parentId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, target_account_id, allocated, created_at, observacoes
           FROM monitor_quota_allocations
          WHERE parent_account_id = :pid AND status = 'active'"
    );
    $stmt->execute(['pid' => $parentId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['target_account_id']] = $r;
    }
    return $out;
}

/**
 * Histórico (ativas + revogadas recentes) com nome da filial.
 */
function fetchAllocationHistory(PDO $pdo, int $parentId, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare(
        "SELECT mqa.id, mqa.target_account_id, mqa.allocated, mqa.status,
                mqa.observacoes, mqa.created_at, mqa.revoked_at,
                mqa.created_by, mqa.revoked_by,
                a.nome  AS target_nome,
                uc.nome AS created_by_nome,
                ur.nome AS revoked_by_nome
           FROM monitor_quota_allocations mqa
           LEFT JOIN accounts a ON a.id = mqa.target_account_id
           LEFT JOIN users uc   ON uc.id = mqa.created_by
           LEFT JOIN users ur   ON ur.id = mqa.revoked_by
          WHERE mqa.parent_account_id = :pid
          ORDER BY (mqa.status = 'active') DESC, mqa.created_at DESC
          LIMIT {$limit}"
    );
    $stmt->execute(['pid' => $parentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Confirma que filial existe e é vinculada à matriz com sync_monitoramentos=1.
 */
function isFilialVinculada(PDO $pdo, int $matrizId, int $filialId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM account_vinculos
          WHERE matriz_account_id = :mid
            AND filial_account_id = :fid
            AND status = 'active'
            AND sync_enabled = 1
            AND sync_monitoramentos = 1
          LIMIT 1"
    );
    $stmt->execute(['mid' => $matrizId, 'fid' => $filialId]);
    return (bool) $stmt->fetchColumn();
}
