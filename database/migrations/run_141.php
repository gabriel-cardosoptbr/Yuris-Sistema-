<?php
/**
 * Migration 141: agenda da próxima interação com o lead.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do cliente Fleetiflow em 10/10/2026: na gestão do lead, agendar a
 * próxima interação (ligação, reunião, mensagem, tarefa) com data e hora, para
 * cair na agenda, avisar na hora e não perder o SLA. Se for mensagem, deixar a
 * mensagem de WhatsApp programada para sair sozinha.
 *
 * A data e a hora NÃO moram aqui: moram em `tasks.prazo`, a tarefa que o
 * agendamento cria no quadro. Assim, quem arrasta a data na agenda move também
 * o lembrete e o envio da mensagem. Esta tabela guarda só o que a tarefa não tem:
 * o lead, o tipo, o lembrete e a mensagem programada.
 *
 *   crm_agendamentos
 *     account_id, card_id, task_id, criado_por_id
 *     tipo                   ligacao | reuniao | mensagem | tarefa
 *     lembrete_min           minutos antes da hora em que o responsável é avisado
 *     lembrete_enviado_para  o prazo para o qual o aviso já saiu (prazo mudou =
 *                            avisa de novo)
 *     mensagem, canal_id, remote_jid, envio_*   a mensagem programada
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_141.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_141.php --dry-run
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

linha('Migration 141' . ($dryRun ? ' (dry-run)' : ''));

if (tabelaExiste($pdo, 'crm_agendamentos')) {
    linha('crm_agendamentos: já existe');
} else {
    linha('crm_agendamentos: criar');
    if (!$dryRun) {
        $pdo->exec("
            CREATE TABLE crm_agendamentos (
              id                     INT NOT NULL AUTO_INCREMENT,
              account_id             INT NOT NULL,
              card_id                INT NOT NULL,
              task_id                INT NOT NULL,
              criado_por_id          INT NULL,
              tipo                   ENUM('ligacao','reuniao','mensagem','tarefa') NOT NULL DEFAULT 'tarefa',
              lembrete_min           SMALLINT NOT NULL DEFAULT 15,
              lembrete_enviado_para  DATETIME NULL,
              mensagem               TEXT NULL,
              canal_id               INT NULL,
              remote_jid             VARCHAR(120) NULL,
              envio_status           ENUM('nao_se_aplica','pendente','enviando','enviada','falhou','cancelada') NOT NULL DEFAULT 'nao_se_aplica',
              envio_tentativas       TINYINT NOT NULL DEFAULT 0,
              envio_erro             VARCHAR(255) NULL,
              enviada_em             DATETIME NULL,
              wamid                  VARCHAR(120) NULL,
              created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_agenda_task (task_id),
              KEY idx_agenda_card (account_id, card_id),
              KEY idx_agenda_envio (envio_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        linha('crm_agendamentos: criada');
    }
}

linha('ok');
