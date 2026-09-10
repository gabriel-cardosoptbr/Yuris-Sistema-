<?php
/**
 * Migration 128 — data de nascimento em cliente e prospecção.
 *
 * ---------------------------------------------------------------------------
 * DE ONDE VEIO
 * ---------------------------------------------------------------------------
 * Pedido de campo: "sugerir que conste data de nascimento no cadastro do cliente
 * com possibilidade de relatório mensal para dar parabéns. Uma forma de
 * contactar o cliente novamente."
 *
 * O escritório não quer a data para calcular idade: quer um MOTIVO de voltar a
 * falar com quem já é cliente. É retenção, não cadastro.
 *
 * ---------------------------------------------------------------------------
 * NOS DOIS LADOS, PELO MESMO MOTIVO DE SEMPRE
 * ---------------------------------------------------------------------------
 * `clientes` E `cards`. Perguntar a data só depois que a pessoa vira cliente é
 * perder a chance de perguntar quando ela estava mais disposta a responder, no
 * meio da conversa comercial. E, estando nos dois lados, a data atravessa a
 * conversão como qualquer outro campo do cadastro.
 *
 * ---------------------------------------------------------------------------
 * POR QUE NÃO TEM ÍNDICE
 * ---------------------------------------------------------------------------
 * O relatório pergunta "quem faz aniversário neste mês", que é
 * `MONTH(data_nascimento) = ?`. Índice comum em `data_nascimento` não serve para
 * isso: a função sobre a coluna impede o uso do índice, e um índice que não é
 * usado só custa escrita.
 *
 * Índice funcional resolveria, mas o ganho seria invisível: a maior conta deste
 * banco tem dezenas de clientes, não milhões. Se um dia um escritório passar de
 * dezenas de milhares, o lugar de resolver é aqui, com medida antes.
 *
 * ---------------------------------------------------------------------------
 * DATE, NÃO DATETIME
 * ---------------------------------------------------------------------------
 * Data de nascimento não tem hora. `DATETIME` convidaria a gravar 00:00:00 e a
 * comparar com fuso, que é onde nasce o clássico bug de aniversário caindo um
 * dia antes.
 *
 * NÃO APAGA E NÃO ALTERA NENHUMA LINHA. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_128.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_128.php --dry-run
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

linha('== Migration 128: data de nascimento (cliente e prospeccao) ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$colunas = [
    ['clientes', 'data_nascimento', "DATE NULL COMMENT 'Aniversario: alimenta o relatorio mensal de parabens'"],
    ['cards',    'data_nascimento', "DATE NULL COMMENT 'Aniversario do lead; acompanha a conversao em cliente'"],
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

linha();

$clientes = (int) $pdo->query('SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL')->fetchColumn();
$cards    = (int) $pdo->query('SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL')->fetchColumn();
linha("  clientes ativos:    $clientes  (todos nascem sem data, e o esperado)");
linha("  prospeccoes ativas: $cards");

linha();
linha("  alteracoes: $feitas   ja existentes: $puladas");
linha($dryRun ? '  DRY-RUN concluido. Rode sem --dry-run para aplicar.' : '  Migration 128 aplicada.');
