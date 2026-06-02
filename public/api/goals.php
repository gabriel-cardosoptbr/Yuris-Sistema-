<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/TenantGuard.php';

use App\Models\Database;
use App\Helpers\AccountContext;
use App\Helpers\TenantGuard;

session_start();
header('Content-Type: application/json; charset=utf-8');

// CSRF (auditoria 2026-06-01): POST/PUT/DELETE de metas exigiam zero validacao.
// requireSameOriginOrCsrf aceita same-origin OU token — mesmo padrao de processes.php.
TenantGuard::requireSameOriginOrCsrf();

$ctx       = AccountContext::fromSession();
$uid       = $ctx->getUserId();
$accountId = $ctx->getAccountId();
// Metas (goals) = CONFIGURAÇÃO da conta — cada tenant define os próprios alvos.
// Matriz NÃO herda metas das filiais. Para visão consolidada usar Painel Master.
$tenantIds = [$accountId];

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

function parse_meta_value($v)
{
    if ($v === null) return 0.0;
    if (is_numeric($v)) return (float)$v;
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $hasDot = strpos($s, '.') !== false;
    $hasComma = strpos($s, ',') !== false;
    if ($hasDot && $hasComma) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($hasComma && !$hasDot) {
        $s = str_replace(',', '.', $s);
    }
    $s = str_replace(' ', '', $s);
    return (float)$s;
}

// Helper local — placeholders nomeados para IN()
function goals_in_clause(array $ids, string $prefix): array
{
    $ph = []; $params = [];
    foreach ($ids as $i => $id) {
        $k = "{$prefix}_{$i}";
        $ph[] = ":{$k}";
        $params[$k] = (int) $id;
    }
    return ['sql' => implode(',', $ph), 'params' => $params];
}
$in = goals_in_clause($tenantIds, 'gacc');

try {
    if ($method === 'GET') {
        $q = isset($_GET['referencia_mes']) ? trim($_GET['referencia_mes']) : null;
        if ($q) {
            $stmt = $pdo->prepare("SELECT * FROM goals WHERE referencia_mes = :r AND user_id = :uid AND account_id IN ({$in['sql']}) LIMIT 1");
            $stmt->execute(['r' => $q, 'uid' => $uid] + $in['params']);
            $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($row && count($row)) {
                echo json_encode($row, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM goals WHERE referencia_mes = :r AND account_id IN ({$in['sql']}) ORDER BY id DESC LIMIT 1");
            $stmt->execute(['r' => $q] + $in['params']);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM goals WHERE user_id = :uid AND account_id IN ({$in['sql']}) ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute(['uid' => $uid] + $in['params']);
        $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($row && count($row)) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM goals WHERE account_id IN ({$in['sql']}) ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute($in['params']);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($method === 'POST' || $method === 'PUT') {
        $valor = parse_meta_value($input['valor_meta'] ?? null);
        // Upsert por usuário dentro do tenant
        $stmt = $pdo->prepare("SELECT id, valor_meta, tipo_meta, referencia_mes FROM goals WHERE user_id = :uid AND account_id = :acc ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute(['uid' => $uid, 'acc' => $accountId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $wasUpdate = false;
        $dadosAntes = null;
        if ($existing && isset($existing['id'])) {
            $wasUpdate = true;
            $dadosAntes = $existing;
            $gid = (int)$existing['id'];
            $pdo->prepare('UPDATE goals SET valor_meta = :val, tipo_meta = :tipo, referencia_mes = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['val' => $valor, 'id' => $gid, 'tipo' => 'single']);
        } else {
            $pdo->prepare('INSERT INTO goals (account_id, user_id, referencia_mes, valor_meta, tipo_meta) VALUES (:acc, :uid, NULL, :val, :tipo)')
                ->execute(['acc' => $accountId, 'uid' => $uid, 'val' => $valor, 'tipo' => 'single']);
            $gid = (int)$pdo->lastInsertId();
        }
        $row = $pdo->prepare('SELECT * FROM goals WHERE id = :id');
        $row->execute(['id' => $gid]);
        $goalRow = $row->fetch(PDO::FETCH_ASSOC);
        \App\Models\Account::audit($accountId, $wasUpdate ? 'goal.updated' : 'goal.created', [
            'user_id'     => $uid,
            'entidade'    => 'goal',
            'entidade_id' => $gid,
            'dados_antes' => $dadosAntes,
            'detalhes'    => ['valor_meta' => $valor, 'tipo_meta' => 'single'],
        ]);
        echo json_encode(['ok' => true, 'id' => $gid, 'goal' => $goalRow], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
        $stPrev = $pdo->prepare("SELECT * FROM goals WHERE id = :id AND account_id IN ({$in['sql']})");
        $stPrev->execute(['id' => $id] + $in['params']);
        $dadosAntes = $stPrev->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt = $pdo->prepare("DELETE FROM goals WHERE id = :id AND account_id IN ({$in['sql']})");
        $stmt->execute(['id' => $id] + $in['params']);
        if ($stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem permissão']);
            exit;
        }
        \App\Models\Account::audit($accountId, 'goal.deleted', [
            'user_id'     => $uid,
            'entidade'    => 'goal',
            'entidade_id' => $id,
            'dados_antes' => $dadosAntes,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
