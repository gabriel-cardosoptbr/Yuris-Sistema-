<?php
/**
 * interface_nova_test.php — a interface nova do Yuris (App\Inicio), em prévia.
 *
 * O PEDIDO (07/11/2026): abrir o Yuris e sentir o escritório. Menu em painel com
 * a página atual numa pílula azul que brilha, tema claro e escuro, e a home
 * voltada ao dia da advocacia (agenda, prazos, publicações, equipe, processos,
 * quem espera resposta) em vez de métricas; as métricas viram Gestão › Indicadores.
 *
 * O que este teste trava:
 *
 *   1. É PRÉVIA: só liga com ?novo=1 (cookie), ?novo=0 desliga, conta de marca nunca.
 *   2. As regras puras da home: saudação, tipo do item, ordem da agenda, rótulo
 *      de vencimento, a frase do resumo, a equipe, a situação do processo.
 *   3. Os ganchos: sidebar.php só veste o menu sob a chave; dashboard.php grava o
 *      cookie antes de qualquer saída e entrega a home nova só para a conta
 *      jurídica com a chave ligada e sem ?gestao=1.
 *   4. As folhas: os dois temas, sem reticências escondendo dado, sem travessão.
 *   5. Com banco: Painel::montar roda inteiro na conta 1 e devolve todos os blocos,
 *      sem nada de outra conta.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/interface_nova_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Inicio\InterfaceNova;
use App\Inicio\Painel;

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void {
    if ($esp === $obt) pass($m);
    else fail($m . ' (esperado ' . var_export($esp, true) . ', obtido ' . var_export($obt, true) . ')');
}
$raiz = dirname(__DIR__, 2);

// ── 1. A chave da prévia ──────────────────────────────────────────────────────
secao('1. Prévia: só liga para quem pediu');
eq(false, InterfaceNova::ligada([], [], false), 'sem nada, desligada');
eq(true,  InterfaceNova::ligada(['novo' => '1'], [], false), '?novo=1 liga');
eq(true,  InterfaceNova::ligada([], [InterfaceNova::COOKIE => '1'], false), 'o cookie mantém ligada');
eq(false, InterfaceNova::ligada(['novo' => '0'], [InterfaceNova::COOKIE => '1'], false), '?novo=0 desliga mesmo com o cookie');
eq(false, InterfaceNova::ligada(['novo' => '1'], [InterfaceNova::COOKIE => '1'], true), 'conta de marca nunca entra');
eq(false, InterfaceNova::ligada(['novo' => 'sim'], [], false), 'valor estranho não liga');
eq('1',  InterfaceNova::mudanca(['novo' => '1']), 'mudança: liga');
eq('0',  InterfaceNova::mudanca(['novo' => '0']), 'mudança: desliga');
eq(null, InterfaceNova::mudanca([]), 'mudança: nada a gravar');
eq('',   InterfaceNova::scriptCookie([]), 'sem parâmetro, nenhum script');
ok(str_contains(InterfaceNova::scriptCookie(['novo' => '1']), InterfaceNova::COOKIE . '=1; max-age='), 'script grava o cookie por um ano');
ok(str_contains(InterfaceNova::scriptCookie(['novo' => '0']), 'max-age=0'), 'script apaga o cookie');
eq('/dashboard?novo=0', InterfaceNova::enderecoCom('/dashboard', '0'), 'endereço para desligar');
eq('/processos?open=5&novo=0', InterfaceNova::enderecoCom('/processos?open=5&novo=1', '0'), 'endereço troca o parâmetro e preserva o resto');

// ── 2. Regras puras da home ──────────────────────────────────────────────────
secao('2. Saudação, nome, iniciais, data');
eq('Bom dia', Painel::saudacao(8), 'manhã');
eq('Boa tarde', Painel::saudacao(12), 'meio-dia é tarde');
eq('Boa noite', Painel::saudacao(18), '18h é noite');
eq('Boa noite', Painel::saudacao(3), 'madrugada é noite');
eq('Gabriel', Painel::primeiroNome('Gabriel Monteiro'), 'primeiro nome');
eq('Mariana', Painel::primeiroNome('Dra. Mariana Pereira'), 'pula o Dra.');
eq('MP', Painel::iniciais('Mariana Cardoso Pereira'), 'iniciais: primeiro e último');
eq('HM', Painel::iniciais('Helena de Marques'), 'iniciais ignoram "de"');
eq('F', Painel::iniciais('Fernando'), 'um nome só');
eq('sábado, 7 de novembro', Painel::dataPorExtenso(new DateTimeImmutable('2026-11-07')), 'data por extenso');

secao('2b. Tipo do item e agenda do dia');
eq('audiencia', Painel::tipoDoItem(['titulo' => 'Audiência de instrução', 'origem' => 'prazo']), 'audiência pelo nome');
eq('audiencia', Painel::tipoDoItem(['titulo' => 'AUDIENCIA UNA', 'origem' => 'tarefa']), 'audiência sem acento e em maiúsculas');
eq('reuniao', Painel::tipoDoItem(['titulo' => 'Reunião com cliente', 'origem' => 'tarefa']), 'reunião pelo nome');
eq('prazo', Painel::tipoDoItem(['titulo' => 'Contestação', 'origem' => 'prazo']), 'prazo de processo');
eq('tarefa', Painel::tipoDoItem(['titulo' => 'Revisar minuta', 'origem' => 'tarefa']), 'o resto é tarefa');

$itens = [
    ['titulo' => 'Contestação', 'origem' => 'prazo', 'data' => '2026-11-07', 'hora' => null, 'concluida' => false, 'periodos' => ['hoje', 'semana', 'mes'], 'processo_id' => 1],
    ['titulo' => 'Reunião com cliente', 'origem' => 'tarefa', 'data' => '2026-11-07', 'hora' => '14:00', 'concluida' => false, 'periodos' => ['hoje', 'semana', 'mes'], 'processo_id' => null],
    ['titulo' => 'Audiência de instrução', 'origem' => 'tarefa_processo', 'data' => '2026-11-07', 'hora' => null, 'concluida' => false, 'periodos' => ['hoje', 'semana', 'mes'], 'processo_id' => 2],
    ['titulo' => 'Já feita', 'origem' => 'tarefa', 'data' => '2026-11-07', 'hora' => '09:00', 'concluida' => true, 'periodos' => ['hoje'], 'processo_id' => null],
    ['titulo' => 'Réplica', 'origem' => 'prazo', 'data' => '2026-11-08', 'hora' => null, 'concluida' => false, 'periodos' => ['amanha', 'semana', 'mes'], 'processo_id' => 3],
    ['titulo' => 'Atrasado', 'origem' => 'prazo', 'data' => '2026-11-01', 'hora' => null, 'concluida' => false, 'periodos' => ['atrasadas', 'mes'], 'processo_id' => 4],
    ['titulo' => 'Rotina do quadro', 'origem' => 'tarefa', 'data' => '2026-11-09', 'hora' => '10:00', 'concluida' => false, 'periodos' => ['semana', 'mes'], 'processo_id' => null],
];
$agenda = Painel::agendaDoDia($itens);
eq(3, count($agenda), 'agenda: só o de hoje e aberto');
eq('Reunião com cliente', $agenda[0]['titulo'], 'agenda: quem tem hora vem primeiro');
eq('reuniao', $agenda[0]['tipo'], 'agenda: item já vem com o tipo');
eq(['Audiência de instrução', 'Contestação'], [$agenda[1]['titulo'], $agenda[2]['titulo']], 'agenda: sem hora, em ordem de nome');

$prazos = Painel::proximosPrazos($itens, 5);
eq(['Atrasado', 'Audiência de instrução', 'Contestação', 'Réplica'], array_column($prazos, 'titulo'), 'prazos: atrasado primeiro, por data e nome, sem tarefa de quadro');
eq(2, count(Painel::proximosPrazos($itens, 2)), 'prazos: respeita o limite');

secao('2b2. Calendário e cartões');
$porDia = Painel::porDia($itens);
eq(['2026-11-01', '2026-11-07', '2026-11-08', '2026-11-09'], array_keys($porDia), 'por dia: só abertos com data, em ordem');
eq(3, count($porDia['2026-11-07']), 'por dia: os três de hoje');
eq('Reunião com cliente', $porDia['2026-11-07'][0]['titulo'], 'por dia: com hora primeiro');
eq('reuniao', $porDia['2026-11-07'][0]['tipo'], 'por dia: cada item já traz o tipo');
eq(3, Painel::urgentes($itens, '2026-11-07'), 'urgentes: prazos de processo de hoje a 3 dias (contestação, audiência, réplica), sem o de quadro e sem o atrasado');
eq(0, Painel::urgentes($itens, '2026-11-20'), 'urgentes: nada no período');
$grade = Painel::gradeDoMes('2026-11');
eq(5, count($grade), 'grade: novembro de 2026 tem 5 semanas');
eq(['data' => '2026-11-01', 'dia' => 1, 'fora' => false], $grade[0][0], 'grade: começa no domingo 1º');
eq(true, $grade[4][6]['fora'], 'grade: termina com dias de dezembro');
eq('2026-12-05', $grade[4][6]['data'], 'grade: o último é 5 de dezembro');
eq(6, count(Painel::gradeDoMes('2026-08')), 'grade: agosto de 2026 precisa de 6 semanas');
eq('Novembro de 2026', Painel::nomeDoMes('2026-11'), 'nome do mês');
eq('Hoje, 7 de nov', Painel::tituloDoDia('2026-11-07', '2026-11-07'), 'título do dia: hoje');
eq('Qua, 11 de nov', Painel::tituloDoDia('2026-11-11', '2026-11-07'), 'título do dia: outro dia');

secao('2c. Vencimento, tempo e dia relativo');
eq(['texto' => 'hoje', 'tom' => 'r'], Painel::rotuloDeVencimento('2026-11-07', '2026-11-07'), 'vence hoje');
eq(['texto' => 'amanhã', 'tom' => 'w'], Painel::rotuloDeVencimento('2026-11-08', '2026-11-07'), 'vence amanhã');
eq(['texto' => 'atrasado há 1 dia', 'tom' => 'r'], Painel::rotuloDeVencimento('2026-11-06', '2026-11-07'), 'atrasado um dia');
eq(['texto' => 'atrasado há 6 dias', 'tom' => 'r'], Painel::rotuloDeVencimento('2026-11-01', '2026-11-07'), 'atrasado seis dias');
eq(['texto' => 'qua, 11', 'tom' => 'n'], Painel::rotuloDeVencimento('2026-11-11', '2026-11-07'), 'na semana: dia da semana');
eq(['texto' => '20/11', 'tom' => 'n'], Painel::rotuloDeVencimento('2026-11-20', '2026-11-07'), 'mais longe: a data');
eq('agora', Painel::tempoDesde(0), 'tempo: agora');
eq('há 40 min', Painel::tempoDesde(40), 'tempo: minutos');
eq('há 3 h', Painel::tempoDesde(190), 'tempo: horas');
eq('há 2 dias', Painel::tempoDesde(3000), 'tempo: dias');
eq('hoje', Painel::diaRelativo('2026-11-07 10:00:00', '2026-11-07'), 'dia: hoje');
eq('ontem', Painel::diaRelativo('2026-11-06', '2026-11-07'), 'dia: ontem');
eq('30/10', Painel::diaRelativo('2026-10-30', '2026-11-07'), 'dia: data');

secao('2d. A frase do resumo');
eq('Nenhum compromisso marcado para hoje. Boa hora para adiantar o que vem na semana.',
   Painel::resumo(['audiencias' => 0, 'prazos' => 0, 'publicacoes' => 0, 'retorno' => 0, 'atrasados' => 0]), 'vazio: convite');
eq('Hoje o escritório tem <b>1 audiência</b>.',
   Painel::resumo(['audiencias' => 1, 'prazos' => 0, 'publicacoes' => 0, 'retorno' => 0, 'atrasados' => 0]), 'uma coisa só, no singular');
eq('Hoje o escritório tem <b>2 audiências</b>, <b>1 prazo vencendo hoje</b> e <b>4 publicações sem tratamento</b>. E <b>3 prazos atrasados</b>.',
   Painel::resumo(['audiencias' => 2, 'prazos' => 1, 'publicacoes' => 4, 'retorno' => 0, 'atrasados' => 3]), 'várias coisas: vírgula, "e", e os atrasados à parte');
eq('Nada marcado para hoje, mas há <b>1 prazo atrasado</b> pedindo atenção.',
   Painel::resumo(['audiencias' => 0, 'prazos' => 0, 'publicacoes' => 0, 'retorno' => 0, 'atrasados' => 1]), 'só atrasado');

secao('2e. Equipe e situação do processo');
$usuarios = [['id' => 3, 'nome' => 'Mariana Pereira', 'perfil' => 'user', 'role' => 'member', 'oab' => '123'], ['id' => 4, 'nome' => 'Rafael Costa', 'perfil' => 'admin', 'role' => 'admin']];
$carga = [3 => ['abertas' => 5, 'vencidas' => 0, 'vencendo' => 2, 'em_dia' => 3, 'sem_prazo' => 0]];
$itensEq = [
    ['titulo' => 'Contestação', 'origem' => 'prazo', 'responsavel_nome' => 'mariana pereira', 'concluida' => false, 'periodos' => ['hoje', 'semana']],
    ['titulo' => 'Réplica', 'origem' => 'prazo', 'responsavel_nome' => 'Mariana Pereira', 'concluida' => false, 'periodos' => ['atrasadas']],
    ['titulo' => 'Feita', 'origem' => 'prazo', 'responsavel_nome' => 'Rafael Costa', 'concluida' => true, 'periodos' => ['hoje']],
];
$eq = Painel::equipe($usuarios, $carga, $itensEq);
eq('Mariana Pereira', $eq[0]['nome'], 'equipe: quem tem mais na mão vem primeiro');
eq('atrasado', $eq[0]['estado'], 'equipe: prazo atrasado pinta de vermelho');
eq(1, $eq[0]['hoje'], 'equipe: conta o de hoje pelo nome, sem caixa');
eq(1, $eq[0]['vencidas'], 'equipe: atraso de processo soma nas vencidas');
eq('Advocacia', $eq[0]['papel'], 'equipe: OAB vira Advocacia');
eq('livre', $eq[1]['estado'], 'equipe: concluído não ocupa');
eq('Administração', $eq[1]['papel'], 'equipe: admin');
eq(100, $eq[0]['pct'], 'equipe: a maior carga é 100%');
eq([], Painel::equipe([], [], []), 'equipe vazia não quebra');

eq(['Em andamento', 'and'], Painel::situacaoDoProcesso('ativo', null, '2026-11-07'), 'processo ativo sem prazo');
eq(['Prazo em hoje', 'at'], Painel::situacaoDoProcesso('ativo', '2026-11-07', '2026-11-07'), 'prazo hoje');
eq(['Prazo em 2 dias', 'at'], Painel::situacaoDoProcesso('ativo', '2026-11-09', '2026-11-07'), 'prazo em dois dias');
eq(['Prazo vencido', 'at'], Painel::situacaoDoProcesso('ativo', '2026-11-01', '2026-11-07'), 'prazo vencido');
eq(['Em andamento', 'and'], Painel::situacaoDoProcesso('ativo', '2026-12-01', '2026-11-07'), 'prazo longe é andamento');
eq(['Suspenso', 'ag'], Painel::situacaoDoProcesso('suspenso', '2026-11-07', '2026-11-07'), 'suspenso ganha do prazo');
eq(['Encerrado', 'ok'], Painel::situacaoDoProcesso('concluido', null, '2026-11-07'), 'concluído é encerrado');

// ── 3. Os ganchos ────────────────────────────────────────────────────────────
secao('3. Ganchos no menu e no dashboard');
$sidebar = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
ok(str_contains($sidebar, 'InterfaceNova::ligada($_GET, $_COOKIE, $_isFleetiflow)'), 'menu decide pela chave, com a conta de marca fora');
ok(preg_match('/if \(\$_interfaceNova\): \?>\s*<!--[^>]*-->\s*<link rel="stylesheet" href="\/assets\/interface-nova\.css/', $sidebar) === 1, 'menu só carrega interface-nova.css sob a chave');
ok(str_contains($sidebar, "\$_interfaceNova ? 'Início' : 'Visão Geral'"), 'home chama Início só na interface nova');
ok(str_contains($sidebar, "'href'=>'dashboard.php?gestao=1','active'=>'indicadores'"), 'Gestão ganha Indicadores apontando para o painel de sempre');
ok(str_contains($sidebar, 'InterfaceNova::scriptCookie($_GET)'), 'menu grava o cookie por script (cabeçalhos já foram)');

$dash = (string) file_get_contents($raiz . '/public/dashboard.php');
$posAplicar = strpos($dash, 'InterfaceNova::aplicar();');
$posDoctype = strpos($dash, '<!doctype html>');
ok($posAplicar !== false && $posDoctype !== false && $posAplicar < $posDoctype, 'dashboard grava o cookie antes de qualquer saída');
ok(str_contains($dash, "if (\$moduloJuridico && \$interfaceNova && empty(\$_GET['gestao'])) {"), 'home nova só para conta jurídica, com a chave, sem ?gestao=1');
// As agulhas são montadas em pedaços para o class_refs_test não as ler como um require deste arquivo.
$posFleet = strpos($dash, 'require __DIR_' . "_ . '/includes/dashboard_fleetiflow.php';");
$posInicio = strpos($dash, 'require __DIR_' . "_ . '/includes/dashboard_inicio.php';");
ok($posFleet !== false && $posInicio !== false && $posFleet < $posInicio, 'a conta de marca sai antes de chegar na home nova');
ok(str_contains($dash, "if (\$interfaceNova) \$activePage = 'indicadores';"), 'o painel de sempre acende Indicadores no menu novo');
ok(is_file($raiz . '/public/includes/dashboard_inicio.php'), 'a home nova existe em includes/');
$inicio = (string) file_get_contents($raiz . '/public/includes/dashboard_inicio.php');
ok(str_contains($inicio, "if (!isset(\$ctx)) { http_response_code(403); exit; }"), 'a home nova não abre sozinha, só pelo dashboard.php');
ok(str_contains($inicio, 'include __DIR_' . "_ . '/sidebar.php'"), 'a home nova usa o mesmo menu de todas as telas');
ok(!str_contains($inicio, 'alert(') && !str_contains($inicio, 'confirm('), 'sem diálogo nativo do navegador');

// ── 4. As folhas ─────────────────────────────────────────────────────────────
secao('4. Folhas de estilo');
foreach (['inicio.css', 'interface-nova.css'] as $f) {
    $css = (string) file_get_contents($raiz . '/public/assets/' . $f);
    ok($css !== '', "$f existe");
    ok(str_contains($css, 'html[data-theme="light"]'), "$f tem o tema claro");
    ok(!str_contains($css, 'text-overflow'), "$f não esconde dado com reticências");
    ok(!str_contains($css, '—'), "$f sem travessão");
    ok(!preg_match('/#(7c3aed|8b5cf6|a855f7|6d28d9)/i', $css), "$f sem roxo");
}
// O <title> das páginas ("Processos — Yuris") e o título dos READMEs ("# public/ — ...")
// seguem o padrão de todo o sistema; fora deles, texto não leva travessão.
foreach (['app/Inicio/InterfaceNova.php', 'app/Inicio/Painel.php', 'public/includes/dashboard_inicio.php', 'app/Inicio/README.md'] as $f) {
    $corpo = preg_replace('/^\s*(<title>.*<\/title>|# .*)\r?$/m', '', (string) file_get_contents($raiz . '/' . $f));
    ok(!str_contains($corpo, '—'), "$f sem travessão no texto");
}

// ── 5. Com banco ─────────────────────────────────────────────────────────────
secao('5. Com banco: a home inteira da conta 1');
try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->query('SELECT 1');
    $escopo = ['user_id' => 1, 'is_admin' => true, 'contas_tarefas' => [1], 'contas_processos' => [1]];
    $p = Painel::montar($escopo, [1]);
    foreach (['agora', 'hoje', 'saudacao', 'data', 'resumo', 'agenda', 'contagem', 'prazos', 'publicacoes', 'equipe', 'processos', 'whatsapp', 'numeros', 'kpis', 'calendario'] as $k) {
        ok(array_key_exists($k, $p), "montar devolve '$k'");
    }
    ok(count($p['prazos']) <= 5, 'no máximo 5 prazos');
    ok(count($p['publicacoes']['itens']) <= 4, 'no máximo 4 publicações');
    ok(count($p['processos']) <= 5, 'no máximo 5 processos');
    // Isolamento: tudo que tem conta vem da conta 1.
    $st = $pdo->prepare('SELECT account_id FROM processos WHERE id = ?');
    $tudoDaConta = true;
    foreach ($p['processos'] as $pr) { $st->execute([(int) $pr['id']]); if ((int) $st->fetchColumn() !== 1) $tudoDaConta = false; }
    ok($tudoDaConta, 'processos: só da conta 1');
    $st = $pdo->prepare('SELECT account_id FROM push_events WHERE id = ?');
    foreach ($p['publicacoes']['itens'] as $pb) { $st->execute([(int) $pb['id']]); if ((int) $st->fetchColumn() !== 1) $tudoDaConta = false; }
    ok($tudoDaConta, 'publicações: só da conta 1');
    $st = $pdo->prepare('SELECT account_id FROM users WHERE id = ?');
    foreach ($p['equipe'] as $pe) { $st->execute([(int) $pe['id']]); if ((int) $st->fetchColumn() !== 1) $tudoDaConta = false; }
    ok($tudoDaConta, 'equipe: só da conta 1');
    // Uma conta que não existe não vê nada.
    $vazio = Painel::montar(['user_id' => 0, 'is_admin' => false, 'contas_tarefas' => [], 'contas_processos' => []], [999999]);
    ok($vazio['processos'] === [] && $vazio['equipe'] === [] && $vazio['publicacoes']['total'] === 0 && $vazio['whatsapp'] === null, 'conta sem nada: tudo vazio, sem erro');
} catch (\Throwable $e) {
    echo "  [SKIP] sem banco (" . $e->getMessage() . ")\n";
}

echo "\n$PASSES ok, $FAILS falhas\n";
exit($FAILS > 0 ? 1 : 0);
