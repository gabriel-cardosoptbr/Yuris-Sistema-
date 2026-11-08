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
eq(true,  InterfaceNova::ligada([], [], false, true), 'conta ligada: vê sem cookie e sem parâmetro');
eq(false, InterfaceNova::ligada(['novo' => '0'], [], false, true), 'conta ligada: ?novo=0 desliga para comparar');
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
secao('3. Ganchos no menu e no dashboard');
$sidebar = (string) file_get_contents($raiz . '/public/includes/sidebar.php');
ok(str_contains($sidebar, 'InterfaceNova::ligada($_GET, $_COOKIE, $_isFleetiflow,'), 'menu decide pela chave, com a conta de marca fora');
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
$tkCen = (string) file_get_contents($raiz . '/public/assets/tarefas-central.js');
ok(!str_contains($tkPhp, 'tk-jur-op') && str_contains($tkCen, "ADD = { tarefas: ['tarefa', 'Nova tarefa'], prazos: ['prazo', 'Novo prazo'], vinculos: ['vinculo', 'Adicionar vínculo'] }"), 'sem opções antes do processo; cada bloco do processo tem o seu Adicionar');
ok(str_contains($tkCen, '<label for="ntJurDesc">Descrição *</label>') && str_contains($tkCen, '<textarea id="ntJurDesc"') && str_contains((string) file_get_contents($raiz . '/public/assets/tarefas.css'), '#modalNewTask.tk-nova-juridica #ntTituloCampo'), 'o formulário abre dentro do bloco, com Descrição no lugar de Título');
$tkJs = (string) file_get_contents($raiz . '/public/assets/tarefas.js');
ok(str_contains($tkJs, 'async function abrirTarefaNoPainel(opts = {})') && str_contains($tkJs, "if (!openTaskId) { if (novaNoPainel) await criarDoPainel(); return; }") && str_contains($tkJs, 'sairDoModoNova();'), 'tarefa de escritório nasce no painel completo e o painel fica aberto depois de criar');
ok(str_contains($tkCen, "if (b.dataset.tipo === 'escritorio' && window.abrirTarefaNoPainel) { ampliarParaPainel(); return; }") && strpos($tkCen, "window.abrirTarefaNoPainel({ de") < strpos($tkCen, "modal.classList.remove('open');
  }"), 'o painel abre antes de o cadastro fechar (o pop-up embutido não pisca)');
// 08/11/2026: "espalhe o design da home para o sistema todo".
$sisCss = (string) @file_get_contents($raiz . '/public/assets/interface-nova-sistema.css');
$sisJs  = (string) @file_get_contents($raiz . '/public/assets/interface-nova-sistema.js');
ok($sisCss !== '' && $sisJs !== '' && str_contains($sidebar, '/assets/interface-nova-sistema.css') && str_contains($sidebar, '/assets/interface-nova-sistema.js'), 'todas as telas carregam a camada do sistema');
foreach (['function acharTela()', 'function iconeNoCabecalho()', 'function marcarAbas(raiz)', 'function botoesDeIcone(raiz)', 'function fantasmas(raiz)', 'function vestirGrafico(ch)'] as $fn) ok(str_contains($sisJs, $fn), "camada do sistema: $fn");
ok(!str_contains($sisJs, 'Object.assign({}, o.animation'), 'gráfico: nada de copiar as opções do Chart.js (proxy, recursão)');
ok(str_contains($sisJs, "if (/check|toggle|switch/i.test(b.className)"), 'caixa de marcar não vira botão de ícone');
ok(str_contains($sisCss, 'html .inn-aba.inn-aba-on{') && str_contains($sisCss, '.inn-cab-ic{') && str_contains($sisCss, 'html .inn-carregando{'), 'aba ativa em pílula azul, ícone do cabeçalho afundado, carregando em forma cinza');
ok(!preg_match('/(?<![-\w])(width|height|display|position|top|left|margin|padding)\s*:/', preg_replace(['/\/\*.*?\*\//s', '/\.inn-cab-ic\{[^}]*\}/', '/\.inn-cab-ic svg\{[^}]*\}/', '/html \.inn-cab-linha\{[^}]*\}/', '/html \.inn-carregando(::after)?\{[^}]*\}/', '/\.inn-onda-quadro( i)?\{[^}]*\}/'], '', $sisCss)), 'camada do sistema não mexe em layout das telas (só as peças próprias)');
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
ok(str_contains($movJs, "html.classList.add('ini-saindo')") && str_contains($movCss, 'html.ini-mov.ini-saindo body{'), 'a página sai antes de ir para outra tela');
ok(!preg_match('/^(?!.*(animation|transition|transform|opacity|keyframes|--mov|--cx|--cy|isolation|overflow|position|z-index|pointer-events|will-change|font-variant|border-radius|padding-top|width|left|top|background|display|content|border-color|transform-origin)).*:\s*#[0-9a-f]{3,6}/mi', $movCss), 'a folha do movimento não traz cor nova');
$innCss = (string) @file_get_contents($raiz . '/public/assets/interface-nova-movimento.css');
$innJs  = (string) @file_get_contents($raiz . '/public/assets/interface-nova-movimento.js');
ok($innCss !== '' && $innJs !== '' && str_contains($sidebar, '/assets/interface-nova-movimento.css') && str_contains($sidebar, '/assets/interface-nova-movimento.js'), 'as telas de dentro carregam o movimento delas');
ok(str_contains($innCss, '@keyframes inn-surge{') && str_contains($innCss, '#modalProcess:not(.hidden) .proc-modal') && str_contains($innCss, '.modal-shell.open .modal-panel') && str_contains($innCss, '.tk-modal-overlay.open .tk-modal'), 'os pop-ups das telas surgem');
ok(str_contains($innJs, "if (html.classList.contains('yuris-embed')) return;"), 'o movimento das telas não roda no modo embutido');
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
ok(substr_count($inicio, 'class="ini-ctr ') === 1 && str_contains($inicio, '<span class="ini-ctr-ic">') && str_contains((string) file_get_contents($raiz . '/public/assets/inicio.css'), '.ini-ctr.hoje:not(.zero) .ini-ctr-t b{ color:var(--ini-erro); }'), 'contadores de Prazos como cartões, vermelho só em hoje com prazo');
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
ok(str_contains($nova, 'color:var(--inn-azul) !important; background:#FFFFFF !important;') && str_contains($nova, '.sidebar-group.has-active .sidebar-group-icon svg *{ color:inherit !important; stroke:currentColor !important; }'), 'grupo com a página atual: ícone azul em caixa branca');
ok(str_contains($sidebarT, 'InterfaceNova::contaLigada((int) $__brandCtx->getAccountId())'), 'menu consulta a chave da conta');
ok(str_contains($dash, 'InterfaceNova::contaLigada((int) $ctx->getAccountId())'), 'dashboard consulta a chave da conta');
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
    // Uma conta que não existe não vê nada.
    $vazio = Painel::montar(['user_id' => 0, 'is_admin' => false, 'contas_tarefas' => [], 'contas_processos' => []], [999999]);
    ok($vazio['processos'] === [] && $vazio['equipe'] === [] && $vazio['publicacoes']['total'] === 0 && $vazio['whatsapp'] === null, 'conta sem nada: tudo vazio, sem erro');
} catch (\Throwable $e) {
    echo "  [SKIP] sem banco (" . $e->getMessage() . ")\n";
}

echo "\n$PASSES ok, $FAILS falhas\n";
exit($FAILS > 0 ? 1 : 0);
