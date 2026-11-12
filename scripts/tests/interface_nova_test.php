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
// Desde 11/11/2026 a interface nova vale para todas as contas Yuris ("é para todo mundo, não
// tem mais limite"). A prévia (?novo, cookie, chave por conta) não liga nem desliga mais nada.
secao('1. Para todas as contas Yuris, menos a de marca');
eq(true,  InterfaceNova::ligada([], [], false), 'sem nada: ligada');
eq(true,  InterfaceNova::ligada(['novo' => '0'], [], false), '?novo=0 não desliga mais');
eq(true,  InterfaceNova::ligada(['novo' => '0'], [InterfaceNova::COOKIE => ''], false, false), 'sem cookie e sem chave da conta: ligada');
eq(false, InterfaceNova::ligada(['novo' => '1'], [InterfaceNova::COOKIE => '1'], true), 'conta de marca nunca entra');
eq(false, InterfaceNova::ligada([], [], true, true), 'conta de marca não liga nem com a chave da conta');
eq(true,  InterfaceNova::contaLigadaPelaLinha(['configuracoes' => '{"interface_nova":true,"modulos":{"x":1}}']), 'linha da conta: ligada');
eq(false, InterfaceNova::contaLigadaPelaLinha(['configuracoes' => '{"interface_nova":"sim"}']), 'linha da conta: só true de verdade');
eq(false, InterfaceNova::contaLigadaPelaLinha(['configuracoes' => null]), 'linha da conta: sem configurações');
eq(false, InterfaceNova::contaLigadaPelaLinha(null), 'linha da conta: conta inexistente');
eq(false, InterfaceNova::contaLigada(0), 'conta 0 nunca');
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
// A home respeita a permissão de cada pessoa (11/11/2026): quem não tem o Chat não vê as
// conversas do WhatsApp, quem não tem Publicações não vê publicações, e assim por diante.
secao('2f. O que cada pessoa pode ver na home');
$todo = Painel::pode(true, []);
ok($todo['tarefas'] && $todo['processos'] && $todo['intimacoes'] && $todo['chat'] && $todo['dashboard'] && $todo['agenda'], 'admin vê tudo');
$estrela = Painel::pode(false, ['*']);
ok($estrela['chat'] && $estrela['intimacoes'] && $estrela['agenda'], "'*' vê tudo");
$membro = Painel::pode(false, ['dashboard', 'processos', 'tarefas', 'whatsapp']);
ok(!$membro['chat'], "membro com 'whatsapp' (nome antigo) não vê o Chat, igual ao menu");
ok(!$membro['intimacoes'], 'membro sem intimacoes não vê publicações');
ok($membro['processos'] && $membro['tarefas'] && $membro['agenda'], 'membro com processos e tarefas vê a agenda');
$soTar = Painel::pode(false, ['tarefas']);
ok($soTar['agenda'] && !$soTar['processos'] && !$soTar['dashboard'], 'só Tarefas: agenda sim, processos e Indicadores não');
$nada = Painel::pode(false, []);
ok(!$nada['agenda'] && !$nada['chat'] && !$nada['intimacoes'] && !$nada['processos'], 'sem permissão nenhuma: nada');

secao('3. Ganchos no menu e no dashboard');
$sidebar = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
ok(str_contains($sidebar, 'InterfaceNova::ligada($_GET, $_COOKIE, $_isFleetiflow);'), 'menu liga para todos, com a conta de marca fora');
ok(preg_match('/if \(\$_interfaceNova\): \?>\s*<!--[^>]*-->\s*<link rel="stylesheet" href="\/assets\/interface-nova\.css/', $sidebar) === 1, 'menu só carrega interface-nova.css sob a chave');
ok(str_contains($sidebar, "\$_interfaceNova ? 'Início' : 'Visão Geral'"), 'home chama Início só na interface nova');
ok(str_contains($sidebar, "'href'=>'dashboard.php?gestao=1','active'=>'indicadores'"), 'Gestão ganha Indicadores apontando para o painel de sempre');
ok(str_contains($sidebar, 'InterfaceNova::scriptCookie($_GET)'), 'menu grava o cookie por script (cabeçalhos já foram)');

$dash = (string) file_get_contents($raiz . '/public/dashboard.php');
ok(!str_contains($dash, 'InterfaceNova::aplicar();'), 'o dashboard não grava mais o cookie da prévia');
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
$detJs = (string) @file_get_contents($raiz . '/public/assets/inicio-detalhe.js');
ok($detJs !== '' && str_contains($inicio, '/assets/inicio-detalhe.js'), 'a home carrega o pop-up de detalhe');
ok(str_contains($inicio, 'data-det=') || str_contains($inicio, 'data-det="'), 'os cartões levam os dados do pop-up');
ok(str_contains($detJs, 'e.metaKey || e.ctrlKey || e.shiftKey'), 'Ctrl/Cmd/Shift+clique continua abrindo a página');
ok(str_contains($detJs, 'X-CSRF-Token'), 'concluir prazo manda o token CSRF');
foreach (['concluir-prazo', 'concluir-tarefa-processo', 'concluir-tarefa', 'tratar-pub'] as $a) ok(str_contains($detJs, "'$a': function"), "pop-up tem a ação $a");
// O pop-up de verdade do módulo (07/11/2026): a tela abre num iframe com ?embed=1 e mostra só o pop-up dela.
foreach (['abrir-processo', 'abrir-tarefa', 'abrir-cliente'] as $a) ok(str_contains($detJs, "ACOES['$a']"), "pop-up tem a ação $a");
ok(!str_contains($detJs, "link('Abrir o processo'"), '"Abrir o processo" abre dentro da home, não sai dela');
ok(str_contains($detJs, "abrirModulo('/processos?open=") && str_contains($detJs, "abrirModulo('/processos?criar=1')") && str_contains($detJs, "abrirModulo('/tarefas?nova=1')"), 'processo, novo processo e nova tarefa abrem o pop-up de verdade do módulo');
ok(!str_contains($detJs, 'abrirProcessoCompleto') && !str_contains($detJs, 'Rapida: function') && !str_contains($detJs, 'Rapido: function'), 'sem imitação do pop-up do módulo na home');
ok(str_contains($detJs, 'e.origin !== location.origin'), 'o aviso do iframe só vale da mesma origem');
// Chat e Clientes (08/11/2026): a conversa abre no Chat de verdade, o nome do cliente na ficha de verdade.
ok(str_contains($detJs, "abrirModulo('/chat?jid=' + encodeURIComponent(d.jid))"), 'a conversa abre no Chat de verdade');
ok(!str_contains($detJs, '/api/whatsapp/send.php') && !str_contains($detJs, 'idtResposta') && !str_contains($detJs, 'function bolha'), 'a home não imita o Chat: nada de envio próprio de WhatsApp');
ok(str_contains($detJs, "abrirModulo('/clientes?open=' + (+b.getAttribute('data-id')))"), 'o nome do cliente abre a ficha de verdade de Clientes');
ok(str_contains($detJs, "campo('Cliente', cliente(p.cliente_nome, p.cliente_id))") && str_contains($detJs, "['Cliente', cliente(d.cliente, d.cliente_id)]"), 'o cliente vira atalho no processo e na publicação');
ok(str_contains((string) file_get_contents($raiz . '/app/Inicio/Painel.php'), 'pr.cliente_nome, pr.cliente_id'), 'a publicação traz o cadastro do cliente do processo');
$sidebar = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
ok(str_contains($sidebar, "\$_interfaceNova && (\$_GET['embed'] ?? '') === '1'"), 'o modo pop-up só liga na interface nova e com ?embed=1');
$embedJs = (string) @file_get_contents($raiz . '/public/assets/embed.js');
ok(str_contains($embedJs, "'embed-pronto'") && str_contains($embedJs, "'embed-fechar'") && str_contains($embedJs, "'embed-sem-popup'"), 'a tela embutida avisa quando abre, quando fecha e quando não tem pop-up');
ok(str_contains($embedJs, 'postMessage({ yuris: tipo, tela: pagina, caixa: caixa || null }, location.origin)'), 'a tela embutida só fala com a mesma origem');
foreach (['processos', 'tarefas', 'clientes', 'chat'] as $t) ok(preg_match('/^\s+' . $t . ':\s+\{ aberto:/m', $embedJs) === 1, "embed.js sabe quando o pop-up de $t está aberto");
ok(str_contains($embedJs, "fora: '#chatContent'") && str_contains($embedJs, 'e.target === document.body'), 'o Chat embutido fecha com o clique fora da conversa');
ok(str_contains($embedJs, 'if (acabou) return;'), 'a tela embutida avisa o fechamento uma vez só');
// 08/11/2026: "se eu ficar clicando fora, que seja cancelado" e "tem que abrir o card, não ir pra tela".
ok(str_contains($detJs, "!caixa.classList.contains('pronto')) fecharModulo()"), 'clicar fora durante o Abrindo cancela');
ok(!str_contains($detJs, 'location.href = u'), 'pop-up que não abre nunca leva para a tela do módulo');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/inicio.css'), '.idt-embed.pronto .idt-embed-quadro{ opacity:1; pointer-events:auto; }'), 'o quadro só pega clique depois de pronto');
$chatJs = (string) file_get_contents($raiz . '/public/assets/chat.js');
ok(str_contains($chatJs, "else if (document.documentElement.classList.contains('yuris-embed')) abrirForaDaLista(AUTO_OPEN_JID);"), 'no embutido o Chat abre a conversa mesmo fora das 500 da lista');
$embedCss = (string) @file_get_contents($raiz . '/public/assets/embed.css');
ok(str_contains($embedCss, 'html.yuris-embed[data-embed-tela="chat"] #chatContent{') && str_contains($embedCss, '#disconnectAlert'), 'no Chat embutido só a conversa aparece, sem as faixas da tela');
ok(str_contains((string) file_get_contents($raiz . '/public/processos.php'), "params.get('criar') === '1'"), 'Processos aceita ?criar=1 (o cadastro abre sozinho)');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/tarefas.js'), "params.get('nova') === '1'"), 'Tarefas aceita ?nova=1 (o cadastro abre sozinho)');
ok(str_contains($detJs, 'function formatarJuridico') && str_contains($detJs, 'function limparTexto'), 'texto sem estrutura cai na limpeza do Diário');
ok(str_contains($detJs, 'e.blocos') && str_contains($detJs, 'e.advogados') && str_contains($detJs, 'idt-pub-assin'), 'a publicação aparece em tópicos (seções, advogados com OAB, assinatura)');
ok(str_contains((string) file_get_contents($raiz . '/app/Inicio/Painel.php'), 'PublicacaoEstrutura::estruturar('), 'a home manda a publicação já separada pelo servidor');
ok(str_contains($inicio, "timeZone: 'America/Sao_Paulo'") && str_contains($inicio, '<span id="iniSaudacao">'), 'a saudação acompanha o relógio de Brasília na página aberta');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/inicio.css'), '.ini-sala h1 .ini-nome{') && str_contains($inicio, '<span class="ini-nome">'), 'o nome em degradê azul para prata, como o logo');
$tkPhp = (string) file_get_contents($raiz . '/public/tarefas.php');
$tkCen = str_replace("
", "
", (string) file_get_contents($raiz . '/public/assets/tarefas-central.js'));
ok(!str_contains($tkPhp, 'tk-jur-op') && str_contains($tkCen, "ADD = { tarefas: ['tarefa', 'Nova tarefa'], prazos: ['prazo', 'Novo prazo'], vinculos: ['vinculo', 'Adicionar vínculo'] }"), 'sem opções antes do processo; cada bloco do processo tem o seu Adicionar');
ok(str_contains($tkCen, '<label for="ntJurDesc">Descrição *</label>') && str_contains($tkCen, '<textarea id="ntJurDesc"') && str_contains((string) file_get_contents($raiz . '/public/assets/tarefas.css'), '#modalNewTask.tk-nova-juridica #ntTituloCampo'), 'o formulário abre dentro do bloco, com Descrição no lugar de Título');
$tkJs = (string) file_get_contents($raiz . '/public/assets/tarefas.js');
ok(str_contains($tkJs, 'async function abrirTarefaNoPainel(opts = {})') && str_contains($tkJs, "if (!openTaskId) { if (novaNoPainel) await criarDoPainel(); return; }") && str_contains($tkJs, 'sairDoModoNova();'), 'tarefa de escritório nasce no painel completo e o painel fica aberto depois de criar');
ok(str_contains($tkCen, "if (b.dataset.tipo === 'escritorio' && window.abrirTarefaNoPainel) { ampliarParaPainel(); return; }") && strpos($tkCen, "window.abrirTarefaNoPainel({ de") < strpos($tkCen, "modal.classList.remove('open');"), 'o painel abre antes de o cadastro fechar (o pop-up embutido não pisca)');
// 08/11/2026: "espalhe o design da home para o sistema todo".
$sisCss = (string) @file_get_contents($raiz . '/public/assets/interface-nova-sistema.css');
$sisJs  = (string) @file_get_contents($raiz . '/public/assets/interface-nova-sistema.js');
ok($sisCss !== '' && $sisJs !== '' && str_contains($sidebar, '/assets/interface-nova-sistema.css') && str_contains($sidebar, '/assets/interface-nova-sistema.js'), 'todas as telas carregam a camada do sistema');
foreach (['function acharTela()', 'function iconeNoCabecalho()', 'function marcarAbas(raiz)', 'function botoesDeIcone(raiz)', 'function fantasmas(raiz)', 'function vestirGrafico(ch)'] as $fn) ok(str_contains($sisJs, $fn), "camada do sistema: $fn");
ok(!str_contains($sisJs, 'Object.assign({}, o.animation'), 'gráfico: nada de copiar as opções do Chart.js (proxy, recursão)');
ok(str_contains($sisJs, "if (/check|toggle|switch/i.test(b.className)"), 'caixa de marcar não vira botão de ícone');
ok(str_contains($sisCss, 'html .inn-aba.inn-aba-on, html:root .inn-aba.inn-aba-on.inn-aba-on') && str_contains($sisCss, '.inn-cab-ic{') && str_contains($sisCss, 'html .inn-carregando{'), 'aba ativa em pílula azul, ícone do cabeçalho afundado, carregando em forma cinza');
ok(!preg_match('/(?<![-\w])(width|height|display|position|top|left|margin|padding)\s*:/', preg_replace(['/\/\*.*?\*\//s', '/\.inn-cab-ic\{[^}]*\}/', '/\.inn-cab-ic svg\{[^}]*\}/', '/html \.inn-cab-linha\{[^}]*\}/', '/html \.inn-carregando(::after)?\{[^}]*\}/', '/\.inn-onda-quadro( i)?\{[^}]*\}/', '/\.inn-(kpi|card)-ic\{[^}]*\}/', '/\.inn-(kpi|card)-ic svg\{[^}]*\}/', '/[^{}]*\.inn-arq[^{}]*\{[^}]*\}/', '/html \.inn-cartao-(acao|link)\{[^}]*\}/'], '', $sisCss)), 'camada do sistema não mexe em layout das telas (só as peças próprias, como o cartão clicável e o link dele)');
ok(str_contains($tkCen, "await POST('/processo_prazos.php'") && str_contains($tkCen, "'&foco=vinculos'") && str_contains($tkCen, "'/resource_shares.php?resource_type=processo&resource_id='"), 'prazo grava pelo endpoint da ficha; vínculo abre a ficha na seção de vínculos; o processo mostra prazos, tarefas e vínculos');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/processos.js'), "if (foco === 'vinculos') {"), 'a ficha do processo entende o foco vinculos');
$cssIni = (string) file_get_contents($raiz . '/public/assets/inicio.css');
ok(str_contains($cssIni, '.ini .ini-cal-nav, .ini .ini-chev, .idt-fechar{') && str_contains($cssIni, 'box-shadow:var(--ini-fundo-sombra) !important; color:var(--ini-icone) !important;') && str_contains($cssIni, '.ini .ini-ctr-ic svg, .ini .ini-ctr-ic svg *{ stroke:var(--ini-icone) !important; }'), 'setas, chevrons, o fechar e os ícones dos prazos no mesmo afundado do ícone');
// 08/11/2026: "agora eu quero animação em tudo". O movimento mora em folhas e scripts próprios, só com movimento.
$movCss = (string) @file_get_contents($raiz . '/public/assets/inicio-movimento.css');
$movJs  = (string) @file_get_contents($raiz . '/public/assets/inicio-movimento.js');
ok($movCss !== '' && $movJs !== '' && str_contains($inicio, '/assets/inicio-movimento.css') && str_contains($inicio, '/assets/inicio-movimento.js'), 'a home carrega a folha e o script do movimento');
ok(str_contains($movCss, '@media (prefers-reduced-motion: no-preference)') && str_contains($movJs, "matchMedia('(prefers-reduced-motion: reduce)').matches) return;"), 'o movimento respeita quem pediu menos movimento');
foreach (['ini-sobe', 'ini-preenche', 'ini-onda', 'ini-entra', 'ini-mes-esq', 'idt-entra', 'idt-surge', 'ini-feito', 'ini-bate'] as $k) ok(str_contains($movCss, '@keyframes ' . $k . '{'), "movimento: existe a animação $k");
ok(str_contains($movJs, "b.style.setProperty('--cx'") && str_contains($movCss, 'left:var(--cx, 50%); top:var(--cy, 50%)'), 'o preenchimento nasce no ponto do clique');
ok(str_contains($movJs, 'function contar(el, dur)') && str_contains($movJs, "'.ini-kpi b, .ini-ctr-t b, .ini-sala p b'") && str_contains($movJs, "'.idt-numeros b'"), 'os números crescem nos cartões de cima, nos prazos e no pop-up da pessoa');
ok(str_contains($detJs, 'function origemRect()') && str_contains($detJs, "fundo.classList.add('fechando')") && str_contains($movCss, '.idt-fundo.fechando .idt-janela'), 'o pop-up nasce do cartão clicado e volta para ele');
ok(str_contains($movJs, "html.classList.add('ini-saindo')") && str_contains($movCss, 'html.ini-mov.ini-saindo .main-content{') && !str_contains($movCss, 'ini-saindo body'), 'a tela sai antes de ir para outra, e o menu fica (nunca apaga a página inteira)');
// O menu parado na troca de tela (08/11/2026): o menu-fixo.js enche o item e fecha os blocos; a
// view transition entre documentos troca o menu sem animação; nenhuma folha apaga o body ao sair.
$menuFixo = (string) @file_get_contents($raiz . '/public/assets/menu-fixo.js');
$innCss   = (string) file_get_contents($raiz . '/public/assets/interface-nova.css');
$innMov   = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.css');
ok($menuFixo !== '' && str_contains($menuFixo, "html.classList.add('inn-menu-saindo')") && str_contains($menuFixo, "a.classList.add('mf-enchendo')") && str_contains($menuFixo, 'yuris_menu_rolagem'), 'menu-fixo.js: o item enche, a tela fecha e a rolagem do menu é guardada');
ok(str_contains($innCss, '@view-transition{ navigation:auto; }') && str_contains($innCss, 'view-transition-name:inn-menu') && str_contains($innCss, '::view-transition-new(inn-menu)') && str_contains($innCss, 'html.inn-menu-saindo .main-content > *'), 'interface-nova.css: o menu troca sem animação e os blocos da tela fecham');
ok(!str_contains($innMov, 'inn-saindo body') && !str_contains($movCss, 'saindo body'), 'nenhuma folha de movimento apaga a página inteira ao sair');
$sidebarMF = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
ok(preg_match('/<\/aside>[\s\S]{0,900}?yuris_menu_rolagem/', $sidebarMF) === 1 && str_contains($sidebarMF, '/assets/menu-fixo.js') && preg_match('/<\/aside>[\s\S]{0,600}?classList\.add\(\'inn-vestindo\'\)/', $sidebarMF) === 1, 'logo depois do <aside>: a tela fica invisível até vestir, a rolagem do menu volta, e o menu-fixo.js carrega');
// A troca de tela piscava em produção (09/11/2026): o PHP manda a página aos pedaços e o primeiro
// quadro da tela nova saía SEM o menu. Toda página com o menu segura a pintura até o fim dele.
$pMarca = strpos($sidebarMF, '<i id="yuris-menu-pronto" hidden></i>');
$pGrupos = strpos($sidebarMF, "var KEY = 'yuris_sidebar_groups_v2';");
ok($pMarca !== false && $pGrupos !== false && $pGrupos < $pMarca && strpos($sidebarMF, '</aside>') < $pMarca, 'a marca do fim do menu vem depois do menu e dos grupos reabertos');
$semExpect = [];
$paginasMenu = array_merge(glob($raiz . '/public/*.php') ?: [], glob($raiz . '/public/includes/dashboard_*.php') ?: [], glob($raiz . '/app/*/Paginas/*.php') ?: []);
foreach ($paginasMenu as $f) {
    $t = (string) file_get_contents($f);
    if (!preg_match('/include[_once]*\s*\(?\s*[^;]*sidebar\.php/', $t)) continue;
    $head = strstr($t, '</head>', true);
    if ($head === false || !str_contains($head, '<link rel="expect" href="#yuris-menu-pronto" blocking="render">')) $semExpect[] = str_replace($raiz . '/', '', $f);
}
// O que ainda mudava no menu da tela nova (09/11/2026, gravado quadro a quadro): o número do sino
// (o servidor contava a lista inteira, o JS só os dirigidos) e o logo de 750 KB, que saía em branco.
ok(str_contains($sidebarMF, '$_notifUnread = \App\Master\AccountNotification::countNaoLidas('), 'o sino nasce com o mesmo número que o notifications.js põe depois');
ok(str_contains($sidebarMF, 'src="/assets/img/logo-menu-320.png"') && !str_contains($sidebarMF, 'src="/sistema_vendas/Imagens/Logo.png"') && is_file($raiz . '/public/assets/img/logo-menu-320.png') && filesize($raiz . '/public/assets/img/logo-menu-320.png') < 150000, 'o logo do menu é o de 320 px, não o PNG de 750 KB');
// Gravado quadro a quadro (09/11/2026): o grupo da tela de antes fechava e os itens pulavam; a
// tela nova só era pedida 430 ms depois do clique; e o número aparecia pronto, zerava e subia.
ok(!str_contains($menuFixo, "g.classList.remove('open')") && str_contains($menuFixo, "sessionStorage.setItem('yuris_menu_grupos'") && str_contains($sidebarMF, "sessionStorage.getItem('yuris_menu_grupos')"), 'na troca pelo menu nenhum grupo fecha sozinho, e a tela nova nasce com os mesmos grupos');
ok(!str_contains($menuFixo, '}, 430);') && str_contains($menuFixo, 'requestAnimationFrame(ir)') && str_contains($menuFixo, 'setTimeout(ir, 150)'), 'a tela nova é pedida logo no clique (a dobra acontece enquanto o servidor responde)');
$innMovJs = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.js');
ok(str_contains($innMovJs, "el.dataset.innConta = num.escreve(0); el.classList.add('inn-contando');") && !str_contains($innMovJs, "el.textContent = '0';") && str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.css'), '.inn-contando::before{') && str_contains((string) file_get_contents($raiz . '/public/assets/inicio-movimento.js'), "el.dataset.alvo = m[1]; el.textContent = '0' + m[2];"), 'a contagem é desenhada por cima e o texto real do número nunca muda (o resumo lia o zero)');
ok(substr_count($sidebarMF, 'data-sb-recolher') >= 4 && str_contains($sidebarMF, "<script>try{if(localStorage.getItem('yuris_menu_retraido')==='1')"), 'o logo recolhe o menu também na interface nova (ele usa: "não tá recolhendo")');
$sisJs = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js');
ok(str_contains($sisJs, "if (ehCaixa(e)) return;") && str_contains($sisJs, "(e.closest('[data-inn-kpi]') && !e.hasAttribute('data-inn-kpi'))") && str_contains($sisJs, '&& !ehCaixa(alvo)) {'), 'cartão de indicador com classe de estado (kpi-danger) nunca vira selo nem faixa (perdia o fundo "às vezes")');
// Revisão de designer da home (09/11/2026): os dias do calendário não esticam, o mês em números
// ocupa o pé do calendário, e o prazo que é teor de publicação ganha um título curto.
$iniCss = (string) file_get_contents($raiz . '/public/assets/inicio.css');
$iniPhp = (string) file_get_contents($raiz . '/public/includes/dashboard_inicio.php');
ok(str_contains($iniCss, '.ini-agenda-corpo > .ini-cal{ container-type:size; min-height:796px; --cal-b:700px; --cal-folga:0px; }') && str_contains($iniCss, '.ini-cal-dias{ grid-auto-rows:clamp(44px, calc(44px + (100cqh - var(--cal-b) - var(--cal-folga)) * .09)') && str_contains($iniCss, '.ini-cal-dias:has(.ini-cal-dia:nth-child(36))') && str_contains($iniCss, '.ini-cal-resumo-n.zero b{') && str_contains($iniPhp, '<div class="ini-cal-mes">') && str_contains($iniPhp, 'id="iniCalResumo"') && str_contains($iniPhp, 'function resumoDoMes(mes)'), 'calendário sem espaço vazio: dias, atalhos e números do mês crescem com a altura do bloco (cqh), com teto, mês de seis semanas e período escolhido previstos');
ok(\App\Inicio\Painel::tituloCurto('Processo 1007778-20.2025.8.26.0554 - Procedimento Comum Cível - Indenização por Dano Material - Rafael de Oliveira Prado - - Vanessa Bucchi Baptista - Vistos. Fls. 671/676: Restando negativas') === 'Procedimento Comum Cível · Indenização por Dano Material'
   && \App\Inicio\Painel::tituloCurto('Processo 1000895-90.2026.5.02.0473 distribuído para 3ª Vara do Trabalho de São Caetano do Sul na data 22/05/2026 Para maiores informações, clique no link a seguir: https://pje.trt2.jus.br/x') === 'Distribuído para 3ª Vara do Trabalho de São Caetano do Sul na data 22/05/2026'
   && \App\Inicio\Painel::tituloCurto('Protocolar petição de instrução') === null, 'prazo com o teor da publicação no nome ganha título curto (o texto inteiro vai para o pop-up)');
ok(\App\Inicio\Painel::tipoDoItem(['titulo' => 'Procedimento Comum Cível', 'texto' => 'Processo 1 - designada audiência de conciliação', 'origem' => 'prazo']) === 'audiencia', 'o tipo continua lido no texto inteiro');
ok(str_contains($iniCss, '.idt-escolha .idt-mini{ display:inline-flex; align-items:center; justify-content:center;') && str_contains($iniCss, '.idt-escolha .idt-mini::after{'), '"Ler e tratar" com o texto no meio da pílula e a seta');
$sisCss = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.css');
ok(str_contains($sisCss, 'html:root body .inn-ic-btn.inn-ic-btn:hover:not(:disabled){ background:linear-gradient('), 'botão só de ícone: no hover o fundo é o azul cheio, acima da folha da tela (o ícone branco sumia no azul-clarinho)');
$movJs2 = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.js'); $movCss2 = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.css');
ok(str_contains($movJs2, "html.classList.add('inn-mov-pop');") && str_contains($movJs2, "if (!embutido) html.classList.add('inn-mov');") && str_contains($movCss2, 'html:is(.inn-mov, .inn-mov-pop) #modalProcess:not(.hidden) .proc-modal,') && str_contains($movCss2, 'html:is(.inn-mov, .inn-mov-pop) .inn-pop-surge{'), 'no modo embutido (pop-up da home e da Central) o pop-up ainda entra com a animação');
$sisJs2 = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js');
ok(str_contains($sisJs2, "if (linha.nextElementSibling) linha.style.marginBottom = '16px';"), 'cabeçalho de cartão com ícone tem 16 px até o conteúdo');
ok(str_contains($sisJs2, "if (tipo === 'bar' && umaSerie && Array.isArray(ds.backgroundColor))") && str_contains($sisJs2, '(p === \'backgroundColor\' && ds.$innUmaCor)') && str_contains($sisJs2, "s.grace = '12%'"), 'gráficos: barra de uma série numa cor (estado guarda a dele), tooltip opaco e folga no topo');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/juridico_charts.js'), "trabalista: 'Trabalhista'"), 'tipos de ação: grafias da mesma ação juntas');
$sisJs3 = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js');
ok(str_contains($sisJs3, 'hNv.h >= 200 && hNv.h <= 240 && hNv.s > .3 && hNv.l < .3') && str_contains($sisJs3, "if (!tom && tb === 'azul' && cheio && r.height >= 30"), 'azul-marinho antigo no escuro vira superfície neutra, e botão azul liso vira o principal da home');
ok(str_contains($sisJs3, 'function marcarQuadros()') && str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.css'), '.inn-tem-mais{'), 'quadro que continua para o lado esmaece na borda direita');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova.css'), 'html body select:not([multiple]):not([size]):not(.inn-sem-seta){'), 'todo select de uma linha tem a seta nos dois temas');
ok(str_contains((string) file_get_contents($raiz . '/public/clientes.php'), 'flex-shrink: 0;'), 'cartão de cliente não encolhe na coluna (telefone e CPF cortados)');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.js'), "var FORA = '.sidebar, .mobile-tabbar, .inn-emb';"), 'o movimento da tela nunca anima o menu (os ícones sumiam em Webhooks)');
ok(str_contains($sisJs3 = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js'), 'function corDoTema(label, tipo)') && str_contains($sisJs3, 'p === fixo') && str_contains($sisJs3, '[class*="chart-box"]'), 'gráficos: a série com nome do tema tem a mesma cor em todo gráfico, e a legenda feita à mão segue as fatias');
ok(str_contains((string) file_get_contents($raiz . '/public/atendimento.php'), "ticks: { precision: 0, callback: function(x){ return duracao(x); } }"), 'Atendimento: o eixo de tempo não repete rótulo');
$innCssCab = (string) file_get_contents($raiz . '/public/assets/interface-nova.css');
ok(str_contains($innCssCab, 'html body .inn-tela .fu-topo{') && str_contains($innCssCab, 'html body:has(#disconnectAlert.visible) .page-layout{ padding-top:56px; }') && str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js'), 'var DA_TELA = {'), 'cabeçalhos: Follow-up no cartão, ícone nas telas fora do menu, e o aviso do Chat não cobre o título');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js'), 'function igualarIndicadores()') && str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js'), "'align-items': 'center', 'align-content': 'start' });"), 'indicadores: o número de cada cartão na mesma altura dos vizinhos da fileira');
ok(str_contains((string) file_get_contents($raiz . '/public/escritorios.php'), 'function rotuloValor(v)') && str_contains((string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.css'), 'html .inn-tela input::placeholder, html .inn-tela textarea::placeholder{'), 'textos: valores do banco viram palavras em Escritórios, e o texto de exemplo dos campos é cinza');
ok(!str_contains((string) file_get_contents($raiz . '/public/agente.php'), 'Descricao do escritorio') && !str_contains((string) file_get_contents($raiz . '/public/processos.php'), 'Controle operacional —'), 'textos: o Agente com acento, e sem travessão nos subtítulos revisados');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/juridico.js'), "set('statSemMovVal',    kpis.sem_mov);") && str_contains((string) file_get_contents($raiz . '/public/assets/dre.js'), ": 'No período consolidado';") && str_contains((string) file_get_contents($raiz . '/public/financas.php'), '.kpi-grid > .kpi-card:last-child:nth-child(4n+3) { grid-column: span 2; }'), 'números que batem (Jurídico), frase sem repetição e grade sem buraco (Finanças)');
// Troca de aba sem a tela mudar de tamanho e sem travar (09/11/2026, "algumas abas a sidebar fica
// menor e outras maior, e tá travando um pouco na hora que troca de aba").
$innCssAba = (string) file_get_contents($raiz . '/public/assets/interface-nova.css');
$sisJsAba  = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js');
$movJsAba  = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.js');
$sbAba     = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
$posAside  = strpos($sbAba, '<aside class="sidebar');
ok(str_contains($innCssAba, 'html:not(.yuris-embed){ scrollbar-gutter:stable; }'), 'o lugar da barra de rolagem fica reservado em toda tela (o Chat não fica 10 px mais largo)');
ok(strpos($sbAba, "<script>document.documentElement.classList.add('yuris-embed');</script>") !== false && strpos($sbAba, "<script>document.documentElement.classList.add('yuris-embed');</script>") < $posAside, 'o pop-up embutido é marcado antes do menu, sem a faixa da barra');
ok(str_contains($innCssAba, '  line-height:normal !important;') && str_contains($innCssAba, 'html .sidebar .sidebar-footer :is(p, a, span){ line-height:normal !important; }'), 'a altura da linha do menu não vem da tela (o rodapé crescia 12 px fora da home)');
ok(strpos($sbAba, 'family=Inter:wght@400;500;600;700;800&display=swap') !== false && strpos($sbAba, 'family=Inter:wght@400;500;600;700;800&display=swap') < $posAside, 'a Inter com os cinco pesos em toda tela da prévia, antes do menu');
ok(str_contains($innCssAba, 'html body #disconnectAlert{ top:16px !important; left:298px !important;') && str_contains($innCssAba, 'html body:has(#disconnectAlert.visible) .main-content{ padding-top:68px !important; }') && str_contains($innCssAba, 'html.sb-retraido body #disconnectAlert{ left:118px !important; }'), 'Chat: o aviso de desconectado fica na coluna da tela e o menu não desce');
ok(str_contains($sisJsAba, 'requestAnimationFrame(vestirNovos)') && str_contains($sisJsAba, 'botoesDeIcone(raizes); marcarAbas(raizes); selos(raizes); vestirCores(raizes, true);'), 'o que a tela acrescenta é vestido uma vez por quadro, a leva inteira');
ok(str_contains($sisJsAba, 'function soMarcaDaCamada(m)') && str_contains($sisJsAba, 'attributeOldValue: true') && str_contains($sisJsAba, '!soMarcaDaCamada(m)) paraVestir.push(alvo);'), 'marca da própria camada (classe inn-*, --mov-i) não faz vestir de novo');
ok(str_contains($sisJsAba, 'rz.getAnimations({ subtree: true })') && !str_contains($sisJsAba, 'el.getAnimations().some('), 'transição recém-começada é vista pela leva, sem perguntar elemento por elemento');
ok(str_contains($sisJsAba, 'requestAnimationFrame(function () { telaPedida = false; vestirTela(); })'), 'indicadores e painéis da tela: uma passada por quadro');
ok(str_contains($movJsAba, 'requestAnimationFrame(entrarLeva)') && str_contains($movJsAba, '[entrarItens(main), crescerIcones(main), contarNumeros(main)].forEach(function (escreve) { escreve(); });'), 'o movimento lê tudo antes de marcar, e entra depois de a camada vestir');
// Todo pop-up entra como a Intimação da home (10/11/2026, "eu quero que todos os pop-ups tenham a
// mesma animação, que os cards vão vindo em sequência; é uma animação do sistema").
$movPop  = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.js');
$movPopC = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.css');
$detPop  = (string) file_get_contents($raiz . '/public/assets/inicio-detalhe.js');
$embPop  = (string) file_get_contents($raiz . '/public/assets/embed-abrir.js');
ok(str_contains($movPop, 'function ehCartao(f, r, cs, fundoPai, pr)') && str_contains($movPop, 'if (ehCartao(f, r, cs, fundoPai, pr)) out.push(f);'), 'pop-up: o cartão (seção com fundo, moldura ou sombra) entra antes do que tem dentro');
ok(str_contains($movPopC, '.inn-pop-entra{ animation:inn-pop-entra .4s var(--mov-saida) both; animation-delay:calc(90ms + var(--mov-i, 0) * 38ms); }'), 'pop-up: o passo da entrada é o da Intimação (38 ms)');
ok(str_contains($movPop, '.sort(function (a, b) { return a.y - b.y || a.x - b.x; })') && str_contains($movPop, 'if (r.top >= baixo || (!r.width && !r.height)) return;'), 'pop-up: a ordem é a da posição, de cima para baixo, e só o que está à vista ganha vez');
ok(str_contains($movPop, 'function entrarTardios(achados)') && str_contains($movPop, "attributes: true, attributeOldValue: true, attributeFilter: ['class', 'style', 'hidden', 'open']"), 'pop-up: o que chega depois de abrir continua a fila (o Nova Conversa abre vazio)');
ok(str_contains($movPop, "e.data.yuris !== 'embed-visivel'") && str_contains($movPop, 'entrarPopup(f, true)') && str_contains($detPop, "postMessage({ yuris: 'embed-visivel' }, location.origin)") && str_contains($embPop, "postMessage({ yuris: 'embed-visivel' }, location.origin)"), 'pop-up aberto por cima de outra tela: o conteúdo entra quando aparece, não escondido');
ok(str_contains($movPop, "n.closest('.sidebar, .inn-emb, .inn-pop-entra')") && str_contains($movPop, "if (el.closest('.inn-pop-entra')) return;"), 'o que já está entrando não entra de novo pela aba');
// Troca de aba pelo menu sem travar (10/11/2026, "na troca de aba parece que tá travando, na aba em si").
$sisTroca = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.js');
$mfTroca  = (string) file_get_contents($raiz . '/public/assets/menu-fixo.js');
$cssTroca = (string) file_get_contents($raiz . '/public/assets/interface-nova.css');
$sbTroca  = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
ok(str_contains($sisTroca, "var c = el.getAttribute ? el.getAttribute('class') : el.className;") && str_contains($sisTroca, "baseSelo({ className: m.oldValue || '' })"), 'a marca da camada no svg e em elemento sem classe não faz vestir de novo');
ok(str_contains($sisTroca, 'function foraDaTela(r)') && str_contains($sisTroca, 'if (!adiado && foraDaTela(r)) { adiar.push(el); return; }') && str_contains($sisTroca, "|| ADIADO.has(el)) return;"), 'o que está fora da tela é vestido logo depois, aos pedaços');
ok(str_contains($sisTroca, 'passada.antes.set(el, corFundo(cs));') && str_contains($sisTroca, 'fundoVisto(el, cacheFundo, passada.antes)'), 'quem é vestido depois mede o contraste contra o fundo de antes');
ok(str_contains($sisTroca, "window.addEventListener('scroll', function () {") && str_contains($sisTroca, '{ capture: true, passive: true }'), 'rolou antes de a fila acabar: o resto é vestido na hora');
ok(str_contains($movPop, 'function naTela(r)') && str_contains($movPop, 'if (giram[i] || !vistos[i]) return;'), 'o movimento só põe a entrada no que está à vista');
$posEnche = strpos($sbTroca, "sessionStorage.getItem('yuris_menu_enche')");
ok(str_contains($mfTroca, "sessionStorage.setItem('yuris_menu_enche'") && $posEnche !== false && $posEnche < strpos($sbTroca, '<i id="yuris-menu-pronto" hidden></i>') && str_contains($cssTroca, 'animation-delay:var(--mf-atraso, 0s);'), 'o item do menu continua enchendo na tela nova, antes da primeira pintura');
ok(str_contains($mfTroca, 'if (!e.persisted) return;'), 'a carga normal não corta o círculo (só a volta pelo histórico desfaz)');
// A tela nova aparece pronta, e a entrada roda inteira (10/11/2026, "acho melhor recarregar sem nada
// na tela, aí vinha a animação; o preenchimento da sidebar pode demorar um pouquinho mais").
$movRev = (string) file_get_contents($raiz . '/public/assets/interface-nova-movimento.js');
ok(str_contains($sisTroca, 'function revelarQuandoPronta(main, ocupada)') && str_contains($sisTroca, "revelarQuandoPronta(main, function () { return pendente || FILA_ADIADA.length > 0; });") && str_contains($sisTroca, 'agora - t0 >= 1200') && !str_contains($sisTroca, "requestAnimationFrame(function () { document.documentElement.classList.remove('inn-vestindo'); });"), 'a tela nova só aparece quando sossegou (no máximo 1,2 s)');
ok(str_contains($cssTroca, 'html.inn-vestindo .sidebar ~ :not(script):not(style):not(link) *{ animation-play-state:paused !important; }'), 'enquanto a tela está invisível, a entrada espera parada no começo');
ok(str_contains($movRev, 'function depoisDeAparecer(fn)') && str_contains($movRev, "depoisDeAparecer(function () { setTimeout(contar.bind(null, el, 800, num), 160 + vez * 40); });") && str_contains($sisTroca, "document.dispatchEvent(new Event('inn:revelada'))"), 'os números só contam com a tela à vista');
ok(str_contains($sbTroca, "document.dispatchEvent(new Event('inn:revelada'))") && str_contains($sbTroca, 'if(__me&&__pa>=0&&__pa<700&&'), 'a rede de 2 s também avisa, e o círculo continua até 0,7 s');
ok(str_contains($cssTroca, '@keyframes mf-enche{ from{ transform:scale(0); } to{ transform:scale(1); } }') && str_contains($cssTroca, 'animation:mf-enche .75s cubic-bezier(.25,.7,.25,1) both;') && !str_contains($cssTroca, 'clip-path:circle(0px at var(--mf-cx'), 'o item do menu enche em 0,75 s, por transform (não engasga enquanto a tela monta)');
// A saída inteira antes da troca (10/11/2026, "está carregando antes dela sair por completo"): o aperto
// no item já pede a tela nova, e o clique troca de tela só depois da dobra.
$posRegra = strpos($sbTroca, '<script type="speculationrules">');
ok($posRegra !== false && preg_match('/<script type="speculationrules">(.*?)<\/script>/s', $sbTroca, $mRegra) === 1 && is_array(json_decode($mRegra[1], true)) && str_contains($mRegra[1], '"eagerness":"conservative"') && str_contains($mRegra[1], '.is-logout'), 'o aperto no item do menu pede a tela nova (regra válida, só no aperto, nunca o Sair)');
ok(str_contains($mfTroca, 'var SAIDA_MS = 350;') && str_contains($mfTroca, 'if (jaPediu(a)) { setTimeout(ir, SAIDA_MS); return; }') && str_contains($mfTroca, "HTMLScriptElement.supports('speculationrules')"), 'com a tela nova já pedida, a troca espera a saída terminar; sem ela, é como antes');
// Revisão de 10/11/2026: a mancha azul perto do logo, o menu que voltava ao topo, os cartões do Chat
// interno sem entrada e os números que não contavam (dinheiro, porcentagem).
ok(str_contains($mfTroca, "return x.pathname.replace(/\\.php$/, '') + x.search;") && str_contains($sbTroca, "(a.classList.contains('active')||a.hasAttribute('aria-current'))") && str_contains($cssTroca, 'a.mf-enchendo.mf-enchendo{ position:relative !important; isolation:isolate;'), 'o círculo continua no item certo (endereço com a busca, só o ativo) e nunca sai dele');
$posRolNav = strpos($sbTroca, "sessionStorage.getItem('yuris_menu_rolagem_nav')");
ok(str_contains($mfTroca, "sessionStorage.setItem('yuris_menu_rolagem_nav', String(nav.scrollTop));") && $posRolNav !== false && $posRolNav > strpos($sbTroca, "localStorage.getItem(KEY)") && $posRolNav < strpos($sbTroca, '<i id="yuris-menu-pronto" hidden></i>'), 'a rolagem do nav do menu volta depois de os grupos reabrirem, antes da primeira pintura');
ok(str_contains($movRev, 'function lerNumero(txt)') && str_contains($movRev, '(?:R\\$|US\\$|€)?') && str_contains($movRev, 'n >= 1900 && n <= 2100') && str_contains($movRev, 'var vez = ++k;'), 'números em qualquer formato contam de zero (dinheiro, porcentagem, milhar), cada um na sua vez; ano solto não');
ok(str_contains($movRev, 'function irmaosIguais(rz, out)') && str_contains($movRev, 'irmaosIguais(rz, itens);') && str_contains($movRev, "!el.closest(SEL_POP)"), 'a lista de irmãos iguais (cartões de conversa, linhas) entra um a um');
ok(str_contains($movRev, "while (main.parentElement && main.parentElement !== document.body && !main.parentElement.querySelector('.sidebar, .mobile-tabbar')) main = main.parentElement;"), 'o movimento observa o bloco inteiro da tela (no Chat interno, a lista e a conversa)');
ok(substr_count($sisTroca, 'kanban|item|row|linha/i.test(baseSelo(el))') === 2, 'a marca inn-item não tira o cabeçalho do painel (classes da tela, sem as da camada)');
// Todo cartão de indicador faz alguma coisa (10/11/2026, "todo card tem que ser interativo: clicar leva
// para a aba; se já for dentro da aba, é um filtro") e o vão entre os blocos em toda tela.
$cartJs = (string) @file_get_contents($raiz . '/public/assets/interface-nova-cartoes.js');
$sisCssC = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.css');
ok($cartJs !== '' && str_contains($sbTroca, '/assets/interface-nova-cartoes.js') && str_contains($mRegra[1] ?? '', 'a.inn-cartao-link[href]'), 'o script dos cartões carrega nas telas de dentro, e o aperto no link do cartão pede a tela');
foreach (['gestao', 'juridico', 'processos', 'usuarios', 'financas', 'planejamento', 'desempenho', 'atendimento', 'configuracoes', 'chat', 'agente'] as $telaC) ok(preg_match('/\n    ' . $telaC . ': \{/', str_replace("\r", '', $cartJs)) === 1, "cartões: a tela $telaC tem o que cada cartão faz");
ok(str_contains($cartJs, 'if (c.dataset.href) return c.dataset.href;') && str_contains($cartJs, "if (a.ir && !a.ligado && !destino) { c.classList.remove('inn-cartao-acao'); return; }"), 'cartões: vale o destino que a tela já dá; sem a tela no menu da pessoa, sem link');
ok(str_contains($cartJs, "return comRotulo.filter(function (c) { return !comRotulo.some(function (o) { return o !== c && c.contains(o); }); });"), 'cartões: o cartão é o menor elemento marcado com rótulo (nem o pedaço de dentro, nem a fileira)');
ok(str_contains($mfTroca, "if (a.classList.contains('inn-cartao-link')) e.stopImmediatePropagation();") && str_contains($mfTroca, "a.inn-cartao-link[href]"), 'o link do cartão tem a saída e o pedido antecipado do menu, e a tela não navega por cima');
// Desde 11/11/2026 o movimento do hover é da regra única (interface-nova-hover): o indicador, em fileira, sobe.
ok(str_contains((string) @file_get_contents($raiz . '/public/assets/interface-nova-hover.css'), 'html.inn-hv .inn-hv-cima:hover{ translate:0 -2px; }') && str_contains($sisCssC, 'html .inn-cartao-on.inn-cartao-on{') && str_contains($sisCssC, '@keyframes inn-destaque{'), 'cartões: sobe ao passar o mouse (como na home), marca o filtro ligado, a seção pisca');
ok(str_contains($cssTroca, 'html #sectionUsuarios > :not(script):not(style) + :not(script):not(style), html #sectionTimes > :not(script):not(style) + :not(script):not(style){ margin-top:16px !important; }') && str_contains($cssTroca, 'html .qr-topo{ margin-bottom:14px !important; }'), 'o vão de 16 px entre os blocos em Usuários e de 14 px nos filtros de Clientes');
// Processos (10/11/2026): a agenda abre pelo botão do cabeçalho, em cartões prata; a barra de filtros alinhada.
$telasJs = (string) @file_get_contents($raiz . '/public/assets/interface-nova-telas.js');
ok($telasJs !== '' && str_contains($sbTroca, '/assets/interface-nova-telas.js') && str_contains($telasJs, "['quadro', 'calendario', 'agenda'].forEach") && str_contains($telasJs, 'function rolarAoMes(m, suave)') && str_contains($telasJs, "var atual = el('button', 'inn-mes-atual'") && str_contains($telasJs, "'<span class=\"inn-cal-n\" data-inn=\"1\">'"), 'Processos: o painel do calendário tem Quadro, Calendário e Agenda, abre no mês atual e tem o botão Mês atual');
ok(str_contains($cssTroca, 'html:root .proc-agenda-row.proc-agenda-row{ background:var(--inn-t-painel) !important; background-image:none !important;') && str_contains($cssTroca, 'html .inn-tela .proc-panel > div:has(> div > #procSearchText)::after{'), 'Processos: a agenda em cartões prata e a barra de filtros em duas linhas');
ok(str_contains($cssTroca, 'html .inn-cal-semana, html .inn-cal-grade{ display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); gap:8px; }') && !str_contains($cssTroca, 'inn-agenda-overlay'), 'Processos: o calendário em grade de sete dias, e a janela da agenda saiu');
ok(str_contains($telasJs, 'function abrirDia(dia)') && str_contains($telasJs, "var fundoDia = el('div', 'inn-dia-overlay')") && str_contains($cssTroca, 'html .inn-cal-dia{ min-height:136px;'), 'Processos: o dia do calendário, maior, abre a janela com tudo o que vence nele');
ok(str_contains($telasJs, "var SITUACOES = [['todos', 'Todos'], ['vencido', 'Vencidos']") && str_contains($telasJs, 'function filtrarAgenda()') && str_contains($cssTroca, 'html .inn-ag-filtros{'), 'Processos: a Agenda tem filtros de situação (com a contagem) e de período');
ok(!str_contains($telasJs, "atual.hidden = v === 'agenda';") && str_contains($telasJs, 'var precisa = innerHeight - r.top - depois;') && str_contains($cssTroca, 'html .inn-vista-seg{ display:inline-flex; gap:6px; padding:0; background:none; box-shadow:none; }') && str_contains($telasJs, "if (vista === 'agenda') {"), 'Processos: o Mês atual fica na Agenda (a troca de visão não anda) e filtrar não encolhe a página');
$intimA = "'label'=>" . '$_interfaceNova ? ' . "'Intimações' : 'Processos'],"; $intimB = "\$_svg['processos'], \$_interfaceNova ? 'Intimações' : 'Processos'];"; ok(str_contains($sbTroca, $intimA) && str_contains($sbTroca, $intimB), 'na prévia, o item de Processos do Jurídico se chama Intimações (menu e celular)');
ok(str_contains($innMovJs, "return cs.position === 'absolute' || cs.position === 'fixed' || cs.transform !== 'none';") && str_contains($innMovJs, '|| proprios[i]) return;'), 'ícone com posição própria não cresce (a lupa da busca descia)');
ok(str_contains($cssTroca, '{ animation:inn-fecha .24s cubic-bezier(.2,.7,.3,1) both !important;') && str_contains($cssTroca, ':nth-last-child(n+6){ animation-delay:100ms !important; }'), 'a dobra da saída dura 0,34 s no total (o SAIDA_MS)');
ok($semExpect === [],'toda página com o menu segura a pintura até o fim dele (rel=expect no <head>)' . ($semExpect ? ': falta em ' . implode(', ', $semExpect) : ''));
ok(!preg_match('/^(?!.*(animation|transition|transform|opacity|keyframes|--mov|--cx|--cy|isolation|overflow|position|z-index|pointer-events|will-change|font-variant|border-radius|padding-top|width|left|top|background|display|content|border-color|transform-origin)).*:\s*#[0-9a-f]{3,6}/mi', $movCss), 'a folha do movimento não traz cor nova');
$innCss = (string) @file_get_contents($raiz . '/public/assets/interface-nova-movimento.css');
$innJs  = (string) @file_get_contents($raiz . '/public/assets/interface-nova-movimento.js');
ok($innCss !== '' && $innJs !== '' && str_contains($sidebar, '/assets/interface-nova-movimento.css') && str_contains($sidebar, '/assets/interface-nova-movimento.js'), 'as telas de dentro carregam o movimento delas');
ok(str_contains($innCss, '@keyframes inn-surge{') && str_contains($innCss, '#modalProcess:not(.hidden) .proc-modal') && str_contains($innCss, '.modal-shell.open .modal-panel') && str_contains($innCss, '.tk-modal-overlay.open .tk-modal'), 'os pop-ups das telas surgem');
ok(str_contains($innJs, "var embutido = html.classList.contains('yuris-embed')") && str_contains($innJs, "if (!embutido) { if (document.readyState === 'loading')"), 'no modo embutido a tela de trás não anima (só o pop-up entra)');
// 08/11/2026: "quero esse simples bonito nas páginas também": a tela entra por partes.
foreach (['inn-titulo', 'inn-cresce', 'inn-item'] as $k) ok(str_contains($innCss, '@keyframes ' . $k . '{'), "telas: existe a animação $k");
ok(str_contains($innCss, '.main-content > :nth-child(2){ animation-delay:70ms; }') && !str_contains($innCss, '.main-content, html.inn-mov'), 'os blocos da tela sobem um a um por CSS, sem depender do script');
ok(str_contains($innJs, 'function crescerIcones(raiz)') && str_contains($innJs, 'function contarNumeros(raiz)') && str_contains($innJs, 'function entrarItens(raiz)'), 'ícones crescem, números contam e itens entram um a um');
ok(str_contains($innJs, 'function podeAnimar()') && str_contains($innJs, 'n - inicio < 5000 || n - ultimoClique < 1500'), 'o que a tela desenha depois só anima nos primeiros segundos ou logo após um clique');
// 08/11/2026, segunda volta: "tem que preencher total", "tela fantasma", "não pode recarregar ao fechar".
ok(str_contains($movJs, "b.style.setProperty('--raio'") && str_contains($movCss, 'width:calc(var(--raio, 320px) * 2)') && str_contains($movCss, '@keyframes ini-assenta{'), 'o círculo cobre a caixa inteira e o fundo assenta por cima');
ok(str_contains($detJs, 'function fantasma(tela)') && str_contains($detJs, 'function morfar(c)') && str_contains($embedJs, "avisar('embed-pronto', medir())") && str_contains($movCss, '.idt-fantasma.morfa{'), 'a tela fantasma aparece no lugar do Abrindo e vai até a medida do pop-up');
ok(str_contains($detJs, 'function ossos(campos, linhas)') && str_contains($detJs, 'ganhaVida(destino)') && str_contains($movCss, '@keyframes idt-vida{') && str_contains($movCss, '@keyframes idt-brilho{'), 'o pop-up da home mostra ossos enquanto carrega e ganha cor depois');
ok(!str_contains($detJs, 'location.reload') && !str_contains($detJs, 'atualizarEmSilencio') && str_contains($detJs, "if (e.data.yuris === 'embed-fechar') fecharModulo();"), 'fechar o pop-up só fecha: nada recarrega e nenhum cartão é trocado');
ok(!str_contains($movJs, 'location.reload') && !str_contains($inicio, 'location.reload'), 'a home não recarrega sozinha em lugar nenhum');
ok(!str_contains($detJs, 'Abrindo…') && !str_contains($detJs, 'carregando()'), 'sem Abrindo nem Carregando: no lugar deles, a forma do que vem');
ok(substr_count($inicio, 'class="ini-ctr ') === 1 && str_contains($inicio, '<span class="ini-ctr-ic">') && str_contains((string) file_get_contents($raiz . '/public/assets/inicio.css'), '.ini-ctr.hoje:not(.zero) .ini-ctr-t b{ color:var(--ini-erro-texto); }'), 'contadores de Prazos como cartões, vermelho só em hoje com prazo');
ok(str_contains($detJs, 'idt-pub-link-txt') && str_contains($detJs, "g.rotulo === 'Partes' ? ''"), 'endereço no teor vira link e o rótulo Partes não se repete');
ok(str_contains($inicio, 'data-novo="processo"') && str_contains($inicio, 'data-novo="tarefa"') && str_contains($inicio, 'data-novo="publicacoes"'), 'os três botões da faixa abrem o pop-up');
ok(!str_contains($detJs, 'alert(') && !str_contains($detJs, 'confirm(') && !str_contains($detJs, '—'), 'pop-up sem diálogo nativo e sem travessão');

// ── 4. As folhas ─────────────────────────────────────────────────────────────
secao('4. Folhas de estilo');
foreach (['inicio.css', 'interface-nova.css', 'interface-nova-telas.css'] as $f) {
    $css = (string) file_get_contents($raiz . '/public/assets/' . $f);
    ok($css !== '', "$f existe");
    ok(str_contains($css, 'html[data-theme="light"]'), "$f tem o tema claro");
    ok(!str_contains($css, 'text-overflow'), "$f não esconde dado com reticências");
    ok(!str_contains($css, '—'), "$f sem travessão");
    ok(!preg_match('/#(7c3aed|8b5cf6|a855f7|6d28d9)/i', $css), "$f sem roxo");
    // "Azul forte no sentido de cor, não de neon": nenhum brilho largo nem o azul elétrico antigo.
    ok(!preg_match('/0 0 \d{2,}px rgba\(\s*(47|91),/', $css), "$f sem brilho neon");
    ok(!preg_match('/#(2F6FEA|5B93FF|1F5AE0|3B78F0)\b/i', $css), "$f sem o azul elétrico da primeira versão");
}
$telas = (string) file_get_contents($raiz . '/public/assets/interface-nova-telas.css');
ok(!preg_match('/(?<![-\w])(display|width|position|float|margin|padding)\s*:/', preg_replace('/\/\*.*?\*\//s', '', $telas)), 'telas: só cor e superfície, nenhuma propriedade de layout');
$sidebarT = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
ok(str_contains($sidebarT, "if (\$_ap !== 'dashboard' || !empty(\$_GET['gestao'])): ?>") && str_contains($sidebarT, 'interface-nova-telas.css'), 'menu carrega a folha das telas fora da home, sob a chave');
$popups = (string) @file_get_contents($raiz . '/public/assets/interface-nova-popups.css');
ok($popups !== '' && str_contains($sidebarT, '/assets/interface-nova-popups.css'), 'o menu carrega a folha do tamanho dos pop-ups');
ok(str_contains($popups, 'width:min(980px, calc(100vw - 32px)) !important;') && str_contains($popups, 'flex-wrap:wrap !important; overflow:visible !important;'), 'o painel da tarefa cresce e as abas quebram linha, sem rolagem lateral');
ok(!str_contains($popups, '—') && !str_contains($popups, 'text-overflow'), 'folha dos pop-ups sem travessão e sem reticências');
$nova = (string) file_get_contents($raiz . '/public/assets/interface-nova.css');
// Contraste de leitura (11/11/2026, "escuro com escuro, não dá para ler"): texto azul e vermelho têm tom próprio.
$iniCss = (string) file_get_contents($raiz . '/public/assets/inicio.css');
$telasCss = (string) file_get_contents($raiz . '/public/assets/interface-nova-telas.css');
$sisCss = (string) file_get_contents($raiz . '/public/assets/interface-nova-sistema.css');
ok(preg_match('/(?<![-\w])color:\s*var\(--ini-(azul2|erro)\)/', $iniCss) === 0, 'home: texto nunca usa o azul de botão nem o vermelho de fundo');
ok(preg_match('/(?<![-\w])color:\s*var\(--inn(-t)?-(azul2|erro)\)/', $nova . $sisCss . $telasCss) === 0, 'telas e menu: o mesmo');
ok(str_contains($iniCss, '--ini-texto3:#9AA3AE;') && str_contains($iniCss, '--ini-texto3:#5F6878;') && str_contains($telasCss, '--inn-t-texto3:#9AA3AE;') && str_contains($nova, '--inn-texto3:#9AA3AE;'), 'o cinza apagado passa de 4,5:1 nos dois temas');
// A guarda de contraste nas telas de dentro (11/11/2026): mede cada texto contra o fundo real.
$guarda = (string) @file_get_contents($raiz . '/public/assets/interface-nova-contraste.js');
$sbG = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
$pMov = strpos($sbG, '/assets/interface-nova-movimento.js'); $pGua = strpos($sbG, '/assets/interface-nova-contraste.js'); $pTelas = strpos($sbG, 'interface-nova-telas.css');
ok($guarda !== '' && $pGua !== false && $pGua > $pMov && $pGua > $pTelas, 'a guarda de contraste carrega nas telas de dentro, depois da camada');
ok(str_contains($guarda, 'var minimo = grande ? 3 : 4.5;') && str_contains($guarda, "cinza:    ['texto3', 'texto2', 'texto']") && str_contains($guarda, "azul:     ['azul-texto', 'texto']"), 'a guarda: 4,5:1 (3:1 grande) e a troca pelo mesmo tom');
ok(str_contains($guarda, "attributeFilter: ['class', 'hidden']") && str_contains($guarda, 'function semMarcas(c)') && !str_contains($guarda, "attributeFilter: ['class', 'style'"), 'a guarda não acorda com as marcas inn-* nem com style (sem laço com a camada)');
ok(str_contains($guarda, "':is(#inn-ct-medindo, *)") , 'a guarda mede a cor final, sem transição no meio');
ok(str_contains($sisCss, 'html:root body .inn-ct-texto3.inn-ct-texto3.inn-ct-texto3.inn-ct-texto3.inn-ct-texto3.inn-ct-texto3{ color:var(--inn-t-texto3) !important; }') && str_contains($sisCss, '.inn-ct-branco.inn-ct-branco'), 'as classes da guarda vencem as regras da camada');
ok(str_contains($iniCss, '.ini-dia-lista > *, .ini-lista > *, .ini-equipe > *{ flex-shrink:0; }'), 'cartão da home nunca encolhe e corta o texto');
// O item ativo do menu tem o MESMO azul do botão primário (11/11/2026, "esse azul da sidebar tá muito diferente"): sem verniz nem relevo.
ok(str_contains(str_replace("", '', $nova), "html .sidebar nav a.active{
  background:linear-gradient(180deg, var(--inn-azul2), var(--inn-azul)) !important;") && str_contains($nova, 'html .sidebar nav a.active::before{ display:none !important; }'), 'item ativo do menu igual ao botão primário, sem verniz');
// Pop-up de outro módulo transparente nos dois temas (11/11/2026, "fica tudo branco no tema escuro").
ok(str_contains($nova, 'html .inn-emb-quadro, html .idt-embed-quadro{ color-scheme:normal !important; }'), 'o iframe do pop-up tem o mesmo color-scheme da página de dentro: fundo borrado, nunca branco');
// O aviso de cookies na paleta da casa (11/11/2026, "não está no padrão de cor"): painel, botões iguais aos da home.
ok(str_contains($nova, 'html #yuris-cookie-banner{ background:var(--inn-painel);') && str_contains($nova, 'html #yuris-cookie-banner button.primary, html #yuris-cookie-prefs button.primary{ background:linear-gradient(180deg, var(--inn-azul2), var(--inn-azul)) !important;') && str_contains($nova, 'html #yuris-cookie-prefs .ycc-cat{ background:var(--inn-painel-fundo);'), 'o aviso de cookies no padrão de cor: painel da casa, botão azul da home, categorias afundadas');
ok(str_contains($nova, 'background:linear-gradient(rgba(46,85,184,.13), rgba(46,85,184,.13)), var(--inn-fundo) !important;') && !str_contains($nova, 'background:#FFFFFF !important;') && str_contains($nova, '.sidebar-group.has-active .sidebar-group-icon svg *{ color:inherit !important; stroke:currentColor !important; }'), 'grupo com a página atual: ícone azul na caixa afundada com véu azul, nunca caixa branca');
ok(!str_contains($sidebarT, 'InterfaceNova::contaLigada(') && !str_contains($dash, 'InterfaceNova::contaLigada('), 'menu e dashboard não consultam mais a chave da conta (vale para todos)');
ok(str_contains($inicio, '$pode = Painel::pode($_adminInicio, $_permsInicio);') && str_contains($inicio, 'Painel::montar($escopoCentral, $tenantIds, $pode)'), 'a home pede a permissão antes de ler o banco');
foreach (["<?php if (\$pode['intimacoes']): ?>\n        <section class=\"ini-card\">", "<?php endif; if (\$pode['processos']): ?>", "<?php if (\$pode['dashboard']): ?>\n        <a class=\"ini-link-topo\"", "<?php if (\$pode['intimacoes'] && \$painel['publicacoes']['total'] > 0): ?>"] as $gancho)
    ok(str_contains(str_replace("\r", '', $inicio), $gancho), 'bloco com permissão: ' . substr($gancho, 0, 50));
ok(str_contains($inicio, 'ini-grade ini-g4 ini-n<?= $nKpi ?>') && str_contains($inicio, 'ini-grade ini-g3 ini-n<?= $nBaixo ?>'), 'as grades contam os blocos que ficaram');
ok(str_contains((string) file_get_contents($raiz . '/public/assets/inicio.css'), '.ini-g4.ini-n3{ grid-template-columns:repeat(3,1fr); }'), 'a grade se fecha sem vão');
ok(!str_contains($inicio, 'Sair da prévia') && !str_contains($inicio, 'Prévia da interface nova') && !str_contains($inicio, 'voltar ao desenho de sempre'), 'a home não fala mais em prévia nem oferece voltar ao desenho antigo');
ok(is_file($raiz . '/scripts/interface_nova_conta.php'), 'existe o script que liga a conta');
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
    $semChat = Painel::montar(['user_id' => 2, 'is_admin' => false, 'contas_tarefas' => [1], 'contas_processos' => [1]], [1],
        Painel::pode(false, ['tarefas']));
    ok($semChat['whatsapp'] === null && $semChat['publicacoes'] === ['total' => 0, 'itens' => []] && $semChat['processos'] === [] && $semChat['kpis']['ativos'] === 0, 'só Tarefas: sem conversa, publicação e processo, nem lidos');
    ok(!array_filter($semChat['agenda'], static fn($i) => ($i['origem'] ?? '') !== 'tarefa'), 'só Tarefas: a agenda só tem tarefa de quadro');
    ok($semChat['pode']['agenda'] && !$semChat['pode']['chat'], 'a home recebe o que pode');
    // Uma conta que não existe não vê nada.
    $vazio = Painel::montar(['user_id' => 0, 'is_admin' => false, 'contas_tarefas' => [], 'contas_processos' => []], [999999]);
    ok($vazio['processos'] === [] && $vazio['equipe'] === [] && $vazio['publicacoes']['total'] === 0 && $vazio['whatsapp'] === null, 'conta sem nada: tudo vazio, sem erro');
} catch (\Throwable $e) {
    echo "  [SKIP] sem banco (" . $e->getMessage() . ")\n";
}

echo "\n$PASSES ok, $FAILS falhas\n";
exit($FAILS > 0 ? 1 : 0);
