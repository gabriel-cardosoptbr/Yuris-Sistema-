<?php
/**
 * Painel Master — gestão manual de pagamentos (invoices).
 *
 * Permite marcar pagamento manualmente como pago/vencido/cancelado e
 * registrar observações. Não substitui webhook de gateway — é controle
 * complementar pra cobrança manual.
 *
 * GET    /api/master/payments.php                            → lista invoices com filtros
 * GET    /api/master/payments.php?id=X                       → detalhe invoice
 * POST   /api/master/payments.php                            → cria invoice avulsa manual
 * PATCH  /api/master/payments.php                            → muda status, due_date, observações
 *
 * Filtros GET:
 *   ?status=open|paid|uncollectible|void
 *   ?account_id=X
 *   ?vencido=1   → status=open AND due_date < NOW()
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Acesso: super_admin apenas. CSRF nas escritas.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

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
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        ApiResponse::badRequest('CSRF inválido');
    }
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare(
            "SELECT i.*, a.nome AS account_nome, a.tipo AS account_tipo,
                    s.status AS subscription_status, p.nome AS plan_nome, p.slug AS plan_slug,
                    sa.nome AS marcado_por_nome
             FROM invoices i
             LEFT JOIN accounts      a  ON a.id  = i.account_id
             LEFT JOIN subscriptions s  ON s.id  = i.subscription_id
             LEFT JOIN plans         p  ON p.id  = s.plan_id
             LEFT JOIN super_admins  sa ON sa.id = i.marcado_manualmente_por
             WHERE i.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $inv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$inv) ApiResponse::notFound('Fatura não encontrada');
        ApiResponse::ok($inv);
    }

    $where = []; $params = [];
    if (!empty($_GET['status']))     { $where[] = 'i.status = :st';      $params['st']  = $_GET['status']; }
    if (!empty($_GET['account_id'])) { $where[] = 'i.account_id = :aid'; $params['aid'] = (int) $_GET['account_id']; }
    if (!empty($_GET['vencido']))    { $where[] = "i.status = 'open' AND i.due_date < CURDATE()"; }
    if (!empty($_GET['from']))       { $where[] = 'i.due_date >= :from'; $params['from'] = $_GET['from']; }
    if (!empty($_GET['to']))         { $where[] = 'i.due_date <= :to';   $params['to']   = $_GET['to']; }

    $wsql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare(
        "SELECT i.id, i.account_id, i.subscription_id, i.amount_cents, i.moeda, i.status,
                i.due_date, i.paid_at, i.numero, i.descricao, i.gateway,
                i.observacoes_cobranca, i.marcado_manualmente_em, i.created_at,
                a.nome AS account_nome, a.tipo AS account_tipo,
                p.nome AS plan_nome
         FROM invoices i
         LEFT JOIN accounts a       ON a.id = i.account_id
         LEFT JOIN subscriptions s  ON s.id = i.subscription_id
         LEFT JOIN plans p          ON p.id = s.plan_id
         {$wsql}
         ORDER BY i.due_date DESC, i.id DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    ApiResponse::ok(['invoices' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $accId  = (int) ($input['account_id'] ?? 0);
    $valor  = (int) ($input['amount_cents'] ?? 0);
    $due    = trim($input['due_date'] ?? '');
    if (!$accId || $valor <= 0 || $due === '') {
        ApiResponse::badRequest('account_id, amount_cents e due_date são obrigatórios');
    }

    $acc = $pdo->prepare("SELECT id FROM accounts WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $acc->execute(['id' => $accId]);
    if (!$acc->fetchColumn()) ApiResponse::badRequest('Conta não encontrada');

    $subId = (int) ($input['subscription_id'] ?? 0) ?: null;
    if ($subId) {
        $check = $pdo->prepare("SELECT id FROM subscriptions WHERE id = :id AND account_id = :acc LIMIT 1");
        $check->execute(['id' => $subId, 'acc' => $accId]);
        if (!$check->fetchColumn()) ApiResponse::badRequest('Subscription não pertence a esta conta');
    }

    $pdo->prepare(
        "INSERT INTO invoices
           (account_id, subscription_id, amount_cents, moeda, status, due_date,
            numero, descricao, observacoes_cobranca, gateway, created_at, updated_at)
         VALUES
           (:acc, :sub, :val, 'BRL', 'open', :due,
            :num, :desc, :obs, 'manual', NOW(), NOW())"
    )->execute([
        'acc'  => $accId,
        'sub'  => $subId,
        'val'  => $valor,
        'due'  => $due,
        'num'  => trim($input['numero'] ?? '') ?: null,
        'desc' => trim($input['descricao'] ?? '') ?: null,
        'obs'  => trim($input['observacoes'] ?? '') ?: null,
    ]);
    $invId = (int) $pdo->lastInsertId();

    MasterAudit::log('payment.create_manual', 'invoice', $invId,
        "Fatura manual criada (R$ " . number_format($valor / 100, 2, ',', '.') . ")",
        ['account_id' => $accId, 'subscription_id' => $subId, 'due_date' => $due]);

    ApiResponse::ok(['invoice_id' => $invId]);
}

if ($method === 'PATCH') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) ApiResponse::badRequest('id obrigatório');

    $st = $pdo->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $prev = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$prev) ApiResponse::notFound('Fatura não encontrada');

    $sets   = [];
    $params = ['id' => $id];
    $isManual = false;

    if (isset($input['status'])) {
        $valid = ['draft','open','paid','void','uncollectible','refunded'];
        if (!in_array($input['status'], $valid, true)) ApiResponse::badRequest('status inválido');
        $sets[] = 'status = :st';
        $params['st'] = $input['status'];
        $isManual = true;

        if ($input['status'] === 'paid') {
            $sets[] = 'paid_at = NOW()';
        } else if ($prev['status'] === 'paid') {
            $sets[] = 'paid_at = NULL';
        }
    }
    if (isset($input['due_date'])) {
        $sets[] = 'due_date = :dd';
        $params['dd'] = $input['due_date'];
        $isManual = true;
    }
    if (isset($input['observacoes'])) {
        $sets[] = 'observacoes_cobranca = :obs';
        $params['obs'] = trim($input['observacoes']);
        $isManual = true;
    }
    if (isset($input['amount_cents'])) {
        $sets[] = 'amount_cents = :val';
        $params['val'] = (int) $input['amount_cents'];
        $isManual = true;
    }

    if (empty($sets)) ApiResponse::badRequest('nada para atualizar');

    if ($isManual) {
        // Resolve super_admin_id atual
        $saStmt = $pdo->prepare("SELECT id FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1");
        $saStmt->execute(['uid' => $ctx->getUserId()]);
        $saId = (int) $saStmt->fetchColumn();
        if ($saId > 0) {
            $sets[] = 'marcado_manualmente_por = :saId';
            $sets[] = 'marcado_manualmente_em = NOW()';
            $params['saId'] = $saId;
        }
    }

    $sql = 'UPDATE invoices SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    $resumo = [];
    foreach (['status','due_date','observacoes','amount_cents'] as $k) {
        if (isset($input[$k])) $resumo[$k] = $input[$k];
    }
    MasterAudit::log(
        'payment.update_manual',
        'invoice',
        $id,
        "Fatura #{$id} atualizada manualmente",
        ['antes' => ['status' => $prev['status'], 'paid_at' => $prev['paid_at']], 'depois' => $resumo]
    );

    ApiResponse::ok(['updated' => true]);
}

ApiResponse::methodNotAllowed();
