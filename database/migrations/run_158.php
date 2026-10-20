<?php
/**
 * Migration 158: o vendedor responsável pelo contrato.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 20/10/2026: a aba Contratos mostra qual vendedor
 * fechou o contrato. É dado do CRM, não do documento assinado (o PDF e o hash
 * não mudam).
 *
 *   crm_contratos.vendedor_id   users.id do vendedor (NULL = não informado).
 *                               Diferente de responsavel_id, que é quem cuida
 *                               do contrato dentro da conta (pode cancelar,
 *                               reprocessar etc.).
 *
 * Preenche os contratos que já existem com o responsável do lead ligado
 * (cards.responsavel_user_id) ou, sem lead, com o responsável do cliente
 * (clientes.responsavel_id). Só grava onde vendedor_id está vazio: não muda
 * dado que já existe. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_158.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_158.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 158' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
$st->execute(['crm_contratos', 'vendedor_id']);
$existe = (int) $st->fetchColumn() > 0;
if ($existe) {
    echo "  = crm_contratos.vendedor_id já existe\n";
} else {
    echo "  + crm_contratos.vendedor_id\n";
    if (!$dryRun) {
        $pdo->exec("ALTER TABLE crm_contratos ADD COLUMN vendedor_id INT NULL COMMENT 'users.id do vendedor que fechou (dado do CRM, fora do documento)' AFTER responsavel_id,
                    ADD KEY idx_contrato_vendedor (account_id, vendedor_id)");
    }
}

// Preenchimento: lead primeiro, depois cliente. Sempre na mesma conta.
$sqlCard = "FROM crm_contratos c
              JOIN cards cd ON cd.id = c.card_id AND cd.account_id = c.account_id
              JOIN users u ON u.id = cd.responsavel_user_id AND u.account_id = c.account_id
             WHERE c.vendedor_id IS NULL AND cd.responsavel_user_id IS NOT NULL";
$sqlCli = "FROM crm_contratos c
             JOIN clientes cl ON cl.id = c.cliente_id AND cl.account_id = c.account_id
             JOIN users u ON u.id = cl.responsavel_id AND u.account_id = c.account_id
            WHERE c.vendedor_id IS NULL AND cl.responsavel_id IS NOT NULL";

if (!$existe && $dryRun) {
    // Coluna ainda não existe: conta pelo que seria preenchido.
    $nCard = (int) $pdo->query("SELECT COUNT(*) FROM crm_contratos c
                                  JOIN cards cd ON cd.id = c.card_id AND cd.account_id = c.account_id
                                  JOIN users u ON u.id = cd.responsavel_user_id AND u.account_id = c.account_id
                                 WHERE cd.responsavel_user_id IS NOT NULL")->fetchColumn();
    $nCli = (int) $pdo->query("SELECT COUNT(*) FROM crm_contratos c
                                 JOIN clientes cl ON cl.id = c.cliente_id AND cl.account_id = c.account_id
                                 JOIN users u ON u.id = cl.responsavel_id AND u.account_id = c.account_id
                                 LEFT JOIN cards cd ON cd.id = c.card_id AND cd.account_id = c.account_id
                                WHERE cl.responsavel_id IS NOT NULL AND (cd.id IS NULL OR cd.responsavel_user_id IS NULL)")->fetchColumn();
    echo "  ~ $nCard contrato(s) pegariam o responsável do lead, $nCli o do cliente\n";
} else {
    $nCard = (int) $pdo->query("SELECT COUNT(*) $sqlCard")->fetchColumn();
    echo "  ~ $nCard contrato(s) com o responsável do lead\n";
    if (!$dryRun && $nCard) $pdo->exec("UPDATE crm_contratos c
                                          JOIN cards cd ON cd.id = c.card_id AND cd.account_id = c.account_id
                                          JOIN users u ON u.id = cd.responsavel_user_id AND u.account_id = c.account_id
                                           SET c.vendedor_id = cd.responsavel_user_id
                                         WHERE c.vendedor_id IS NULL AND cd.responsavel_user_id IS NOT NULL");
    $nCli = (int) $pdo->query("SELECT COUNT(*) $sqlCli")->fetchColumn();
    echo "  ~ $nCli contrato(s) com o responsável do cliente" . ($dryRun ? ' (antes do passo do lead)' : '') . "\n";
    if (!$dryRun && $nCli) $pdo->exec("UPDATE crm_contratos c
                                         JOIN clientes cl ON cl.id = c.cliente_id AND cl.account_id = c.account_id
                                         JOIN users u ON u.id = cl.responsavel_id AND u.account_id = c.account_id
                                          SET c.vendedor_id = cl.responsavel_id
                                        WHERE c.vendedor_id IS NULL AND cl.responsavel_id IS NOT NULL");
}

$semVinculo = (int) $pdo->query('SELECT COUNT(*) FROM crm_contratos WHERE cliente_id IS NULL AND card_id IS NULL')->fetchColumn();
echo "  i $semVinculo contrato(s) sem lead nem cliente (a tela pede o vínculo)\n";

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
