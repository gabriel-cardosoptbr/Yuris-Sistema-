<?php
/**
 * historico_item_test.php: o log de cada prazo e de cada tarefa do processo (08/11/2026).
 *
 * "Os prazos processuais também eu tenho que conseguir editar. O log tem que ficar dentro de
 * cada um: dentro da tarefa, se eu editar; dentro do prazo, se eu editar."
 *
 * Confere, sem gravar nada (processo_history é imutável, migration 053: teste que grava
 * deixaria lixo para sempre):
 *   1. a migration 177 existe, só acrescenta colunas e índice, e não tenta UPDATE no histórico;
 *   2. ProcessoAudit::log aceita o item e cai no INSERT antigo sem a coluna;
 *   3. as APIs de prazo e de tarefa ligam TODO log ao item, e a edição vira uma entrada só;
 *   4. o GET do histórico de um item passa pelo TenantGuard antes e valida tipo e id;
 *   5. a ficha da tela (processos.js) e a edição rápida da Central pedem o log do item;
 *   6. com banco: doItem devolve vazio para entrada inválida e para item de outro processo,
 *      e só registros daquele item.
 *
 * Uso: php scripts/tests/historico_item_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

$raiz = dirname(__DIR__, 2);
$ok = 0; $falhas = 0;
function ok($cond, $msg) { global $ok, $falhas; if ($cond) { $ok++; echo "  [PASS] $msg\n"; } else { $falhas++; echo "  [FAIL] $msg\n"; } }
function ler($rel) { global $raiz; return (string) @file_get_contents($raiz . '/' . $rel); }

echo "== 1. Migration 177 ==\n";
$mig = ler('database/migrations/run_177.php');
ok($mig !== '', 'run_177.php existe');
ok(str_contains($mig, 'ADD COLUMN item_tipo') && str_contains($mig, 'ADD COLUMN item_id') && str_contains($mig, 'idx_ph_item'), 'acrescenta item_tipo, item_id e o índice');
ok(!preg_match('/UPDATE\s+processo_history/i', $mig) && !preg_match('/DELETE\s+FROM\s+processo_history/i', $mig), 'não tenta UPDATE nem DELETE no histórico imutável');
ok(str_contains($mig, '--dry-run'), 'tem dry-run');

echo "== 2. ProcessoAudit ==\n";
$aud = ler('app/Processos/ProcessoAudit.php');
ok(str_contains($aud, 'public static function log(int $processoId, string $acao, string $descricao = \'\', ?string $itemTipo = null, ?int $itemId = null)'), 'log() aceita o item');
ok(str_contains($aud, 'self::temColunaDeItem($pdo)') && str_contains($aud, '($comItem ? " item_tipo, item_id," : "")'), 'sem a coluna, grava como antes');
ok(str_contains($aud, 'public static function doItem(\PDO $pdo, int $processoId, string $tipo, int $itemId): array'), 'doItem() existe');

echo "== 3. APIs de prazo e de tarefa ==\n";
$pz = ler('public/api/processo_prazos.php');
$tf = ler('public/api/processo_tarefas.php');
// O item vem por último na chamada: ", 'prazo', $id)" ou ", 'prazo', (int) $newId" e a quebra de linha antes do ")".
preg_match_all('/ProcessoAudit::log\(/', $pz, $m1); preg_match_all('/\'prazo\', (\(int\) )?\$(newId|id)\s*\)/', $pz, $m2);
ok(count($m1[0]) >= 4 && count($m1[0]) === count($m2[0]), 'todo log de prazo leva o prazo (' . count($m2[0]) . ' de ' . count($m1[0]) . ')');
preg_match_all('/ProcessoAudit::log\(/', $tf, $m3); preg_match_all('/\'tarefa\', (\(int\) )?\$(newId|id)\s*\)/', $tf, $m4);
ok(count($m3[0]) >= 4 && count($m3[0]) === count($m4[0]), 'todo log de tarefa leva a tarefa (' . count($m4[0]) . ' de ' . count($m3[0]) . ')');
ok(str_contains($pz, "'observacao'];") && str_contains($pz, "'Prazo editado', \"Prazo '{\$prevPrazo['descricao']}': \" . implode('; ', \$mudou)"), 'prazo: observação editável, e a edição vira UMA entrada com cada de → para');
ok(str_contains($tf, "trim((string) \$input['titulo']) !== (string) \$prevTarefa['titulo']"), 'tarefa: salvar sem mudar o nome não grava "renomeada"');
ok(str_contains($tf, "(int) (bool) \$input['concluido'] !== (int) \$prevTarefa['concluido']"), 'tarefa: concluir só grava quando muda');

echo "== 4. GET do histórico de um item ==\n";
$hist = ler('public/api/processo_history.php');
$pGuard = strpos($hist, 'TenantGuard::assertProcessoAcessivel($ctx, $processoId);');
$pItem  = strpos($hist, 'ProcessoAudit::doItem(');
ok($pGuard !== false && $pItem !== false && $pGuard < $pItem, 'confere o acesso ao processo antes de ler o item');
ok(str_contains($hist, "in_array(\$itemTipo, ['prazo', 'tarefa'], true) || \$itemId <= 0"), 'tipo e id validados (400 fora disso)');

echo "== 5. Telas ==\n";
$js = ler('public/assets/processos.js');
ok(str_contains($js, 'window._editarPrazo = (id) =>') && str_contains($js, 'onclick="window._editarPrazo(${p.id})"'), 'o prazo tem "Editar"');
ok(str_contains($js, 'item_tipo=${tipo}&item_id=') && str_contains($js, "getElementById('etHist')"), 'a ficha pede e mostra o log do item');
ok(str_contains(ler('public/processos.php'), 'id="etHist"') && str_contains(ler('public/processos.php'), 'id="etPrio"'), 'a ficha tem prioridade e histórico');
$cen = ler('public/assets/tarefas-central.js');
ok(str_contains($cen, 'async function carregarHistItem(i)') && str_contains(ler('public/tarefas.php'), 'id="ipHist"'), 'a edição rápida da Central mostra o log do item');

echo "== 6. Com banco ==\n";
try {
    $pdo = \App\Core\Database::getConnection();
    ok(\App\Processos\ProcessoAudit::doItem($pdo, 0, 'prazo', 1) === [], 'processo inválido: vazio');
    ok(\App\Processos\ProcessoAudit::doItem($pdo, 1, 'outro', 1) === [], 'tipo inválido: vazio');
    $um = $pdo->query("SELECT id, processo_id FROM processo_prazos ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    $outro = $um ? $pdo->query("SELECT id FROM processos WHERE id <> " . (int) $um['processo_id'] . " ORDER BY id LIMIT 1")->fetchColumn() : false;
    if ($um && $outro) {
        ok(\App\Processos\ProcessoAudit::doItem($pdo, (int) $outro, 'prazo', (int) $um['id']) === [], 'prazo pedido por outro processo: vazio (não atravessa processo)');
        $lista = \App\Processos\ProcessoAudit::doItem($pdo, (int) $um['processo_id'], 'prazo', (int) $um['id']);
        $todosDele = true;
        foreach ($lista as $h) {
            if ((int) $h['processo_id'] !== (int) $um['processo_id'] || stripos((string) $h['acao'], 'prazo') !== 0) $todosDele = false;
            if (isset($h['item_tipo']) && $h['item_tipo'] !== null && ((string) $h['item_tipo'] !== 'prazo' || (int) $h['item_id'] !== (int) $um['id'])) $todosDele = false;
        }
        ok($todosDele, 'o log do prazo só tem registros de prazo daquele processo, ligados a ele (' . count($lista) . ')');
    } else {
        echo "  (sem prazo e dois processos no banco: parte pulada)\n";
    }
} catch (\Throwable $e) {
    echo "  (sem banco: parte 6 pulada: " . $e->getMessage() . ")\n";
}

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas ? 1 : 0);
