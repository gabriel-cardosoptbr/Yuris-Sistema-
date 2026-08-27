<?php
/**
 * Painel Master — CRUD de planos e features.
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) ApiResponse::badRequest('CSRF inválido');
}

if ($method === 'GET') {
    $plans = $pdo->query("SELECT * FROM plans ORDER BY ordem, id")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($plans as &$p) {
        $stmt = $pdo->prepare("SELECT feature_key, limit_value, is_enabled FROM plan_features WHERE plan_id = :pid");
        $stmt->execute(['pid' => $p['id']]);
        $p['features'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmtN = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE plan_id = :pid");
        $stmtN->execute(['pid' => $p['id']]);
        $p['subscriptions_count'] = (int) $stmtN->fetchColumn();
    }
    ApiResponse::ok(['plans' => $plans]);
}

if ($method === 'POST') {
    $slug = trim($input['slug'] ?? '');
    $nome = trim($input['nome'] ?? '');
    if ($slug === '' || $nome === '') ApiResponse::badRequest('slug e nome são obrigatórios');

    $pdo->prepare(
        'INSERT INTO plans (slug, nome, descricao, preco_mensal_cents, preco_anual_cents, trial_dias, ativo, destaque, ordem)
         VALUES (:slug, :nome, :desc, :pm, :pa, :td, :at, :ds, :or)'
    )->execute([
        'slug' => $slug, 'nome' => $nome,
        'desc' => $input['descricao'] ?? '',
        'pm'   => (int)($input['preco_mensal_cents'] ?? 0),
        'pa'   => (int)($input['preco_anual_cents']  ?? 0),
        'td'   => (int)($input['trial_dias']         ?? 0),
        // Antes era "? 1 : 1" (erro de digitação): os dois ramos davam 1, então
        // era impossível criar um plano já inativo. Agora respeita o payload,
        // mantendo 1 como padrão quando a chave nem vem.
        'at'   => array_key_exists('ativo', $input) ? (!empty($input['ativo']) ? 1 : 0) : 1,
        'ds'   => !empty($input['destaque']) ? 1 : 0,
        'or'   => (int)($input['ordem'] ?? 99),
    ]);
    $newId = (int) $pdo->lastInsertId();
    MasterAudit::log('plan.create', 'plan', $newId, "Plano '{$nome}' ({$slug}) criado",
        ['slug' => $slug, 'preco_mensal_cents' => (int)($input['preco_mensal_cents'] ?? 0)]);
    ApiResponse::ok(['id' => $newId]);
}

if ($method === 'PATCH') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $allowed = ['slug','nome','descricao','preco_mensal_cents','preco_anual_cents','trial_dias','ativo','destaque','ordem'];
    $sets = []; $params = ['id' => $id];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $input)) {
            $sets[]      = "{$k} = :{$k}";
            $params[$k]  = $input[$k];
        }
    }
    if (empty($sets)) ApiResponse::badRequest('nada a atualizar');
    $pdo->prepare('UPDATE plans SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

    // Sincroniza features se enviadas.
    //
    // ANTES: DELETE de TODAS as features do plano + reinserção do que veio no
    // payload. Isso apagava silenciosamente toda chave que a tela não conhece.
    // Na prática o Master zerava 'monitors.limit' (semeada pela migration 077)
    // a cada edição de plano, e como "sem linha" faz o BillingGuard liberar por
    // fail-soft, o efeito era o INVERSO do pretendido: o plano passava de
    // "0 monitores" para "monitores ilimitados".
    //
    // AGORA: upsert apenas das chaves enviadas (a tabela tem UNIQUE
    // uk_plan_feature(plan_id, feature_key)). Payload parcial atualiza só o que
    // mandou e não encosta no resto.
    if (isset($input['features']) && is_array($input['features'])) {
        $ins = $pdo->prepare(
            'INSERT INTO plan_features (plan_id, feature_key, limit_value, is_enabled)
             VALUES (:pid, :fk, :lv, :ie)
             ON DUPLICATE KEY UPDATE limit_value = VALUES(limit_value), is_enabled = VALUES(is_enabled)'
        );
        foreach ($input['features'] as $f) {
            if (empty($f['feature_key'])) continue;
            $ins->execute([
                'pid' => $id,
                'fk'  => $f['feature_key'],
                'lv'  => isset($f['limit_value']) && $f['limit_value'] !== '' ? (int)$f['limit_value'] : null,
                'ie'  => !empty($f['is_enabled']) ? 1 : 0,
            ]);
        }
    }
    MasterAudit::log('plan.update', 'plan', $id, "Plano #{$id} editado",
        ['campos' => array_keys($input)]);
    ApiResponse::ok(['updated' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');
    // Soft: marca ativo=0 (nunca apaga pra não quebrar foreign keys históricas)
    $pdo->prepare('UPDATE plans SET ativo = 0 WHERE id = :id')->execute(['id' => $id]);
    ApiResponse::ok(['deactivated' => true]);
}

ApiResponse::methodNotAllowed();
