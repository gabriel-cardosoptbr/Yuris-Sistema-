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
// As seções com banco GRAVAM (linhas marcadas, apagadas no fim). No servidor
// (Docker, produção) só rodam com TESTE_NO_SERVIDOR=1, como orcamento_crm e
// agenda_lead; e sem banco no ar (Database encerraria o script) também pulam.
$semBanco = null;
if (file_exists('/.dockerenv') && getenv('TESTE_NO_SERVIDOR') !== '1') $semBanco = 'no servidor (produção) a parte com banco não roda';
elseif (!($s = @fsockopen(\App\Core\EnvLoader::get('DB_HOST', '127.0.0.1'), (int) \App\Core\EnvLoader::get('DB_PORT', '3306'), $en, $es, 2))) $semBanco = 'banco fora do ar';
else fclose($s);
$pdo = $semBanco ? null : Database::getConnection();
$tem = $semBanco ? 0 : (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_itens'")->fetchColumn();
$contas = $semBanco ? [] : $pdo->query("SELECT id FROM accounts ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if ($semBanco) { echo "  [pulado] $semBanco\n"; }
elseif (!$tem) { echo "  (pulado: rode a migration 146)\n"; }
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

echo "\n== 8. Contas: rateio do consumo (migration 147) ==\n";
use App\Projetos\Conta;
use App\Projetos\Acesso;
$ct = fn(array $x) => $x + ['id' => 0, 'status' => 'ativa', 'receita_mensal' => null, 'moeda' => 'BRL', 'usuarios' => null,
                            'peso_consumo' => '1.00', 'custo_direto' => null, 'inicio' => null];
$r = Conta::rateio(10000, [$ct(['id' => 1]), $ct(['id' => 2, 'peso_consumo' => '3.00']), $ct(['id' => 3, 'status' => 'cancelada'])], 5.0);
ok('100,00 pelo peso 1:3 = 25 e 75; cancelada não consome', $r[1]['custo'] === 2500 && $r[2]['custo'] === 7500 && $r[3]['custo'] === 0);
$r = Conta::rateio(10000, [$ct(['id' => 1]), $ct(['id' => 2]), $ct(['id' => 3])], 5.0);
ok('100,00 em 3 contas soma exata (33,34 + 33,33 + 33,33)', $r[1]['custo'] + $r[2]['custo'] + $r[3]['custo'] === 10000);
$r = Conta::rateio(0, [$ct(['id' => 1, 'custo_direto' => '12.50', 'receita_mensal' => '300.00']), $ct(['id' => 2, 'status' => 'teste', 'receita_mensal' => '99.00'])], 5.0);
ok('custo direto entra só na conta dela', $r[1]['custo'] === 1250 && $r[1]['direto'] === 1250 && $r[2]['custo'] === 0);
ok('receita só de conta que paga (em teste não conta)', $r[1]['receita'] === 30000 && $r[2]['receita'] === 0);
ok('conta em dólar convertida', Conta::brl('10.00', 'USD', 5.0) === 5000 && Conta::brl(null, 'BRL', 5.0) === null);
[$dc, $ec] = Conta::validar(['nome' => 'Escritório X', 'receita_mensal' => '249,00', 'usuarios' => '5', 'peso_consumo' => '2', 'custo_direto' => '']);
ok('conta válida: receita 249,00, peso 2, sem custo direto', $ec === null && $dc['receita_mensal_centavos'] === 24900 && $dc['peso_consumo'] === 2.0 && $dc['custo_direto_centavos'] === null);
ok('conta sem nome ou situação inválida é recusada', Conta::validar(['nome' => ''])[1] !== null && Conta::validar(['nome' => 'a', 'status' => 'x'])[1] !== null);

$projC = ['tipo' => 'saas', 'status' => 'producao', 'cliente' => null, 'descricao' => 'x', 'url' => 'https://x', 'servidor_id' => 1,
          'stack' => 'PHP', 'responsavel' => 'G', 'usuarios_ativos' => null];
$contasC = [$ct(['id' => 1, 'receita_mensal' => '249.00', 'usuarios' => 4]), $ct(['id' => 2, 'usuarios' => 6, 'custo_direto' => '10.00'])];
$c = P::calcular($projC, [$it(['valor_unitario' => '90.00'])], 10000, ['nome' => 'EC2', 'valor_mensal' => '100.00'], 5.5, '2026-10-14', $contasC);
ok('projeto: receita das contas (249) e custo 90 + 100 + 10 direto', $c['receita_mensal'] === 249.0 && $c['custo_mensal'] === 200.0);
ok('cada conta leva metade do compartilhado (95) + o direto', $c['contas'][1]['custo'] === 95.0 && $c['contas'][2]['custo'] === 105.0);
ok('custo por usuário da conta: 95 / 4 = 23,75', $c['contas'][1]['custo_por_usuario'] === 23.75);
ok('usuários do projeto vêm da soma das contas (10)', $c['usuarios'] === 10 && $c['custo_por_usuario'] === 20.0);
ok('conta ativa sem receita vira pendência', in_array('Quanto paga 1 conta ativa', $c['pendencias'], true));
$cMae = P::calcular(['tipo' => 'cliente', 'cliente' => 'A', 'servidor_id' => null, 'stack' => null, 'url' => null] + $projC, [], 0, null, 5.5, '2026-10-14', [], true);
ok('mãe com subprojetos não cobra servidor, endereço, stack nem receita', !array_intersect(['Onde roda (servidor)', 'Endereço de produção', 'Stack (tecnologia)', 'Receita cadastrada'], $cMae['pendencias']));

echo "\n== 9. Evolução e soma da mãe ==\n";
$evo = P::evolucao(['inicio' => '2026-09-15'], [
    $it(['natureza' => 'receita', 'categoria' => 'mensalidade', 'valor_unitario' => '500.00', 'inicio' => '2026-08-01']),
    $it(['valor_unitario' => '120.00', 'recorrencia' => 'anual']),
    $it(['natureza' => 'receita', 'categoria' => 'implantacao', 'valor_unitario' => '3000.00', 'recorrencia' => 'unico', 'inicio' => '2026-09-10']),
], [$ct(['id' => 9, 'receita_mensal' => '100.00', 'inicio' => '2026-10-01'])], 2000, 5.5, '2026-10-14', 4);
ok('4 meses, do mais antigo ao atual', array_column($evo, 'mes') === ['2026-07', '2026-08', '2026-09', '2026-10']);
ok('julho: só o anual (10/mês), sem servidor (projeto começa em setembro)', $evo[0]['receita'] === 0 && $evo[0]['custo'] === 1000);
ok('setembro: mensalidade + implantação única; custo com servidor', $evo[2]['receita'] === 350000 && $evo[2]['custo'] === 3000);
ok('outubro: mensalidade + conta nova', $evo[3]['receita'] === 60000);
$mae = ['id' => 1, 'parent_id' => null, 'status' => 'producao', 'calc' => $c, 'evolucao' => [['mes' => '2026-10', 'receita' => 10.0, 'custo' => 5.0]]];
$f1 = ['id' => 2, 'parent_id' => 1, 'status' => 'producao', 'calc' => $c, 'evolucao' => [['mes' => '2026-10', 'receita' => 1.0, 'custo' => 2.0]]];
$f2 = ['id' => 3, 'parent_id' => 1, 'status' => 'encerrado', 'calc' => $c, 'evolucao' => [['mes' => '2026-10', 'receita' => 99.0, 'custo' => 99.0]]];
$ag = P::agregar([$mae, $f1, $f2]);
ok('mãe soma ela + subprojetos ativos (encerrado fora)', $ag[0]['agregado']['receita_mensal'] === 498.0 && $ag[0]['agregado']['subprojetos'] === 1);
ok('evolução da mãe somada mês a mês', $ag[0]['agregado']['evolucao'][0]['receita'] === 11.0 && $ag[0]['filhos'] === [2, 3]);
ok('subprojeto sem filhos: agregado = os próprios números', $ag[1]['agregado']['receita_mensal'] === 249.0 && $ag[1]['filhos'] === []);

echo "\n== 10. Banco: hierarquia, contas, acessos e isolamento ==\n";
$tem147 = $semBanco ? 0 : (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projeto_acessos'")->fetchColumn();
if ($semBanco) { echo "  [pulado] $semBanco\n"; }
elseif (!$tem147 || count($contas) < 2) { echo "  (pulado: rode a migration 147 e tenha duas contas)\n"; }
else {
    [$a, $b] = array_map('intval', $contas);
    $marca = 'teste-proj2-' . bin2hex(random_bytes(3));
    try {
        [$dm] = P::validarProjeto(['nome' => "$marca mae", 'tipo' => 'cliente']);
        $mae = P::criarProjeto($a, $dm, null);
        [$df] = P::validarProjeto(['nome' => "$marca filho", 'tipo' => 'site', 'parent_id' => $mae]);
        $filho = P::criarProjeto($a, $df, null);
        $paiDe = fn(int $id) => $pdo->query("SELECT parent_id FROM projetos WHERE id = $id")->fetchColumn();
        ok('subprojeto criado dentro da mãe', (int) $paiDe($filho) === $mae);
        [$dn] = P::validarProjeto(['nome' => "$marca neto", 'tipo' => 'site', 'parent_id' => $filho]);
        $neto = P::criarProjeto($a, $dn, null);
        ok('só um nível: subprojeto não vira mãe (neto fica no primeiro nível)', $paiDe($neto) === null);
        [$dm2] = P::validarProjeto(['nome' => "$marca mae", 'tipo' => 'cliente', 'parent_id' => $neto]);
        P::atualizarProjeto($mae, [$a], $dm2);
        ok('projeto com subprojetos não vira subprojeto', $paiDe($mae) === null);
        [$dx] = P::validarProjeto(['nome' => "$marca de fora", 'tipo' => 'site', 'parent_id' => $mae]);
        $deFora = P::criarProjeto($b, $dx, null);
        ok('mãe de outra conta não vale', $paiDe($deFora) === null);

        [$dc] = Conta::validar(['nome' => 'Conta A', 'receita_mensal' => '200,00', 'usuarios' => '2']);
        $cid = Conta::criar($filho, [$a], $dc, null);
        ok('conta criada no subprojeto; outra conta não cria nele', $cid > 0 && Conta::criar($filho, [$b], $dc, null) === null);
        ok('outra conta não altera nem exclui a conta', !Conta::atualizar($cid, [$b], $dc) && !Conta::excluir($cid, [$b]));

        [$da] = Acesso::validar(['rotulo' => 'Painel Master', 'url' => 'https://x.test/master', 'usuario' => 'admin', 'senha' => 'S3nh@-teste']);
        $aid = Acesso::criar($filho, [$a], $da, null);
        $enc = (string) $pdo->query("SELECT senha_enc FROM projeto_acessos WHERE id = $aid")->fetchColumn();
        ok('senha gravada cifrada (o texto não aparece no banco)', $enc !== '' && !str_contains($enc, 'S3nh@-teste'));
        ok('revelar devolve a senha; outra conta não revela', Acesso::revelar($aid, [$a]) === 'S3nh@-teste' && Acesso::revelar($aid, [$b]) === null);
        [$da2] = Acesso::validar(['rotulo' => 'Painel Master', 'url' => 'https://x.test/master', 'senha' => '']);
        Acesso::atualizar($aid, [$a], $da2);
        ok('editar com senha em branco mantém a guardada', Acesso::revelar($aid, [$a]) === 'S3nh@-teste');
        [$da3] = Acesso::validar(['rotulo' => 'Painel Master', 'limpar_senha' => true]);
        Acesso::atualizar($aid, [$a], $da3);
        ok('"apagar senha" apaga', Acesso::revelar($aid, [$a]) === null);

        $painel = P::painel([$a], 5.0);
        $pm = array_values(array_filter($painel['projetos'], fn($x) => $x['id'] === $mae))[0] ?? null;
        $pf = array_values(array_filter($painel['projetos'], fn($x) => $x['id'] === $filho))[0] ?? null;
        ok('painel: mãe traz o filho e soma a receita da conta dele', $pm && $pm['filhos'] === [$filho] && $pm['agregado']['receita_mensal'] === 200.0);
        ok('painel: acesso sai sem senha, só tem_senha', $pf && !isset($pf['acessos'][0]['senha_enc']) && $pf['acessos'][0]['tem_senha'] === false);

        $log = Conta::sincronizar($pdo, $a, $filho, [['identificador' => 'conta #999', 'nome' => 'Medida', 'plano' => 'Equipe', 'status' => 'ativa',
            'usuarios' => 3, 'custo_direto_centavos' => 550, 'inicio' => '2026-01-01', 'metricas' => ['mensagens_30d' => 10]]], true);
        $sid = (int) $pdo->query("SELECT id FROM projeto_contas WHERE projeto_id = $filho AND identificador = 'conta #999'")->fetchColumn();
        $pdo->prepare("UPDATE projeto_contas SET receita_mensal = 249.00 WHERE id = ?")->execute([$sid]);
        Conta::sincronizar($pdo, $a, $filho, [['identificador' => 'conta #999', 'nome' => 'Medida', 'plano' => 'Equipe', 'status' => 'ativa',
            'usuarios' => 4, 'custo_direto_centavos' => 700, 'inicio' => '2026-01-01', 'metricas' => ['mensagens_30d' => 12]]], true);
        $row = $pdo->query("SELECT usuarios, custo_direto, receita_mensal, origem, (SELECT COUNT(*) FROM projeto_contas WHERE projeto_id = $filho AND identificador = 'conta #999') n FROM projeto_contas WHERE id = $sid")->fetch(PDO::FETCH_ASSOC);
        ok('sincronização cria e depois atualiza a mesma conta (sem duplicar)', (int) $row['n'] === 1 && (int) $row['usuarios'] === 4 && (float) $row['custo_direto'] === 7.0);
        ok('sincronização não mexe na receita que a pessoa preencheu', (float) $row['receita_mensal'] === 249.0 && $row['origem'] === 'sync');

        ok('excluir a mãe solta o filho e apaga contas e acessos dela', P::excluirProjeto($mae, [$a]) && $paiDe($filho) === null);
        P::excluirProjeto($filho, [$a]);
        ok('excluir projeto leva contas e acessos', (int) $pdo->query("SELECT COUNT(*) FROM projeto_contas WHERE projeto_id = $filho AND deleted_at IS NULL")->fetchColumn() === 0
            && (int) $pdo->query("SELECT COUNT(*) FROM projeto_acessos WHERE projeto_id = $filho AND deleted_at IS NULL")->fetchColumn() === 0);
    } finally {
        $ids = $pdo->query("SELECT id FROM projetos WHERE nome LIKE '$marca%'")->fetchAll(PDO::FETCH_COLUMN) ?: [0];
        $in = implode(',', array_map('intval', $ids));
        foreach (['projeto_itens', 'projeto_contas', 'projeto_acessos'] as $t) $pdo->exec("DELETE FROM $t WHERE projeto_id IN ($in)");
        $pdo->exec("DELETE FROM projetos WHERE nome LIKE '$marca%'");
    }
    ok('limpeza: nada do teste ficou no banco', (int) $pdo->query("SELECT COUNT(*) FROM projetos WHERE nome LIKE '$marca%'")->fetchColumn() === 0);
}

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
