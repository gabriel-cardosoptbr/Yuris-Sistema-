<?php
// Runner standalone da migration 095 (recorrencia custom -> coluna `unidade`).
//
// Uso local:  php database/migrations/run_095.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_095.php
//
// Adiciona task_recurrences.unidade (VARCHAR(10), default 'day'), pra que uma
// recorrencia 'custom' respeite a unidade escolhida (dias/semanas/meses/anos).
// Sem essa coluna o calculo da proxima data caia sempre em 'day'.
//
// Idempotente: o ALTER e precedido de check em information_schema.

require_once __DIR__ . '/../../app/Core/Database.php';

use App\Core\Database;

$pdo = Database::getConnection();
echo "== Migration 095: task_recurrences.unidade ==\n";
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

echo "Tabela: task_recurrences\n";
$addCol(
    'task_recurrences',
    'unidade',
    "ALTER TABLE task_recurrences
       ADD COLUMN unidade VARCHAR(10) NOT NULL DEFAULT 'day'
       COMMENT 'Unidade da recorrencia custom: day|week|month|year (usado por calcularProximaData)'
       AFTER intervalo"
);

echo "== Migration 095 concluída ==\n";
