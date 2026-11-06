<?php
/**
 * card_fechamento_test.php: arrastar para a etapa de venda concluída fecha a venda
 * (App\Prospeccao\Card, 05/11/2026).
 *
 *   - entrar na etapa grava a data de hoje e não troca data que já exista;
 *   - voltar para o funil ou ir para perdido limpa a data; pós-venda mantém;
 *   - vale para Card::move, bulkUpdateOrders (o arrastar do quadro) e update;
 *   - salvar o formulário aberto antes do arraste não apaga a data;
 *   - a mudança fica no histórico.
 *
 * Usa um card da conta local 304 e o devolve como estava. As linhas de card_history
 * ficam: a tabela é imutável por gatilho (LGPD). Não rodar no servidor.
 * Uso: php scripts/tests/card_fechamento_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Prospeccao\Card;
use App\Tarefas\TaskEntrega;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }

echo "\n== 1. A regra ==\n";
$funil = ['conta_funil' => 1, 'conta_fechado' => 0, 'conta_perdido' => 0];
$feito = ['conta_funil' => 0, 'conta_fechado' => 1, 'conta_perdido' => 0];
$perda = ['conta_funil' => 0, 'conta_fechado' => 0, 'conta_perdido' => 1];
$posV  = ['conta_funil' => 0, 'conta_fechado' => 0, 'conta_perdido' => 0];
$r = static fn($de, $para, $data) => Card::fechamentoAoMudarDeEtapa($de, $para, $data, '2026-11-05');
ok('entrar em venda concluída grava hoje', $r($funil, $feito, null) === '2026-11-05');
ok('data zerada do banco conta como sem data', $r($funil, $feito, '0000-00-00') === '2026-11-05');
ok('data que já existe não é trocada', $r($funil, $feito, '2026-10-20') === false);
ok('voltar para o funil limpa a data', $r($feito, $funil, '2026-10-20') === null);
ok('ir para perdido limpa a data', $r($feito, $perda, '2026-10-20') === null);
ok('ir para pós-venda mantém a venda', $r($feito, $posV, '2026-10-20') === false);
ok('entre etapas do funil não mexe', $r($funil, $funil, null) === false && $r($funil, $funil, '2026-10-20') === false);
ok('sair do fechado sem data não tem o que limpar', $r($feito, $funil, null) === false);

echo "\n== 2. Pelo banco ==\n";
$pdo = Database::getConnection();
$card = null;
try {
    $conta = 304;
    $colFeito = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $conta AND conta_fechado = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $colFunil = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $conta AND conta_fechado = 0 AND conta_perdido = 0 AND conta_funil = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if (!$colFeito || !$colFunil) throw new \RuntimeException('Preciso das etapas de funil e fechado na conta 304 local.');
    $card = $pdo->query("SELECT id, coluna_id, ordem_na_coluna, data_fechamento, updated_at FROM cards
                          WHERE account_id = $conta AND deleted_at IS NULL AND coluna_id = $colFunil
                            AND (data_fechamento IS NULL OR data_fechamento = '0000-00-00') ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if (!$card) throw new \RuntimeException('Preciso de um card em aberto no funil da conta 304 local.');
    $id   = (int) $card['id'];
    $hoje = substr(TaskEntrega::agoraLocal(), 0, 10);
    $data = static fn() => $pdo->query("SELECT data_fechamento FROM cards WHERE id = $id")->fetchColumn() ?: null;

    Card::move($id, $colFeito, 0, null);
    ok('Card::move para venda concluída grava hoje', $data() === $hoje);
    $h = $pdo->query("SELECT acao, valor_novo FROM card_history WHERE card_id = $id AND campo_alterado = 'data_fechamento' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    ok('a data entra no histórico do card', $h && $h['valor_novo'] === $hoje);

    Card::update($id, ['data_fechamento' => '']);
    ok('formulário antigo, com a data vazia, não apaga a venda', $data() === $hoje);

    Card::update($id, ['data_fechamento' => '2026-10-01']);
    ok('a data ainda pode ser corrigida à mão', $data() === '2026-10-01');

    Card::bulkUpdateOrders([['id' => $id, 'coluna_id' => $colFunil, 'ordem_na_coluna' => 0]], null);
    ok('arrastar de volta para o funil limpa a data', $data() === null);

    Card::bulkUpdateOrders([['id' => $id, 'coluna_id' => $colFeito, 'ordem_na_coluna' => 0]], null);
    ok('arrastar pelo quadro para venda concluída grava hoje', $data() === $hoje);

    Card::update($id, ['coluna_id' => $colFunil]);
    ok('trocar a etapa pelo formulário também limpa', $data() === null);
} catch (\Throwable $e) {
    $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . "\n";
} finally {
    if ($card) {
        $pdo->prepare('UPDATE cards SET coluna_id = ?, ordem_na_coluna = ?, data_fechamento = ?, updated_at = ? WHERE id = ?')
            ->execute([$card['coluna_id'], $card['ordem_na_coluna'], $card['data_fechamento'], $card['updated_at'], $card['id']]);
    }
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
