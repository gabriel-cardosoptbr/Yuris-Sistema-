<?php
/**
 * Migration 153: cobranças dos clientes (contas a receber) e serviço avulso.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 20/10/2026: a mensalidade do cliente liga ao
 * financeiro (vira cobrança todo mês, a partir de quando começa), o cliente
 * pode ter lançamento de serviço avulso ("fez um site") e cada valor pode
 * apontar para o contrato. Regra em app/Clientes/CobrancaDoCliente.php.
 *
 *   crm_cliente_valores    + tipo 'servico' (serviço avulso, cobrança única)
 *                          + contrato_id (o contrato do valor, opcional)
 *   crm_cliente_cobrancas  cada cobrança: competência, vencimento, valor,
 *                          aberta, paga (quando, quanto, forma) ou cancelada.
 *                          (valor_id, competencia) único: gerar de novo não duplica.
 *
 * Só acrescenta (um valor novo no ENUM e uma coluna NULL; tabela nova).
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_153.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_153.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 153' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$col = function (string $t, string $c) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    $v = $st->fetchColumn();
    return $v === false ? null : ['tipo' => (string) $v];
};

$tipo = $col('crm_cliente_valores', 'tipo');
if ($tipo === null) { echo "  ! crm_cliente_valores não existe: rode a migration 152 antes\n"; exit(1); }
if (!str_contains($tipo['tipo'], "'servico'")) {
    echo "  + crm_cliente_valores.tipo ganha 'servico'\n";
    if (!$dryRun) $pdo->exec("ALTER TABLE crm_cliente_valores MODIFY tipo ENUM('mensal','anual','fixo','servico','demanda') NOT NULL DEFAULT 'mensal'");
} else echo "  = crm_cliente_valores.tipo já tem 'servico'\n";

if ($col('crm_cliente_valores', 'contrato_id') === null) {
    echo "  + crm_cliente_valores.contrato_id\n";
    if (!$dryRun) $pdo->exec('ALTER TABLE crm_cliente_valores ADD COLUMN contrato_id INT NULL AFTER cliente_id');
} else echo "  = crm_cliente_valores.contrato_id já existe\n";

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$st->execute(['crm_cliente_cobrancas']);
if ((int) $st->fetchColumn() > 0) {
    echo "  = crm_cliente_cobrancas já existe\n";
} else {
    echo "  + crm_cliente_cobrancas\n";
    if (!$dryRun) {
        $pdo->exec("
            CREATE TABLE crm_cliente_cobrancas (
              id              INT NOT NULL AUTO_INCREMENT,
              account_id      INT NOT NULL,
              cliente_id      INT NOT NULL,
              valor_id        INT NULL,
              contrato_id     INT NULL,
              tipo            ENUM('mensal','anual','fixo','servico') NOT NULL,
              descricao       VARCHAR(200) NOT NULL,
              competencia     DATE NOT NULL COMMENT 'mês (dia 1) ou ano a que a cobrança se refere',
              vencimento      DATE NOT NULL,
              valor           DECIMAL(12,2) NOT NULL,
              status          ENUM('aberta','paga','cancelada') NOT NULL DEFAULT 'aberta',
              pago_em         DATE NULL,
              valor_pago      DECIMAL(12,2) NULL,
              forma_pagamento VARCHAR(40) NULL,
              observacao      VARCHAR(300) NULL,
              baixado_por     INT NULL,
              created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_cobranca_valor_comp (valor_id, competencia),
              KEY idx_cobranca_conta (account_id, status, vencimento),
              KEY idx_cobranca_cliente (account_id, cliente_id, vencimento)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
echo ($dryRun ? 'Nada gravado (dry-run).' : 'Pronto.') . PHP_EOL;
