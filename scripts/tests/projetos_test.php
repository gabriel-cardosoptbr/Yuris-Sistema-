<?php
/**
 * scripts/tests/projetos_test.php — App\Projetos\Projeto (aba Projetos da
 * edição CRM, 14/10/2026).
 *
 * Confere as contas (centavos, conversão do dólar, equivalente mensal, vigência,
 * cobranças vencidas para o arrecadado, rateio do servidor com soma exata), as
 * pendências do dossiê, a validação, a chave por conta, e no banco: criar,
 * painel, item a preencher, servidor rateado, importação sem duplicar nem
 * sobrescrever, e o isolamento entre contas. Apaga o que criou.
 *
 * Precisa da migration 146 e de duas contas quaisquer no banco.
 * Uso: php scripts/tests/projetos_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Projetos\Projeto as P;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

echo "== 1. Dinheiro ==\n";
ok('"1.234,56" vira 123456 centavos', P::centavos('1.234,56') === 123456);
ok('"US$ 20" vira 2000', P::centavos('US$ 20') === 2000);
ok('vazio e texto inválido devolvem null', P::centavos('') === null && P::centavos('vinte') === null && P::centavos(null) === null);
ok('quantidade "2,5" vira 2.5; vazia vira 1', P::quantidade('2,5') === 2.5 && P::quantidade('') === 1.0);
ok('quantidade zero ou inválida é recusada', P::quantidade('0') === null && P::quantidade('abc') === null);
ok('slug sem acento', P::slug('  Gestão Sêller 2.0 ') === 'gestao-seller-2-0');

$it = fn(array $x) => $x + ['natureza' => 'custo', 'categoria' => 'ia', 'descricao' => 'x', 'valor_unitario' => '10.00',
                            'quantidade' => '1.00', 'moeda' => 'BRL', 'recorrencia' => 'mensal', 'inicio' => null, 'fim' => null];
ok('total = valor x quantidade', P::totalItemBrl($it(['quantidade' => '3.00']), 5.5) === 3000);
ok('dólar convertido pela cotação', P::totalItemBrl($it(['moeda' => 'USD', 'valor_unitario' => '20.00']), 5.5) === 11000);
ok('sem valor: null (a preencher, nunca zero)', P::totalItemBrl($it(['valor_unitario' => null]), 5.5) === null);
ok('anual vira 1/12 por mês', P::mensal($it(['recorrencia' => 'anual', 'valor_unitario' => '120.00']), 5.5, '2026-10-14') === 1000);
ok('único não entra no mês', P::mensal($it(['recorrencia' => 'unico']), 5.5, '2026-10-14') === 0);
ok('fora da vigência não entra no mês', P::mensal($it(['fim' => '2026-09-30']), 5.5, '2026-10-14') === 0
    && P::mensal($it(['inicio' => '2026-11-01']), 5.5, '2026-10-14') === 0);

echo "\n== 2. Arrecadado ==\n";
ok('mensal desde 10/08 até 14/10: 3 cobranças', P::cobrancasVencidas('2026-08-10', '2026-10-14', 1) === 3);
ok('no dia do vencimento já conta', P::cobrancasVencidas('2026-08-14', '2026-10-14', 1) === 3);
ok('antes do dia não conta o mês', P::cobrancasVencidas('2026-08-20', '2026-10-14', 1) === 2);
ok('dia 31 em mês de 30 vence no dia 30', P::cobrancasVencidas('2026-08-31', '2026-09-30', 1) === 2);
ok('anual: 1 cobrança no primeiro ano, 2 depois de um ano', P::cobrancasVencidas('2025-11-01', '2026-10-14', 12) === 1
    && P::cobrancasVencidas('2025-10-01', '2026-10-14', 12) === 2);
ok('início no futuro: nada', P::cobrancasVencidas('2026-11-01', '2026-10-14', 1) === 0);
$rec = $it(['natureza' => 'receita', 'categoria' => 'mensalidade', 'valor_unitario' => '500.00', 'inicio' => '2026-08-10']);
ok('mensalidade de 500 desde 10/08: 1.500 arrecadado', P::arrecadado($rec, 5.5, '2026-10-14') === 150000);
ok('com fim, para no fim', P::arrecadado(['fim' => '2026-08-31'] + $rec, 5.5, '2026-10-14') === 50000);
ok('sem início: não calcula (null)', P::arrecadado(['inicio' => null] + $rec, 5.5, '2026-10-14') === null);
ok('implantação única já paga entra uma vez', P::arrecadado(['recorrencia' => 'unico', 'valor_unitario' => '3000.00'] + $rec, 5.5, '2026-10-14') === 300000);

echo "\n== 3. Rateio de servidor ==\n";
$srv = [['id' => 1, 'valor_mensal' => '100.00', 'moeda' => 'BRL'], ['id' => 2, 'valor_mensal' => null, 'moeda' => 'BRL'],
        ['id' => 3, 'valor_mensal' => '10.00', 'moeda' => 'USD']];
$projs = [
    ['id' => 10, 'servidor_id' => 1, 'servidor_peso' => 1, 'status' => 'producao'],
    ['id' => 11, 'servidor_id' => 1, 'servidor_peso' => 1, 'status' => 'producao'],
    ['id' => 12, 'servidor_id' => 1, 'servidor_peso' => 1, 'status' => 'desenvolvimento'],
    ['id' => 13, 'servidor_id' => 1, 'servidor_peso' => 5, 'status' => 'encerrado'],
    ['id' => 20, 'servidor_id' => 2, 'servidor_peso' => 1, 'status' => 'producao'],
    ['id' => 30, 'servidor_id' => 3, 'servidor_peso' => 3, 'status' => 'producao'],
    ['id' => 31, 'servidor_id' => 3, 'servidor_peso' => 1, 'status' => 'pausado'],
];
$r = P::rateioServidores($srv, $projs, 5.5);
ok('100,00 em 3: 33,34 + 33,33 + 33,33 (soma exata)', [$r[10], $r[11], $r[12]] === [3334, 3333, 3333]);
ok('projeto encerrado não paga servidor', !array_key_exists(13, $r));
ok('servidor sem custo: rateio null (a preencher)', array_key_exists(20, $r) && $r[20] === null);
ok('peso 3 x 1 em US$ 10 (R$ 55): 41,25 e 13,75', $r[30] === 4125 && $r[31] === 1375);

echo "\n== 4. Dossiê e pendências ==\n";
$proj = ['tipo' => 'cliente', 'status' => 'producao', 'cliente' => 'Via Autodoc', 'descricao' => 'CRM', 'url' => 'https://x',
         'servidor_id' => 1, 'stack' => 'PHP', 'responsavel' => 'Gabriel', 'usuarios_ativos' => 4];
$itens = [$it(['categoria' => 'ia', 'valor_unitario' => '50.00']),
          $it(['natureza' => 'receita', 'categoria' => 'mensalidade', 'valor_unitario' => '800.00', 'inicio' => '2026-09-01'])];
$c = P::calcular($proj, $itens, 3334, ['nome' => 'EC2', 'valor_mensal' => '100.00'], 5.5, '2026-10-14');
ok('receita 800, custo 50 + 33,34 de servidor', $c['receita_mensal'] === 800.0 && abs($c['custo_mensal'] - 83.34) < 0.001);
ok('margem 716,66 e 89,6%', abs($c['margem_mensal'] - 716.66) < 0.001 && $c['margem_pct'] === 89.6);
ok('custo por usuário 20,84', abs($c['custo_por_usuario'] - 20.84) < 0.001);
ok('arrecadado: setembro e outubro = 1.600', $c['arrecadado'] === 1600.0);
ok('tudo preenchido: 100% e nenhuma pendência', $c['completude'] === 100 && $c['pendencias'] === []);
$c2 = P::calcular(['cliente' => null, 'usuarios_ativos' => null, 'responsavel' => null] + $proj,
    [$it(['valor_unitario' => null, 'descricao' => 'OpenAI'])], null, ['nome' => 'EC2', 'valor_mensal' => null], 5.5, '2026-10-14');
ok('aponta cliente, responsável e usuários faltando', !array_diff(['Cliente', 'Responsável', 'Usuários ativos'], $c2['pendencias']));
ok('aponta receita faltando, valor a preencher e custo do servidor', in_array('Receita cadastrada', $c2['pendencias'], true)
    && in_array('Valor de OpenAI', $c2['pendencias'], true) && in_array('Custo do servidor EC2', $c2['pendencias'], true));
ok('item a preencher não vira zero de custo, mas também não soma', $c2['custo_mensal'] === 0.0 && $c2['completude'] < 60);
$c3 = P::calcular(['tipo' => 'interno', 'cliente' => null, 'usuarios_ativos' => null] + $proj, [], 0, null, 5.5, '2026-10-14');
ok('projeto interno não cobra receita nem usuários', !in_array('Receita cadastrada', $c3['pendencias'], true) && !in_array('Usuários ativos', $c3['pendencias'], true));

echo "\n== 5. Validação ==\n";
[$d, $e] = P::validarProjeto(['nome' => 'CRM X', 'tipo' => 'saas', 'status' => 'producao', 'usuarios_ativos' => '12', 'servidor_peso' => 2]);
ok('projeto certo passa', $e === null && $d['usuarios_ativos'] === 12 && $d['servidor_peso'] === 2 && $d['cliente'] === null);
ok('sem nome é recusado', P::validarProjeto(['nome' => ' '])[1] !== null);
ok('tipo e situação inválidos são recusados', P::validarProjeto(['nome' => 'a', 'tipo' => 'x'])[1] !== null && P::validarProjeto(['nome' => 'a', 'status' => 'x'])[1] !== null);
ok('usuários não inteiro é recusado', P::validarProjeto(['nome' => 'a', 'usuarios_ativos' => '2,5'])[1] !== null);
[$di, $ei] = P::validarItem(['natureza' => 'custo', 'categoria' => 'ia', 'descricao' => 'OpenAI', 'valor_unitario' => '']);
ok('item sem valor passa como a preencher', $ei === null && $di['valor_centavos'] === null);
ok('item com categoria de outra natureza é recusado', P::validarItem(['natureza' => 'receita', 'categoria' => 'ia', 'descricao' => 'x'])[1] !== null);
ok('fim antes do início é recusado', P::validarItem(['natureza' => 'custo', 'categoria' => 'ia', 'descricao' => 'x', 'inicio' => '2026-10-10', 'fim' => '2026-10-01'])[1] !== null);
ok('valor inválido é recusado', P::validarItem(['natureza' => 'custo', 'categoria' => 'ia', 'descricao' => 'x', 'valor_unitario' => 'dez'])[1] !== null);

echo "\n== 6. Módulo ligado por conta ==\n";
$cfg = fn(array $x) => ['configuracoes' => json_encode($x)];
ok('CRM com a chave: ligado', P::habilitado($cfg(['produto' => 'fleetiflow', 'modulos' => ['projetos' => true]])));
ok('CRM sem a chave: desligado', !P::habilitado($cfg(['produto' => 'fleetiflow', 'modulos' => ['reembolsos' => true]])));
ok('Yuris com a chave: desligado, sempre', !P::habilitado($cfg(['modulos' => ['projetos' => true]])));
ok('cotação padrão e cotação gravada', P::cotacaoUsd([]) === P::COTACAO_PADRAO && P::cotacaoUsd($cfg(['projetos' => ['cotacao_usd' => 5.12]])) === 5.12);

echo "\n== 7. Banco, fluxo e isolamento ==\n";
$pdo = Database::getConnection();
$tem = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_itens'")->fetchColumn();
$contas = $pdo->query("SELECT id FROM accounts ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (!$tem) { echo "  (pulado: rode a migration 146)\n"; }
elseif (count($contas) < 2) { echo "  (pulado: precisa de duas contas no banco)\n"; }
else {
    [$a, $b] = array_map('intval', $contas);
    $marca = 'teste-proj-' . bin2hex(random_bytes(3));
    try {
        [$ds] = P::validarServidor(['nome' => "$marca srv", 'valor_mensal' => '90,00']);
        $srvId = P::criarServidor($a, $ds, null);
        [$dp] = P::validarProjeto(['nome' => "$marca projeto", 'tipo' => 'cliente', 'servidor_id' => $srvId]);
        $pid = P::criarProjeto($a, $dp, null);
        [$dp2] = P::validarProjeto(['nome' => "$marca outro", 'tipo' => 'interno', 'servidor_id' => $srvId, 'servidor_peso' => 2]);
        $pid2 = P::criarProjeto($a, $dp2, null);
        [$di] = P::validarItem(['natureza' => 'custo', 'categoria' => 'ia', 'descricao' => 'OpenAI', 'valor_unitario' => '10', 'moeda' => 'USD']);
        $iid = P::criarItem($pid, [$a], $di, null);
        [$dv] = P::validarItem(['natureza' => 'custo', 'categoria' => 'dominio', 'descricao' => 'Domínio', 'valor_unitario' => '']);
        P::criarItem($pid, [$a], $dv, null);

        $painel = P::painel([$a], 5.0, '2026-10-14');
        $meu = array_values(array_filter($painel['projetos'], fn($x) => $x['id'] === $pid))[0] ?? null;
        ok('painel traz o projeto com 2 itens', $meu && count($meu['itens']) === 2);
        ok('servidor de 90 rateado 1:2 = 30 para este projeto', $meu && $meu['calc']['custo_servidor'] === 30.0);
        ok('custo = US$ 10 x 5,00 + 30 de servidor = 80', $meu && $meu['calc']['custo_mensal'] === 80.0);
        ok('domínio sem valor aparece como pendência', $meu && in_array('Valor de Domínio', $meu['calc']['pendencias'], true));
        ok('servidor lista os dois projetos', count(array_values(array_filter($painel['servidores'], fn($s) => $s['id'] === $srvId))[0]['projetos'] ?? []) === 2);

        ok('outra conta não enxerga', !array_filter(P::painel([$b], 5.0)['projetos'], fn($x) => $x['id'] === $pid));
        ok('outra conta não altera projeto', P::atualizarProjeto($pid, [$b], $dp) === false);
        ok('outra conta não cria item no projeto', P::criarItem($pid, [$b], $di, null) === null);
        ok('outra conta não altera nem exclui item', P::atualizarItem($iid, [$b], $di) === false && P::excluirItem($iid, [$b]) === false);
        ok('outra conta não exclui servidor', P::excluirServidor($srvId, [$b]) === false);
        $outroSrv = P::criarServidor($b, $ds, null);
        P::atualizarProjeto($pid, [$a], ['servidor_id' => $outroSrv] + $dp);
        $sid = (int) $pdo->query("SELECT COALESCE(servidor_id, 0) FROM projetos WHERE id = $pid")->fetchColumn();
        ok('projeto não liga em servidor de outra conta', $sid === 0);
        $pdo->prepare("DELETE FROM projeto_servidores WHERE id = ?")->execute([$outroSrv]);

        $dossie = ['servidores' => [['nome' => "$marca srv", 'provedor' => 'AWS', 'valor_mensal' => '500']],
                   'projetos' => [['slug' => P::slug("$marca projeto"), 'nome' => "$marca projeto", 'tipo' => 'cliente',
                                   'cliente' => 'Cliente Novo', 'stack' => 'PHP', 'servidor' => "$marca srv",
                                   'itens' => [['natureza' => 'custo', 'categoria' => 'ia', 'descricao' => 'OpenAI', 'valor_unitario' => '99'],
                                               ['natureza' => 'receita', 'categoria' => 'mensalidade', 'descricao' => 'Mensalidade', 'valor_unitario' => '700']]],
                                  ['nome' => "$marca novo", 'tipo' => 'site']]];
        P::importar($pdo, $a, $dossie, true);
        $s = $pdo->query("SELECT valor_mensal, provedor FROM projeto_servidores WHERE id = $srvId")->fetch(PDO::FETCH_ASSOC);
        ok('importar não sobrescreve custo já preenchido e completa o vazio', (float) $s['valor_mensal'] === 90.0 && $s['provedor'] === 'AWS');
        $pr = $pdo->query("SELECT cliente, stack, servidor_id FROM projetos WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
        ok('importar completa cliente, stack e servidor do projeto existente', $pr['cliente'] === 'Cliente Novo' && $pr['stack'] === 'PHP' && (int) $pr['servidor_id'] === $srvId);
        $n = (int) $pdo->query("SELECT COUNT(*) FROM projeto_itens WHERE projeto_id = $pid AND deleted_at IS NULL")->fetchColumn();
        ok('importar não duplica o item OpenAI e acrescenta a mensalidade', $n === 3);
        P::importar($pdo, $a, $dossie, true);
        $n2 = (int) $pdo->query("SELECT COUNT(*) FROM projeto_itens WHERE projeto_id = $pid AND deleted_at IS NULL")->fetchColumn();
        $np = (int) $pdo->query("SELECT COUNT(*) FROM projetos WHERE account_id = $a AND nome LIKE '$marca%' AND deleted_at IS NULL")->fetchColumn();
        ok('importar de novo não muda nada (idempotente)', $n2 === 3 && $np === 3);

        ok('excluir servidor solta os projetos', P::excluirServidor($srvId, [$a])
            && (int) $pdo->query("SELECT COUNT(*) FROM projetos WHERE servidor_id = $srvId")->fetchColumn() === 0);
        ok('excluir projeto (lógico) leva os itens', P::excluirProjeto($pid, [$a])
            && (int) $pdo->query("SELECT COUNT(*) FROM projeto_itens WHERE projeto_id = $pid AND deleted_at IS NULL")->fetchColumn() === 0);
    } finally {
        $ids = $pdo->query("SELECT id FROM projetos WHERE nome LIKE '$marca%'")->fetchAll(PDO::FETCH_COLUMN) ?: [0];
        $pdo->exec("DELETE FROM projeto_itens WHERE projeto_id IN (" . implode(',', array_map('intval', $ids)) . ")");
        $pdo->exec("DELETE FROM projetos WHERE nome LIKE '$marca%'");
        $pdo->exec("DELETE FROM projeto_servidores WHERE nome LIKE '$marca%'");
    }
    $sobra = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM projetos WHERE nome LIKE '$marca%') + (SELECT COUNT(*) FROM projeto_servidores WHERE nome LIKE '$marca%')")->fetchColumn();
    ok('limpeza: nada do teste ficou no banco', $sobra === 0);
}

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
