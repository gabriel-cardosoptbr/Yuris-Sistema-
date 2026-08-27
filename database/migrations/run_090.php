<?php
// Runner standalone da migration 090 (create_clientes) — local + produção.
//
// Uso local:  php database/migrations/run_090.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_090.php
//
// O que faz:
//   1) Cria tabelas clientes_setores, clientes, clientes_history (idempotente).
//   2) Popula 8 setores padrão para cada account ativa que ainda não tem nenhum.
//   3) Concede permissão 'clientes' a todos os admins (não-admins continuam
//      governados pela tela de permissões — admin tem acesso total).
//   4) NÃO insere clientes mock. Tabela fica vazia.
//
// Seguro reexecutar: tudo é INSERT IGNORE / IF NOT EXISTS / count-then-insert.

require_once __DIR__ . '/../../app/Core/Database.php';

use App\Core\Database;

$pdo = Database::getConnection();
echo "== Migration 090: create_clientes ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

// ── 1) Cria tabelas ────────────────────────────────────────────
$sqlPath = __DIR__ . '/090_create_clientes.sql';
if (!is_readable($sqlPath)) {
    fwrite(STDERR, "ERRO: SQL não encontrado em $sqlPath\n");
    exit(1);
}
$sql = file_get_contents($sqlPath);
// 1) Strip line comments (-- até fim da linha). Mantém SQL puro.
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
// 2) Split por ; (statements). Sem `;` dentro de string literal no nosso SQL.
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== ''
);

foreach ($statements as $stmt) {
    // Pega 1ª linha útil pra log (pula linhas em branco)
    $head = '';
    foreach (explode("\n", $stmt) as $ln) {
        $ln = trim($ln);
        if ($ln !== '') { $head = mb_substr($ln, 0, 80); break; }
    }
    try {
        $pdo->exec($stmt);
        echo "  [ok] $head\n";
    } catch (Throwable $e) {
        // Re-lança só se NÃO for "table already exists" ou erro idempotente conhecido
        if (str_contains($e->getMessage(), 'already exists')
            || str_contains($e->getMessage(), 'Duplicate')) {
            echo "  [skip] $head — já aplicado\n";
        } else {
            echo "  [ERRO] $head\n  -> " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

// ── 2) Seed dos 8 setores padrão por account ───────────────────
echo "----\nSemeando setores padrão...\n";

$setoresDefault = [
    ['Atendimento Inicial',   'atendimento-inicial',   '#3b82f6', 1],
    ['Documentação',          'documentacao',          '#8b5cf6', 2],
    ['Jurídico',              'juridico',              '#6366f1', 3],
    ['Financeiro',            'financeiro',            '#0ea5e9', 4],
    ['Em Andamento',          'em-andamento',          '#f59e0b', 5],
    ['Aguardando Cliente',    'aguardando-cliente',    '#eab308', 6],
    ['Finalizado',            'finalizado',            '#22c55e', 7],
    ['Arquivado',             'arquivado',             '#94a3b8', 8],
];

// Pega todas as contas matriz ATIVAS (filiais herdam da matriz via pipeline).
// Aqui Clientes é por tenant, então cada matriz ganha seus setores.
$accounts = $pdo->query(
    "SELECT id, nome, tipo
       FROM accounts
      WHERE deleted_at IS NULL
        AND status IN ('active','trial','overdue')
        AND tipo IN ('matriz','advogado')
   ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$ins = $pdo->prepare(
    "INSERT INTO clientes_setores (account_id, nome, slug, cor, ordem, ativo)
     VALUES (?, ?, ?, ?, ?, 1)"
);

$totalCriados = 0;
$totalContas  = 0;
foreach ($accounts as $acc) {
    $existing = (int)$pdo->query(
        "SELECT COUNT(*) FROM clientes_setores WHERE account_id = " . (int)$acc['id']
    )->fetchColumn();

    if ($existing > 0) {
        echo "  [skip] conta #{$acc['id']} ({$acc['nome']}) — já tem $existing setores\n";
        continue;
    }
    foreach ($setoresDefault as [$nome, $slug, $cor, $ordem]) {
        $ins->execute([(int)$acc['id'], $nome, $slug, $cor, $ordem]);
        $totalCriados++;
    }
    $totalContas++;
    echo "  [ok] conta #{$acc['id']} ({$acc['nome']}) — 8 setores criados\n";
}
echo "  → $totalCriados setores em $totalContas contas.\n";

// ── 3) Concede permissão 'clientes' aos admins ─────────────────
echo "----\nConcedendo permissão 'clientes' aos admins...\n";

$admins = $pdo->query(
    "SELECT u.id, u.login, u.account_id
       FROM users u
      WHERE u.perfil = 'admin'
        AND u.deleted_at IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$insPerm = $pdo->prepare(
    "INSERT IGNORE INTO user_permissions (user_id, page, account_id) VALUES (?, 'clientes', ?)"
);
$nPerm = 0;
foreach ($admins as $u) {
    $insPerm->execute([(int)$u['id'], $u['account_id'] !== null ? (int)$u['account_id'] : null]);
    if ($insPerm->rowCount() > 0) $nPerm++;
}
echo "  → $nPerm admins receberam 'clientes' (de " . count($admins) . " admins totais).\n";

echo "== Migration 090 concluída ==\n";
