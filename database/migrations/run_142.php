<?php
/**
 * Migration 142: quem mandou cada mensagem e o que foi entregue ao agente.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do cliente Fleetiflow em 10/10/2026, olhando um lead que respondeu e
 * ficou três dias sem resposta: "o que aconteceu, por que está sem resposta?" e
 * "aqui não é a Vitória, é o robô". O Chat não sabia dizer nenhuma das duas.
 *
 *   whatsapp_msg_autor
 *     Quem mandou uma mensagem NOSSA quando o próprio Yuris sabe: 'chat' (uma
 *     pessoa pelo Chat do CRM, com user_id) ou 'celular' (digitada no aparelho
 *     do número, celular ou WhatsApp Web). O que o n8n manda pela API não passa
 *     por aqui; ver App\WhatsAppAgente\AutorDaMensagem.
 *
 *   sdr_encaminhamentos
 *     Cada entrega de mensagem do lead ao agente de pré-venda (a Vitória), com o
 *     resultado HTTP. 'webhook' é a entrega automática; 'manual' é o botão
 *     "Mandar para a Vitória" do Chat. É o que permite dizer "a Vitória recebeu
 *     e não respondeu" em vez de supor.
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_142.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_142.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function tabelaExiste(\PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int) $st->fetchColumn() > 0;
}

linha('Migration 142' . ($dryRun ? ' (dry-run)' : ''));

// Mesma collation de whatsapp_messages, para wamid e remote_jid compararem sem erro.
$tabelas = [
    'whatsapp_msg_autor' => "
        CREATE TABLE whatsapp_msg_autor (
          id           INT NOT NULL AUTO_INCREMENT,
          account_id   INT NOT NULL,
          instance_id  INT NOT NULL,
          wamid        VARCHAR(128) NOT NULL,
          autor        VARCHAR(20) NOT NULL,
          user_id      INT NULL,
          created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_msg_autor (instance_id, wamid),
          KEY idx_msg_autor_conta (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'sdr_encaminhamentos' => "
        CREATE TABLE sdr_encaminhamentos (
          id           INT NOT NULL AUTO_INCREMENT,
          account_id   INT NOT NULL,
          instance_id  INT NOT NULL,
          remote_jid   VARCHAR(120) NOT NULL,
          wamid        VARCHAR(128) NULL,
          origem       VARCHAR(10) NOT NULL DEFAULT 'webhook',
          http_status  SMALLINT NOT NULL DEFAULT 0,
          ok           TINYINT(1) NOT NULL DEFAULT 0,
          user_id      INT NULL,
          created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_sdr_enc_conversa (instance_id, remote_jid, created_at),
          KEY idx_sdr_enc_conta (account_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

foreach ($tabelas as $nome => $sql) {
    if (tabelaExiste($pdo, $nome)) { linha($nome . ': já existe'); continue; }
    linha($nome . ': criar');
    if (!$dryRun) {
        $pdo->exec($sql);
        linha($nome . ': criada');
    }
}

linha('ok');
