<?php
/**
 * /api/master/processors.php — inventário de operadores (LGPD Art. 33 + 39).
 *
 * Acesso: super_admin em sessão master_mode.
 *
 * Endpoints:
 *   GET                              → lista (filtros: categoria, dpa_status, ativo,
 *                                              transferencia_internacional, q, pendentes,
 *                                              vencendo_dias=N)
 *   GET ?id=N                        → detalhe + history
 *   GET ?counts=1                    → contagens (badge)
 *   GET ?action=export_inventory     → JSON do inventário (Art. 33 evidência)
 *
 *   POST                             → cria operador (body JSON)
 *   PATCH                            → atualiza (body: { id, ...campos })
 *   POST ?action=sign_dpa            → marca dpa_status=assinado (body: { id, assinado_em, validade?, url? })
 *   POST ?action=deactivate          → desativa (body: { id, motivo })
 *   POST ?action=add_event           → comentário manual na timeline
 *
 * Toda mutação é registrada em master_audit_log via MasterAudit::log().
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Lgpd/DataProcessor.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';
require_once __DIR__ . '/../../../app/Core/RequestId.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Lgpd\DataProcessor;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $ctx->getUserId();

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['counts'])) {
        ApiResponse::ok(DataProcessor::countByStatus());
    }
    if (($_GET['action'] ?? null) === 'export_inventory') {
        MasterAudit::log('processor.export_inventory', 'processor', null,
            'Inventário de operadores exportado', []);
        ApiResponse::ok(DataProcessor::exportInventory());
    }
    if (!empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $p  = DataProcessor::findById($id);
        if (!$p) ApiResponse::notFound('Operador não encontrado.');
        ApiResponse::ok([
            'processor' => $p,
            'history'   => DataProcessor::listHistory($id),
        ]);
    }
    $list = DataProcessor::listForMaster([
        'categoria'                    => $_GET['categoria']                    ?? null,
        'dpa_status'                   => $_GET['dpa_status']                   ?? null,
        'transferencia_internacional'  => $_GET['transferencia_internacional']  ?? '',
        'ativo'                        => $_GET['ativo']                        ?? '',
        'q'                            => $_GET['q']                            ?? null,
        'pendentes'                    => $_GET['pendentes']                    ?? null,
        'vencendo_dias'                => $_GET['vencendo_dias']                ?? null,
    ]);
    ApiResponse::ok($list);
}

// ─── CSRF para mutações ──────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

// ─── POST ?action=sign_dpa ───────────────────────────────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'sign_dpa') {
    $id   = (int)($input['id'] ?? 0);
    $ass  = trim((string)($input['assinado_em'] ?? ''));
    $val  = !empty($input['validade']) ? (string)$input['validade'] : null;
    $url  = !empty($input['url']) ? (string)$input['url'] : null;
    if (!$id || $ass === '') ApiResponse::badRequest('id e assinado_em obrigatórios');
    if (!DataProcessor::findById($id)) ApiResponse::notFound();

    DataProcessor::signDpa($id, $ass, $val, $url, $userId);
    MasterAudit::log('processor.sign_dpa', 'processor', $id,
        "DPA assinado em $ass" . ($val ? " (valido ate $val)" : ''),
        ['assinado_em' => $ass, 'validade' => $val, 'url' => $url]);
    ApiResponse::ok(['signed' => true]);
}

// ─── POST ?action=deactivate ─────────────────────────────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'deactivate') {
    $id     = (int)($input['id'] ?? 0);
    $motivo = trim((string)($input['motivo'] ?? ''));
    if (!$id) ApiResponse::badRequest('id obrigatório');
    if (!DataProcessor::findById($id)) ApiResponse::notFound();

    DataProcessor::deactivate($id, $motivo ?: null, $userId);
    MasterAudit::log('processor.deactivate', 'processor', $id,
        "Operador desativado" . ($motivo ? " — motivo: $motivo" : ''),
        ['motivo' => $motivo]);
    ApiResponse::ok(['deactivated' => true]);
}

// ─── POST ?action=add_event — comentário manual na timeline ──────────────────
if ($method === 'POST' && ($_GET['action'] ?? null) === 'add_event') {
    $id   = (int)($input['id'] ?? 0);
    $desc = trim((string)($input['descricao'] ?? ''));
    if (!$id || $desc === '') ApiResponse::badRequest('id e descricao obrigatórios');
    if (!DataProcessor::findById($id)) ApiResponse::notFound();

    DataProcessor::addHistory($id, 'comentario', $desc, null, $userId);
    MasterAudit::log('processor.comment', 'processor', $id,
        "Comentário adicionado", ['descricao' => $desc]);
    ApiResponse::ok(['added' => true]);
}

// ─── POST — cria novo operador ───────────────────────────────────────────────
if ($method === 'POST') {
    try {
        $newId = DataProcessor::create([
            'nome'                        => $input['nome']                        ?? '',
            'categoria'                   => $input['categoria']                   ?? '',
            'papel'                       => $input['papel']                       ?? 'operador',
            'cnpj_ou_id'                  => $input['cnpj_ou_id']                  ?? null,
            'pais'                        => $input['pais']                        ?? null,
            'dados_tratados'              => $input['dados_tratados']              ?? null,
            'finalidade'                  => $input['finalidade']                  ?? null,
            'retencao_terceiro'           => $input['retencao_terceiro']           ?? null,
            'transferencia_internacional' => $input['transferencia_internacional'] ?? false,
            'base_legal_transferencia'    => $input['base_legal_transferencia']    ?? null,
            'dpa_status'                  => $input['dpa_status']                  ?? 'pendente',
            'dpa_assinado_em'             => $input['dpa_assinado_em']             ?? null,
            'dpa_validade'                => $input['dpa_validade']                ?? null,
            'dpa_url'                     => $input['dpa_url']                     ?? null,
            'certificacoes'               => $input['certificacoes']               ?? null,
            'contato_dpo_terceiro'        => $input['contato_dpo_terceiro']        ?? null,
            'url_politica_privacidade'    => $input['url_politica_privacidade']    ?? null,
            'ativo'                       => isset($input['ativo']) ? (bool)$input['ativo'] : true,
            'notas'                       => $input['notas']                       ?? null,
        ], $userId);
    } catch (\InvalidArgumentException $e) {
        ApiResponse::badRequest($e->getMessage());
    } catch (\PDOException $e) {
        // Ex: UNIQUE nome duplicado
        if ($e->getCode() === '23000') {
            ApiResponse::badRequest('Já existe operador com este nome.');
        }
        ApiResponse::serverError('Erro ao criar operador.');
    }

    MasterAudit::log('processor.create', 'processor', $newId,
        'Operador adicionado ao inventário',
        ['nome' => $input['nome'] ?? null, 'categoria' => $input['categoria'] ?? null]);

    ApiResponse::ok(['id' => $newId, 'created' => true]);
}

// ─── PATCH — atualiza ────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');
    if (!DataProcessor::findById($id)) ApiResponse::notFound();

    $up = $input;
    unset($up['id'], $up['csrf_token']);

    $ok = DataProcessor::update($id, $up, $userId);
    if ($ok) {
        MasterAudit::log('processor.update', 'processor', $id,
            'Operador atualizado',
            ['campos' => array_keys($up)]);
    }
    ApiResponse::ok(['updated' => $ok]);
}

ApiResponse::methodNotAllowed();
