<?php
// Runner standalone da migration 094 (ENUM chat_mencoes.tipo + 'cliente').
//
// Uso local:  php database/migrations/run_094.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_094.php
//
// Auditoria 2026-06-01 (ALTA #1): a busca de menções (@) no chat interno passou
// a incluir a tabela `clientes`. O ENUM chat_mencoes.tipo precisa aceitar
// 'cliente' (antes: 'usuario','processo','card'), senão o INSERT da menção
// quebra com valor truncado.
//
// Idempotente: só altera se 'cliente' ainda não estiver no ENUM.

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
echo "== Migration 094: chat_mencoes.tipo + 'cliente' ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$st = $pdo->prepare(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_mencoes' AND COLUMN_NAME = 'tipo'"
);
$st->execute();
$colType = (string) $st->fetchColumn();

if ($colType === '') {
    echo "  [warn] coluna chat_mencoes.tipo não encontrada — nada a fazer\n";
} elseif (stripos($colType, "'cliente'") !== false) {
    echo "  [skip] 'cliente' já está no ENUM ($colType)\n";
} else {
    $pdo->exec(
        "ALTER TABLE chat_mencoes
            MODIFY COLUMN tipo ENUM('usuario','processo','card','cliente') NOT NULL"
    );
    echo "  [ok] 'cliente' adicionado ao ENUM chat_mencoes.tipo\n";
}

echo "== Migration 094 concluída ==\n";
