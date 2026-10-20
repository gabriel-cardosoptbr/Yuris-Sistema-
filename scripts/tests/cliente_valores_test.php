<?php
/**
 * cliente_valores_test.php: o Financeiro da ficha do cliente
 * (App\Clientes\ValoresDoCliente e App\Clientes\CobrancaDoCliente, migrations 152 e 153).
 *
 *   - resumo: "por mês" soma mensal + anual/12; linha encerrada ou vencida não conta;
 *   - número em formato brasileiro ("2.600,00") e validações (tipo, valor, dia, datas,
 *     "começa em" obrigatório);
 *   - cobranças previstas: mensal a partir do início, dia 31 em mês curto, fim, anual,
 *     serviço e taxa (uma só), demanda (nenhuma), sem pilha para trás;
 *   - salvar gera as cobranças; editar refaz as abertas e mantém as pagas; encerrar
 *     cancela; reativar devolve; gerar de novo não duplica; baixa e desfazer;
 *   - remover recusa valor com cobrança paga; outra conta não lê nem grava;
 *   - negócio fechado de cliente com valores sai da soma de fechamentos (sem dobrar receita).
 *
 * Usa um cliente da conta CRM local 304; as linhas criadas são apagadas no fim.
 * Uso: php scripts/tests/cliente_valores_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\CobrancaDoCliente as C;
use App\Clientes\ValoresDoCliente as V;
use App\Core\Database;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }
function recusa(callable $f): ?string { try { $f(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } }

echo "\n== 1. Conta (sem banco) ==\n";
$r = V::resumo([
    ['tipo' => 'mensal', 'valor' => 2600, 'ativo' => true, 'fim' => null],
    ['tipo' => 'anual', 'valor' => 1200, 'ativo' => true, 'fim' => null],
    ['tipo' => 'fixo', 'valor' => 1000, 'ativo' => true, 'fim' => null],
    ['tipo' => 'servico', 'valor' => 3500, 'ativo' => true, 'fim' => null],
    ['tipo' => 'mensal', 'valor' => 500, 'ativo' => false, 'fim' => null],
    ['tipo' => 'mensal', 'valor' => 300, 'ativo' => true, 'fim' => '2020-01-01'],
]);
ok('por mês = mensal + anual/12, sem encerrado nem vencido', $r['por_mes'] === 2700.0);
ok('fixo, serviço e anual somados à parte', $r['fixo'] === 1000.0 && $r['servico'] === 3500.0 && $r['anual'] === 1200.0);
ok('"2.600,00" vira 2600', V::numero('2.600,00') === 2600.0);
ok('"1234.5" vira 1234.5', V::numero('1234.5') === 1234.5);
ok('texto não é número', V::numero('abc') === null);

echo "\n== 2. Cobranças previstas (sem banco) ==\n";
$hoje = '2026-10-20';
ok('horizonte é o fim do mês seguinte', C::horizonte($hoje) === '2026-11-30');
ok('dia 31 em fevereiro cai no dia 28', C::diaNoMes('2027-02-01', 31) === '2027-02-28');
$p = C::previstas(['tipo' => 'mensal', 'inicio' => '2026-10-15', 'dia_vencimento' => 10, 'ativo' => 1, 'criado_em' => '2026-10-20 10:00:00'], $hoje);
ok('mensal começa no mês do início; primeira vence no início se o dia já passou', count($p) === 2 && $p[0]['vencimento'] === '2026-10-15' && $p[1]['vencimento'] === '2026-11-10');
$p = C::previstas(['tipo' => 'mensal', 'inicio' => '2026-12-01', 'dia_vencimento' => 5, 'ativo' => 1, 'criado_em' => '2026-10-20'], $hoje);
ok('mensal que começa depois do horizonte ainda não gera', $p === []);
$p = C::previstas(['tipo' => 'mensal', 'inicio' => '2026-01-01', 'dia_vencimento' => 5, 'ativo' => 1, 'criado_em' => '2026-10-20'], $hoje);
ok('mensal antiga cadastrada hoje não vira pilha para trás', count($p) === 2 && $p[0]['competencia'] === '2026-10-01');
$p = C::previstas(['tipo' => 'mensal', 'inicio' => '2026-10-01', 'fim' => '2026-10-31', 'dia_vencimento' => 5, 'ativo' => 1, 'criado_em' => '2026-10-01'], $hoje);
ok('fim corta as seguintes', count($p) === 1);
$p = C::previstas(['tipo' => 'mensal', 'inicio' => '2026-10-31', 'ativo' => 1, 'criado_em' => '2026-10-20'], $hoje);
ok('sem dia de vencimento usa o dia do início (31 vira 30 em novembro)', count($p) === 2 && $p[1]['vencimento'] === '2026-11-30');
$p = C::previstas(['tipo' => 'anual', 'inicio' => '2026-11-05', 'ativo' => 1, 'criado_em' => '2026-10-20'], $hoje);
ok('anual: uma no aniversário do início', count($p) === 1 && $p[0]['vencimento'] === '2026-11-05');
$p = C::previstas(['tipo' => 'servico', 'inicio' => '2026-09-01', 'ativo' => 1, 'criado_em' => '2026-10-20'], $hoje);
ok('serviço: uma cobrança na data, mesmo no passado', count($p) === 1 && $p[0]['vencimento'] === '2026-09-01');
ok('taxa fixa: uma cobrança', count(C::previstas(['tipo' => 'fixo', 'inicio' => '2026-10-25', 'ativo' => 1], $hoje)) === 1);
ok('por demanda não gera cobrança', C::previstas(['tipo' => 'demanda', 'inicio' => '2026-10-01', 'ativo' => 1], $hoje) === []);
ok('encerrado não gera cobrança', C::previstas(['tipo' => 'mensal', 'inicio' => '2026-10-01', 'ativo' => 0], $hoje) === []);

echo "\n== 3. Com banco ==\n";
$pdo = Database::getConnection();
$cli = (int) $pdo->query('SELECT id FROM clientes WHERE account_id = 304 AND deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
$criados = [];
$cobsDe = function (int $valor, ?string $status = null) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM crm_cliente_cobrancas WHERE valor_id = ?' . ($status ? ' AND status = ?' : '') . ' ORDER BY competencia');
    $st->execute($status ? [$valor, $status] : [$valor]);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
};
try {
    if (!$cli) throw new \RuntimeException('Sem cliente na conta 304 local.');
    $ini = C::hoje();
    ok('sem tipo é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'x', 'descricao' => 'A', 'valor' => 1, 'inicio' => $ini])) !== null);
    ok('valor inválido é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'A', 'valor' => 'abc', 'inicio' => $ini])) !== null);
    ok('dia 32 é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'A', 'valor' => 1, 'dia_vencimento' => 32, 'inicio' => $ini])) !== null);
    ok('fim antes do início é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'A', 'valor' => 1, 'inicio' => '2026-11-01', 'fim' => '2026-10-01'])) !== null);
    ok('mensalidade sem "começa em" é recusada', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'A', 'valor' => 1])) === 'Informe quando a cobrança começa.');
    ok('serviço sem data é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'servico', 'descricao' => 'A', 'valor' => 1])) === 'Informe a data da cobrança.');
    ok('contrato de outra conta é recusado', recusa(fn() => V::salvar(304, 0, $cli, ['tipo' => 'servico', 'descricao' => 'A', 'valor' => 1, 'inicio' => $ini, 'contrato_id' => 999999999])) !== null);

    $a = V::salvar(304, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'ZZTESTE mensalidade', 'valor' => '2.600,00', 'dia_vencimento' => 28, 'inicio' => $ini]);
    $criados[] = $a;
    $b = V::salvar(304, 0, $cli, ['tipo' => 'fixo', 'descricao' => 'ZZTESTE implantação', 'valor' => 1000, 'dia_vencimento' => 5, 'inicio' => $ini, 'fim' => '2030-01-01']);
    $criados[] = $b;
    $s = V::salvar(304, 0, $cli, ['tipo' => 'servico', 'descricao' => 'ZZTESTE criação do site', 'valor' => '3.500,00', 'inicio' => $ini]);
    $criados[] = $s;
    $d = V::doCliente(304, $cli);
    $mine = array_values(array_filter($d['itens'], fn($i) => in_array($i['id'], $criados, true)));
    ok('três linhas salvas', count($mine) === 3);
    $fixo = array_values(array_filter($mine, fn($i) => $i['tipo'] === 'fixo'))[0];
    ok('taxa fixa não guarda dia de vencimento nem fim', $fixo['dia_vencimento'] === null && $fixo['fim'] === null);

    $ca = $cobsDe($a);
    ok('mensalidade gerou as cobranças até o fim do mês que vem', count($ca) === 2 && $ca[0]['status'] === 'aberta' && (float) $ca[0]['valor'] === 2600.0);
    ok('serviço gerou uma cobrança', count($cobsDe($s)) === 1);
    ok('gerar de novo não duplica', C::sincronizar(304, $a) === 0 && count($cobsDe($a)) === 2);

    // Paga a primeira, muda o valor: a paga fica, a aberta futura muda.
    C::baixar(304, 0, (int) $ca[0]['id'], ['pago_em' => $ini, 'forma_pagamento' => 'pix']);
    ok('baixa marca paga com o valor cheio', ($x = $cobsDe($a, 'paga')) && (float) $x[0]['valor_pago'] === 2600.0 && $x[0]['forma_pagamento'] === 'pix');
    ok('baixa repetida é recusada', recusa(fn() => C::baixar(304, 0, (int) $ca[0]['id'], [])) !== null);
    ok('outra conta não dá baixa', recusa(fn() => C::baixar(476, 0, (int) $ca[1]['id'], [])) !== null);
    V::salvar(304, 0, $cli, ['id' => $a, 'tipo' => 'mensal', 'descricao' => 'ZZTESTE mensalidade', 'valor' => 2800, 'dia_vencimento' => 28, 'inicio' => $ini]);
    $ca2 = $cobsDe($a);
    ok('editar refaz a aberta e mantém a paga', (float) $ca2[0]['valor'] === 2600.0 && $ca2[0]['status'] === 'paga' && (float) $ca2[1]['valor'] === 2800.0);

    V::salvar(304, 0, $cli, ['id' => $a, 'tipo' => 'mensal', 'descricao' => 'ZZTESTE mensalidade', 'valor' => 2800, 'dia_vencimento' => 28, 'inicio' => $ini, 'ativo' => false]);
    ok('encerrar cancela a aberta futura', $cobsDe($a, 'aberta') === [] && count($cobsDe($a, 'cancelada')) === 1);
    $d2 = V::doCliente(304, $cli);
    ok('encerrar tira do total por mês', $d2['resumo']['por_mes'] === round($d['resumo']['por_mes'] - 2600, 2));
    V::salvar(304, 0, $cli, ['id' => $a, 'tipo' => 'mensal', 'descricao' => 'ZZTESTE mensalidade', 'valor' => 2800, 'dia_vencimento' => 28, 'inicio' => $ini, 'ativo' => true]);
    ok('reativar devolve a cobrança cancelada', count($cobsDe($a, 'aberta')) === 1);

    C::desfazer(304, (int) $ca[0]['id']);
    ok('desfazer volta para em aberto', $cobsDe($a, 'paga') === []);
    C::baixar(304, 0, (int) $ca[0]['id'], ['valor_pago' => '2.000,00']);
    $tot = C::doCliente(304, $cli);
    ok('cobranças do cliente trazem totais', isset($tot['totais']['aberto']) && $tot['totais']['recebido_ano'] >= 2000.0);
    $painel = C::painel(304, 'abertas');
    ok('painel de Finanças lista as abertas da conta', in_array((int) $ca2[1]['id'], array_column($painel['itens'], 'id'), true));
    ok('painel de outra conta não vê', !in_array((int) $ca2[1]['id'], array_column(C::painel(476, 'abertas')['itens'], 'id'), true));

    ok('remover valor com cobrança paga é recusado', recusa(fn() => V::remover(304, $cli, $a)) !== null);
    ok('outra conta não lê o cliente', recusa(fn() => V::doCliente(476, $cli)) !== null);
    ok('outra conta não grava no cliente', recusa(fn() => V::salvar(476, 0, $cli, ['tipo' => 'mensal', 'descricao' => 'X', 'valor' => 1, 'inicio' => $ini])) !== null);
    ok('outra conta não remove', recusa(fn() => V::remover(476, $cli, $b)) !== null);
    V::remover(304, $cli, $b);
    ok('remover apaga a linha e as cobranças abertas dela', !in_array($b, array_column(V::doCliente(304, $cli)['itens'], 'id'), true) && $cobsDe($b) === []);

    // Fechamento sem dobrar: um card fechado da conta passa a apontar para um cliente sem valores.
    $card = $pdo->query("SELECT id, cliente_id FROM cards WHERE account_id = 304 AND deleted_at IS NULL AND status = 'fechado' ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    // Cliente provisório (apagado no fim), sem valor nenhum.
    $setor = (int) $pdo->query("SELECT setor_id FROM clientes WHERE id = $cli")->fetchColumn();
    $pdo->prepare("INSERT INTO clientes (account_id, setor_id, nome) VALUES (304, ?, 'ZZTESTE cliente provisório')")->execute([$setor]);
    $cli2 = (int) $pdo->lastInsertId();
    if ($card && $cli2) {
        $cardOrig = $card;
        $conta = fn() => (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE id = {$card['id']}" . C::sqlFechamentoSemDuplicar())->fetchColumn();
        $pdo->exec("UPDATE cards SET cliente_id = $cli2 WHERE id = {$card['id']}");
        ok('card fechado de cliente sem valores conta pelo fechamento', $conta() === 1);
        $dv = V::salvar(304, 0, $cli2, ['tipo' => 'demanda', 'descricao' => 'ZZTESTE por demanda', 'valor' => 100]);
        $criados[] = $dv;
        ok('cliente só com "por demanda" continua contando pelo fechamento', $conta() === 1);
        $mv = V::salvar(304, 0, $cli2, ['tipo' => 'mensal', 'descricao' => 'ZZTESTE mensal', 'valor' => 100, 'inicio' => $ini]);
        $criados[] = $mv;
        ok('cliente com mensalidade sai da soma de fechamentos', $conta() === 0);
    }
} catch (\Throwable $e) {
    $FALHAS[] = $e->getMessage(); echo '  [FALHA] ' . $e->getMessage() . "\n";
} finally {
    if (!empty($cardOrig)) $pdo->prepare('UPDATE cards SET cliente_id = ? WHERE id = ?')->execute([$cardOrig['cliente_id'], $cardOrig['id']]);
    if (!empty($cli2)) { $pdo->exec("DELETE FROM crm_cliente_cobrancas WHERE cliente_id = $cli2"); $pdo->exec("DELETE FROM crm_cliente_valores WHERE cliente_id = $cli2"); $pdo->exec("DELETE FROM clientes WHERE id = $cli2 AND nome = 'ZZTESTE cliente provisório'"); }
    if ($criados) {
        $in = implode(',', array_map('intval', $criados));
        $pdo->exec("DELETE FROM crm_cliente_cobrancas WHERE valor_id IN ($in)");
        $pdo->exec("DELETE FROM crm_cliente_valores WHERE id IN ($in)");
    }
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
