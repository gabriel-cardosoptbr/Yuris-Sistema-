<?php
/**
 * Migration 134: cards.tipo_lead e cards.temperatura.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * O card de lead da edição CRM comercial passa a ser o do Fleetiflow: no alto,
 * à esquerda, o termômetro (frio, morno, quente); à direita, o TIPO do lead
 * (concessionária, despachante, loja multimarcas, gestão de frota...).
 *
 *  - `tipo_lead`: texto curto, escolhido pelo consultor. Lista sugerida na tela,
 *    mas livre: cada empresa classifica como quiser.
 *  - `temperatura`: 'frio' | 'morno' | 'quente', escolhida pelo consultor. NULL
 *    = automática (a conta por valor, checklist e prazo, como sempre foi).
 *
 * As duas colunas são opcionais e começam NULL: card existente não muda, e a
 * edição jurídica (Yuris) nem as mostra.
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_134.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_134.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function temColuna(\PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (bool) $st->fetchColumn();
}

linha('== Migration 134: cards.tipo_lead e cards.temperatura ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

if (!temColuna($pdo, 'cards', 'id')) {
    linha('  [ERRO] tabela cards nao existe.');
    exit(1);
}

$colunas = [
    'tipo_lead'   => "ADD COLUMN `tipo_lead` VARCHAR(60) NULL COMMENT 'classificacao do lead na edicao CRM (concessionaria, despachante...)' AFTER `empresa_nome`",
    'temperatura' => "ADD COLUMN `temperatura` VARCHAR(10) NULL COMMENT 'frio | morno | quente escolhido pelo consultor; NULL = automatica' AFTER `tipo_lead`",
];
foreach ($colunas as $nome => $ddl) {
    if (temColuna($pdo, 'cards', $nome)) {
        linha("  [ja existe] cards.$nome");
    } elseif ($dryRun) {
        linha("  [criaria]   cards.$nome");
    } else {
        $pdo->exec("ALTER TABLE `cards` $ddl");
        linha("  [criada]    cards.$nome");
    }
}

linha();
linha($dryRun ? 'Dry-run concluido. Nada foi gravado.' : 'Concluido.');
