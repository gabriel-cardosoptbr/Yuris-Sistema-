<?php
/**
 * Painel Master — gestão de subscriptions e invoices.
 *
 * GET    /api/master/billing.php              → lista subscriptions + filtros
 * GET    /api/master/billing.php?invoices=1   → lista invoices
 * PATCH  /api/master/billing.php              → muda plano da assinatura
 * POST   /api/master/billing.php?cancel=1     → cancela assinatura
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
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) ApiResponse::badRequest('CSRF inválido');
}

if ($method === 'GET') {
    if (!empty($_GET['invoices'])) {
        $rows = $pdo->query(
            "SELECT i.*, a.nome AS account_nome
             FROM invoices i INNER JOIN accounts a ON a.id = i.account_id
             ORDER BY i.id DESC LIMIT 100"
        )->fetchAll(\PDO::FETCH_ASSOC);
        ApiResponse::ok(['invoices' => $rows]);
    }

    // Assinaturas do plano do sistema (subscriptions)
    $subs = $pdo->query(
        "SELECT s.*, a.nome AS account_nome, p.nome AS plan_nome, p.slug AS plan_slug,
                p.preco_mensal_cents, p.preco_anual_cents
         FROM subscriptions s
         INNER JOIN accounts a ON a.id = s.account_id
         INNER JOIN plans    p ON p.id = s.plan_id
         WHERE a.deleted_at IS NULL
         ORDER BY s.id DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Assinaturas de monitoramento (overrides com billing_cycle preenchido).
    // Etapa 6+7 (add-on Monitoramentos) — monitoramento é cobrado por unidade
    // à parte do plano, em ciclo recorrente (mensal/trimestral/anual).
    // Aparece como linha separada na tabela "Assinaturas" pro Master ter
    // visão única de toda receita recorrente do tenant.
    $monitorSubs = $pdo->query(
        "SELECT aqo.id, aqo.account_id, aqo.limit_value AS qtd,
                aqo.unit_price_cents, aqo.billing_cycle, aqo.contract_ref,
                aqo.expires_at, aqo.observacoes, aqo.created_at,
                a.nome AS account_nome
         FROM account_quota_overrides aqo
         INNER JOIN accounts a ON a.id = aqo.account_id
         WHERE aqo.feature_key  = 'monitors.limit'
           AND aqo.source        = 'purchase'
           AND aqo.billing_cycle IS NOT NULL
           AND aqo.billing_cycle <> 'one_off'
           AND aqo.revoked_at    IS NULL
           AND (aqo.expires_at IS NULL OR aqo.expires_at > NOW())
           AND a.deleted_at IS NULL
         ORDER BY aqo.id DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    ApiResponse::ok([
        'subscriptions'        => $subs,
        'monitor_subscriptions' => $monitorSubs,
    ]);
}

if ($method === 'PATCH') {
    $subId = (int)($input['subscription_id'] ?? 0);
    if (!$subId) ApiResponse::badRequest('subscription_id obrigatório');

    // Carrega estado anterior pra audit log
    $before = $pdo->prepare("SELECT * FROM subscriptions WHERE id = :id LIMIT 1");
    $before->execute(['id' => $subId]);
    $prev = $before->fetch(\PDO::FETCH_ASSOC);
    if (!$prev) ApiResponse::notFound('Assinatura não encontrada');

    $sets = []; $params = ['id' => $subId];

    if (isset($input['plan_id'])) {
        $pid = (int) $input['plan_id'];
        $check = $pdo->prepare("SELECT id FROM plans WHERE id = :id LIMIT 1");
        $check->execute(['id' => $pid]);
        if (!$check->fetchColumn()) ApiResponse::badRequest('Plano não encontrado');
        $sets[] = 'plan_id = :plan_id'; $params['plan_id'] = $pid;
    }
    if (isset($input['status'])) {
        $valid = ['trialing','active','past_due','canceled','unpaid','incomplete'];
        if (!in_array($input['status'], $valid, true)) ApiResponse::badRequest('status inválido');
        $sets[] = 'status = :status'; $params['status'] = $input['status'];
        if ($input['status'] === 'canceled' && empty($prev['canceled_at'])) {
            $sets[] = 'canceled_at = NOW()';
        }
    }
    if (isset($input['billing_cycle'])) {
        if (!in_array($input['billing_cycle'], ['monthly','quarterly','yearly'], true)) {
            ApiResponse::badRequest('billing_cycle inválido');
        }
        $sets[] = 'billing_cycle = :bc'; $params['bc'] = $input['billing_cycle'];
    }
    if (array_key_exists('trial_ends_at', $input)) {
        $sets[] = 'trial_ends_at = :te';
        $params['te'] = $input['trial_ends_at'] ?: null;
    }
    if (array_key_exists('current_period_end', $input)) {
        $sets[] = 'current_period_end = :pe';
        $params['pe'] = $input['current_period_end'] ?: null;
    }
    if (isset($input['cancel_at_period_end'])) {
        $sets[] = 'cancel_at_period_end = :cape';
        $params['cape'] = $input['cancel_at_period_end'] ? 1 : 0;
    }

    if (empty($sets)) ApiResponse::badRequest('Nada para atualizar');

    $pdo->prepare('UPDATE subscriptions SET ' . implode(', ', $sets) . ' WHERE id = :id')
        ->execute($params);

    MasterAudit::log(
        'subscription.update',
        'subscription',
        $subId,
        "Assinatura #{$subId} editada via Painel Master",
        [
            'antes' => [
                'plan_id' => $prev['plan_id'],
                'status'  => $prev['status'],
                'billing_cycle' => $prev['billing_cycle'],
            ],
            'depois' => array_intersect_key($input, array_flip([
                'plan_id','status','billing_cycle','trial_ends_at','current_period_end','cancel_at_period_end'
            ])),
        ]
    );
    ApiResponse::ok(['updated' => true]);
}

if ($method === 'POST' && !empty($_GET['cancel'])) {
    $subId = (int)($input['subscription_id'] ?? 0);
    $atEnd = !empty($input['at_period_end']);
    if (!$subId) ApiResponse::badRequest('subscription_id obrigatório');
    if ($atEnd) {
        $pdo->prepare('UPDATE subscriptions SET cancel_at_period_end = 1 WHERE id = :id')
            ->execute(['id' => $subId]);
    } else {
        $pdo->prepare('UPDATE subscriptions SET status="canceled", canceled_at=NOW() WHERE id = :id')
            ->execute(['id' => $subId]);
    }
    MasterAudit::log('subscription.cancel', 'subscription', $subId,
        $atEnd ? "Assinatura #{$subId} agendada para cancelar no fim do período"
               : "Assinatura #{$subId} cancelada imediatamente",
        ['at_period_end' => $atEnd]);
    ApiResponse::ok(['canceled' => true, 'at_period_end' => $atEnd]);
}

ApiResponse::methodNotAllowed();
