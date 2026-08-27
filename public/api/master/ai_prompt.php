<?php
/**
 * /api/master/ai_prompt.php — Painel Master: ver e CORRIGIR o prompt UNIVERSAL do
 * Assistente de Pre-Atendimento (asset global da Inovaize, ai_prompts account_id NULL).
 *
 * Cada correcao cria uma NOVA versao (vN) ativa, desativando a anterior (histórico +
 * rollback). Os escritorios nao editam este texto; so injetam variaveis {{...}} pela tela
 * do agente. O motor usa sempre a versao ativa mais recente.
 *
 * Acesso: super_admin em sessao master_mode. Escrita exige nivel != viewer. CSRF nos POST.
 * Tudo auditado em master_audit_log.
 *
 *   GET                      -> { active:{id,version,template,changelog,chars,tokens_est,updated_at}, versions:[...] }
 *   GET ?id=N                -> { id,version,active,template,changelog,chars,tokens_est,... } (uma versao)
 *   POST action=save   {template, changelog}   -> cria nova versao ativa
 *   POST action=activate {id}                  -> ativa uma versao existente (rollback)
 *   POST action=delete {id}                    -> exclui uma versao (nunca a ativa nem a ultima)
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../../app/Core/ApiResponse.php';
require_once __DIR__ . '/../../../app/Master/MasterAudit.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();
if (empty($_SESSION['master_mode'])) {
    ApiResponse::forbidden('Acesso somente pelo Painel Master.');
}

$pdo    = \App\Core\Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$NAME   = 'pre_atendimento_universal';

function _tokensEst(string $s): int { return (int) round(mb_strlen($s) / 4); }

function _activePrompt(\PDO $pdo, string $name): ?array {
    $st = $pdo->prepare("SELECT id, version, template, changelog, updated_at
                           FROM ai_prompts WHERE name = ? AND account_id IS NULL AND active = 1
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$name]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['chars']      = mb_strlen((string)$r['template']);
    $r['tokens_est'] = _tokensEst((string)$r['template']);
    return $r;
}

function _versions(\PDO $pdo, string $name): array {
    $st = $pdo->prepare("SELECT id, version, active, changelog, created_at, updated_at,
                                CHAR_LENGTH(template) AS chars
                           FROM ai_prompts WHERE name = ? AND account_id IS NULL
                          ORDER BY id DESC");
    $st->execute([$name]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function _nextVersion(\PDO $pdo, string $name): string {
    $st = $pdo->prepare("SELECT version FROM ai_prompts WHERE name = ? AND account_id IS NULL");
    $st->execute([$name]);
    $max = 0;
    foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $v) {
        if (preg_match('/(\d+)/', (string)$v, $m)) $max = max($max, (int)$m[1]);
    }
    return 'v' . ($max + 1);
}

// ─── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $vid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($vid > 0) {
        $st = $pdo->prepare("SELECT id, version, active, template, changelog, created_at, updated_at
                               FROM ai_prompts WHERE id = ? AND name = ? AND account_id IS NULL LIMIT 1");
        $st->execute([$vid, $NAME]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) ApiResponse::badRequest('Versão não encontrada.');
        $r['chars']      = mb_strlen((string)$r['template']);
        $r['tokens_est'] = _tokensEst((string)$r['template']);
        ApiResponse::ok($r);
    }
    ApiResponse::ok([
        'active'   => _activePrompt($pdo, $NAME),
        'versions' => _versions($pdo, $NAME),
    ]);
}

// ─── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? ($in['_csrf'] ?? null));
    if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
    }
    // Escrita: viewer nao corrige o prompt.
    if ($ctx->getSuperAdminLevel() === 'viewer') {
        ApiResponse::forbidden('Seu nível de acesso (viewer) não permite editar o prompt.');
    }

    $action = $in['action'] ?? 'save';

    if ($action === 'save') {
        $template = (string)($in['template'] ?? '');
        if (mb_strlen(trim($template)) < 200) {
            ApiResponse::badRequest('O prompt está muito curto (mínimo 200 caracteres).');
        }
        $changelog = trim((string)($in['changelog'] ?? '')) ?: ('Correção via Painel Master');
        $ver = _nextVersion($pdo, $NAME);

        // reaproveita o schema_json da versao ativa atual
        $schema = $pdo->prepare("SELECT schema_json FROM ai_prompts WHERE name=? AND account_id IS NULL AND schema_json IS NOT NULL ORDER BY id DESC LIMIT 1");
        $schema->execute([$NAME]);
        $schemaJson = $schema->fetchColumn() ?: null;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE ai_prompts SET active = 0 WHERE name = ? AND account_id IS NULL")->execute([$NAME]);
            $pdo->prepare(
                "INSERT INTO ai_prompts (account_id, name, version, template, schema_json, changelog, active, created_by)
                 VALUES (NULL, :name, :ver, :tpl, :sch, :chg, 1, :by)"
            )->execute([
                ':name' => $NAME, ':ver' => $ver, ':tpl' => $template, ':sch' => $schemaJson,
                ':chg' => $changelog, ':by' => $ctx->getUserId(),
            ]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[master/ai_prompt] save falhou: ' . $e->getMessage());
            ApiResponse::serverError('Falha ao salvar o prompt.');
        }

        MasterAudit::log('ai_prompt.save', 'ai_prompt', $newId, 'Nova versão ' . $ver . ' do prompt do agente', [
            'version' => $ver, 'chars' => mb_strlen($template), 'tokens_est' => _tokensEst($template), 'changelog' => $changelog,
        ]);

        ApiResponse::ok([
            'saved'    => true,
            'version'  => $ver,
            'active'   => _activePrompt($pdo, $NAME),
            'versions' => _versions($pdo, $NAME),
            'message'  => 'Prompt salvo como versão ' . $ver . ' e ativado.',
        ]);
    }

    if ($action === 'activate') {
        $id = (int)($in['id'] ?? 0);
        $chk = $pdo->prepare("SELECT version FROM ai_prompts WHERE id = ? AND name = ? AND account_id IS NULL LIMIT 1");
        $chk->execute([$id, $NAME]);
        $ver = $chk->fetchColumn();
        if (!$ver) ApiResponse::badRequest('Versão não encontrada.');

        $pdo->prepare("UPDATE ai_prompts SET active = 0 WHERE name = ? AND account_id IS NULL")->execute([$NAME]);
        $pdo->prepare("UPDATE ai_prompts SET active = 1 WHERE id = ?")->execute([$id]);

        MasterAudit::log('ai_prompt.activate', 'ai_prompt', $id, 'Ativou a versão ' . $ver . ' do prompt do agente', ['version' => $ver]);

        ApiResponse::ok([
            'activated' => true, 'version' => $ver,
            'active' => _activePrompt($pdo, $NAME), 'versions' => _versions($pdo, $NAME),
            'message' => 'Versão ' . $ver . ' ativada.',
        ]);
    }

    if ($action === 'delete') {
        $id  = (int)($in['id'] ?? 0);
        $chk = $pdo->prepare("SELECT version, active FROM ai_prompts WHERE id = ? AND name = ? AND account_id IS NULL LIMIT 1");
        $chk->execute([$id, $NAME]);
        $row = $chk->fetch(\PDO::FETCH_ASSOC);
        if (!$row) ApiResponse::badRequest('Versão não encontrada.');
        if ((int)$row['active'] === 1) {
            ApiResponse::badRequest('Não é possível excluir a versão ativa. Ative outra versão antes de excluir esta.');
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM ai_prompts WHERE name = ? AND account_id IS NULL");
        $cnt->execute([$NAME]);
        if ((int)$cnt->fetchColumn() <= 1) {
            ApiResponse::badRequest('Não é possível excluir a única versão existente.');
        }

        $pdo->prepare("DELETE FROM ai_prompts WHERE id = ?")->execute([$id]);
        MasterAudit::log('ai_prompt.delete', 'ai_prompt', $id, 'Excluiu a versão ' . $row['version'] . ' do prompt do agente', ['version' => $row['version']]);

        ApiResponse::ok([
            'deleted' => true, 'version' => $row['version'],
            'active' => _activePrompt($pdo, $NAME), 'versions' => _versions($pdo, $NAME),
            'message' => 'Versão ' . $row['version'] . ' excluída.',
        ]);
    }

    ApiResponse::badRequest('Ação desconhecida (use save, activate ou delete).');
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
