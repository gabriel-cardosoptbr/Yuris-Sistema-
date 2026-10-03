<?php
/**
 * scripts/tests/reembolsos_test.php — App\Financas\Reembolso (tela Finanças da
 * edição CRM, 03/10/2026).
 *
 * Confere a conta do dinheiro (centavos, divisão das parcelas com soma exata,
 * vencimentos no fim de mês), a situação derivada das parcelas, a validação, e
 * no banco: criar, pagar, desfazer, quitar, a trava de valor com parcela paga,
 * e o isolamento entre contas (outra conta não lê, não altera, não paga, não
 * exclui). Apaga o que criou.
 *
 * Precisa da migration 138 e de duas contas quaisquer no banco.
 * Uso: php scripts/tests/reembolsos_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Financas\Reembolso as R;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

echo "== 1. Dinheiro ==\n";
ok('"1.234,56" vira 123456 centavos', R::centavos('1.234,56') === 123456);
ok('"1234.5" vira 123450', R::centavos('1234.5') === 123450);
ok('"R$ 10,00" vira 1000', R::centavos('R$ 10,00') === 1000);
ok('texto inválido devolve null', R::centavos('dez reais') === null && R::centavos('') === null && R::centavos('1,234') === null);
ok('100,00 em 3x: 33,34 + 33,33 + 33,33', R::dividir(10000, 3) === [3334, 3333, 3333]);
ok('a soma das parcelas é sempre o total', array_sum(R::dividir(99999, 7)) === 99999 && array_sum(R::dividir(1, 1)) === 1);
ok('à vista é uma parcela só', R::dividir(5000, 1) === [5000]);

echo "\n== 2. Vencimentos ==\n";
ok('mensal a partir do primeiro', R::vencimentos('2026-10-10', 3) === ['2026-10-10', '2026-11-10', '2026-12-10']);
ok('dia 31 cai no último dia do mês curto, sem pular mês', R::vencimentos('2026-01-31', 4) === ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
ok('vira o ano', R::vencimentos('2026-12-05', 2) === ['2026-12-05', '2027-01-05']);
ok('data inválida devolve vazio', R::vencimentos('2026-02-30', 2) === []);

echo "\n== 3. Situação ==\n";
$p = fn($v, $pago = null) => ['vencimento' => $v, 'pago_em' => $pago];
ok('nada pago, nada vencido: pendente', R::situacao([$p('2026-10-10'), $p('2026-11-10')], '2026-10-03') === 'pendente');
ok('uma paga, resto no prazo: pagando', R::situacao([$p('2026-10-10', '2026-10-01'), $p('2026-11-10')], '2026-10-03') === 'pagando');
ok('parcela em aberto vencida: atrasado', R::situacao([$p('2026-10-10', '2026-10-01'), $p('2026-10-02')], '2026-10-03') === 'atrasado');
ok('vence hoje ainda não é atraso', R::situacao([$p('2026-10-03')], '2026-10-03') === 'pendente');
ok('todas pagas: pago, mesmo pagas depois do vencimento', R::situacao([$p('2026-09-01', '2026-10-01')], '2026-10-03') === 'pago');

echo "\n== 4. Validação ==\n";
$base = ['favorecido' => 'Ana', 'descricao' => 'Hospedagem', 'data_despesa' => '2026-10-01', 'valor_total' => '150,00', 'parcelas' => 3, 'primeiro_vencimento' => '2026-10-10'];
[$d, $e] = R::validar($base);
ok('dados certos passam e viram centavos', $e === null && $d['valor_centavos'] === 15000 && $d['parcelas'] === 3);
ok('sem favorecido é recusado', R::validar(['favorecido' => ' '] + $base)[1] !== null);
ok('valor zero é recusado', R::validar(['valor_total' => '0'] + $base)[1] !== null);
ok('25 parcelas é recusado', R::validar(['parcelas' => 25] + $base)[1] !== null);
ok('parcela menor que um centavo é recusada', R::validar(['valor_total' => '0,02', 'parcelas' => 3] + $base)[1] !== null);
ok('data impossível é recusada', R::validar(['data_despesa' => '2026-02-30'] + $base)[1] !== null);

echo "\n== 5. Banco, fluxo e isolamento ==\n";
$pdo = Database::getConnection();
$contas = $pdo->query("SELECT id FROM accounts ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($contas) < 2) { echo "  (pulado: precisa de duas contas no banco)\n"; }
else {
    [$a, $b] = array_map('intval', $contas);
    $criados = [];
    try {
        [$d] = R::validar($base);
        $id = R::criar($a, $d, null); $criados[] = $id;
        $r = R::buscar($id, [$a]);
        ok('cria com 3 parcelas que somam o total', $r && $r['qtd_parcelas'] === 3 && abs(array_sum(array_column($r['parcelas'], 'valor')) - 150.0) < 0.001);
        ok('outra conta não enxerga', R::buscar($id, [$b]) === null && !array_filter(R::listar([$b]), fn($x) => $x['id'] === $id));
        ok('outra conta não paga parcela', R::marcarParcela($r['parcelas'][0]['id'], [$b], '2026-10-03', null) === null);
        ok('outra conta não altera', R::atualizar($id, [$b], $d) !== null);
        ok('outra conta não quita', R::quitar($id, [$b], '2026-10-03', null) === false);
        ok('outra conta não exclui', R::excluir($id, [$b]) === false && R::buscar($id, [$a]) !== null);

        ok('paga a primeira parcela', R::marcarParcela($r['parcelas'][0]['id'], [$a], '2026-10-03', null) === $id);
        $r = R::buscar($id, [$a]);
        ok('fica "pagando", com 50,00 pago e 100,00 em aberto', $r['qtd_pagas'] === 1 && abs($r['valor_pago'] - 50) < 0.001 && abs($r['valor_aberto'] - 100) < 0.001 && in_array($r['situacao'], ['pagando', 'atrasado'], true));
        [$d2] = R::validar(['valor_total' => '200,00'] + $base);
        ok('com parcela paga, valor não muda', R::atualizar($id, [$a], $d2) !== null && R::buscar($id, [$a])['valor_total'] === 150.0);
        [$d3] = R::validar(['descricao' => 'Hospedagem anual'] + $base);
        ok('com parcela paga, a descrição muda', R::atualizar($id, [$a], $d3) === null && R::buscar($id, [$a])['descricao'] === 'Hospedagem anual');
        ok('desfaz o pagamento', R::marcarParcela($r['parcelas'][0]['id'], [$a], null, null) === $id && R::buscar($id, [$a])['qtd_pagas'] === 0);
        ok('sem parcela paga, valor e parcelamento mudam e as parcelas são refeitas', R::atualizar($id, [$a], ['parcelas' => 2] + $d2) === null
            && ($x = R::buscar($id, [$a]))['qtd_parcelas'] === 2 && $x['valor_total'] === 200.0 && abs($x['parcelas'][1]['valor'] - 100) < 0.001);
        ok('quita tudo de uma vez', R::quitar($id, [$a], '2026-10-03', null) && R::buscar($id, [$a])['situacao'] === 'pago');
        $res = R::resumo(R::listar([$a]));
        ok('resumo conta o pago', $res['pago'] >= 200.0);
        ok('exclui (lógico) e some da lista', R::excluir($id, [$a]) && R::buscar($id, [$a]) === null);
    } finally {
        foreach ($criados as $c) $pdo->prepare("DELETE FROM reembolsos WHERE id = ?")->execute([$c]);
    }
    $sobra = (int) $pdo->query("SELECT COUNT(*) FROM reembolso_parcelas WHERE reembolso_id IN (" . implode(',', $criados ?: [0]) . ")")->fetchColumn();
    ok('limpeza: parcelas saem junto (cascade)', $sobra === 0);
}

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
