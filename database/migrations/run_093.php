<?php
// Runner standalone da migration 093 (vínculo Processo -> Clientes).
//
// Uso local:  php database/migrations/run_093.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_093.php
//
// Adiciona processos.cliente_id (FK soft -> clientes.id) + index, pra que um
// processo possa vincular um cliente cadastrado na aba Clientes (além do
// Lead da Prospecção, que continua via processos.card_id).
//
// Idempotente: cada ALTER é precedido de check em information_schema.

require_once __DIR__ . '/../../app/Models/Database.php';

use App\Models\Database;

$pdo = Database::getConnection();
echo "== Migration 093: vínculo Processo -> Clientes ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

/** Adiciona uma coluna se ainda não existir. */
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

/** Adiciona um index se ainda não existir. */
$addIdx = function (string $table, string $idx, string $ddl) use ($pdo) {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $st->execute([$table, $idx]);
    if ((int)$st->fetchColumn() > 0) {
        echo "  [skip] index $table.$idx já existe\n";
        return;
    }
    $pdo->exec($ddl);
    echo "  [ok] index $table.$idx criado\n";
};

echo "Tabela: processos\n";
$addCol('processos', 'cliente_id', "ALTER TABLE processos ADD COLUMN cliente_id INT NULL AFTER card_id");
$addIdx('processos', 'idx_processos_cliente', "ALTER TABLE processos ADD INDEX idx_processos_cliente (cliente_id)");

echo "== Migration 093 concluída ==\n";
