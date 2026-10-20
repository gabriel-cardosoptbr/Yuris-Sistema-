<?php
/**
 * Migration 160: Google Drive ligado a cada cliente (ambientes da Inovaize).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize em 20/10/2026 (ver app/Drive/README.md). Os arquivos ficam
 * no Drive; aqui ficam só as referências e as associações. Só tabelas novas.
 *
 *   drive_contas          a conta Google autorizada de cada conta do sistema:
 *                         e-mail, refresh token CIFRADO (App\Core\Crypto), o
 *                         marcador da API de mudanças e o estado da última
 *                         sincronização
 *   drive_pastas_cliente  a pasta do Drive de cada cliente (o identificador)
 *   drive_itens           a última listagem conhecida de cada pasta do cliente,
 *                         com a hora em que foi lida: é o que permite mostrar
 *                         "sincronizado às 14:32" e nunca vender lista velha
 *                         como atual
 *   task_drive_arquivos   arquivo do Drive vinculado a um cartão
 *
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_160.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_160.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 160' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'drive_contas' => "CREATE TABLE drive_contas (
        account_id      INT NOT NULL PRIMARY KEY,
        email           VARCHAR(191) NOT NULL,
        refresh_token   TEXT NOT NULL COMMENT 'cifrado (App\\\\Core\\\\Crypto)',
        access_token    TEXT NULL COMMENT 'cifrado',
        expira_em       DATETIME NULL,
        page_token      VARCHAR(255) NULL COMMENT 'marcador da API de mudanças',
        conectado_por   INT NULL,
        conectado_em    DATETIME NOT NULL,
        ultima_sync     DATETIME NULL,
        ultimo_erro     VARCHAR(500) NULL,
        erro_em         DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'drive_pastas_cliente' => "CREATE TABLE drive_pastas_cliente (
        cliente_id     INT NOT NULL PRIMARY KEY,
        account_id     INT NOT NULL,
        folder_id      VARCHAR(128) NOT NULL,
        folder_nome    VARCHAR(255) NOT NULL,
        folder_link    VARCHAR(500) NULL,
        vinculado_por  INT NULL,
        vinculado_em   DATETIME NOT NULL,
        precisa_sync   TINYINT(1) NOT NULL DEFAULT 1,
        ultima_sync    DATETIME NULL,
        ultimo_erro    VARCHAR(500) NULL,
        erro_em        DATETIME NULL,
        KEY idx_dpc_conta (account_id),
        KEY idx_dpc_pasta (account_id, folder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'drive_itens' => "CREATE TABLE drive_itens (
        id              BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id      INT NOT NULL,
        cliente_id      INT NOT NULL,
        file_id         VARCHAR(128) NOT NULL,
        parent_id       VARCHAR(128) NOT NULL,
        nome            VARCHAR(255) NOT NULL,
        mime            VARCHAR(128) NOT NULL,
        modificado_em   DATETIME NULL,
        link            VARCHAR(500) NULL,
        tamanho         BIGINT NULL,
        sincronizado_em DATETIME NOT NULL,
        UNIQUE KEY uq_drive_item (account_id, cliente_id, file_id),
        KEY idx_drive_pai (account_id, cliente_id, parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'drive_pastas_lidas' => "CREATE TABLE drive_pastas_lidas (
        account_id   INT NOT NULL,
        cliente_id   INT NOT NULL,
        folder_id    VARCHAR(128) NOT NULL,
        lida_em      DATETIME NULL,
        erro         VARCHAR(500) NULL,
        erro_em      DATETIME NULL,
        PRIMARY KEY (account_id, cliente_id, folder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'task_drive_arquivos' => "CREATE TABLE task_drive_arquivos (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        account_id     INT NOT NULL,
        task_id        INT NOT NULL,
        cliente_id     INT NULL,
        file_id        VARCHAR(128) NOT NULL,
        nome           VARCHAR(255) NOT NULL,
        mime           VARCHAR(128) NULL,
        link           VARCHAR(500) NULL,
        vinculado_por  INT NULL,
        vinculado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_task_drive (task_id, file_id),
        KEY idx_tda_conta (account_id, cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach ($tabelas as $nome => $sql) {
    $st->execute([$nome]);
    if ((int) $st->fetchColumn() > 0) { echo "  = $nome já existe\n"; continue; }
    echo "  + $nome\n";
    if (!$dryRun) $pdo->exec($sql);
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
