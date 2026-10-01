<?php
/**
 * Migration 135: chat_mencoes.tipo aceita 'tarefa' e 'conversa'.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * O @ do Chat Interno na edição CRM passa a sugerir o que existe nela: pessoas,
 * leads, clientes, TAREFAS e CONVERSAS DE WHATSAPP. As duas últimas precisam
 * de lugar no enum de `chat_mencoes.tipo`, que até aqui era
 * ('usuario','processo','card','cliente'); sem isso a menção é descartada em
 * silêncio no envio (api/chat/mensagens.php só grava tipo conhecido).
 *
 * Só ACRESCENTA valores ao fim do enum: nenhuma linha existente muda, e a edição
 * jurídica continua usando os quatro de sempre.
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_135.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_135.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

linha('== Migration 135: chat_mencoes.tipo com tarefa e conversa ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_mencoes' AND COLUMN_NAME = 'tipo'");
$st->execute();
$tipo = (string)$st->fetchColumn();
if ($tipo === '') {
    linha('  [ERRO] chat_mencoes.tipo nao existe.');
    exit(1);
}
linha("  atual: $tipo");

$faltam = array_values(array_filter(['tarefa', 'conversa'], fn($v) => !str_contains($tipo, "'$v'")));
if (!$faltam) {
    linha('  [ja existe] tarefa e conversa no enum');
} else {
    // Mantém os valores atuais na ordem e acrescenta os que faltam no fim.
    preg_match_all("/'([^']+)'/", $tipo, $m);
    $valores = array_merge($m[1], $faltam);
    $enum = "ENUM('" . implode("','", $valores) . "')";
    if ($dryRun) {
        linha("  [alteraria] tipo -> $enum");
    } else {
        $pdo->exec("ALTER TABLE `chat_mencoes` MODIFY `tipo` $enum NOT NULL");
        linha("  [alterado]  tipo -> $enum");
    }
}

linha();
linha($dryRun ? 'Dry-run concluido. Nada foi gravado.' : 'Concluido.');
