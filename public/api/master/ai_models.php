<?php
/**
 * /api/master/ai_models.php — Painel Master: CATALOGO GLOBAL de modelos de IA.
 *
 * Fonte unica dos dropdowns de "Modelo" do agente em TODAS as contas. O super-admin
 * adiciona/edita/remove/ativa modelos aqui e reflete no sistema inteiro.
 *
 * Acesso: super_admin em sessao master_mode; escrita exige nivel != viewer + CSRF. Auditado.
 *
 *   GET                              -> { ok, data:{ models:[...], providers:[...] } }
 *   POST action=add    {provider, code, label, sort}
 *   POST action=update {id, provider, code, label, sort, active}
 *   POST action=toggle {id}
 *   POST action=delete {id}
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) ApiResponse::forbidden('Acesso somente pelo Painel Master.');

$pdo    = \App\Models\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$PROVIDERS = ['openai', 'anthropic'];

function _list(\PDO $pdo): array {
    return $pdo->query("SELECT id, provider, code, label, active, sort
                          FROM ai_models ORDER BY provider ASC, sort ASC, code ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

if ($method === 'GET') {
    ApiResponse::ok(['models' => _list($pdo), 'providers' => $PROVIDERS]);
}

if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    if ($ctx->getSuperAdminLevel() === 'viewer') ApiResponse::forbidden('Seu nível (viewer) não permite editar os modelos.');

    $action = $in['action'] ?? '';

    if ($action === 'add') {
        $provider = strtolower(trim((string)($in['provider'] ?? 'openai'))) ?: 'openai';
        if (!in_array($provider, $PROVIDERS, true)) ApiResponse::badRequest('Provedor inválido.');
        $code = trim((string)($in['code'] ?? ''));
        if ($code === '') ApiResponse::badRequest('Código do modelo é obrigatório.');
        if (!preg_match('/^[A-Za-z0-9._-]{2,80}$/', $code)) ApiResponse::badRequest('Código inválido (use letras, números, ponto, hífen).');
        $label = trim((string)($in['label'] ?? '')) ?: $code;
        $sort  = (int)($in['sort'] ?? 0);
        try {
            $pdo->prepare("INSERT INTO ai_models (provider, code, label, active, sort) VALUES (?,?,?,1,?)")
                ->execute([$provider, $code, $label, $sort]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') ApiResponse::badRequest('Esse modelo já existe nesse provedor.');
            throw $e;
        }
        MasterAudit::log('ai_model.add', 'ai_model', (int)$pdo->lastInsertId(), "Adicionou modelo {$provider}/{$code}", ['provider'=>$provider,'code'=>$code]);
        ApiResponse::ok(['models' => _list($pdo)]);
    }

    if ($action === 'update') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) ApiResponse::badRequest('id obrigatório.');
        $cur = $pdo->prepare("SELECT * FROM ai_models WHERE id = ? LIMIT 1"); $cur->execute([$id]);
        $row = $cur->fetch(\PDO::FETCH_ASSOC);
        if (!$row) ApiResponse::badRequest('Modelo não encontrado.');
        $provider = isset($in['provider']) ? (strtolower(trim((string)$in['provider'])) ?: $row['provider']) : $row['provider'];
        if (!in_array($provider, $PROVIDERS, true)) ApiResponse::badRequest('Provedor inválido.');
        $code = isset($in['code']) ? trim((string)$in['code']) : $row['code'];
        if ($code === '' || !preg_match('/^[A-Za-z0-9._-]{2,80}$/', $code)) ApiResponse::badRequest('Código inválido.');
        $label  = isset($in['label']) ? (trim((string)$in['label']) ?: $code) : $row['label'];
        $sort   = isset($in['sort'])   ? (int)$in['sort']   : (int)$row['sort'];
        $active = isset($in['active']) ? (int)((bool)$in['active']) : (int)$row['active'];
        try {
            $pdo->prepare("UPDATE ai_models SET provider=?, code=?, label=?, sort=?, active=? WHERE id=?")
                ->execute([$provider, $code, $label, $sort, $active, $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') ApiResponse::badRequest('Já existe esse código nesse provedor.');
            throw $e;
        }
        MasterAudit::log('ai_model.update', 'ai_model', $id, "Editou modelo {$provider}/{$code}", ['active'=>$active]);
        ApiResponse::ok(['models' => _list($pdo)]);
    }

    if ($action === 'toggle') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) ApiResponse::badRequest('id obrigatório.');
        $pdo->prepare("UPDATE ai_models SET active = IF(active=1,0,1) WHERE id = ?")->execute([$id]);
        MasterAudit::log('ai_model.toggle', 'ai_model', $id, 'Alternou ativo/inativo do modelo', []);
        ApiResponse::ok(['models' => _list($pdo)]);
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) ApiResponse::badRequest('id obrigatório.');
        $cur = $pdo->prepare("SELECT provider, code FROM ai_models WHERE id = ? LIMIT 1"); $cur->execute([$id]);
        $row = $cur->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare("DELETE FROM ai_models WHERE id = ?")->execute([$id]);
        MasterAudit::log('ai_model.delete', 'ai_model', $id, 'Removeu modelo ' . ($row ? $row['provider'].'/'.$row['code'] : "#$id"), []);
        ApiResponse::ok(['models' => _list($pdo)]);
    }

    ApiResponse::badRequest('Ação inválida.');
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
