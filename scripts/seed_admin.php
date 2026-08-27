<?php
/**
 * scripts/seed_admin.php — Bootstrap inicial do Yuris em servidor virgem.
 *
 * Cria a stack mínima pra o sistema funcionar:
 *   1. account raiz (tipo='matriz', plano='basico', status='active')
 *   2. subscription apontando pra plan_id=1 (Básico, status='active')
 *   3. user super_admin com account_id, role='super_admin', perfil='admin'
 *
 * Idempotente: se o login (default 'admin') já existe, só atualiza a senha
 * (útil pra recuperação após troca de servidor) — NÃO duplica account/sub.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * USO
 * ──────────────────────────────────────────────────────────────────────────
 *
 *   # 1. Rodar com senha aleatória (RECOMENDADO em produção):
 *   php scripts/seed_admin.php
 *   # Imprime a senha gerada UMA vez. Anote — não fica salva em lugar nenhum
 *   # legível depois (hash bcrypt no banco, plaintext só no stdout).
 *
 *   # 2. Forçar senha específica (útil em dev/teste):
 *   php scripts/seed_admin.php --password=minhasenha123
 *
 *   # 3. Customizar login/nome:
 *   php scripts/seed_admin.php --login=bruno --nome="Bruno Admin"
 *
 *   # 4. Customizar e-mail e nome da conta:
 *   php scripts/seed_admin.php --account-name="Yuris Producao" \
 *     --account-email=admin@yuris.app.br
 *
 * ──────────────────────────────────────────────────────────────────────────
 * HISTÓRICO
 * ──────────────────────────────────────────────────────────────────────────
 *
 * 2026-05-26 — Reescrito completo (auditoria pré-deploy AWS). Versão antiga
 *   inseria `users` sem account_id (NOT NULL pós-016) nem role (24) — quebrava
 *   na primeira tentativa de login em servidor com migrations 001-070 aplicadas.
 *
 * 2026-04-01 — Versão original (simples insert de users).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só roda via CLI (php scripts/seed_admin.php).\n");
}

require __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

// ─── Parse args ──────────────────────────────────────────────────────────
$opts = getopt('', [
    'login:',
    'password:',
    'nome:',
    'account-name:',
    'account-email:',
    'help',
]);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__);
    exit(0);
}

$login        = $opts['login']         ?? 'admin';
$nome         = $opts['nome']          ?? 'Administrador';
$accountName  = $opts['account-name']  ?? 'Conta Principal';
$accountEmail = $opts['account-email'] ?? null;

// Gera senha aleatória se não foi passada (16 chars, alfanumérico legível)
$senhaProvided = isset($opts['password']);
if ($senhaProvided) {
    $senha = $opts['password'];
} else {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem i/l/0/o
    $senha = '';
    for ($i = 0; $i < 16; $i++) {
        $senha .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}

$senhaHash = password_hash($senha, PASSWORD_BCRYPT);

// ─── Conecta ─────────────────────────────────────────────────────────────
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, "ERRO ao conectar no banco: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Verifique config/database.php e o .env.\n");
    exit(1);
}

$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ─── 1. Idempotência: user já existe? ────────────────────────────────────
$stmt = $pdo->prepare('SELECT id, account_id FROM users WHERE login = :login LIMIT 1');
$stmt->execute(['login' => $login]);
$existing = $stmt->fetch(\PDO::FETCH_ASSOC);

if ($existing) {
    // Já existe — só rotaciona senha + garante role/perfil/status corretos
    $stmt = $pdo->prepare("
        UPDATE users
        SET senha_hash = :hash,
            perfil     = 'admin',
            role       = 'super_admin',
            status     = 'active',
            deleted_at = NULL,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute(['hash' => $senhaHash, 'id' => $existing['id']]);

    echo "─────────────────────────────────────────────────────────\n";
    echo "Usuário '{$login}' (id={$existing['id']}) — senha rotacionada.\n";
    echo "Account ID: {$existing['account_id']}\n";
    echo "Login:      {$login}\n";
    if (!$senhaProvided) {
        echo "Senha NOVA: {$senha}\n";
        echo "(anote agora — senha não é salva em texto)\n";
    } else {
        echo "Senha:      (a que você passou via --password)\n";
    }
    echo "─────────────────────────────────────────────────────────\n";
    echo "IMPORTANTE: troque essa senha no primeiro login.\n";
    exit(0);
}

// ─── 2. Criar account + subscription + user em transação ────────────────
$pdo->beginTransaction();
try {
    // 2.1) Account raiz (matriz)
    $codigoVinculo = bin2hex(random_bytes(8)); // 16 hex chars, UNIQUE
    $stmt = $pdo->prepare("
        INSERT INTO accounts
            (nome, email, tipo, plano, status, codigo_vinculo, created_at, updated_at)
        VALUES
            (:nome, :email, 'matriz', 'basico', 'active', :cv, NOW(), NOW())
    ");
    $stmt->execute([
        'nome'  => $accountName,
        'email' => $accountEmail,
        'cv'    => $codigoVinculo,
    ]);
    $accountId = (int) $pdo->lastInsertId();

    // 2.2) Subscription (precisa de plan_id válido — usa o primeiro plano)
    $planRow = $pdo->query('SELECT id FROM plans ORDER BY id ASC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
    if (!$planRow) {
        throw new \RuntimeException(
            "Tabela 'plans' vazia. Importe database/seeds/seed_demo.sql ou rode " .
            "as migrations primeiro (043_seed_planos_teste_e_pago.sql)."
        );
    }
    $planId = (int) $planRow['id'];

    $stmt = $pdo->prepare("
        INSERT INTO subscriptions
            (account_id, plan_id, status, billing_cycle,
             current_period_start, current_period_end, created_at, updated_at)
        VALUES
            (:aid, :pid, 'active', 'monthly',
             NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())
    ");
    $stmt->execute(['aid' => $accountId, 'pid' => $planId]);

    // 2.3) User super_admin
    $userCodigoVinculo = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
    $stmt = $pdo->prepare("
        INSERT INTO users
            (account_id, nome, login, senha_hash, perfil, role, status,
             codigo_vinculo, created_at, updated_at)
        VALUES
            (:aid, :nome, :login, :hash, 'admin', 'super_admin', 'active',
             :cv, NOW(), NOW())
    ");
    $stmt->execute([
        'aid'   => $accountId,
        'nome'  => $nome,
        'login' => $login,
        'hash'  => $senhaHash,
        'cv'    => $userCodigoVinculo,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->commit();

    echo "═══════════════════════════════════════════════════════════════\n";
    echo " YURIS — BOOTSTRAP COMPLETO                                    \n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo " Account:       #{$accountId}  ({$accountName})\n";
    echo " Subscription:  plano id={$planId}, status=active, 30 dias\n";
    echo " User:          #{$userId}  ({$nome})\n";
    echo "                role=super_admin, perfil=admin\n";
    echo "                codigo_vinculo={$userCodigoVinculo}\n";
    echo "───────────────────────────────────────────────────────────────\n";
    echo " LOGIN\n";
    echo "───────────────────────────────────────────────────────────────\n";
    echo " URL:           https://<seu-dominio>/master_login.php\n";
    echo "                (super_admin entra pelo portal isolado)\n";
    echo " Login:         {$login}\n";
    if (!$senhaProvided) {
        echo " Senha:         {$senha}\n";
        echo "                ── anote AGORA, não fica salva em texto ──\n";
    } else {
        echo " Senha:         (a que você passou via --password)\n";
    }
    echo "═══════════════════════════════════════════════════════════════\n";
    echo " IMPORTANTE:\n";
    echo "   1. Troque a senha no primeiro login.\n";
    echo "   2. Habilite MFA (2FA) imediatamente em Configurações > Perfil.\n";
    echo "   3. Crie o segundo super_admin antes de remover este do CRM.\n";
    echo "═══════════════════════════════════════════════════════════════\n";

    exit(0);

} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRO ao criar bootstrap: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Transação revertida — nenhum dado foi gravado.\n");
    exit(1);
}
