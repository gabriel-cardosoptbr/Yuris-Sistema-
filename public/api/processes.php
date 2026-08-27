<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Processos/Processo.php';
require_once __DIR__ . '/../../app/Prospeccao/Contato.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Integracoes/WebhookDispatcher.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../app/Core/TenantGuard.php';
require_once __DIR__ . '/../../app/Processos/ProcessoAudit.php';
require_once __DIR__ . '/../../app/Core/ErrorReporter.php';

use App\Integracoes\WebhookDispatcher;
use App\Processos\Processo;
use App\Core\Database;
use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Processos\ProcessoAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// CSRF defense (2026-05-26 — auditoria de seg pré-deploy): aceita request se
// Origin/Referer = same-origin OU token CSRF presente. Bloqueia cross-site.
// Cobre POST/PUT/PATCH/DELETE (state-changing). GET é deixado em paz.
TenantGuard::requireSameOriginOrCsrf();

// Carrega contexto de tenant — aborta com 401 se sessão inválida
$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();  // NUNCA lido do request

// Garante que a coluna card_id existe na tabela processos
try {
    $pdo = Database::getConnection();
    $pdo->exec("ALTER TABLE processos ADD COLUMN IF NOT EXISTS card_id INT DEFAULT NULL");
} catch (\Throwable $e) { /* ignora se já existe ou DB não suporta IF NOT EXISTS */ }

$method = $_SERVER['REQUEST_METHOD'];
try {
    if ($method === 'GET') {
        if (!empty($_GET['schema'])) {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("DESCRIBE processos");
            $cols = $stmt->fetchAll();
            echo json_encode(['schema' => $cols]);
            exit;
        }
        if (!empty($_GET['id'])) {
            // Valida tenant ANTES de retornar dados — bloqueia leitura cross-tenant
            TenantGuard::assertProcessoAcessivel($ctx, (int)$_GET['id']);
            $p = Processo::find((int)$_GET['id']);
            echo json_encode($p ?: []);
            exit;
        }
        $filters = [
            'account_ids' => $ctx->getAccessibleAccountIds('processos'),
            'user_id'     => $ctx->getUserId(),
        ];
        if (!empty($_GET['responsavel_user_id'])) $filters['responsavel_user_id'] = (int)$_GET['responsavel_user_id'];
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
        if (!empty($_GET['from']) && !empty($_GET['to'])) { $filters['from'] = $_GET['from']; $filters['to'] = $_GET['to']; }
        if (isset($_GET['card_id']) && $_GET['card_id'] !== '') $filters['card_id'] = (int)$_GET['card_id'];
        if (isset($_GET['cliente_id']) && $_GET['cliente_id'] !== '') $filters['cliente_id'] = (int)$_GET['cliente_id'];
        $list = Processo::list($filters);
        echo json_encode(['data'=>$list]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $input['account_id'] = $accountId;  // injeta tenant server-side, nunca do body
        // SEGURANCA (auditoria 2026-06-01): valida o tenant das fontes de vinculo
        // ANTES de gravar. Sem isso, um usuario podia mandar card_id/cliente_id de
        // outro tenant e o processo herdava o contato_id alheio (vazamento cross-tenant).
        if (!empty($input['card_id']))    TenantGuard::assertCardAcessivel($ctx, (int)$input['card_id']);
        if (!empty($input['cliente_id'])) TenantGuard::assertClienteAcessivel($ctx, (int)$input['cliente_id']);
        $id = Processo::create($input);
        $p = Processo::find($id);
        // Auditoria garantida server-side (não depende do JS)
        ProcessoAudit::log(
            (int)$id,
            'Processo criado',
            'Processo ' . ($p['numero'] ?? '#'.$id) . ' cadastrado no sistema'
        );
        WebhookDispatcher::fire($accountId, 'processo.created', WebhookDispatcher::buildPayload('processo.created', [
            'entity' => 'processo', 'entity_id' => $id, 'processo_id' => $id,
            'cliente_id' => $p['contato_id'] ?? null, 'data' => $p,
        ]));
        echo json_encode(['success'=>true,'id'=>$id,'data'=>$p]);
        exit;
    }

    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = $input['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        // Valida tenant ANTES de atualizar — bloqueia edição cross-tenant
        TenantGuard::assertProcessoAcessivel($ctx, (int)$id);
        // SEGURANCA (auditoria 2026-06-01): valida tenant do card_id/cliente_id
        // recebidos antes de vincular — impede vincular recurso de outro tenant
        // e herdar contato_id alheio.
        if (!empty($input['card_id']))    TenantGuard::assertCardAcessivel($ctx, (int)$input['card_id']);
        if (!empty($input['cliente_id'])) TenantGuard::assertClienteAcessivel($ctx, (int)$input['cliente_id']);
        // Remove campos imutáveis do payload (defesa em profundidade)
        unset($input['account_id'], $input['account_seq']);
        $prev = Processo::find((int)$id);
        $ok = Processo::update((int)$id, $input);
        if ($ok && $prev) {
            $updated = Processo::find((int)$id);
            // Auditoria garantida server-side (compara prev vs new, lista campos alterados + de/para)
            ProcessoAudit::logChanges((int)$id, $prev, $input);
            $eventKey = 'processo.updated';
            if (isset($input['status']) && ($prev['status'] ?? null) !== $input['status']) {
                $eventKey = 'processo.status_changed';
            } elseif (isset($input['responsavel_user_id']) && ($prev['responsavel_user_id'] ?? null) != $input['responsavel_user_id']) {
                $eventKey = 'processo.responsavel_changed';
            } elseif (isset($input['fase']) && ($prev['fase'] ?? null) !== $input['fase']) {
                $eventKey = 'processo.etapa_changed';
            } elseif (isset($input['etapa']) && ($prev['etapa'] ?? null) !== $input['etapa']) {
                $eventKey = 'processo.etapa_changed';
            }
            // Usa account_id do processo (preserva ownership em shares)
            $ownerAcc = (int)($updated['account_id'] ?? $prev['account_id'] ?? $accountId);
            WebhookDispatcher::fire($ownerAcc, $eventKey, WebhookDispatcher::buildPayload($eventKey, [
                'entity' => 'processo', 'entity_id' => (int)$id, 'processo_id' => (int)$id,
                'cliente_id' => $updated['contato_id'] ?? null,
                'data' => $updated, 'previous_data' => $prev,
            ]));
        }
        echo json_encode(['success'=>$ok]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = $input['id'] ?? null;
        }
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
        // Valida tenant ANTES de soft-delete — bloqueia exclusão cross-tenant
        TenantGuard::assertProcessoAcessivel($ctx, (int)$id);
        $prev = Processo::find((int)$id);
        $ok = Processo::softDelete((int)$id);
        if ($ok && $prev) {
            // Auditoria server-side: registra exclusão antes que o soft-delete esconda o processo
            ProcessoAudit::log(
                (int)$id,
                'Processo excluído',
                'Processo ' . ($prev['numero'] ?? '#'.$id) . ' removido (soft-delete)'
            );
            $ownerAcc = (int)($prev['account_id'] ?? $accountId);
            WebhookDispatcher::fire($ownerAcc, 'processo.deleted', WebhookDispatcher::buildPayload('processo.deleted', [
                'entity' => 'processo', 'entity_id' => (int)$id, 'processo_id' => (int)$id,
                'cliente_id' => $prev['contato_id'] ?? null, 'data' => $prev,
            ]));
        }
        echo json_encode(['success'=>$ok]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error'=>'Method not allowed']);
    exit;
} catch (\Throwable $e) {
    // P1 LGPD (2D.1): erros padronizados — em prod esconde getMessage
    \App\Core\ErrorReporter::handle($e);
}
