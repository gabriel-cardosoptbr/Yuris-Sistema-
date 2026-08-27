<?php
// Runner standalone da migration 091 (dados_pessoais + endereço estruturado).
//
// Uso local:  php database/migrations/run_091.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_091.php
//
// Adiciona, em CLIENTES e CARDS (Prospecção), colunas pra:
//   - Dados PF: rg, nome_mae
//   - Endereço estruturado: cep, logradouro, numero, complemento, bairro, cidade, uf
//   - Em CARDS apenas: cpf_cnpj (clientes já tinha)
//
// Idempotente: cada ADD COLUMN é precedido de check em information_schema.

require_once __DIR__ . '/../../app/Core/Database.php';

use App\Core\Database;

$pdo = Database::getConnection();
echo "== Migration 091: dados_pessoais + endereço ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

/** Adiciona uma coluna em $table se ainda não existir. */
$addCol = function (string $table, string $col, string $ddl) use ($pdo) {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $col]);
    if ((int)$st->fetchColumn() > 0) {
        echo "  [skip] $table.$col já existe\n";
        return;
    }
    $pdo->exec($ddl);
    echo "  [ok] $table.$col adicionada\n";
};

// ── Definição dos campos novos ─────────────────────────────────────
// (col, ddl_para_clientes, ddl_para_cards)
$dadosPessoais = [
    ['rg',          "VARCHAR(30)  NULL"],
    ['nome_mae',    "VARCHAR(190) NULL"],
];
$endereco = [
    ['cep',         "VARCHAR(10)  NULL"],
    ['logradouro',  "VARCHAR(190) NULL"],
    ['numero',      "VARCHAR(20)  NULL"],
    ['complemento', "VARCHAR(100) NULL"],
    ['bairro',      "VARCHAR(100) NULL"],
    ['cidade',      "VARCHAR(100) NULL"],
    ['uf',          "CHAR(2)      NULL"],
];

// ── CLIENTES ────────────────────────────────────────────────────────
echo "Tabela: clientes\n";
foreach (array_merge($dadosPessoais, $endereco) as [$col, $type]) {
    $addCol('clientes', $col, "ALTER TABLE clientes ADD COLUMN $col $type");
}

// ── CARDS (Prospecção) ─────────────────────────────────────────────
echo "----\nTabela: cards\n";
// cpf_cnpj é só pra cards (clientes já tem)
$addCol('cards', 'cpf_cnpj', "ALTER TABLE cards ADD COLUMN cpf_cnpj VARCHAR(20) NULL");
foreach (array_merge($dadosPessoais, $endereco) as [$col, $type]) {
    $addCol('cards', $col, "ALTER TABLE cards ADD COLUMN $col $type");
}

echo "== Migration 091 concluída ==\n";
