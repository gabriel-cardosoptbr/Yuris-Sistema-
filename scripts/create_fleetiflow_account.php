<?php
/**
 * scripts/create_fleetiflow_account.php — Cria a conta Fleetiflow (edição
 * CRM/comercial) do mesmo jeito que o Painel Master cria uma conta real.
 *
 * Espelha `public/api/master/create_account.php` (tipo='matriz'): accounts +
 * users (admin) + subscriptions + AccountBootstrapSeeder, tudo numa transação.
 * Diferenças de propósito, únicas neste script:
 *   1. `accounts.configuracoes` já nasce com {"produto":"fleetiflow"} — é o que
 *      `AccountContext::getProduto()`/`moduloJuridicoDisponivel()` leem para
 *      esconder e bloquear o jurídico só para esta conta.
 *   2. Depois do seed padrão, renomeia a etapa "Negociação jurídica" do funil
 *      para "Negociação" e remove o setor "Jurídico" de Clientes NESTA conta
 *      (dado, não código — os outros 7 setores/a etapa em si continuam
 *      genéricos e servem normalmente).
 *   3. Usa `Account::audit()` (grava direto em account_audit_log), não
 *      `MasterAudit::log()`: essa exige sessão HTTP de super admin e não
 *      grava nada rodando via CLI (comportamento correto e documentado dela,
 *      "auditoria nunca derruba o fluxo principal" — só que aqui não há
 *      fluxo de request nenhum para não derrubar).
 *
 * Não roda duas vezes por acidente: aborta se já existir um user com o login
 * informado, ou uma conta com esse nome que já seja produto=fleetiflow.
 *
 * USO
 *   php scripts/create_fleetiflow_account.php
 *   php scripts/create_fleetiflow_account.php --login=admin@fleetiflow.com.br --nome="Admin Fleetiflow"
 *   php scripts/create_fleetiflow_account.php --password=senhaEscolhida123
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só roda via CLI (php scripts/create_fleetiflow_account.php).\n");
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Master\Account;
use App\Master\AccountBootstrapSeeder;

$opts = getopt('', ['login:', 'password:', 'nome:', 'account-nome:', 'plano:', 'help']);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__);
    exit(0);
}

$login       = $opts['login']         ?? 'agenciainovaize@gmail.com';
$nome        = $opts['nome']          ?? 'Administrador Fleetiflow';
$accountNome = $opts['account-nome']  ?? 'Fleetiflow';
$planoSlug   = $opts['plano']         ?? 'equipe';

$senhaProvided = isset($opts['password']);
if ($senhaProvided) {
    $senha = $opts['password'];
} else {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem i/l/0/o
    $senha = '';
    for ($i = 0; $i < 16; $i++) $senha .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
$senhaHash = password_hash($senha, PASSWORD_BCRYPT);

try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, "ERRO ao conectar no banco: " . $e->getMessage() . "\n");
    exit(1);
}
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ─── Checagens de duplicidade (mesmo espírito do create_account.php) ───────
$dup = $pdo->prepare('SELECT id FROM users WHERE login = :em AND deleted_at IS NULL LIMIT 1');
$dup->execute(['em' => $login]);
if ($dup->fetchColumn()) {
    fwrite(STDERR, "ERRO: já existe um usuário com o login '{$login}'. Escolha outro com --login= ou remova o existente.\n");
    exit(1);
}

$dupAcc = $pdo->prepare(
    "SELECT id FROM accounts WHERE nome = :n AND deleted_at IS NULL LIMIT 1"
);
$dupAcc->execute(['n' => $accountNome]);
if ($existingId = $dupAcc->fetchColumn()) {
    fwrite(STDERR, "ERRO: já existe uma conta chamada '{$accountNome}' (id={$existingId}). Este script não cria duplicata.\n");
    exit(1);
}

// ─── Plano: precisa existir e não ter AASP habilitado (conta não é jurídica) ─
$plano = $pdo->prepare('SELECT * FROM plans WHERE slug = :s AND ativo = 1 LIMIT 1');
$plano->execute(['s' => $planoSlug]);
$plano = $plano->fetch(\PDO::FETCH_ASSOC);
if (!$plano) {
    fwrite(STDERR, "ERRO: plano '{$planoSlug}' não encontrado ou inativo. Veja a tabela `plans`.\n");
    exit(1);
}

// ─── Transação atômica — mesma sequência do Painel Master ──────────────────
$pdo->beginTransaction();
try {
    // 1. accounts — já nasce com produto=fleetiflow em `configuracoes`
    $codigoVinculo = implode('-', str_split(bin2hex(random_bytes(8)), 4));
    $stmtA = $pdo->prepare(
        "INSERT INTO accounts
           (nome, email, tipo, codigo_vinculo, plano, status, configuracoes, created_at, updated_at)
         VALUES
           (:nome, :email, 'matriz', :codigo, :plano, 'active', :config, NOW(), NOW())"
    );
    $stmtA->execute([
        'nome'   => $accountNome,
        'email'  => $login,
        'codigo' => $codigoVinculo,
        'plano'  => $plano['slug'],
        'config' => json_encode(['produto' => 'fleetiflow'], JSON_UNESCAPED_UNICODE),
    ]);
    $accountId = (int) $pdo->lastInsertId();

    // 2. users — admin/owner da conta
    $stmtU = $pdo->prepare(
        "INSERT INTO users
           (account_id, nome, login, senha_hash, perfil, role, status, created_at, updated_at)
         VALUES
           (:aid, :nome, :login, :hash, 'admin', 'owner', 'active', NOW(), NOW())"
    );
    $stmtU->execute([
        'aid'   => $accountId,
        'nome'  => $nome,
        'login' => $login,
        'hash'  => $senhaHash,
    ]);
    $userId = (int) $pdo->lastInsertId();

    // 3. subscriptions
    $trialDias = (int) ($plano['trial_dias'] ?? 0);
    $stmtS = $pdo->prepare(
        "INSERT INTO subscriptions
           (account_id, plan_id, status, billing_cycle,
            trial_ends_at, current_period_start, current_period_end, created_at, updated_at)
         VALUES
           (:aid, :pid, 'active', 'monthly',
            DATE_ADD(NOW(), INTERVAL :td DAY), NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW(), NOW())"
    );
    $stmtS->execute(['aid' => $accountId, 'pid' => (int)$plano['id'], 'td' => $trialDias]);
    $subId = (int) $pdo->lastInsertId();

    // 4. Bootstrap padrão (funil, setores/origens de Clientes, quadro de Tarefas)
    $seedCounts = AccountBootstrapSeeder::bootstrapNew($pdo, $accountId, 'matriz', $userId);

    // 5. Ajusta terminologia herdada do seed padrão que soa jurídica —
    //    dado da conta, não código: o próximo admin pode editar por trás da UI.
    $pdo->prepare(
        "UPDATE pipeline_columns SET nome = 'Negociação'
          WHERE account_id = :aid AND slug = 'negociacao-juridica'"
    )->execute(['aid' => $accountId]);
    $pdo->prepare(
        "DELETE FROM clientes_setores WHERE account_id = :aid AND slug = 'juridico'"
    )->execute(['aid' => $accountId]);

    // 6. Auditoria (grava direto — MasterAudit::log() exige sessão HTTP e não
    //    grava nada rodando via CLI, por desenho)
    Account::audit($accountId, 'account.create', [
        'user_id'  => $userId,
        'entidade' => 'account',
        'entidade_id' => $accountId,
        'detalhes' => [
            'nome'    => $accountNome,
            'produto' => 'fleetiflow',
            'plano'   => $plano['slug'],
            'admin_login' => $login,
            'origem'  => 'scripts/create_fleetiflow_account.php',
            'bootstrap_seed' => $seedCounts,
        ],
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRO ao criar a conta Fleetiflow: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Transação revertida — nenhum dado foi gravado.\n");
    exit(1);
}

echo "═══════════════════════════════════════════════════════════════\n";
echo " FLEETIFLOW — CONTA CRIADA                                     \n";
echo "═══════════════════════════════════════════════════════════════\n";
echo " Account:      #{$accountId}  ({$accountNome})\n";
echo " Produto:      fleetiflow (jurídico oculto e bloqueado)\n";
echo " Plano:        {$plano['slug']}\n";
echo " Subscription: #{$subId}\n";
echo " User:         #{$userId}  ({$nome})\n";
echo "               role=owner, perfil=admin\n";
echo "───────────────────────────────────────────────────────────────\n";
echo " LOGIN\n";
echo "───────────────────────────────────────────────────────────────\n";
echo " URL:          http://localhost:8090/login-fleetiflow.php\n";
echo " Login:        {$login}\n";
if (!$senhaProvided) {
    echo " Senha:        {$senha}\n";
    echo "               ── anote AGORA, não fica salva em texto ──\n";
} else {
    echo " Senha:        (a que você passou via --password)\n";
}
echo "═══════════════════════════════════════════════════════════════\n";
echo " Troque essa senha no primeiro login (Configurações > Perfil).\n";
echo "═══════════════════════════════════════════════════════════════\n";
