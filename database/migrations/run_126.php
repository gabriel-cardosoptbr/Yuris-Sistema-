<?php
/**
 * Migration 126 — liga PROSPECCAO e CLIENTES (conversao "Tornar cliente").
 *
 * O QUE FALTAVA
 *
 * As duas tabelas ja existiam e ja tinham cadastro quase identico, mas NADA as
 * ligava: `cards` nao sabia dizer em que cliente virou, e `clientes` nao sabia
 * de que prospeccao veio. Sem isso nao ha rastreabilidade ("como este cliente
 * entrou no sistema?") nem como impedir conversao em duplicidade.
 *
 * O QUE ESTA MIGRATION FAZ
 *
 *   1. cards:    cliente_id, convertido_em, convertido_por  (+ indice)
 *   2. clientes: card_origem_id, convertido_em, convertido_por (+ indice)
 *
 * O vinculo e nos DOIS sentidos de proposito:
 *   · cards.cliente_id     responde "em que cliente esta prospeccao virou?"
 *   · clientes.card_origem_id responde "qual foi a PRIMEIRA prospeccao deste
 *     cliente?" (a que o criou)
 *
 * Nao e redundancia. Uma pessoa pode voltar como prospeccao meses depois e ser
 * vinculada ao MESMO cliente: nesse caso varios cards apontam para um cliente,
 * e `card_origem_id` continua marcando so o que deu origem a ele. E dessa
 * relacao 1-para-N que a timeline do cliente se alimenta, sem copiar historico.
 *
 * `cards.status` ganha o valor 'convertida'. A coluna e VARCHAR(50), entao nao
 * ha mudanca de tipo: e so um valor novo.
 *
 * NAO APAGA NADA e NAO altera nenhuma linha existente: so acrescenta colunas
 * anulaveis. Card antigo continua com cliente_id NULL, que e o que ele e.
 *
 * IDEMPOTENTE: confere information_schema antes de cada ALTER.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_126.php
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_126.php
 *
 * Flags:
 *   --dry-run   diz o que faria, sem gravar nada. RODE ISSO PRIMEIRO EM PRODUCAO.
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void
{
    echo $s . PHP_EOL;
}

function temColuna(\PDO $pdo, string $tabela, string $coluna): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$tabela, $coluna]);
    return (bool) $st->fetchColumn();
}

function temIndice(\PDO $pdo, string $tabela, string $indice): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$tabela, $indice]);
    return (bool) $st->fetchColumn();
}

linha('== Migration 126: vinculo Prospeccao <-> Clientes ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$colunas = [
    ['cards', 'cliente_id',      "INT NULL COMMENT 'Cliente em que esta prospeccao foi convertida'"],
    ['cards', 'convertido_em',   "DATETIME NULL COMMENT 'Quando a conversao aconteceu'"],
    ['cards', 'convertido_por',  "INT NULL COMMENT 'users.id de quem converteu'"],
    ['clientes', 'card_origem_id',  "INT NULL COMMENT 'Prospeccao que ORIGINOU este cliente'"],
    ['clientes', 'convertido_em',   "DATETIME NULL COMMENT 'Quando o cliente nasceu da conversao'"],
    ['clientes', 'convertido_por',  "INT NULL COMMENT 'users.id de quem converteu'"],
];

$indices = [
    ['cards',    'idx_cards_cliente',        'cliente_id'],
    ['clientes', 'idx_clientes_card_origem', 'card_origem_id'],
];

$feitas = 0;
$puladas = 0;

foreach ($colunas as [$tabela, $coluna, $def]) {
    if (temColuna($pdo, $tabela, $coluna)) {
        linha("  [ja existe] $tabela.$coluna");
        $puladas++;
        continue;
    }
    $sql = "ALTER TABLE `$tabela` ADD COLUMN `$coluna` $def";
    if ($dryRun) {
        linha("  [faria]     $sql");
    } else {
        $pdo->exec($sql);
        linha("  [criada]    $tabela.$coluna");
    }
    $feitas++;
}

foreach ($indices as [$tabela, $indice, $coluna]) {
    if (temIndice($pdo, $tabela, $indice)) {
        linha("  [ja existe] indice $tabela.$indice");
        $puladas++;
        continue;
    }
    // Em dry-run a coluna pode nao existir ainda; o indice viria depois dela.
    if ($dryRun && !temColuna($pdo, $tabela, $coluna)) {
        linha("  [faria]     ALTER TABLE `$tabela` ADD INDEX `$indice` (`$coluna`)  (depois da coluna)");
        $feitas++;
        continue;
    }
    $sql = "ALTER TABLE `$tabela` ADD INDEX `$indice` (`$coluna`)";
    if ($dryRun) {
        linha("  [faria]     $sql");
    } else {
        $pdo->exec($sql);
        linha("  [criado]    indice $tabela.$indice");
    }
    $feitas++;
}

linha();

/* ---------------------------------------------------------------------------
 * Diagnostico: quantas linhas existem, e quantas ja estariam convertidas.
 * Serve para o dry-run em producao dizer o tamanho do que sera tocado.
 * ------------------------------------------------------------------------- */
$cards    = (int) $pdo->query('SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL')->fetchColumn();
$clientes = (int) $pdo->query('SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL')->fetchColumn();
linha("  prospeccoes ativas: $cards");
linha("  clientes ativos:    $clientes");

if (!$dryRun && temColuna($pdo, 'cards', 'cliente_id')) {
    $conv = (int) $pdo->query('SELECT COUNT(*) FROM cards WHERE cliente_id IS NOT NULL')->fetchColumn();
    linha("  prospeccoes ja convertidas: $conv  (esperado 0 na primeira execucao)");
}

linha();
linha("  alteracoes: $feitas   ja existentes: $puladas");
linha($dryRun ? '  DRY-RUN concluido. Rode sem --dry-run para aplicar.' : '  Migration 126 aplicada.');
