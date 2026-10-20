<?php
/**
 * card_reorder_test.php: o arrastar do quadro da Prospecção grava
 * (App\Prospeccao\Card::bulkUpdateOrders, corrigido em 20/10/2026).
 *
 *   - mover um card de etapa grava coluna e ordem e escreve "moved" no histórico
 *     (com o request_id), como Card::move;
 *   - os outros cards das duas colunas, mandados juntos pela tela, não ganham
 *     histórico nem updated_at novo; só a ordem muda quando mudou;
 *   - card inexistente na lista é ignorado sem derrubar o resto.
 *
 * Usa dois cards da conta local 304; volta os cards como estavam no fim. A linha
 * "moved" do histórico fica: card_history é imutável por gatilho (LGPD).
 * Uso: php scripts/tests/card_reorder_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Prospeccao\Card;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }

$pdo = Database::getConnection();
$cols = $pdo->query("SELECT c.coluna_id, COUNT(*) n FROM cards c WHERE c.account_id = 304 AND c.deleted_at IS NULL GROUP BY c.coluna_id HAVING n >= 2 ORDER BY n DESC LIMIT 2")->fetchAll(\PDO::FETCH_ASSOC);
$orig = [];
$histAntes = 0;
try {
    if (count($cols) < 2) throw new \RuntimeException('Preciso de duas etapas com cards na conta 304 local.');
    [$a, $b] = [(int) $cols[0]['coluna_id'], (int) $cols[1]['coluna_id']];
    $lista = fn(int $col) => $pdo->query("SELECT id, coluna_id, ordem_na_coluna, updated_at FROM cards WHERE account_id = 304 AND deleted_at IS NULL AND coluna_id = $col ORDER BY ordem_na_coluna, id")->fetchAll(\PDO::FETCH_ASSOC);
    $la = $lista($a); $lb = $lista($b);
    foreach (array_merge($la, $lb) as $r) $orig[(int) $r['id']] = $r;
    $movido = (int) $la[0]['id'];
    $outro = (int) $la[1]['id'];
    $pdo->exec("UPDATE cards SET updated_at = '2026-01-01 10:00:00' WHERE id = $outro");
    $histAntes = (int) $pdo->query('SELECT COUNT(*) FROM card_history')->fetchColumn();

    // O que a tela manda: as duas colunas inteiras, com o card movido no topo da B.
    $updates = [];
    $novaA = array_values(array_filter($la, fn($r) => (int) $r['id'] !== $movido));
    foreach ($novaA as $i => $r) $updates[] = ['id' => (int) $r['id'], 'coluna_id' => $a, 'ordem_na_coluna' => $i];
    $updates[] = ['id' => $movido, 'coluna_id' => $b, 'ordem_na_coluna' => 0];
    foreach ($lb as $i => $r) $updates[] = ['id' => (int) $r['id'], 'coluna_id' => $b, 'ordem_na_coluna' => $i + 1];
    $updates[] = ['id' => 999999999, 'coluna_id' => $b, 'ordem_na_coluna' => 99];

    ok('a gravação do arrastar funciona', Card::bulkUpdateOrders($updates, null) === true);
    $m = $pdo->query("SELECT coluna_id, ordem_na_coluna FROM cards WHERE id = $movido")->fetch(\PDO::FETCH_ASSOC);
    ok('o card movido está na etapa nova, no topo', (int) $m['coluna_id'] === $b && (int) $m['ordem_na_coluna'] === 0);
    $h = $pdo->query("SELECT acao, de_coluna_id, para_coluna_id, request_id FROM card_history WHERE card_id = $movido ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    ok('histórico "moved" com de/para e request_id', $h && $h['acao'] === 'moved' && (int) $h['de_coluna_id'] === $a && (int) $h['para_coluna_id'] === $b && $h['request_id'] !== null);
    $novas = (int) $pdo->query('SELECT COUNT(*) FROM card_history')->fetchColumn() - $histAntes;
    ok('só o card movido ganhou histórico', $novas === 1);
    ok('card que só mudou de ordem mantém o updated_at', $pdo->query("SELECT updated_at FROM cards WHERE id = $outro")->fetchColumn() === '2026-01-01 10:00:00');
    ok('card que só mudou de ordem tem a ordem nova', (int) $pdo->query("SELECT ordem_na_coluna FROM cards WHERE id = $outro")->fetchColumn() === 0);
} catch (\Throwable $e) {
    $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . "\n";
} finally {
    $st = $pdo->prepare('UPDATE cards SET coluna_id = ?, ordem_na_coluna = ?, updated_at = ? WHERE id = ?');
    foreach ($orig as $id => $r) $st->execute([$r['coluna_id'], $r['ordem_na_coluna'], $r['updated_at'], $id]);
    // card_history é imutável por gatilho (LGPD): a linha "moved" do teste fica (só no banco local).
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
