<?php
/**
 * Painel Master — criação atômica de nova conta (matriz OU advogado-solo).
 *
 * Cria em UMA transação:
 *   1. accounts (tipo='matriz' ou 'advogado')
 *   2. users (admin da conta com role=owner)
 *      - Se tipo='advogado': também is_advogado=1, oab/oab_uf, codigo_advogado
 *   3. subscriptions (status=trialing ou active, conforme plano)
 *
 * Se qualquer passo falhar, faz ROLLBACK completo.
 *
 * Acesso: super_admin apenas. CSRF obrigatório.
 *
 * POST body (JSON):
 *   {
 *     csrf_token: "...",
 *     tipo?: "matriz"|"advogado",   // default: matriz
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
 *       telefone?: "...",
 *       oab?: "...",       // obrigatório quando tipo='advogado'
 *       oab_uf?: "SP",     // obrigatório quando tipo='advogado'
 *     },
 *     subscription: {
 *       plan_id: 1,
 *       billing_cycle?: "monthly|yearly",  // default: monthly
 *       trial_dias?: 14,                   // default: lê do plano
 *     }
 *   }
 *
 * Retorna:
 *   { ok: true, data: { account_id, user_id, subscription_id, codigo_vinculo,
 *                       senha_gerada?, codigo_advogado? } }
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Master\MasterAudit;
use App\Core\Database;
use App\Master\AccountBootstrapSeeder;

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
$tipo = trim($input['tipo'] ?? 'matriz');
if (!in_array($tipo, ['matriz','advogado'], true)) {
    ApiResponse::badRequest("tipo inválido (use 'matriz' ou 'advogado')");
}
$isAdvogado = ($tipo === 'advogado');

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

// Quando tipo='advogado', OAB e UF são obrigatórios
$advOab    = trim($adm['oab']    ?? '');
$advOabUf  = strtoupper(trim($adm['oab_uf'] ?? ''));
if ($isAdvogado) {
    if ($advOab === '' || $advOabUf === '') {
        ApiResponse::badRequest('OAB e UF da OAB são obrigatórios pra advogado solo');
    }
    if (strlen($advOabUf) !== 2) {
        ApiResponse::badRequest('UF da OAB deve ter 2 letras');
    }
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

// ─── OAB duplicada (apenas pra tipo advogado) ───────────────────────────────
if ($isAdvogado && $advOab !== '') {
    $dupO = $pdo->prepare("SELECT id FROM users WHERE oab = :o AND deleted_at IS NULL LIMIT 1");
    $dupO->execute(['o' => $advOab]);
    if ($dupO->fetchColumn()) {
        ApiResponse::badRequest('Já existe um advogado cadastrado com esta OAB');
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
$cycle     = in_array($sub['billing_cycle'] ?? 'monthly', ['monthly','quarterly','yearly'], true)
    ? $sub['billing_cycle']
    : 'monthly';
$subStatus = $accStatus === 'active' ? 'active' : 'trialing';

// ─── Gera código ADV-XXXXXX único quando for advogado solo ──────────────────
$codigoAdvogado = null;
if ($isAdvogado) {
    for ($i = 0; $i < 12; $i++) {
        $cand = 'ADV-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $ck = $pdo->prepare("SELECT id FROM users WHERE codigo_advogado = :c LIMIT 1");
        $ck->execute(['c' => $cand]);
        if (!$ck->fetchColumn()) { $codigoAdvogado = $cand; break; }
    }
    if ($codigoAdvogado === null) {
        $codigoAdvogado = 'ADV-' . bin2hex(random_bytes(3));
    }
}

// ─── Transação atômica ──────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // 1. INSERT accounts (tipo dinâmico: matriz ou advogado)
    $stmtA = $pdo->prepare(
        "INSERT INTO accounts
           (nome, razao_social, cnpj, email, telefone, cidade, estado,
            tipo, codigo_vinculo, plano, status, created_at, updated_at)
         VALUES
           (:nome, :rs, :cnpj, :em, :tel, :ci, :uf,
            :tipo, :codigo, :plano, :status, NOW(), NOW())"
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
        'tipo'   => $tipo,
        'codigo' => $codigo,
        'plano'  => $plano['slug'],
        'status' => $accStatus,
    ]);
    $accountId = (int) $pdo->lastInsertId();

    // 2. INSERT users (admin) — para advogado solo, também é_advogado=1, oab, oab_uf
    if ($isAdvogado) {
        $stmtU = $pdo->prepare(
            "INSERT INTO users
               (account_id, nome, login, senha_hash, perfil, role, status, telefone,
                oab, oab_uf, codigo_advogado, is_advogado, created_at, updated_at)
             VALUES
               (:aid, :nome, :login, :sh, 'admin', 'owner', 'active', :tel,
                :oab, :uf, :codAdv, 1, NOW(), NOW())"
        );
        $stmtU->execute([
            'aid'    => $accountId,
            'nome'   => $admNome,
            'login'  => $admEmail,
            'sh'     => $senhaHash,
            'tel'    => trim($adm['telefone'] ?? '') ?: null,
            'oab'    => $advOab,
            'uf'     => $advOabUf,
            'codAdv' => $codigoAdvogado,
        ]);
    } else {
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
    }
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
        'periodo' => $cycle === 'yearly' ? 12 : ($cycle === 'quarterly' ? 3 : 1),
    ]);
    $subId = (int) $pdo->lastInsertId();

    // 4. Monitoramento (add-on opcional). Se vier no payload `monitor`,
    //    insere em account_quota_overrides — mesma mecânica do /quotas.php.
    //    Tudo na mesma transação: se algo abaixo falhar, rollback total.
    $monOverrideId = null;
    $mon = $input['monitor'] ?? null;
    if (is_array($mon) && !empty($mon['qtd'])) {
        $monQtd = (int)$mon['qtd'];
        if ($monQtd > 0 && $monQtd <= 1000) {
            $monCycle = $mon['billing_cycle'] ?? null;
            // Normaliza: backend de quotas aceita só monthly/yearly/one_off.
            // Convertemos 'quarterly' (oferecido na UI) pra um valor compatível +
            // registramos no observacoes pra preservar a intenção comercial.
            $observacoes = trim($mon['observacoes'] ?? '');
            if ($monCycle === 'quarterly') {
                $observacoes = ($observacoes ? $observacoes . ' · ' : '') . 'Ciclo: trimestral';
                $monCycle = 'monthly';
            }
            if (!in_array($monCycle, ['monthly', 'yearly', 'one_off'], true)) {
                $monCycle = 'monthly';
            }
            $monPrice    = isset($mon['unit_price_cents']) && $mon['unit_price_cents'] !== ''
                ? (int)$mon['unit_price_cents'] : null;
            $monContract = !empty($mon['contract_ref']) ? trim($mon['contract_ref']) : null;
            $monObs      = $observacoes !== '' ? $observacoes : null;

            // FIX: set_by_super_admin_id é FK pra super_admins.id (NÃO users.id).
            // O quotas.php faz a mesma resolução. Antes eu estava gravando o
            // user_id direto — semanticamente errado (override 12 da conta 76
            // ficou com sa_id=91 que é user_id do super, não sa.id real).
            $saStmt = $pdo->prepare('SELECT id FROM super_admins WHERE user_id = :uid AND ativo = 1 LIMIT 1');
            $saStmt->execute(['uid' => $ctx->getUserId()]);
            $superAdminId = (int)($saStmt->fetchColumn() ?: 0);
            // Se o caller não é super_admin (raro neste endpoint que já checou
            // assertSuperAdmin), usa NULL — a coluna é nullable.

            $stmtM = $pdo->prepare(
                "INSERT INTO account_quota_overrides
                    (account_id, feature_key, limit_value, source,
                     set_by_super_admin_id,
                     unit_price_cents, billing_cycle, contract_ref, observacoes)
                 VALUES
                    (:aid, 'monitors.limit', :lv, 'purchase',
                     :sa,
                     :upc, :bc, :ref, :obs)"
            );
            $stmtM->execute([
                'aid' => $accountId,
                'lv'  => $monQtd,
                'sa'  => $superAdminId > 0 ? $superAdminId : null,
                'upc' => $monPrice,
                'bc'  => $monCycle,
                'ref' => $monContract,
                'obs' => $monObs,
            ]);
            $monOverrideId = (int)$pdo->lastInsertId();
        }
    }

    // 5. Bootstrap — conta nova não nasce 100% crua. Semeia:
    //   - 4 etapas de pipeline (Prospecção)  → matriz e advogado (filial herda)
    //   - 8 setores em Clientes              → todas
    //   - 10 origens de cadastro em Clientes → todas
    //   - 1 quadro Tarefas compartilhado + 4 colunas (admin = owner)
    // Admin pode renomear/reordenar/arquivar a qualquer momento pela UI.
    $seedCounts = AccountBootstrapSeeder::bootstrapNew($pdo, $accountId, $tipo, $userId);

    // 6. Audit
    MasterAudit::log(
        'account.create',
        'account',
        $accountId,
        "Conta '{$nome}' (tipo: {$tipo}) criada via Painel Master",
        [
            'tipo'         => $tipo,
            'plano'        => $plano['slug'],
            'status'       => $accStatus,
            'admin_email'  => $admEmail,
            'trial_dias'   => $trialDias,
            'billing_cycle'=> $cycle,
            'subscription_id' => $subId,
            'user_id'      => $userId,
            'oab'          => $isAdvogado ? "{$advOab}/{$advOabUf}" : null,
            'bootstrap_seed' => $seedCounts,
            'monitor_override_id' => $monOverrideId,
        ]
    );

    $pdo->commit();

    // Auto-instância WhatsApp (BEST-EFFORT): matriz e advogado novos já nascem com
    // instância na Evolution. Roda DEPOIS do commit, fora da transação — se a
    // Evolution falhar, NÃO derruba o cadastro (a conta já está persistida). O
    // helper já é try/catch interno e idempotente; o guard evita tentar quando a
    // config global ainda não existe. (Filial é tratada à parte no create_filial.)
    $whatsappResult = null;
    if (in_array($tipo, ['matriz', 'advogado'], true)
        && WhatsAppProvisioningService::isGloballyConfigured($pdo)) {
        try {
            $whatsappResult = WhatsAppProvisioningService::provision($pdo, (int)$accountId, (string)$nome);
        } catch (\Throwable $e) {
            error_log('[master/create_account/whatsapp] ' . $e->getMessage());
            $whatsappResult = ['success' => false, 'error' => $e->getMessage()];
        }
    }

    $payload = [
        'account_id'      => $accountId,
        'user_id'         => $userId,
        'subscription_id' => $subId,
        'codigo_vinculo'  => $codigo,
        'tipo'            => $tipo,
    ];
    if ($senhaGerada)    $payload['senha_gerada']    = $senhaTexto;
    if ($codigoAdvogado) $payload['codigo_advogado'] = $codigoAdvogado;
    if ($whatsappResult) $payload['whatsapp']        = $whatsappResult;

    ApiResponse::ok($payload);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[master/create_account] ' . $e->getMessage());
    ApiResponse::serverError('Falha ao criar conta: ' . $e->getMessage());
}
