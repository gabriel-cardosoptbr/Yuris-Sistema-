<?php
/**
 * API: /api/resource_shares.php
 * Compartilhamento seletivo de recursos entre contas.
 *
 * GET    /api/resource_shares.php?resource_type=processo&resource_id=42  → lista quem tem acesso
 * GET    /api/resource_shares.php?shared_with_me=1&resource_type=processo → recursos compartilhados comigo
 * POST   /api/resource_shares.php                                         → cria share
 *          Body: { resource_type, resource_id, to_account_id?, to_user_id?, permission_level }
 * DELETE /api/resource_shares.php?id=5                                    → revoga share
 *
 * SEGURANÇA:
 *   - from_account_id SEMPRE vem da sessão (nunca do body)
 *   - Valida que a conta dona é realmente dona do recurso
 *   - Valida vínculo ativo entre as contas antes de compartilhar
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Master/AccountNotification.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../app/Processos/ProcessoAudit.php';

use App\Master\Account;
use App\Core\Database;
use App\Master\ResourceShare;
use App\Master\AccountNotification;
use App\Core\AccountContext;
use App\Processos\ProcessoAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');

// ─── helpers locais p/ auditoria no processo_history ────────────────────
// (somente usados quando resource_type='processo')
$_permLabel = static function (string $p): string {
    return ['view' => 'Visualização', 'edit' => 'Edição', 'full' => 'Acesso total'][$p] ?? $p;
};
$_tipoLabel = static function (string $t): string {
    return ['matriz' => 'Matriz', 'filial' => 'Filial', 'advogado' => 'Advogado solo'][$t] ?? $t;
};
// Descreve o destinatário do share em português, ex.:
//   "Conta Gabriel (Advogado solo · código d6fe-a916-08c0-fea8)"
//   "Advogado Silvana Monteiro (ADV-3504EB)"
$_describeTarget = static function (?int $toAccountId, ?int $toUserId) use ($_tipoLabel): string {
    try {
        $pdo = Database::getConnection();
        if ($toUserId) {
            $st = $pdo->prepare("SELECT nome, codigo_advogado FROM users WHERE id = :id LIMIT 1");
            $st->execute(['id' => $toUserId]);
            $u = $st->fetch(\PDO::FETCH_ASSOC);
            if ($u) {
                $cod = $u['codigo_advogado'] ? " ({$u['codigo_advogado']})" : '';
                return "Advogado {$u['nome']}{$cod}";
            }
        }
        if ($toAccountId) {
            $st = $pdo->prepare("SELECT nome, tipo, codigo_vinculo FROM accounts WHERE id = :id LIMIT 1");
            $st->execute(['id' => $toAccountId]);
            $a = $st->fetch(\PDO::FETCH_ASSOC);
            if ($a) {
                $bits = [$_tipoLabel($a['tipo'] ?? '')];
                if (!empty($a['codigo_vinculo'])) $bits[] = "código {$a['codigo_vinculo']}";
                return "Conta {$a['nome']} (" . implode(' · ', $bits) . ")";
            }
        }
    } catch (\Throwable $_e) { /* fall through */ }
    return $toUserId ? "Advogado #{$toUserId}" : "Conta #{$toAccountId}";
};

$ctx    = AccountContext::fromSession();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF
if (in_array($method, ['POST', 'DELETE', 'PATCH'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Lista módulos liberados PELA conta atual (shares de tipo module emitidos por ela)
    if (!empty($_GET['listar_modulos'])) {
        $pdo  = \App\Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT rs.*, a.nome AS to_account_nome, tu.nome AS to_user_nome
             FROM resource_shares rs
             LEFT JOIN accounts a  ON a.id  = rs.to_account_id
             LEFT JOIN users    tu ON tu.id = rs.to_user_id
             WHERE rs.resource_type   = 'module'
               AND rs.from_account_id = :acc
             ORDER BY rs.created_at DESC"
        );
        $stmt->execute(['acc' => $ctx->getAccountId()]);
        echo json_encode(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        exit;
    }

    // Lista TODOS shares emitidos pela conta atual (exceto module, que tem rota propria).
    // Usado pela aba "Compartilhamentos pontuais por processo" em /escritorios.php.
    // Traz codigo_vinculo (conta) + codigo_advogado (user) + tipo da conta destino,
    // pra UI mostrar info completa do destinatario.
    if (!empty($_GET['listar_meus'])) {
        $pdo  = \App\Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT rs.*,
                    a.nome  AS to_account_nome,
                    a.tipo  AS to_account_tipo,
                    a.codigo_vinculo AS to_account_codigo,
                    tu.nome AS to_user_nome,
                    tu.codigo_advogado AS to_user_codigo_adv,
                    tu.codigo_vinculo  AS to_user_codigo_vinculo,
                    -- Resolve label humano do recurso pra UI nao mostrar so '#10'
                    CASE rs.resource_type
                      WHEN 'processo' THEN (SELECT COALESCE(p.numero, CONCAT('Processo #', p.id)) FROM processos p WHERE p.id = rs.resource_id LIMIT 1)
                      WHEN 'card'     THEN (SELECT COALESCE(c.cliente_nome, c.empresa_nome, CONCAT('Card #', c.id)) FROM cards c WHERE c.id = rs.resource_id LIMIT 1)
                      WHEN 'contato'  THEN (SELECT COALESCE(co.nome, CONCAT('Contato #', co.id)) FROM contatos co WHERE co.id = rs.resource_id LIMIT 1)
                      ELSE NULL
                    END AS resource_label
             FROM resource_shares rs
             LEFT JOIN accounts a  ON a.id  = rs.to_account_id
             LEFT JOIN users    tu ON tu.id = rs.to_user_id
             WHERE rs.from_account_id = :acc
               AND rs.resource_type   <> 'module'
               AND rs.status          = 'active'
             ORDER BY rs.created_at DESC"
        );
        $stmt->execute(['acc' => $ctx->getAccountId()]);
        echo json_encode(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        exit;
    }

    // Lista recursos compartilhados COM a conta logada
    if (!empty($_GET['shared_with_me'])) {
        $type  = $_GET['resource_type'] ?? null;
        $data  = ResourceShare::listSharedWithAccount($ctx->getAccountId(), $type ?: null);
        echo json_encode(['data' => $data]);
        exit;
    }

    // Lista shares de um recurso específico
    $type       = $_GET['resource_type'] ?? null;
    $resourceId = (int) ($_GET['resource_id'] ?? 0);

    if (!$type || !$resourceId) {
        http_response_code(400);
        echo json_encode(['error' => 'resource_type e resource_id são obrigatórios']);
        exit;
    }

    // Verifica que o usuário tem acesso ao recurso
    $ctx->assertCanRead($type, $resourceId);

    $shares = ResourceShare::listByResource($type, $resourceId);
    echo json_encode(['data' => $shares]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — cria novo compartilhamento
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $resourceType  = $input['resource_type']  ?? null;
    $resourceId    = (int) ($input['resource_id'] ?? 0);
    $moduleKey     = isset($input['module_key']) ? trim((string)$input['module_key']) : null;
    $toAccountId   = isset($input['to_account_id'])  ? (int)$input['to_account_id']  : null;
    $toUserId      = isset($input['to_user_id'])      ? (int)$input['to_user_id']      : null;
    $permLevel     = $input['permission_level']       ?? 'view';

    // Validações básicas
    if (!$resourceType) {
        http_response_code(400);
        echo json_encode(['error' => 'resource_type é obrigatório']);
        exit;
    }

    // Validação especial para módulos
    $modulesAllowed = ['processos','juridico','dashboard','planejamento','prospeccao','financas','tarefas','chat','chat_interno'];
    if ($resourceType === 'module') {
        if (!$moduleKey || !in_array($moduleKey, $modulesAllowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'module_key obrigatório e deve ser um de: ' . implode(', ', $modulesAllowed)]);
            exit;
        }
        $resourceId = 0;  // convenção: módulo não tem id de recurso
    } elseif (!$resourceId) {
        http_response_code(400);
        echo json_encode(['error' => 'resource_id é obrigatório quando resource_type != module']);
        exit;
    }

    if (!in_array($permLevel, ['view', 'edit', 'full'])) {
        http_response_code(400);
        echo json_encode(['error' => 'permission_level inválido. Use: view, edit ou full']);
        exit;
    }

    // Apenas owner/admin pode compartilhar
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode compartilhar recursos']);
        exit;
    }

    // A conta logada precisa ser DONA do recurso (somente para recursos com id)
    if ($resourceType !== 'module') {
        $ctx->assertIsOwnerOfResource($resourceType, $resourceId);
    }

    // Valida que a conta de destino existe e está em estado utilizável.
    // Aceita active/trial/overdue — mesma whitelist do AuthController e do
    // lookup. Só suspended/cancelled/inactive bloqueiam compartilhamento.
    // Advogado Associado não exige vínculo Matriz/Filial formal — apenas conta Yuris utilizável.
    if ($toAccountId !== null) {
        $contaDestino = Account::findById($toAccountId);
        $statusOk     = ['active','trial','overdue'];
        if (!$contaDestino || !in_array($contaDestino['status'] ?? '', $statusOk, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Conta de destino não encontrada ou indisponível']);
            exit;
        }
    }

    try {
        $shareId = ResourceShare::create([
            'resource_type'   => $resourceType,
            'resource_id'     => $resourceId,
            'module_key'      => $moduleKey,
            'from_account_id' => $ctx->getAccountId(),
            'to_account_id'   => $toAccountId,
            'to_user_id'      => $toUserId,
            'permission_level'=> $permLevel,
            'criado_por'      => $ctx->getUserId(),
        ]);
    } catch (\InvalidArgumentException $e) {
        // P1 LGPD (2D.1): InvalidArgumentException tem mensagem segura
        // (validação de input) — mantém visível ao cliente mesmo em prod
        // mas usa o helper pra padronizar resposta + correlation_id
        require_once __DIR__ . '/../../app/Core/ErrorReporter.php';
        \App\Core\ErrorReporter::handle($e, 400, $e->getMessage());
    }

    // Notifica a conta de destino (se especificada)
    if ($toAccountId) {
        $sourceAccount = Account::findById($ctx->getAccountId());
        AccountNotification::criar([
            'account_id' => $toAccountId,
            'user_id'    => null,
            'tipo'       => 'share.criado',
            'titulo'     => "Recurso compartilhado por {$sourceAccount['nome']}",
            'mensagem'   => "Um {$resourceType} foi compartilhado com sua conta com permissão: {$permLevel}.",
            'payload'    => [
                'share_id'      => $shareId,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'permission'    => $permLevel,
            ],
        ]);
    }

    Account::audit($ctx->getAccountId(), 'share.criado', [
        'user_id'       => $ctx->getUserId(),
        'resource_type' => $resourceType,
        'resource_id'   => $resourceId,
        'detalhes'      => ['share_id' => $shareId, 'to_account_id' => $toAccountId, 'permission' => $permLevel],
    ]);

    // Loga no histórico do processo quando o recurso for um processo.
    // Ex.: "Vínculo criado com Conta Gabriel (Advogado solo · código d6fe-a916-08c0-fea8) — Acesso total"
    if ($resourceType === 'processo' && $resourceId > 0) {
        $alvo = $_describeTarget($toAccountId, $toUserId);
        ProcessoAudit::log(
            $resourceId,
            'Vínculo criado',
            "Vínculo criado com {$alvo} — {$_permLabel($permLevel)}"
        );
    }

    echo json_encode(['success' => true, 'id' => $shareId]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PATCH — edita permission_level de um share existente
//   Body: { id, permission_level }
//   Apenas a conta DONA do recurso (from_account_id == sessão) pode editar.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $id   = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    $perm = $input['permission_level'] ?? null;

    if (!$id || !$perm) {
        http_response_code(400);
        echo json_encode(['error' => 'id e permission_level são obrigatórios']);
        exit;
    }
    if (!in_array($perm, ['view', 'edit', 'full'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'permission_level inválido. Use: view, edit ou full']);
        exit;
    }

    $share = ResourceShare::findById($id);
    if (!$share) {
        http_response_code(404);
        echo json_encode(['error' => 'Share não encontrado']);
        exit;
    }

    if ((int)$share['from_account_id'] !== $ctx->getAccountId()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas a conta que criou o compartilhamento pode editá-lo']);
        exit;
    }

    if ((string)$share['permission_level'] === (string)$perm) {
        // No-op: cliente mandou o mesmo valor. Não loga, só confirma.
        echo json_encode(['success' => true, 'changed' => false]);
        exit;
    }

    $ok = ResourceShare::updatePermission($id, $perm);

    Account::audit($ctx->getAccountId(), 'share.editado', [
        'user_id'       => $ctx->getUserId(),
        'resource_type' => $share['resource_type'],
        'resource_id'   => $share['resource_id'],
        'detalhes'      => [
            'share_id'         => $id,
            'permission_from'  => $share['permission_level'],
            'permission_to'    => $perm,
            'to_account_id'    => $share['to_account_id'],
            'to_user_id'       => $share['to_user_id'],
        ],
    ]);

    // Loga no histórico do processo quando aplicável.
    // Ex.: "Vínculo com Conta Gabriel: Edição → Acesso total"
    if ($share['resource_type'] === 'processo' && (int)$share['resource_id'] > 0) {
        $alvo = $_describeTarget(
            isset($share['to_account_id']) ? (int)$share['to_account_id'] : null,
            isset($share['to_user_id'])    ? (int)$share['to_user_id']    : null
        );
        ProcessoAudit::log(
            (int)$share['resource_id'],
            'Vínculo editado',
            "Vínculo com {$alvo}: {$_permLabel($share['permission_level'])} → {$_permLabel($perm)}"
        );
    }

    echo json_encode(['success' => $ok, 'changed' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE — revoga share
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }

    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode revogar compartilhamentos']);
        exit;
    }

    $share = ResourceShare::findById($id);
    if (!$share) { http_response_code(404); echo json_encode(['error' => 'Share não encontrado']); exit; }

    // Apenas a conta dona pode revogar
    if ((int)$share['from_account_id'] !== $ctx->getAccountId()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas a conta dona do recurso pode revogar o compartilhamento']);
        exit;
    }

    $ok = ResourceShare::revogar($id, $ctx->getUserId());

    Account::audit($ctx->getAccountId(), 'share.revogado', [
        'user_id'       => $ctx->getUserId(),
        'resource_type' => $share['resource_type'],
        'resource_id'   => $share['resource_id'],
        'detalhes'      => ['share_id' => $id],
    ]);

    // Loga no histórico do processo quando aplicável.
    // Ex.: "Vínculo revogado: Conta Gabriel (era Acesso total)"
    if ($share['resource_type'] === 'processo' && (int)$share['resource_id'] > 0) {
        $alvo = $_describeTarget(
            isset($share['to_account_id']) ? (int)$share['to_account_id'] : null,
            isset($share['to_user_id'])    ? (int)$share['to_user_id']    : null
        );
        ProcessoAudit::log(
            (int)$share['resource_id'],
            'Vínculo revogado',
            "Vínculo revogado: {$alvo} (era {$_permLabel($share['permission_level'])})"
        );
    }

    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
