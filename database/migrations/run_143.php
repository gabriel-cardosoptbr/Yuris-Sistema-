<?php
/**
 * Migration 143: orçamento no CRM (edição Fleetiflow).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 13/10/2026: "coloque isso de orçamento no CRM
 * também". É o mesmo orçamento da plataforma Fleetiflow (13/10/2026): serviços
 * e débitos do veículo (IPVA, multas...) num orçamento só, o que o CLIENTE lê
 * (Detran R$ 295,91 + Honorário R$ 200,00) separado do controle do ESCRITÓRIO
 * (Detran repasse imediato, SERPRO repasse mensal, honorário R$ 191,39).
 *
 *   crm_servicos             o catálogo de serviços da conta, com preço de tabela
 *   crm_servico_componentes  as taxas de cada serviço: REPASSE (prazo imediato
 *                            ou mensal, cliente vê ou não) e CUSTO
 *   crm_orcamentos           o orçamento: lead e/ou cliente, placa, situação
 *   crm_orcamento_itens      cada linha: SERVICO (do catálogo ou livre) ou
 *                            DEBITO do veículo (repasse integral)
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_143.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_143.php --dry-run
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

linha('Migration 143' . ($dryRun ? ' (dry-run)' : ''));

$tabelas = [
    'crm_servicos' => "
        CREATE TABLE crm_servicos (
          id           INT NOT NULL AUTO_INCREMENT,
          account_id   INT NOT NULL,
          nome         VARCHAR(120) NOT NULL,
          valor_padrao DECIMAL(10,2) NULL,
          ativo        TINYINT(1) NOT NULL DEFAULT 1,
          ordem        INT NOT NULL DEFAULT 0,
          created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_crm_servico_nome (account_id, nome),
          KEY idx_crm_servico_conta (account_id, ativo, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'crm_servico_componentes' => "
        CREATE TABLE crm_servico_componentes (
          id              INT NOT NULL AUTO_INCREMENT,
          account_id      INT NOT NULL,
          servico_id      INT NOT NULL,
          categoria       ENUM('REPASSE','CUSTO') NOT NULL DEFAULT 'REPASSE',
          descricao       VARCHAR(120) NOT NULL,
          valor           DECIMAL(10,2) NOT NULL,
          prazo           ENUM('IMEDIATO','MENSAL') NOT NULL DEFAULT 'IMEDIATO',
          visivel_cliente TINYINT(1) NOT NULL DEFAULT 1,
          ordem           INT NOT NULL DEFAULT 0,
          created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_crm_componente_servico (account_id, servico_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'crm_orcamentos' => "
        CREATE TABLE crm_orcamentos (
          id                INT NOT NULL AUTO_INCREMENT,
          account_id        INT NOT NULL,
          numero            INT NOT NULL,
          card_id           INT NULL,
          cliente_id        INT NULL,
          cliente_nome      VARCHAR(190) NOT NULL,
          cliente_documento VARCHAR(30) NULL,
          cliente_telefone  VARCHAR(50) NULL,
          placa             VARCHAR(12) NULL,
          veiculo           VARCHAR(120) NULL,
          vendedor_id       INT NULL,
          canal             VARCHAR(60) NULL,
          observacoes       TEXT NULL,
          status            ENUM('RASCUNHO','APROVADO','REJEITADO','CONVERTIDO') NOT NULL DEFAULT 'RASCUNHO',
          aprovado_em       DATETIME NULL,
          convertido_em     DATETIME NULL,
          created_by        INT NULL,
          created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_crm_orcamento_numero (account_id, numero),
          KEY idx_crm_orcamento_status (account_id, status, created_at),
          KEY idx_crm_orcamento_card (account_id, card_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'crm_orcamento_itens' => "
        CREATE TABLE crm_orcamento_itens (
          id             INT NOT NULL AUTO_INCREMENT,
          account_id     INT NOT NULL,
          orcamento_id   INT NOT NULL,
          natureza       ENUM('SERVICO','DEBITO') NOT NULL DEFAULT 'SERVICO',
          servico_id     INT NULL,
          descricao      VARCHAR(200) NOT NULL,
          quantidade     DECIMAL(10,2) NOT NULL DEFAULT 1,
          valor_unitario DECIMAL(10,2) NOT NULL,
          desconto       DECIMAL(10,2) NOT NULL DEFAULT 0,
          ordem          INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_crm_orcamento_item (account_id, orcamento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tabelas as $nome => $sql) {
    if (tabelaExiste($pdo, $nome)) { linha("$nome: já existe"); continue; }
    linha("$nome: criar");
    if (!$dryRun) { $pdo->exec($sql); linha("$nome: criada"); }
}

linha('ok');
