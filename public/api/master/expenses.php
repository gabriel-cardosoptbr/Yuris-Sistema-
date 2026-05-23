<?php
/**
 * Painel Master — CRUD de despesas operacionais do SaaS.
 *
 * Permite controlar custos do negócio: servidor, salários, APIs, marketing,
 * infraestrutura, impostos, software, jurídico, outros.
 *
 * GET    /api/master/expenses.php                    → lista (com filtros)
 * GET    /api/master/expenses.php?id=X               → detalhe
 * POST   /api/master/expenses.php                    → cria
 * PATCH  /api/master/expenses.php                    → atualiza
 * DELETE /api/master/expenses.php?id=X               → soft-delete
 *
 * Filtros GET:
 *   ?status=pendente|pago|atrasado|cancelado
 *   ?categoria=servidor|pessoas|apis|...
 *   ?month=YYYY-MM   (data_competencia entra naquele mês)
 *   ?vencido=1       (status pendente + vencimento < hoje)
 *
 * Acesso: super_admin apenas. CSRF nas escritas.
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Models\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

const VALID_CATEGORIES = ['servidor','pessoas','apis','marketing','infraestrutura',
                          'impostos','software','juridico','outros'];
const VALID_STATUS     = ['pendente','pago','atrasado','cancelado'];
const VALID_RECORRENCIA = ['nenhuma','mensal','anual'];

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM master_expenses WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) ApiResponse::notFound('Despesa não encontrada');
        ApiResponse::ok($row);
    }

    $where = ['deleted_at IS NULL']; $params = [];

    if (!empty($_GET['status']))    { $where[] = 'status = :st';     $params['st']  = $_GET['status']; }
    if (!empty($_GET['categoria'])) { $where[] = 'categoria = :ct';  $params['ct']  = $_GET['categoria']; }
    if (!empty($_GET['month']))     {
        $where[] = "DATE_FORMAT(data_competencia, '%Y-%m') = :mm";
        $params['mm'] = $_GET['month'];
    }
    if (!empty($_GET['vencido']))   { $where[] = "status = 'pendente' AND vencimento < CURDATE()"; }

    $sql = "SELECT * FROM master_expenses
            WHERE " . implode(' AND ', $where) . "
            ORDER BY data_competencia DESC, vencimento ASC
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Calcula total e breakdown rápido
    $totalCents = 0;
    $byCategoria = [];
    foreach ($rows as $r) {
        $totalCents += (int) $r['valor_cents'];
        $cat = $r['categoria'];
        $byCategoria[$cat] = ($byCategoria[$cat] ?? 0) + (int) $r['valor_cents'];
    }

    ApiResponse::ok([
        'expenses'      => $rows,
        'total_cents'   => $totalCents,
        'total_brl'     => number_format($totalCents / 100, 2, ',', '.'),
        'by_categoria'  => $byCategoria,
    ]);
}

// ─── POST: criar ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $categoria  = $input['categoria']  ?? 'outros';
    $descricao  = trim($input['descricao'] ?? '');
    $valor_cents = (int) ($input['valor_cents'] ?? 0);
    $competencia = trim($input['data_competencia'] ?? '');

    if ($descricao === '')              ApiResponse::badRequest('Descrição é obrigatória');
    if ($valor_cents <= 0)              ApiResponse::badRequest('Valor inválido');
    if ($competencia === '')            ApiResponse::badRequest('Data de competência é obrigatória');
    if (!in_array($categoria, VALID_CATEGORIES, true)) ApiResponse::badRequest('Categoria inválida');

    $recorrencia = $input['recorrencia'] ?? 'nenhuma';
    if (!in_array($recorrencia, VALID_RECORRENCIA, true)) ApiResponse::badRequest('Recorrência inválida');

    $status = $input['status'] ?? 'pendente';
    if (!in_array($status, VALID_STATUS, true)) ApiResponse::badRequest('Status inválido');

    // Resolve super_admin_id do criador
    $sa = $pdo->prepare("SELECT id FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1");
    $sa->execute(['uid' => $ctx->getUserId()]);
    $saId = (int) $sa->fetchColumn() ?: null;

    $pdo->prepare(
        "INSERT INTO master_expenses
           (categoria, descricao, fornecedor, valor_cents, data_competencia,
            vencimento, data_pagamento, status, recorrencia, metodo_pagamento,
            observacoes, created_by_super_admin_id, created_at, updated_at)
         VALUES
           (:cat, :desc, :forn, :val, :comp,
            :venc, :pag, :st, :rec, :met,
            :obs, :saId, NOW(), NOW())"
    )->execute([
        'cat'   => $categoria,
        'desc'  => $descricao,
        'forn'  => trim($input['fornecedor'] ?? '') ?: null,
        'val'   => $valor_cents,
        'comp'  => $competencia,
        'venc'  => $input['vencimento']     ?: null,
        'pag'   => $input['data_pagamento'] ?: null,
        'st'    => $status,
        'rec'   => $recorrencia,
        'met'   => trim($input['metodo_pagamento'] ?? '') ?: null,
        'obs'   => trim($input['observacoes'] ?? '') ?: null,
        'saId'  => $saId,
    ]);
    $id = (int) $pdo->lastInsertId();

    MasterAudit::log('expense.create', 'expense', $id,
        "Despesa criada: {$descricao} (R$ " . number_format($valor_cents / 100, 2, ',', '.') . ")",
        ['categoria' => $categoria, 'valor_cents' => $valor_cents]);

    ApiResponse::ok(['id' => $id]);
}

// ─── PATCH: editar ───────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $stPrev = $pdo->prepare("SELECT * FROM master_expenses WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stPrev->execute(['id' => $id]);
    $prev = $stPrev->fetch(\PDO::FETCH_ASSOC);
    if (!$prev) ApiResponse::notFound('Despesa não encontrada');

    $sets = []; $params = ['id' => $id];

    if (isset($input['categoria'])) {
        if (!in_array($input['categoria'], VALID_CATEGORIES, true)) ApiResponse::badRequest('Categoria inválida');
        $sets[] = 'categoria = :cat'; $params['cat'] = $input['categoria'];
    }
    if (isset($input['descricao']))       { $sets[] = 'descricao = :desc';        $params['desc']  = trim($input['descricao']); }
    if (isset($input['fornecedor']))      { $sets[] = 'fornecedor = :forn';       $params['forn']  = trim($input['fornecedor']) ?: null; }
    if (isset($input['valor_cents']))     { $sets[] = 'valor_cents = :val';       $params['val']   = (int) $input['valor_cents']; }
    if (isset($input['data_competencia'])){ $sets[] = 'data_competencia = :comp'; $params['comp']  = $input['data_competencia']; }
    if (array_key_exists('vencimento', $input))      { $sets[] = 'vencimento = :venc';     $params['venc'] = $input['vencimento'] ?: null; }
    if (array_key_exists('data_pagamento', $input))  { $sets[] = 'data_pagamento = :pag';  $params['pag']  = $input['data_pagamento'] ?: null; }
    if (isset($input['status'])) {
        if (!in_array($input['status'], VALID_STATUS, true)) ApiResponse::badRequest('Status inválido');
        $sets[] = 'status = :st'; $params['st'] = $input['status'];
        // Se marcar como pago e não tem data_pagamento, seta hoje
        if ($input['status'] === 'pago' && empty($prev['data_pagamento']) && !isset($input['data_pagamento'])) {
            $sets[] = 'data_pagamento = CURDATE()';
        }
    }
    if (isset($input['recorrencia'])) {
        if (!in_array($input['recorrencia'], VALID_RECORRENCIA, true)) ApiResponse::badRequest('Recorrência inválida');
        $sets[] = 'recorrencia = :rec'; $params['rec'] = $input['recorrencia'];
    }
    if (isset($input['metodo_pagamento'])) { $sets[] = 'metodo_pagamento = :met'; $params['met'] = trim($input['metodo_pagamento']) ?: null; }
    if (isset($input['observacoes']))      { $sets[] = 'observacoes = :obs';      $params['obs'] = trim($input['observacoes']) ?: null; }

    if (empty($sets)) ApiResponse::badRequest('Nada para atualizar');

    $pdo->prepare('UPDATE master_expenses SET ' . implode(', ', $sets) . ' WHERE id = :id')
        ->execute($params);

    MasterAudit::log('expense.update', 'expense', $id,
        "Despesa #{$id} editada",
        ['campos' => array_keys($input)]);

    ApiResponse::ok(['updated' => true]);
}

// ─── DELETE: soft-delete ─────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $st = $pdo->prepare("SELECT descricao FROM master_expenses WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $st->execute(['id' => $id]);
    $desc = $st->fetchColumn();
    if (!$desc) ApiResponse::notFound('Despesa não encontrada');

    $pdo->prepare("UPDATE master_expenses SET deleted_at = NOW() WHERE id = :id")
        ->execute(['id' => $id]);

    MasterAudit::log('expense.delete', 'expense', $id, "Despesa '{$desc}' removida (soft-delete)");
    ApiResponse::ok(['deleted' => true]);
}

ApiResponse::methodNotAllowed();
