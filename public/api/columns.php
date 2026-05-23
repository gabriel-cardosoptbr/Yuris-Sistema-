<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/PipelineColumn.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\PipelineColumn;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();              // 401 se sessão inválida
$accountId = $ctx->getAccountId();                       // conta da sessão
// Pipeline único da matriz (Opção 2): filial vinculada herda colunas da matriz.
// Matriz → próprias colunas. Filial isolada → próprias colunas.
// CREATE/UPDATE/DELETE só são permitidos pela conta que possui o pipeline.
$pipelineAccountId = $ctx->getPipelineAccountId();
$tenantIds         = [$pipelineAccountId];
$isInherited       = $ctx->isPipelineInherited();        // true = filial herda da matriz

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    $list = PipelineColumn::listAll(['account_ids' => $tenantIds]);
    echo json_encode([
        'data'                 => $list,
        'pipeline_account_id'  => $pipelineAccountId,
        'inherited'            => $isInherited,
    ]);
    exit;
}

// state-changing: CSRF
if (in_array($method, ['POST','PUT','DELETE','PATCH'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

// Filial vinculada NÃO pode mexer nas colunas (pipeline é da matriz).
// Apenas a matriz dona do pipeline pode criar/atualizar/deletar.
function _denyInheritedWrite(bool $isInherited): void {
    if ($isInherited) {
        http_response_code(403);
        echo json_encode([
            'error'   => 'Pipeline definido pela matriz. Solicite alteração ao administrador da matriz.',
            'code'    => 'pipeline_inherited',
        ]);
        exit;
    }
}

if ($method === 'POST') {
    _denyInheritedWrite($isInherited);
    $input['account_id'] = $pipelineAccountId;          // injeta tenant dono do pipeline
    $id = PipelineColumn::create($input);
    \App\Models\Account::audit($accountId, 'column.created', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'pipeline_column',
        'entidade_id' => (int)$id,
        'detalhes'    => [
            'nome'                => $input['nome'] ?? null,
            'cor'                 => $input['cor'] ?? null,
            'ordem'               => $input['ordem'] ?? null,
            'pipeline_account_id' => $pipelineAccountId,
        ],
    ]);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'PUT' || $method === 'PATCH') {
    _denyInheritedWrite($isInherited);
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $colId = (int)$input['id'];
    $ok = PipelineColumn::update($colId, $input, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Models\Account::audit($accountId, 'column.updated', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'pipeline_column',
        'entidade_id' => $colId,
        'detalhes'    => $input,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    _denyInheritedWrite($isInherited);
    $id = $_GET['id'] ?? ($input['id'] ?? null);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $colId = (int)$id;
    $ok = PipelineColumn::delete($colId, $tenantIds);
    if (!$ok) { http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit; }
    \App\Models\Account::audit($accountId, 'column.deleted', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'pipeline_column',
        'entidade_id' => $colId,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
