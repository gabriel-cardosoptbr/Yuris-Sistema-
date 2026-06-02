<?php
/**
 * Painel Master — criação de filial vinculada a uma matriz.
 *
 * Cria em transação:
 *   1. accounts (tipo=filial) — SEM gravar matriz_id (coluna vestigial)
 *   2. account_vinculos (status=active, aprovado_por=user atual) — o vínculo real
 *      matriz↔filial vive AQUI, não em accounts.matriz_id
 *   3. opcional: users (admin da filial) — só se "admin" for enviado
 *
 * Acesso: super_admin apenas. CSRF obrigatório.
 *
 * POST body:
 *   {
 *     csrf_token: "...",
 *     matriz_id: 1,
 *     filial: {
 *       nome: "...", razao_social?, cnpj?, email?, telefone?, cidade?, estado?,
 *       status?: "active|trial"
 *     },
 *     admin?: {       // OPCIONAL — se enviado, cria admin da filial
 *       nome, email, senha?, telefone?
 *     }
 *   }
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ApiResponse.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Services/AccountBootstrapSeeder.php';

use App\Helpers\AccountContext;
use App\Helpers\ApiResponse;
use App\Helpers\MasterAudit;
use App\Models\Database;
use App\Services\AccountBootstrapSeeder;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ApiResponse::methodNotAllowed();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    ApiResponse::badRequest('CSRF inválido');
}

$matrizId = (int) ($input['matriz_id'] ?? 0);
if ($matrizId <= 0) ApiResponse::badRequest('matriz_id é obrigatório');

$fil = $input['filial'] ?? [];
$adm = $input['admin']  ?? null;

$nome = trim($fil['nome'] ?? '');
if ($nome === '') ApiResponse::badRequest('Nome da filial é obrigatório');

$pdo = Database::getConnection();

// Matriz existe e está ativa?
$m = $pdo->prepare(
    "SELECT id, tipo, status FROM accounts
     WHERE id = :id AND deleted_at IS NULL LIMIT 1"
);
$m->execute(['id' => $matrizId]);
$matriz = $m->fetch(\PDO::FETCH_ASSOC);
if (!$matriz) ApiResponse::badRequest('Matriz não encontrada');
if ($matriz['tipo'] !== 'matriz') {
    ApiResponse::badRequest('A conta selecionada não é uma matriz');
}

$cnpj = preg_replace('/\D/', '', $fil['cnpj'] ?? '') ?: null;
if ($cnpj) {
    $dupC = $pdo->prepare("SELECT id FROM accounts WHERE cnpj = :c AND deleted_at IS NULL LIMIT 1");
    $dupC->execute(['c' => $cnpj]);
    if ($dupC->fetchColumn()) {
        ApiResponse::badRequest('Já existe uma conta com este CNPJ');
    }
}

$filStatus = in_array($fil['status'] ?? 'active', ['active','trial','suspended'], true)
    ? $fil['status'] : 'active';

// Admin opcional — valida se for enviado
$senhaGerada = false; $senhaTexto = null;
if (is_array($adm) && !empty($adm)) {
    $admNome  = trim($adm['nome']  ?? '');
    $admEmail = trim($adm['email'] ?? '');
    if ($admNome === '' || $admEmail === '') {
        ApiResponse::badRequest('Nome e email do admin da filial são obrigatórios quando admin é enviado');
    }
    if (!filter_var($admEmail, FILTER_VALIDATE_EMAIL)) {
        ApiResponse::badRequest('Email do admin inválido');
    }
    $dup = $pdo->prepare("SELECT id FROM users WHERE login = :em AND deleted_at IS NULL LIMIT 1");
    $dup->execute(['em' => $admEmail]);
    if ($dup->fetchColumn()) {
        ApiResponse::badRequest('Já existe um usuário com este email');
    }
    $senhaTexto = trim($adm['senha'] ?? '');
    $senhaGerada = $senhaTexto === '';
    if ($senhaGerada) {
        $alpha = 'abcdefghijkmnpqrstuvwxyz23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $senhaTexto = '';
        for ($i = 0; $i < 10; $i++) $senhaTexto .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
}

try {
    $pdo->beginTransaction();

    // 1. INSERT filial
    // NÃO gravamos accounts.matriz_id: essa coluna é vestigial (regra canônica —
    // sempre NULL). O vínculo matriz↔filial vive EXCLUSIVAMENTE em
    // account_vinculos, criado no passo 2 abaixo.
    $stmtA = $pdo->prepare(
        "INSERT INTO accounts
           (nome, razao_social, cnpj, email, telefone, cidade, estado,
            tipo, codigo_vinculo, plano, status, created_at, updated_at)
         VALUES
           (:nome, :rs, :cnpj, :em, :tel, :ci, :uf,
            'filial', :codigo, 'basico', :status, NOW(), NOW())"
    );
    $codigo = implode('-', str_split(bin2hex(random_bytes(8)), 4));
    $stmtA->execute([
        'nome'   => $nome,
        'rs'     => trim($fil['razao_social'] ?? '') ?: null,
        'cnpj'   => $cnpj,
        'em'     => trim($fil['email'] ?? '') ?: null,
        'tel'    => trim($fil['telefone'] ?? '') ?: null,
        'ci'     => trim($fil['cidade'] ?? '') ?: null,
        'uf'     => trim($fil['estado'] ?? '') ?: null,
        'codigo' => $codigo,
        'status' => $filStatus,
    ]);
    $filialId = (int) $pdo->lastInsertId();

    // 2. INSERT account_vinculos (active direto — criado por super_admin)
    $userId = $ctx->getUserId();
    $stmtV = $pdo->prepare(
        "INSERT INTO account_vinculos
           (matriz_account_id, filial_account_id, status,
            solicitado_por, solicitado_em, aprovado_por, aprovado_em,
            sync_enabled, sync_processos, sync_cards, sync_tarefas,
            created_at, updated_at)
         VALUES
           (:m, :f, 'active', :u, NOW(), :u2, NOW(),
            1, 1, 1, 1,
            NOW(), NOW())"
    );
    $stmtV->execute(['m' => $matrizId, 'f' => $filialId, 'u' => $userId, 'u2' => $userId]);
    $vinculoId = (int) $pdo->lastInsertId();

    // 3. INSERT admin (opcional)
    $adminUserId = null;
    if ($adm) {
        $stmtU = $pdo->prepare(
            "INSERT INTO users
               (account_id, nome, login, senha_hash, perfil, role, status, telefone, created_at, updated_at)
             VALUES
               (:aid, :nome, :login, :sh, 'admin', 'owner', 'active', :tel, NOW(), NOW())"
        );
        $stmtU->execute([
            'aid'   => $filialId,
            'nome'  => $admNome,
            'login' => $admEmail,
            'sh'    => password_hash($senhaTexto, PASSWORD_BCRYPT),
            'tel'   => trim($adm['telefone'] ?? '') ?: null,
        ]);
        $adminUserId = (int) $pdo->lastInsertId();
    }

    // Bootstrap — filial não nasce crua tampouco:
    //   - 0 pipeline_columns (filial herda da matriz — não recebe)
    //   - 8 setores em Clientes (setores são per-conta no módulo Clientes)
    //   - 10 origens de cadastro em Clientes
    //   - 1 quadro Tarefas (compartilhado) — APENAS SE filial tiver admin próprio.
    //     Sem admin, $adminUserId = null e o seed pula (board precisa de owner_id).
    //     Filial sem admin compartilha tarefas via escopo da matriz mesmo.
    $seedCounts = AccountBootstrapSeeder::bootstrapNew($pdo, $filialId, 'filial', $adminUserId);

    MasterAudit::log(
        'filial.create',
        'account',
        $filialId,
        "Filial '{$nome}' criada vinculada à matriz #{$matrizId}",
        [
            'matriz_id'   => $matrizId,
            'vinculo_id'  => $vinculoId,
            'admin_user_id' => $adminUserId,
            'status'      => $filStatus,
            'bootstrap_seed' => $seedCounts,
        ]
    );

    $pdo->commit();

    $payload = [
        'filial_id'      => $filialId,
        'vinculo_id'     => $vinculoId,
        'codigo_vinculo' => $codigo,
    ];
    if ($adminUserId) {
        $payload['admin_user_id'] = $adminUserId;
        if ($senhaGerada) $payload['senha_gerada'] = $senhaTexto;
    }

    ApiResponse::ok($payload);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[master/create_filial] ' . $e->getMessage());
    ApiResponse::serverError('Falha ao criar filial: ' . $e->getMessage());
}
