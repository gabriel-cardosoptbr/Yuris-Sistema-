<?php
/**
 * Painel Master — criação atômica de nova conta (matriz).
 *
 * Cria em UMA transação:
 *   1. accounts (tipo=matriz)
 *   2. users (admin da matriz com role=owner)
 *   3. subscriptions (status=trialing ou active, conforme plano)
 *
 * Se qualquer passo falhar, faz ROLLBACK completo.
 *
 * Acesso: super_admin apenas. CSRF obrigatório.
 *
 * POST body (JSON):
 *   {
 *     csrf_token: "...",
 *     account: {
 *       nome: "...",
 *       razao_social?: "...",
 *       cnpj?: "...",
 *       email?: "...",
 *       telefone?: "...",
 *       cidade?: "...",
 *       estado?: "SP",
 *       status?: "trial|active",  // default: trial
 *     },
 *     admin: {
 *       nome: "...",
 *       email: "...",
 *       senha?: "...",     // se ausente, gera senha aleatória
 *       telefone?: "..."
 *     },
 *     subscription: {
 *       plan_id: 1,
 *       billing_cycle?: "monthly|yearly",  // default: monthly
 *       trial_dias?: 14,                   // default: lê do plano
 *     }
 *   }
 *
 * Retorna:
 *   { ok: true, data: { account_id, user_id, subscription_id, senha_gerada? } }
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ApiResponse::methodNotAllowed();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

$acc  = $input['account']      ?? [];
$adm  = $input['admin']        ?? [];
$sub  = $input['subscription'] ?? [];

// ─── Validações básicas ─────────────────────────────────────────────────────
$nome = trim($acc['nome'] ?? '');
if ($nome === '') ApiResponse::badRequest('Nome da conta é obrigatório');

$admNome  = trim($adm['nome']  ?? '');
$admEmail = trim($adm['email'] ?? '');
if ($admNome === '' || $admEmail === '') {
    ApiResponse::badRequest('Nome e email do admin são obrigatórios');
}
if (!filter_var($admEmail, FILTER_VALIDATE_EMAIL)) {
    ApiResponse::badRequest('Email do admin inválido');
}

$planId = (int) ($sub['plan_id'] ?? 0);
if ($planId <= 0) ApiResponse::badRequest('plan_id é obrigatório');

$pdo = Database::getConnection();

// ─── Plano existe e está ativo? ─────────────────────────────────────────────
$plano = $pdo->prepare("SELECT * FROM plans WHERE id = :pid LIMIT 1");
$plano->execute(['pid' => $planId]);
$plano = $plano->fetch(\PDO::FETCH_ASSOC);
if (!$plano)  ApiResponse::badRequest('Plano não encontrado');
if (!$plano['ativo']) ApiResponse::badRequest('Plano está inativo');

// ─── Email duplicado em users (login)? ──────────────────────────────────────
$dup = $pdo->prepare("SELECT id FROM users WHERE login = :em AND deleted_at IS NULL LIMIT 1");
$dup->execute(['em' => $admEmail]);
if ($dup->fetchColumn()) {
    ApiResponse::badRequest('Já existe um usuário com este email');
}

// ─── CNPJ duplicado em accounts? ────────────────────────────────────────────
$cnpj = preg_replace('/\D/', '', $acc['cnpj'] ?? '') ?: null;
if ($cnpj) {
    $dupC = $pdo->prepare("SELECT id FROM accounts WHERE cnpj = :c AND deleted_at IS NULL LIMIT 1");
    $dupC->execute(['c' => $cnpj]);
    if ($dupC->fetchColumn()) {
        ApiResponse::badRequest('Já existe uma conta com este CNPJ');
    }
}

// ─── Status & senha ─────────────────────────────────────────────────────────
$accStatus = in_array($acc['status'] ?? 'trial', ['trial','active','suspended'], true)
    ? $acc['status']
    : 'trial';

$senhaTexto    = trim($adm['senha'] ?? '');
$senhaGerada   = $senhaTexto === '';
if ($senhaGerada) {
    // gera senha temporária legível (9 chars, sem chars ambíguos)
    $alpha = 'abcdefghijkmnpqrstuvwxyz23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $senhaTexto = '';
    for ($i = 0; $i < 10; $i++) $senhaTexto .= $alpha[random_int(0, strlen($alpha) - 1)];
}
$senhaHash = password_hash($senhaTexto, PASSWORD_BCRYPT);

$trialDias = isset($sub['trial_dias']) ? (int) $sub['trial_dias'] : (int) $plano['trial_dias'];
$cycle     = in_array($sub['billing_cycle'] ?? 'monthly', ['monthly','yearly'], true)
    ? $sub['billing_cycle']
    : 'monthly';
$subStatus = $accStatus === 'active' ? 'active' : 'trialing';

// ─── Transação atômica ──────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // 1. INSERT accounts
    $stmtA = $pdo->prepare(
        "INSERT INTO accounts
           (nome, razao_social, cnpj, email, telefone, cidade, estado,
            tipo, codigo_vinculo, plano, status, created_at, updated_at)
         VALUES
           (:nome, :rs, :cnpj, :em, :tel, :ci, :uf,
            'matriz', :codigo, :plano, :status, NOW(), NOW())"
    );
    $codigo = implode('-', str_split(bin2hex(random_bytes(8)), 4));
    $stmtA->execute([
        'nome'   => $nome,
        'rs'     => trim($acc['razao_social'] ?? '') ?: null,
        'cnpj'   => $cnpj,
        'em'     => trim($acc['email'] ?? '') ?: null,
        'tel'    => trim($acc['telefone'] ?? '') ?: null,
        'ci'     => trim($acc['cidade'] ?? '') ?: null,
        'uf'     => trim($acc['estado'] ?? '') ?: null,
        'codigo' => $codigo,
        'plano'  => $plano['slug'],
        'status' => $accStatus,
    ]);
    $accountId = (int) $pdo->lastInsertId();

    // 2. INSERT users (admin)
    $stmtU = $pdo->prepare(
        "INSERT INTO users
           (account_id, nome, login, senha_hash, perfil, role, status, telefone, created_at, updated_at)
         VALUES
           (:aid, :nome, :login, :sh, 'admin', 'owner', 'active', :tel, NOW(), NOW())"
    );
    $stmtU->execute([
        'aid'   => $accountId,
        'nome'  => $admNome,
        'login' => $admEmail,
        'sh'    => $senhaHash,
        'tel'   => trim($adm['telefone'] ?? '') ?: null,
    ]);
    $userId = (int) $pdo->lastInsertId();

    // 3. INSERT subscriptions
    $stmtS = $pdo->prepare(
        "INSERT INTO subscriptions
           (account_id, plan_id, status, billing_cycle,
            trial_ends_at, current_period_start, current_period_end, created_at, updated_at)
         VALUES
           (:aid, :pid, :st, :bc,
            DATE_ADD(NOW(), INTERVAL :td DAY), NOW(),
            DATE_ADD(NOW(), INTERVAL :periodo MONTH), NOW(), NOW())"
    );
    $stmtS->execute([
        'aid'     => $accountId,
        'pid'     => $planId,
        'st'      => $subStatus,
        'bc'      => $cycle,
        'td'      => $trialDias,
        'periodo' => $cycle === 'yearly' ? 12 : 1,
    ]);
    $subId = (int) $pdo->lastInsertId();

    // 4. Audit
    MasterAudit::log(
        'account.create',
        'account',
        $accountId,
        "Conta '{$nome}' criada via Painel Master",
        [
            'plano'        => $plano['slug'],
            'status'       => $accStatus,
            'admin_email'  => $admEmail,
            'trial_dias'   => $trialDias,
            'billing_cycle'=> $cycle,
            'subscription_id' => $subId,
            'user_id'      => $userId,
        ]
    );

    $pdo->commit();

    $payload = [
        'account_id'      => $accountId,
        'user_id'         => $userId,
        'subscription_id' => $subId,
        'codigo_vinculo'  => $codigo,
    ];
    if ($senhaGerada) $payload['senha_gerada'] = $senhaTexto;

    ApiResponse::ok($payload);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[master/create_account] ' . $e->getMessage());
    ApiResponse::serverError('Falha ao criar conta: ' . $e->getMessage());
}
