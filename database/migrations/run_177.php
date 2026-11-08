<?php
/**
 * Migration 177: cada registro NOVO do histórico processual sabe de qual prazo ou tarefa é.
 *
 * "Os prazos processuais também eu tenho que conseguir editar. Lembrando que o log tem que
 * ficar dentro de cada um: dentro da tarefa, se eu editar; dentro do prazo, se eu editar"
 * (08/11/2026). O processo_history só guardava o processo; a ficha do prazo e a da tarefa
 * não tinham como listar só o que é delas.
 *
 * processo_history ganha item_tipo ('prazo' | 'tarefa') e item_id, as duas NULL (registro
 * do processo em si continua sem item), e o índice (processo_id, item_tipo, item_id).
 * O App\Processos\ProcessoAudit grava as duas daqui em diante.
 *
 * O QUE JÁ EXISTE NÃO É TOCADO: processo_history é imutável (migration 053, gatilhos
 * trg_ph_no_update e trg_ph_no_delete, LGPD Art. 37), e preencher as colunas nas linhas
 * antigas seria um UPDATE. A ficha do item acha os registros antigos dele na LEITURA, pelo
 * nome (ProcessoAudit::doItem), sem gravar nada.
 *
 * IDEMPOTENTE: coluna e índice só são criados se faltarem.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_177.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_177.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo "== Migration 177: historico processual por prazo e por tarefa ==" . PHP_EOL;
echo($dryRun ? "   MODO DRY-RUN: nada sera gravado." : "   MODO APLICACAO.") . PHP_EOL . PHP_EOL;

$colunas = array_column($pdo->query("SHOW COLUMNS FROM processo_history")->fetchAll(\PDO::FETCH_ASSOC), 'Field');
$faltam = array_values(array_diff(['item_tipo', 'item_id'], $colunas));
$indices = array_column($pdo->query("SHOW INDEX FROM processo_history")->fetchAll(\PDO::FETCH_ASSOC), 'Key_name');
$faltaIndice = !in_array('idx_ph_item', $indices, true);

echo "colunas que faltam: " . ($faltam ? implode(', ', $faltam) : 'nenhuma') . PHP_EOL;
echo "indice idx_ph_item: " . ($faltaIndice ? 'falta' : 'ja existe') . PHP_EOL;
if (!$dryRun) {
    if (in_array('item_tipo', $faltam, true)) $pdo->exec("ALTER TABLE processo_history ADD COLUMN item_tipo VARCHAR(10) NULL AFTER descricao");
    if (in_array('item_id', $faltam, true))   $pdo->exec("ALTER TABLE processo_history ADD COLUMN item_id INT NULL AFTER item_tipo");
    if ($faltaIndice) $pdo->exec("ALTER TABLE processo_history ADD INDEX idx_ph_item (processo_id, item_tipo, item_id)");
}
echo PHP_EOL . ($dryRun ? "Dry-run concluido. Rode sem --dry-run para aplicar." : "Migration 177 aplicada.") . PHP_EOL;
