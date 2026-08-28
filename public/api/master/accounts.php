<?php
/**
 * Painel Master — CRUD/listagem global de accounts.
 * Acesso: super_admin (apenas).
 *
 * GET     /api/master/accounts.php           → lista accounts
 * GET     /api/master/accounts.php?id=X      → detalhe + subscription + users
 * POST    /api/master/accounts.php           → cria nova conta
 * PATCH   /api/master/accounts.php           → atualiza status/plano (suspender/reativar)
 * DELETE  /api/master/accounts.php?id=X      → soft-delete
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Processos\MonitorQuota;
use App\Core\Database;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF nos métodos de escrita
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) ApiResponse::badRequest('CSRF inválido');
}

function _audit(\PDO $pdo, int $userId, string $acao, ?string $tt, ?int $tid, string $desc): void {
    try {
        $pdo->prepare(
            "INSERT INTO master_audit_log (super_admin_id, acao, target_type, target_id, descricao, ip)
             SELECT id, :acao, :tt, :tid, :desc, :ip FROM super_admins WHERE user_id = :uid LIMIT 1"
        )->execute([
            'acao' => $acao, 'tt' => $tt, 'tid' => $tid, 'desc' => $desc,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'uid' => $userId
        ]);
    } catch (\Throwable $_e) {}
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM users u WHERE u.account_id=a.id AND u.deleted_at IS NULL) AS users_count,
                    (SELECT COUNT(*) FROM users u WHERE u.account_id=a.id AND u.deleted_at IS NULL AND u.is_advogado=1) AS advogados_count,
                    (SELECT COUNT(*) FROM processos p WHERE p.account_id=a.id AND p.deleted_at IS NULL) AS processos_count,
                    (SELECT COUNT(*) FROM cards c WHERE c.account_id=a.id AND c.deleted_at IS NULL) AS cards_count
             FROM accounts a WHERE a.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $acc = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$acc) ApiResponse::notFound('Conta não encontrada');

        $sub = $pdo->prepare(
            "SELECT s.*, p.slug AS plan_slug, p.nome AS plan_nome, p.preco_mensal_cents
             FROM subscriptions s INNER JOIN plans p ON p.id=s.plan_id
             WHERE s.account_id = :id ORDER BY s.id DESC LIMIT 1"
        );
        $sub->execute(['id' => $id]);
        $acc['subscription'] = $sub->fetch(\PDO::FETCH_ASSOC) ?: null;

        $usrs = $pdo->prepare(
            "SELECT id, nome, login AS email, perfil, role, status, created_at
             FROM users WHERE account_id = :id AND deleted_at IS NULL ORDER BY nome"
        );
        $usrs->execute(['id' => $id]);
        $acc['users'] = $usrs->fetchAll(\PDO::FETCH_ASSOC);

        $invs = $pdo->prepare(
            "SELECT id, amount_cents, status, due_date, paid_at, created_at
             FROM invoices WHERE account_id = :id ORDER BY id DESC LIMIT 20"
        );
        $invs->execute(['id' => $id]);
        $acc['invoices'] = $invs->fetchAll(\PDO::FETCH_ASSOC);

        // ── Monitor add-on (Etapa 6) ─────────────────────────────────────
        // Estrutura completa: limit, used, available, percent, has_quota
        // + array `overrides` (compras/grants ativos) — o modal Master
        // "Detalhes" precisa disso pra renderizar a tabela. Sem o array,
        // a UI mostrava "Sem contratados" mesmo com purchases ativas
        // (fix #4 auditoria E2E 2026-05-26).
        $monStatus = MonitorQuota::getQuotaStatus($id);
        try {
            $ovStmt = $pdo->prepare(
                "SELECT id, limit_value, source, billing_cycle, unit_price_cents,
                        contract_ref, expires_at, created_at, observacoes
                   FROM account_quota_overrides
                  WHERE account_id  = :id
                    AND feature_key = 'monitors.limit'
                    AND revoked_at  IS NULL
                    AND (expires_at IS NULL OR expires_at > NOW())
                  ORDER BY id DESC"
            );
            $ovStmt->execute(['id' => $id]);
            $monStatus['overrides'] = $ovStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $monStatus['overrides'] = [];
        }
        // Inclui detalhamento plan_base / override_sum pra coerência com
        // /api/push/quota.php e /api/master/quotas.php.
        $monStatus['plan_base']    = \App\Billing\BillingGuard::getBaseLimit($id, 'monitors.limit');
        $monStatus['override_sum'] = \App\Billing\BillingGuard::getOverrideSum($id, 'monitors.limit');
        $acc['monitor_quota']      = $monStatus;

        ApiResponse::ok($acc);
    }

    $filter = [];
    if (!empty($_GET['status'])) $filter[] = "a.status = " . $pdo->quote($_GET['status']);
    if (!empty($_GET['tipo']))   $filter[] = "a.tipo = " . $pdo->quote($_GET['tipo']);
    if (!empty($_GET['q'])) {
        $q = '%' . $_GET['q'] . '%';
        $filter[] = "a.nome LIKE " . $pdo->quote($q);
    }
    $where = empty($filter) ? '' : ' AND ' . implode(' AND ', $filter);

    // ── Lista base de contas ──
    // monitors_limit e monitors_used são preenchidos depois via MonitorQuota
    // (single source of truth — herda matriz↔filial via account_vinculos).
    // Não duplique a lógica em SQL inline aqui.
    $rows = $pdo->query(
        "SELECT a.id, a.nome, a.razao_social, a.cnpj, a.email, a.telefone, a.cidade, a.estado,
                a.tipo, a.status, a.plano, a.matriz_id, a.created_at,
                (SELECT COUNT(*) FROM users u WHERE u.account_id=a.id AND u.deleted_at IS NULL) AS users_count,
                (SELECT COUNT(*) FROM users u WHERE u.account_id=a.id AND u.deleted_at IS NULL AND u.is_advogado=1) AS advogados_count,
                (SELECT s.status FROM subscriptions s WHERE s.account_id=a.id ORDER BY s.id DESC LIMIT 1) AS sub_status,
                (SELECT p.nome   FROM subscriptions s INNER JOIN plans p ON p.id=s.plan_id WHERE s.account_id=a.id ORDER BY s.id DESC LIMIT 1) AS sub_plan
         FROM accounts a
         WHERE a.deleted_at IS NULL {$where}
         ORDER BY a.created_at DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // ── Anexa monitors_limit/used via batch (resolve herança matriz↔filial)
    // ── Mesma fonte que o modal "Detalhes" (GET ?id=X) e o cliente
    //    (/api/push/quota.php) — garante zero discrepância entre UIs.
    $ids = array_column($rows, 'id');
    $batch = MonitorQuota::getQuotaStatusBatch($ids);
    foreach ($rows as &$r) {
        $s = $batch[(int)$r['id']] ?? null;
        $r['monitors_limit'] = $s ? (int)$s['effective_limit'] : 0;
        $r['monitors_used']  = $s ? (int)$s['current_usage']  : 0;
    }
    unset($r);

    // Não cachear — sempre buscar fresco (cota muda em tempo real via Master).
    if (!headers_sent()) header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    ApiResponse::ok(['accounts' => $rows]);
}

if ($method === 'PATCH') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    // Captura snapshot anterior pra audit
    $stPrev = $pdo->prepare("SELECT * FROM accounts WHERE id = :id LIMIT 1");
    $stPrev->execute(['id' => $id]);
    $prev = $stPrev->fetch(\PDO::FETCH_ASSOC);
    if (!$prev) ApiResponse::notFound('Conta não encontrada');

    $fields = [];
    $params = ['id' => $id];

    if (isset($input['status'])) {
        if (!in_array($input['status'], ['active','trial','overdue','suspended','cancelled','inactive'], true)) {
            ApiResponse::badRequest('status inválido');
        }
        $fields[] = 'status = :status'; $params['status'] = $input['status'];
    }
    if (isset($input['tipo'])) {
        if (!in_array($input['tipo'], ['matriz','filial','advogado'], true)) {
            ApiResponse::badRequest('tipo inválido');
        }
        // Só processa se o tipo realmente muda (PATCH idempotente não bloqueia).
        if ($input['tipo'] !== $prev['tipo']) {
            // Trocar o tipo sem ajustar account_vinculos deixaria a herança
            // matriz↔filial incoerente (o vínculo é quem governa o acesso, não
            // accounts.tipo). Em vez de mutar vínculos silenciosamente (arriscado),
            // BLOQUEAMOS a troca enquanto existirem vínculos ativos que o novo
            // tipo tornaria órfãos. O operador deve primeiro desvincular pelo
            // fluxo próprio (account_vinculos) e só então trocar o tipo.

            // Como matriz: possui filiais vinculadas (matriz_account_id = :id)?
            $stFil = $pdo->prepare(
                "SELECT COUNT(*) FROM account_vinculos
                 WHERE matriz_account_id = :id AND status IN ('active','pending','suspended')"
            );
            $stFil->execute(['id' => $id]);
            $temFiliais = (int) $stFil->fetchColumn();

            // Como filial: está vinculada a alguma matriz (filial_account_id = :id)?
            $stMat = $pdo->prepare(
                "SELECT COUNT(*) FROM account_vinculos
                 WHERE filial_account_id = :id AND status IN ('active','pending','suspended')"
            );
            $stMat->execute(['id' => $id]);
            $temMatriz = (int) $stMat->fetchColumn();

            // Sair de 'matriz' com filiais vinculadas → órfãs.
            if ($prev['tipo'] === 'matriz' && $temFiliais > 0) {
                ApiResponse::badRequest(
                    'Não é possível alterar o tipo: esta matriz possui ' . $temFiliais .
                    ' filial(is) vinculada(s). Desvincule as filiais antes de trocar o tipo.'
                );
            }
            // Sair de 'filial' com vínculo ativo a uma matriz → vínculo incoerente.
            if ($prev['tipo'] === 'filial' && $temMatriz > 0) {
                ApiResponse::badRequest(
                    'Não é possível alterar o tipo: esta filial está vinculada a uma matriz. ' .
                    'Remova o vínculo antes de trocar o tipo.'
                );
            }
            // Virar 'matriz' enquanto ainda é filial vinculada → conflito de papel.
            if ($input['tipo'] === 'matriz' && $temMatriz > 0) {
                ApiResponse::badRequest(
                    'Não é possível tornar esta conta matriz: ela está vinculada a outra matriz como filial. ' .
                    'Remova o vínculo primeiro.'
                );
            }
            // Virar 'filial'/'advogado' enquanto tem filiais sob si → órfãs.
            if (in_array($input['tipo'], ['filial','advogado'], true) && $temFiliais > 0) {
                ApiResponse::badRequest(
                    'Não é possível alterar o tipo: esta conta possui ' . $temFiliais .
                    ' filial(is) vinculada(s) como matriz. Desvincule-as primeiro.'
                );
            }
        }
        $fields[] = 'tipo = :tipo'; $params['tipo'] = $input['tipo'];
    }
    if (isset($input['plano']))        { $fields[] = 'plano = :plano';        $params['plano']        = $input['plano']; }
    if (isset($input['nome']))         { $fields[] = 'nome = :nome';          $params['nome']         = trim($input['nome']); }
    if (isset($input['razao_social'])) { $fields[] = 'razao_social = :rs';    $params['rs']           = trim($input['razao_social']) ?: null; }
    if (isset($input['cnpj']))         { $fields[] = 'cnpj = :cnpj';          $params['cnpj']         = preg_replace('/\D/', '', $input['cnpj']) ?: null; }
    if (isset($input['email']))        { $fields[] = 'email = :email';        $params['email']        = trim($input['email']) ?: null; }
    if (isset($input['telefone']))     { $fields[] = 'telefone = :tel';       $params['tel']          = trim($input['telefone']) ?: null; }
    if (isset($input['cidade']))       { $fields[] = 'cidade = :ci';          $params['ci']           = trim($input['cidade']) ?: null; }
    if (isset($input['estado']))       { $fields[] = 'estado = :uf';          $params['uf']           = strtoupper(trim($input['estado'])) ?: null; }

    if (empty($fields)) ApiResponse::badRequest('Nenhum campo pra atualizar');

    $pdo->prepare('UPDATE accounts SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id')
        ->execute($params);

    _audit($pdo, $ctx->getUserId(), 'account.update', 'account', $id,
        "Conta '{$prev['nome']}' editada. Campos: " . implode(', ', array_keys($input)));
    ApiResponse::ok(['updated' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');
    $pdo->prepare('UPDATE accounts SET deleted_at = NOW(), status="cancelled" WHERE id = :id')
        ->execute(['id' => $id]);
    _audit($pdo, $ctx->getUserId(), 'account.delete', 'account', $id, 'Soft-delete via Painel Master');
    ApiResponse::ok(['deleted' => true]);
}

ApiResponse::methodNotAllowed();
