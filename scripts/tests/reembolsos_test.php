<?php
/**
 * scripts/tests/reembolsos_test.php — App\Financas\Reembolso (tela Finanças da
 * edição CRM, 03/10/2026).
 *
 * Confere a conta do dinheiro (centavos, divisão das parcelas com soma exata,
 * vencimentos no fim de mês), a situação derivada das parcelas, a validação, a
 * chave por conta (habilitado), e no banco: criar, pagar com quem pagou,
 * desfazer, quitar, a trava de valor com parcela paga, e o isolamento entre
 * contas (outra conta não lê, não altera, não paga, não exclui). Apaga o que criou.
 *
 * Precisa das migrations 138 e 139 e de duas contas quaisquer no banco.
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

echo "\n== 5. Módulo ligado por conta ==\n";
$cfg = fn(array $c) => ['configuracoes' => json_encode($c)];
ok('CRM com a chave: ligado', R::habilitado($cfg(['produto' => 'fleetiflow', 'modulos' => ['reembolsos' => true]])));
ok('CRM sem a chave: desligado', !R::habilitado($cfg(['produto' => 'fleetiflow'])));
ok('Yuris com a chave: desligado, sempre', !R::habilitado($cfg(['modulos' => ['reembolsos' => true]])));
ok('chave com valor que não é true: desligado', !R::habilitado($cfg(['produto' => 'fleetiflow', 'modulos' => ['reembolsos' => 1]])));
ok('conta vazia: desligado', !R::habilitado([]));

echo "\n== 6. Banco, fluxo e isolamento ==\n";
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

        ok('nome de quem pagou com mais de 150 caracteres é recusado', R::marcarParcela($r['parcelas'][0]['id'], [$a], '2026-10-03', null, str_repeat('x', 151)) === null);
        ok('paga a primeira parcela, com quem pagou', R::marcarParcela($r['parcelas'][0]['id'], [$a], '2026-10-03', null, '  Gabriel ') === $id);
        $r = R::buscar($id, [$a]);
        ok('guarda data e quem pagou (aparado)', $r['parcelas'][0]['pago_em'] === '2026-10-03' && $r['parcelas'][0]['pago_por_nome'] === 'Gabriel');
        ok('parcela em aberto não tem quem pagou', $r['parcelas'][1]['pago_por_nome'] === null);
        ok('fica "pagando", com 50,00 pago e 100,00 em aberto', $r['qtd_pagas'] === 1 && abs($r['valor_pago'] - 50) < 0.001 && abs($r['valor_aberto'] - 100) < 0.001 && in_array($r['situacao'], ['pagando', 'atrasado'], true));
        [$d2] = R::validar(['valor_total' => '200,00'] + $base);
        ok('com parcela paga, valor não muda', R::atualizar($id, [$a], $d2) !== null && R::buscar($id, [$a])['valor_total'] === 150.0);
        [$d3] = R::validar(['descricao' => 'Hospedagem anual'] + $base);
        ok('com parcela paga, a descrição muda', R::atualizar($id, [$a], $d3) === null && R::buscar($id, [$a])['descricao'] === 'Hospedagem anual');
        ok('desfaz o pagamento e limpa quem pagou', R::marcarParcela($r['parcelas'][0]['id'], [$a], null, null) === $id && ($x = R::buscar($id, [$a]))['qtd_pagas'] === 0 && $x['parcelas'][0]['pago_por_nome'] === null);
        ok('sem parcela paga, valor e parcelamento mudam e as parcelas são refeitas', R::atualizar($id, [$a], ['parcelas' => 2] + $d2) === null
            && ($x = R::buscar($id, [$a]))['qtd_parcelas'] === 2 && $x['valor_total'] === 200.0 && abs($x['parcelas'][1]['valor'] - 100) < 0.001);
        ok('quita tudo de uma vez, com quem pagou', R::quitar($id, [$a], '2026-10-03', null, 'Milton') && ($x = R::buscar($id, [$a]))['situacao'] === 'pago' && $x['parcelas'][1]['pago_por_nome'] === 'Milton');
        $res = R::resumo(R::listar([$a]));
        ok('resumo conta o pago', $res['pago'] >= 200.0);
        ok('exclui (lógico) e some da lista', R::excluir($id, [$a]) && R::buscar($id, [$a]) === null);
    } finally {
        foreach ($criados as $c) $pdo->prepare("DELETE FROM reembolsos WHERE id = ?")->execute([$c]);
    }
    $sobra = (int) $pdo->query("SELECT COUNT(*) FROM reembolso_parcelas WHERE reembolso_id IN (" . implode(',', $criados ?: [0]) . ")")->fetchColumn();
    ok('limpeza: parcelas saem junto (cascade)', $sobra === 0);
}

echo "\n== 7. Estorno e saldo por pessoa (migration 149) ==\n";
[$de, $ee] = R::validar(['tipo' => 'estorno', 'favorecido' => '', 'favorecido_user_id' => 5] + $base);
ok('estorno com pessoa escolhida (sem nome digitado) passa', $ee === null && $de['tipo'] === 'estorno' && $de['favorecido_user_id'] === 5);
ok('tipo inválido é recusado', R::validar(['tipo' => 'outro'] + $base)[1] !== null);
ok('sem nome e sem pessoa é recusado', R::validar(['favorecido' => '', 'favorecido_user_id' => 0] + $base)[1] !== null);
ok('sem tipo continua reembolso (compatível)', R::validar($base)[0]['tipo'] === 'reembolso');
$lan = fn(array $x) => $x + ['tipo' => 'reembolso', 'favorecido_user_id' => null, 'favorecido' => 'Gabriel', 'valor_total' => 0.0,
                            'valor_pago' => 0.0, 'valor_aberto' => 0.0, 'valor_atrasado' => 0.0, 'situacao' => 'pendente'];
$pp = R::porPessoa([
    $lan(['favorecido_user_id' => 123, 'valor_total' => 300.0, 'valor_pago' => 100.0, 'valor_aberto' => 200.0, 'situacao' => 'pagando']),
    $lan(['favorecido_user_id' => 123, 'tipo' => 'estorno', 'valor_total' => 50.0, 'valor_aberto' => 50.0]),
    $lan(['favorecido_user_id' => 124, 'favorecido' => 'Milton', 'valor_total' => 80.0, 'valor_pago' => 80.0, 'situacao' => 'pago']),
    $lan(['favorecido_user_id' => 125, 'favorecido' => 'Marcelo', 'valor_total' => 40.0, 'valor_aberto' => 40.0, 'valor_atrasado' => 40.0, 'situacao' => 'atrasado']),
    $lan(['favorecido' => ' fornecedor x ', 'valor_total' => 10.0, 'valor_aberto' => 10.0]),
    $lan(['favorecido' => 'Fornecedor X', 'valor_total' => 5.0, 'valor_aberto' => 5.0]),
]);
$porNome = array_column($pp, null, 'nome');
ok('agrupa por usuário: Gabriel soma reembolso e estorno', $porNome['Gabriel']['total'] === 350.0 && $porNome['Gabriel']['aberto'] === 250.0
    && $porNome['Gabriel']['reembolso_aberto'] === 200.0 && $porNome['Gabriel']['estorno_aberto'] === 50.0);
ok('Gabriel recebeu parte: "pago em parte"', $porNome['Gabriel']['situacao'] === 'parcial' && $porNome['Gabriel']['pago'] === 100.0);
ok('Milton recebeu tudo: quitado', $porNome['Milton']['situacao'] === 'quitado' && $porNome['Milton']['aberto'] === 0.0);
ok('Marcelo com parcela vencida: em atraso', $porNome['Marcelo']['situacao'] === 'atrasado');
ok('sem usuário agrupa pelo nome (sem caixa e espaço)', count($pp) === 4 && $pp[1]['nome'] !== '' );
ok('ordem: quem tem mais a receber primeiro', $pp[0]['nome'] === 'Gabriel');
$semNada = array_values(array_filter($pp, fn($x) => $x['user_id'] === null))[0] ?? null;
ok('pessoa de fora: pendente, 15,00 em aberto', $semNada && $semNada['aberto'] === 15.0 && $semNada['situacao'] === 'pendente');

$tem149 = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reembolsos' AND COLUMN_NAME = 'tipo'")->fetchColumn();
if (!$tem149 || count($contas) < 2) { echo "  (pulado: rode a migration 149 e tenha duas contas)\n"; }
else {
    [$a, $b] = array_map('intval', $contas);
    $ua = $pdo->query("SELECT id, nome FROM users WHERE account_id = $a AND deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $ub = $pdo->query("SELECT id FROM users WHERE account_id = $b AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    if (!$ua) { echo "  (pulado: a conta $a não tem usuário)\n"; }
    else {
        $criados2 = [];
        try {
            [$d] = R::validar(['tipo' => 'estorno', 'favorecido' => 'nome digitado', 'favorecido_user_id' => (int) $ua['id']] + $base);
            ok('pessoa da própria conta: aceita e o nome vem do cadastro', R::resolverPessoa($a, $d) === null && $d['favorecido'] === $ua['nome']);
            $id2 = R::criar($a, $d, null); $criados2[] = $id2;
            $r2 = R::buscar($id2, [$a]);
            ok('estorno gravado com tipo e pessoa', $r2['tipo'] === 'estorno' && $r2['favorecido_user_id'] === (int) $ua['id']);
            if ($ub) {
                [$dx] = R::validar(['favorecido_user_id' => (int) $ub] + $base);
                ok('usuário de outra conta é recusado', R::resolverPessoa($a, $dx) !== null);
            }
            [$d3] = R::validar(['tipo' => 'reembolso', 'favorecido' => 'Fornecedor', 'favorecido_user_id' => 0] + $base);
            R::atualizar($id2, [$a], $d3);
            $r3 = R::buscar($id2, [$a]);
            ok('editar troca o tipo e solta a pessoa', $r3['tipo'] === 'reembolso' && $r3['favorecido_user_id'] === null && $r3['favorecido'] === 'Fornecedor');
            ok('pessoas da conta não trazem usuário de outra conta', !in_array((int) $ub, array_column(R::pessoasDaConta([$a]), 'id'), true));
        } finally {
            foreach ($criados2 as $c) $pdo->prepare("DELETE FROM reembolsos WHERE id = ?")->execute([$c]);
        }
    }
}

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
