<?php
// Runner standalone da migration 092 (clientes_origens editáveis).
//
// Uso local: php database/migrations/run_092.php
// Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_092.php
//
// 1) Cria tabela clientes_origens (id, account_id, nome, slug, ordem, ativo).
// 2) Altera clientes.origem ENUM → VARCHAR(80) preservando os valores existentes.
// 3) Semeia 10 origens padrão por conta matriz/advogado ativa que ainda não tem.
//
// Idempotente: tudo via information_schema check. Re-rodar é seguro.

require_once __DIR__ . '/../../app/Core/Database.php';

use App\Core\Database;

$pdo = Database::getConnection();
echo "== Migration 092: clientes_origens editáveis ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

// ── 1) Tabela clientes_origens ──────────────────────────────────────
$exists = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes_origens'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec("
        CREATE TABLE clientes_origens (
          id          INT           AUTO_INCREMENT PRIMARY KEY,
          account_id  INT           NOT NULL,
          nome        VARCHAR(80)   NOT NULL,
          slug        VARCHAR(80)   NOT NULL,
          ordem       INT           DEFAULT 0,
          ativo       TINYINT(1)    DEFAULT 1,
          created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
          updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_co_account (account_id),
          KEY idx_co_account_ordem (account_id, ordem),
          UNIQUE KEY uk_co_account_slug (account_id, slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  [ok] tabela clientes_origens criada\n";
} else {
    echo "  [skip] tabela clientes_origens já existe\n";
}

// ── 2) ALTER clientes.origem ENUM → VARCHAR(80) ─────────────────────
$col = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'origem'"
)->fetchColumn();

if (stripos((string)$col, 'enum') === 0) {
    $pdo->exec("ALTER TABLE clientes MODIFY COLUMN origem VARCHAR(80) NULL");
    echo "  [ok] clientes.origem: ENUM → VARCHAR(80)\n";
} else {
    echo "  [skip] clientes.origem já é " . $col . "\n";
}

// ── 3) Seed das origens padrão por conta ────────────────────────────
$defaults = [
    ['Cadastro manual',  'cadastro_manual',  1],
    ['Cliente antigo',   'cliente_antigo',   2],
    ['Cliente ativo',    'cliente_ativo',    3],
    ['Indicação',        'indicacao',        4],
    ['Anúncio',          'anuncio',          5],
    ['Site',             'site',             6],
    ['Redes sociais',    'redes_sociais',    7],
    ['Evento / Palestra','evento_palestra',  8],
    ['Captação ativa',   'captacao_ativa',   9],
    ['Outro',            'outro',           10],
];

$accounts = $pdo->query(
    "SELECT id, nome FROM accounts
      WHERE deleted_at IS NULL
        AND status IN ('active','trial','overdue')
        AND tipo IN ('matriz','advogado')
   ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$ins = $pdo->prepare(
    "INSERT INTO clientes_origens (account_id, nome, slug, ordem, ativo)
     VALUES (?, ?, ?, ?, 1)"
);

$totalSemeadas = 0; $totalContas = 0;
foreach ($accounts as $acc) {
    $aid = (int)$acc['id'];
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM clientes_origens WHERE account_id = $aid")->fetchColumn();
    if ($existing > 0) {
        echo "  [skip] conta #$aid ({$acc['nome']}) — já tem $existing origens\n";
        continue;
    }
    foreach ($defaults as [$nome, $slug, $ordem]) {
        $ins->execute([$aid, $nome, $slug, $ordem]);
        $totalSemeadas++;
    }
    $totalContas++;
    echo "  [ok] conta #$aid ({$acc['nome']}) — 10 origens padrão criadas\n";
}
echo "  → $totalSemeadas origens em $totalContas contas.\n";

echo "== Migration 092 concluída ==\n";
