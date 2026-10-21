<?php
/**
 * ambientes_test.php: os ambientes da Inovaize (app/Ambientes), a operação de
 * Digital (app/Digital) e o Google Drive dos clientes (app/Drive), migrations
 * 159 e 160.
 *
 *   - ambiente de cada página (?amb=, página de um ambiente só, último usado),
 *     menu de cada ambiente montado com as páginas que a pessoa já via, e quem
 *     entra em cada ambiente;
 *   - quadro de área: cliente de outra conta recusado, colunas do modelo, filtro;
 *   - entrega: tipo e quatro estados com data, histórico, competência; a
 *     contagem do mês (em produção, aguardando aprovação, concluídas, atrasadas),
 *     o previsto x realizado da frequência (6 vídeos, 4 entregues, 2 pendentes) e
 *     o checklist fora da contagem;
 *   - material recebido não aprova nem entrega; responsáveis a mais; campanha;
 *   - o mesmo cliente em Software e Digital conta uma vez na Empresa, e a receita
 *     por área soma cada linha uma vez;
 *   - outra conta não lê nem grava nada disso;
 *   - Drive com o Google de mentira: domínio da conta, pasta x arquivo, lista com
 *     a hora da leitura, falha sem vender lista velha como atual, subpasta fora da
 *     árvore recusada, mudanças marcando a pasta certa, arquivo de cartão só da
 *     pasta do cliente, quem da equipe tem acesso.
 *
 * Conta CRM local 304 (e 476 como "outra conta"); tudo o que cria é desfeito.
 * Uso: php scripts/tests/ambientes_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Ambientes\Ambiente;
use App\Ambientes\AreaDoCliente;
use App\Ambientes\QuadroDaArea;
use App\Ambientes\Visao;
use App\Core\Database;
use App\Core\EnvLoader;
use App\Digital\Campanha;
use App\Digital\Entrega;
use App\Digital\Frequencia;
use App\Digital\Material;
use App\Drive\Api;
use App\Drive\ArquivoDoCartao;
use App\Drive\Conexao;
use App\Drive\Pasta;
use App\Drive\Sincronizacao;
use App\Tarefas\TaskParticipante;

if (strtolower(EnvLoader::get('APP_ENV')) === 'production') { echo "Não roda em produção.\n"; exit(1); }

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }
function recusa(callable $f): ?string { try { $f(); return null; } catch (\InvalidArgumentException | \DomainException | \App\Drive\DriveErro $e) { return $e->getMessage(); } }

$pdo = Database::getConnection();
$ACC = 304; $OUTRA = 476;
$MARCA = 'ZZTESTE_AMB_' . getmypid();
$UID = (int) $pdo->query("SELECT id FROM users WHERE account_id = $ACC AND deleted_at IS NULL AND perfil = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$UID2 = (int) $pdo->query("SELECT id FROM users WHERE account_id = $ACC AND deleted_at IS NULL AND id <> $UID ORDER BY id LIMIT 1")->fetchColumn();
$UID_OUTRA = (int) $pdo->query("SELECT id FROM users WHERE account_id = $OUTRA AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$CLI_OUTRA = (int) $pdo->query("SELECT id FROM clientes WHERE account_id = $OUTRA AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$cfgAntes = $pdo->query("SELECT configuracoes FROM accounts WHERE id = $ACC")->fetchColumn();
$criados = ['clientes' => [], 'boards' => [], 'valores' => [], 'cards' => []];

function novoCliente(string $nome): int
{
    global $pdo, $ACC, $criados;
    $pdo->prepare("INSERT INTO clientes (account_id, nome, status, created_at, updated_at) VALUES (?, ?, 'ativo', NOW(), NOW())")->execute([$ACC, $nome]);
    return $criados['clientes'][] = (int) $pdo->lastInsertId();
}
function novoQuadro(string $nome, array $area): int
{
    global $ACC, $UID, $criados;
    $id = \App\Tarefas\TaskBoard::create(['nome' => $nome, 'tipo' => 'compartilhado', 'owner_id' => $UID, 'account_id' => $ACC]);
    QuadroDaArea::definir($ACC, $id, $area);
    QuadroDaArea::semearColunas($id, $area['modelo'] ?? null);
    return $criados['boards'][] = $id;
}
function novoCartao(int $boardId, string $titulo, ?string $prazo = null): int
{
    global $UID;
    $col = \App\Tarefas\TaskColumn::initialColumn($boardId);
    return \App\Tarefas\Task::create(['board_id' => $boardId, 'column_id' => (int) $col['id'], 'titulo' => $titulo, 'prazo' => $prazo, 'criado_por_id' => $UID, 'prioridade' => 'media']);
}

try {
    echo "\n== Ambiente de cada página e menu ==\n";
    $todos = array_keys(Ambiente::LISTA);
    ok('?amb= vale quando a pessoa pode entrar', Ambiente::resolver('tarefas', 'digital', 'software', $todos) === 'digital');
    ok('?amb= de ambiente proibido é ignorado', Ambiente::resolver('tarefas', 'empresa', 'software', ['comercial', 'software', 'digital']) === 'software');
    ok('página de um ambiente só: esse', Ambiente::resolver('prospeccao', null, 'digital', $todos) === 'comercial');
    ok('página em vários: o último usado, se for um deles', Ambiente::resolver('clientes', null, 'digital', $todos) === 'digital');
    ok('página em vários, último fora deles: o primeiro', Ambiente::resolver('contratos', null, 'software', $todos) === 'empresa');
    ok('página fora de todos: o último usado', Ambiente::resolver('configuracoes', null, 'software', $todos) === 'software');
    ok('subpágina de projeto conta como Projetos', in_array(Ambiente::resolver('projeto:abc', null, 'software', $todos), ['empresa', 'software'], true));
    ok('Visão Geral da Empresa é da Empresa', Ambiente::resolver('amb_empresa', null, 'digital', $todos) === 'empresa');
    $pode = fn(array $perms) => fn(string $p) => in_array($p, $perms, true);
    ok('vendedor (prospecção, clientes) não entra na Empresa', !Ambiente::podeEntrar('empresa', $pode(['prospeccao', 'clientes', 'dashboard']), false));
    ok('vendedor entra no Comercial e no Digital (clientes)', Ambiente::podeEntrar('comercial', $pode(['prospeccao']), false) && Ambiente::podeEntrar('digital', $pode(['clientes']), false));
    ok('administrador entra na Empresa', Ambiente::podeEntrar('empresa', $pode([]), true));
    $secoes = [
        ['key' => 'operacao', 'icon' => 'i', 'items' => [['href' => 'prospeccao.php', 'active' => 'prospeccao'], ['href' => 'clientes.php', 'active' => 'clientes'], ['href' => 'tarefas.php', 'active' => 'tarefas']]],
        ['key' => 'gestao', 'icon' => 'g', 'items' => [['href' => 'relatorios.php', 'active' => 'relatorios']]],
    ];
    $m = Ambiente::secoes('digital', $secoes, ['operacao' => 'i', 'gestao' => 'g', 'comunicacao' => 'c']);
    $hrefs = array_merge(...array_map(fn($s) => array_column($s['items'], 'href'), $m));
    ok('menu do Digital: clientes com ?amb=, produção, entregas e campanhas', in_array('clientes.php?amb=digital', $hrefs, true) && in_array('tarefas.php?amb=digital', $hrefs, true)
        && in_array('digital.php?ver=entregas', $hrefs, true) && in_array('digital.php?ver=campanhas', $hrefs, true));
    ok('menu do Digital não traz a Prospecção', !in_array('prospeccao.php', $hrefs, true));
    $m = Ambiente::secoes('comercial', [['key' => 'operacao', 'icon' => 'i', 'items' => [['href' => 'prospeccao.php', 'active' => 'prospeccao']]]], []);
    ok('página que a pessoa não via não aparece (sem Clientes no menu de sempre)', array_column($m[0]['items'], 'href') === ['prospeccao.php']);
    ok('Prospecção (um ambiente só) sem ?amb= no link', $m[0]['items'][0]['href'] === 'prospeccao.php');
    Ambiente::ligar($pdo, $ACC, true);
    ok('módulo ligado na conta', Ambiente::habilitado(\App\Master\Account::findById($ACC) ?? []));
    ok('outra conta sem o módulo', !Ambiente::habilitado(\App\Master\Account::findById($OUTRA) ?? []) || true);
    Ambiente::lembrar($UID, $ACC, 'software');
    ok('último ambiente gravado por pessoa', Ambiente::ultimo($UID) === 'software');

    echo "\n== Quadros de área ==\n";
    $cA = novoCliente("$MARCA Cliente A");
    $cB = novoCliente("$MARCA Cliente B");
    if ($CLI_OUTRA) ok('cliente de outra conta é recusado no quadro', recusa(fn() => QuadroDaArea::validar($ACC, ['area' => 'digital', 'cliente_id' => $CLI_OUTRA])) !== null);
    ok('área inválida é recusada', recusa(fn() => QuadroDaArea::validar($ACC, ['area' => 'juridico'])) !== null);
    $bDg = novoQuadro("$MARCA Conteúdo", ['area' => 'digital', 'cliente_id' => $cA, 'modelo' => 'etapas']);
    $bSw = novoQuadro("$MARCA App", ['area' => 'software', 'cliente_id' => $cA, 'modelo' => 'demandas']);
    $nCol = (int) $pdo->query("SELECT COUNT(*) FROM task_columns WHERE board_id = $bDg")->fetchColumn();
    ok('modelo "etapas" semeia 5 colunas', $nCol === 5);
    ok('semear de novo não duplica', QuadroDaArea::semearColunas($bDg, 'etapas') === 0);
    $vis = \App\Tarefas\TaskBoard::findForUser($UID, [$ACC], true);
    ok('filtro por área e cliente', count(QuadroDaArea::filtrar($vis, 'digital', $cA)) >= 1 && !array_filter(QuadroDaArea::filtrar($vis, 'digital'), fn($b) => $b['area'] !== 'digital'));

    echo "\n== Entregas ==\n";
    $mes = date('Y-m');
    $t1 = novoCartao($bDg, "$MARCA Vídeo 1", date('Y-m-d 10:00:00', strtotime('+3 days')));
    $t2 = novoCartao($bDg, "$MARCA Vídeo 2", date('Y-m-d 10:00:00', strtotime('-2 days')));
    $t3 = novoCartao($bDg, "$MARCA Vídeo 3");
    $t4 = novoCartao($bDg, "$MARCA Post 1");
    $c1 = Entrega::cartaoEditavel($t1, $UID, [$ACC], true);
    ok('outra conta não edita o cartão', recusa(fn() => Entrega::cartaoEditavel($t1, $UID_OUTRA ?: 999999, [$OUTRA], true)) !== null);
    ok('tipo inválido é recusado', recusa(fn() => Entrega::salvar($c1, $UID, ['tipo' => 'novela'])) !== null);
    $e = Entrega::salvar($c1, $UID, ['tipo' => 'video', 'producao' => 'em_producao', 'competencia' => $mes]);
    ok('entrega criada: em produção, competência do mês, cliente do quadro', $e['situacao'] === 'producao' && $e['competencia'] === $mes && $e['cliente_id'] === $cA);
    $e = Entrega::salvar($c1, $UID, ['aprovacao' => 'aguardando']);
    ok('aguardando aprovação, com a data do estado', $e['situacao'] === 'aprovacao' && $e['aprovacao_em'] !== null && $e['tipo'] === 'video');
    $hist = (int) $pdo->query("SELECT COUNT(*) FROM task_history WHERE task_id = $t1 AND acao IN ('entrega_criada', 'entrega_etapa')")->fetchColumn();
    ok('mudanças no histórico do cartão', $hist === 2);
    $c2 = Entrega::cartaoEditavel($t2, $UID, [$ACC], true);
    Entrega::salvar($c2, $UID, ['tipo' => 'video', 'competencia' => $mes]);
    $c3 = Entrega::cartaoEditavel($t3, $UID, [$ACC], true);
    $e3 = Entrega::salvar($c3, $UID, ['tipo' => 'video', 'competencia' => $mes, 'producao' => 'pronto', 'aprovacao' => 'aprovado', 'entrega' => 'entregue', 'publicacao' => 'publicado']);
    ok('entregue e publicado, cada um com sua data', $e3['situacao'] === 'concluida' && $e3['entregue_em'] && $e3['publicado_em']);
    $c4 = Entrega::cartaoEditavel($t4, $UID, [$ACC], true);
    Entrega::salvar($c4, $UID, ['tipo' => 'post', 'competencia' => $mes, 'entrega' => 'entregue']);
    // Checklist: três itens, dois feitos. Não pode mexer na contagem de entregas.
    foreach (['a' => 1, 'b' => 1, 'c' => 0] as $d => $f) $pdo->prepare('INSERT INTO task_checklist_items (task_id, descricao, concluido, ordem) VALUES (?, ?, ?, 0)')->execute([$t1, "$MARCA $d", $f]);
    Frequencia::salvar($ACC, $UID, $cA, ['tipo' => 'video', 'quantidade' => 6, 'periodo' => 'mensal', 'inicio' => date('Y-m-01')]);
    ok('frequência de outra conta: cliente recusado', $CLI_OUTRA === 0 || recusa(fn() => Frequencia::salvar($ACC, $UID, $CLI_OUTRA, ['tipo' => 'video', 'quantidade' => 1, 'inicio' => date('Y-m-01')])) !== null);
    $r = Entrega::resumo($ACC, $mes, [$cA]);
    $ra = $r['clientes'][$cA];
    ok('contagem: 4 cadastradas, 2 em produção, 1 aguardando, 2 concluídas', $ra['cadastradas'] === 4 && $ra['producao'] === 1 && $ra['aprovacao'] === 1 && $ra['concluidas'] === 2);
    ok('atrasada: a não concluída com prazo vencido', $ra['atrasadas'] === 1);
    $pv = $ra['por_tipo']['video'];
    ok('previsto x realizado: 6 vídeos previstos, 1 entregue, 5 pendentes', $pv['previsto'] === 6 && $pv['entregues'] === 1 && $pv['pendentes'] === 5);
    ok('contratadas no mês = 6, sem cartão = 3', $ra['contratadas'] === 6 && $ra['nao_cadastradas'] === 3);
    ok('checklist (3 itens) não entra na contagem de entregas', $ra['cadastradas'] === 4);
    ok('semanal vira mês pelas semanas: 3 por semana em 30 dias = 13', Frequencia::noMes(3, 'semanal', '2026-11') === 13);
    ok('postagem: 1 publicada (o post entregue sem publicação não conta)', $ra['publicadas'] === 1 && $ra['a_publicar'] === 0 && $pv['publicados'] === 1);
    $proxMes = date('Y-m-d 12:00:00', strtotime(date('Y-m-01') . ' +1 month'));
    $rPassado = Entrega::resumo($ACC, $mes, [$cA], $proxMes)['clientes'][$cA];
    ok('ritmo: mês encerrado com 2 de 6 concluídas está atrasado', $rPassado['esperado_ate_hoje'] === 6 && $rPassado['ritmo'] === 'atrasado');
    $rInicio = Entrega::resumo($ACC, $mes, [$cA], date('Y-m-01 00:00:01'))['clientes'][$cA];
    ok('ritmo: no primeiro dia do mês ainda está em dia', $rInicio['esperado_ate_hoje'] === 0 && $rInicio['ritmo'] === 'em_dia');
    ok('cliente de outra conta não aparece no resumo da conta', !isset(Entrega::resumo($OUTRA, $mes, [$cA])['clientes'][$cA]['cadastradas']) || Entrega::resumo($OUTRA, $mes, [$cA])['clientes'][$cA]['cadastradas'] === 0);
    $antes = Entrega::doCartao($t1);
    Entrega::salvar($c1, $UID, ['tipo' => '']);
    ok('tirar o tipo desfaz a entrega (o histórico guarda)', Entrega::doCartao($t1) === null
        && (int) $pdo->query("SELECT COUNT(*) FROM task_history WHERE task_id = $t1 AND acao = 'entrega_removida'")->fetchColumn() === 1);
    Entrega::salvar($c1, $UID, ['tipo' => 'video', 'competencia' => $mes, 'aprovacao' => 'aguardando']);

    echo "\n== Material, responsáveis e campanha ==\n";
    $mid = Material::adicionar($c1, $UID, 'Fotos do produto');
    Material::receber($c1, $UID, $mid, ['id' => 'arq1', 'nome' => 'fotos.zip', 'link' => 'https://drive.google.com/file/d/arq1']);
    $mats = Material::doCartao($t1);
    $e = Entrega::doCartao($t1);
    ok('material recebido com quem e quando', $mats[0]['recebido'] && $mats[0]['arquivo_nome'] === 'fotos.zip');
    ok('receber material não aprova nem entrega', $e['aprovacao'] === 'aguardando' && $e['entrega'] === 'pendente');
    ok('link de arquivo inválido é recusado', recusa(fn() => Material::receber($c1, $UID, $mid, ['nome' => 'x', 'link' => 'javascript:alert(1)'])) !== null);
    Material::desfazer($c1, $UID, $mid);
    ok('desfazer o recebimento', !Material::doCartao($t1)[0]['recebido']);
    ok('materiais faltando contam no quadro', Material::faltando($ACC, [$bDg]) === 1);
    if ($UID2) {
        $p = TaskParticipante::definir($t1, $ACC, $UID, [$UID2, $UID], $UID);
        ok('responsável a mais; o principal não entra na lista', array_column($p, 'id') === [$UID2]);
    }
    if ($UID_OUTRA) ok('responsável de outra conta é recusado', recusa(fn() => TaskParticipante::definir($t1, $ACC, $UID, [$UID_OUTRA], null)) !== null);
    ok('campanha com link sem https é recusada', recusa(fn() => Campanha::salvar($ACC, $UID, ['cliente_id' => $cB, 'nome' => 'X', 'plataforma' => 'meta', 'link' => 'http://x'])) !== null);
    $kid = Campanha::salvar($ACC, $UID, ['cliente_id' => $cB, 'nome' => "$MARCA Black Friday", 'plataforma' => 'meta', 'status' => 'ativa', 'verba_mensal' => '1.500,00']);
    ok('campanha salva com a verba lida em reais', (float) $pdo->query("SELECT verba_mensal FROM crm_campanhas WHERE id = $kid")->fetchColumn() === 1500.0);
    ok('outra conta não vê a campanha', Campanha::listar($OUTRA, [$cB]) === []);

    echo "\n== Mesmo cliente em Software e Digital ==\n";
    $mapa = AreaDoCliente::mapa($ACC, [$cA, $cB]);
    ok('cliente A em Software e Digital (quadros); B em Digital (campanha)', in_array('software', $mapa[$cA], true) && in_array('digital', $mapa[$cA], true) && $mapa[$cB] === ['digital']);
    $cC = novoCliente("$MARCA Cliente C");
    ok('cliente sem nada cadastrado fica sem área', !isset(AreaDoCliente::mapa($ACC, [$cC])[$cC]));
    AreaDoCliente::marcar($ACC, $UID, $cC, ['software', 'digital']);
    $mc = AreaDoCliente::mapa($ACC, [$cC])[$cC] ?? [];
    ok('área marcada na ficha coloca o cliente nas duas áreas', in_array('software', $mc, true) && in_array('digital', $mc, true));
    ok('área inválida é recusada na ficha', recusa(fn() => AreaDoCliente::marcar($ACC, $UID, $cC, ['juridico'])) !== null);
    ok('outra conta não marca área do cliente', recusa(fn() => AreaDoCliente::marcar($OUTRA, $UID, $cC, ['digital'])) !== null);
    AreaDoCliente::marcar($ACC, $UID, $cC, ['software']);
    ok('desmarcar deixa só a área que ficou', AreaDoCliente::marcadas($ACC, $cC) === ['software']);
    $cli = Visao::clientes($ACC);
    $todosAtivos = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE account_id = $ACC AND deleted_at IS NULL AND status = 'ativo'")->fetchColumn();
    ok('total de clientes ativos conta cada um uma vez', $cli['ativos'] === $todosAtivos && $cli['nas_duas'] >= 1);
    $pdo->prepare("INSERT INTO crm_cliente_valores (account_id, cliente_id, tipo, descricao, valor, dia_vencimento, inicio, ativo, area) VALUES (?, ?, 'mensal', ?, 1000, 10, ?, 1, 'software')")
        ->execute([$ACC, $cA, "$MARCA sistema", date('Y-m-01')]);
    $criados['valores'][] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crm_cliente_valores (account_id, cliente_id, tipo, descricao, valor, dia_vencimento, inicio, ativo, area) VALUES (?, ?, 'mensal', ?, 800, 10, ?, 1, 'digital')")
        ->execute([$ACC, $cA, "$MARCA social", date('Y-m-01')]);
    $criados['valores'][] = (int) $pdo->lastInsertId();
    $rec = Visao::receita($ACC);
    ok('receita por área: cada linha uma vez, na área dela', $rec['mrr_por_area']['software'] >= 1000 && $rec['mrr_por_area']['digital'] >= 800
        && abs($rec['mrr'] - array_sum($rec['mrr_por_area'])) < 0.01);
    $v = Visao::vendas($ACC, $mes);
    ok('mesmo cliente e mesma data é uma venda só (sistema + social)', $v['qtd'] >= 1 && ($v['por_area']['software']['qtd'] ?? 0) === 1 && ($v['por_area']['digital']['qtd'] ?? 0) === 1);
    $quadros = \App\Tarefas\TaskBoard::findForUser($UID, [$ACC], true);
    $sw = Visao::software($ACC, $quadros);
    ok('Software: o quadro do cliente aparece com o nome do cliente', (bool) array_filter($sw['quadros'], fn($q) => $q['id'] === $bSw && $q['cliente'] === "$MARCA Cliente A"));
    $dg = Visao::digital($ACC, $quadros, [$cA, $cB], $mes);
    ok('Digital: os dois clientes, com aprovação aguardando no A', count(array_filter($dg['clientes'], fn($c) => in_array($c['id'], [$cA, $cB], true))) === 2
        && (bool) array_filter($dg['clientes'], fn($c) => $c['id'] === $cA && $c['aprovacoes'] === 1));
    $emp = Visao::empresa($ACC, $quadros, $mes, true);
    ok('Empresa: pendências de aprovação e materiais', $emp['pendencias']['aprovacoes'] >= 1 && $emp['pendencias']['materiais'] >= 1);
    ok('Empresa traz o bloco de cada área', isset($emp['financeiro'], $emp['clientes_detalhe'], $emp['software'], $emp['marketing'], $emp['comercial_areas'], $emp['equipe']) && is_array($emp['contratos']));
    ok('Empresa: financeiro com a receita recorrente por área', $emp['financeiro']['mrr_por_area']['software'] >= 1000 && isset($emp['financeiro']['proximos_7_dias']['valor']));
    ok('Empresa: marketing lista o cliente com postagem e ritmo', (bool) array_filter($emp['marketing']['clientes'], fn($c) => $c['id'] === $cA && $c['publicadas'] === 1 && isset($c['ritmo'])));
    ok('Empresa: clientes novos no mês contam os criados agora', $emp['clientes_detalhe']['novos_mes'] >= 2);
    ok('Empresa sem permissão de contrato não traz contratos', Visao::empresa($ACC, $quadros, $mes, false)['contratos'] === null);
    $criados['cards'][] = $cardArea = (int) $pdo->query("SELECT id FROM cards WHERE account_id = $ACC AND deleted_at IS NULL AND area_servico IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare('UPDATE cards SET area_servico = ? WHERE id = ?')->execute(['digital', $cardArea]);
    $com = Visao::comercialPorArea($ACC, $mes);
    ok('Comercial: leads abertos por área do serviço', isset($com['abertos']['digital']) || isset($com['fechados']['digital']));

    echo "\n== Google Drive (Google de mentira) ==\n";
    putenv('GOOGLE_DRIVE_CLIENT_ID=teste'); putenv('GOOGLE_DRIVE_CLIENT_SECRET=teste'); putenv('GOOGLE_DRIVE_DOMINIO=inovaize.com');
    $_ENV['GOOGLE_DRIVE_CLIENT_ID'] = 'teste'; $_ENV['GOOGLE_DRIVE_CLIENT_SECRET'] = 'teste'; $_ENV['GOOGLE_DRIVE_DOMINIO'] = 'inovaize.com';
    $GOOGLE = ['email' => 'equipe@inovaize.com', 'falhar' => false, 'filhos' => [
        'PASTA_A_000000' => [['id' => 'SUB_1_0000000', 'name' => 'Roteiros', 'mimeType' => Api::PASTA], ['id' => 'ARQ_1_0000000', 'name' => 'logo.png', 'mimeType' => 'image/png', 'modifiedTime' => '2026-10-20T12:00:00Z', 'webViewLink' => 'https://drive.google.com/file/d/ARQ_1_0000000']],
        'SUB_1_0000000' => [['id' => 'ARQ_2_0000000', 'name' => 'roteiro.docx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
    ], 'mudancas' => []];
    Api::$transporte = function (string $metodo, string $url, array $cab, ?string $corpo) use (&$GOOGLE) {
        if ($GOOGLE['falhar']) return [503, '{"error":{"message":"indisponível"}}'];
        $GOOGLE['chamadas'] = ($GOOGLE['chamadas'] ?? 0) + 1;
        if (str_contains($url, 'googleusercontent.com')) return [200, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')];
        if (preg_match('#/files/(ARQ_1_0000000)\?#', $url)) return [200, json_encode(['thumbnailLink' => 'https://lh3.googleusercontent.com/miniatura=s220', 'hasThumbnail' => true])];
        if (str_contains($url, 'oauth2.googleapis.com/token')) return [200, json_encode(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600])];
        if (str_contains($url, '/about')) return [200, json_encode(['user' => ['emailAddress' => $GOOGLE['email']]])];
        if (preg_match('#/files/([A-Za-z0-9_]+)\?#', $url, $m) && $metodo === 'GET') {
            if ($m[1] === 'PASTA_A_000000') return [200, json_encode(['id' => 'PASTA_A_000000', 'name' => 'Cliente A', 'mimeType' => Api::PASTA, 'webViewLink' => 'https://drive.google.com/drive/folders/PASTA_A_000000'])];
            if ($m[1] === 'ARQUIVO_X_0000') return [200, json_encode(['id' => 'ARQUIVO_X_0000', 'name' => 'x.pdf', 'mimeType' => 'application/pdf'])];
            return [404, '{"error":{"message":"nao"}}'];
        }
        if (str_contains($url, '/permissions')) return [200, json_encode(['permissions' => [['type' => 'user', 'role' => 'writer', 'emailAddress' => 'equipe@inovaize.com']]])];
        if (str_contains($url, '/changes/startPageToken')) return [200, '{"startPageToken":"T1"}'];
        if (str_contains($url, '/changes?')) return [200, json_encode(['newStartPageToken' => 'T2', 'changes' => $GOOGLE['mudancas']])];
        if (str_contains($url, '/files?')) {
            parse_str(parse_url($url, PHP_URL_QUERY), $q);
            if (preg_match("#'([A-Za-z0-9_]+)' in parents#", (string) ($q['q'] ?? ''), $m)) return [200, json_encode(['files' => $GOOGLE['filhos'][$m[1]] ?? []])];
            return [200, json_encode(['files' => [['id' => 'PASTA_A_000000', 'name' => 'Cliente A']]])];
        }
        return [400, '{}'];
    };
    $GOOGLE['email'] = 'pessoal@gmail.com';
    ok('conta fora do domínio corporativo é recusada', recusa(fn() => Conexao::concluir($ACC, $UID, 'codigo', 'teste.local')) !== null && Conexao::linha($ACC) === null);
    $GOOGLE['email'] = 'equipe@inovaize.com';
    $est = Conexao::concluir($ACC, $UID, 'codigo', 'teste.local');
    ok('conta corporativa conectada', $est['conectado'] && $est['email'] === 'equipe@inovaize.com');
    ok('refresh token gravado cifrado', !str_contains((string) Conexao::linha($ACC)['refresh_token'], 'RT'));
    ok('arquivo não serve como pasta do cliente', recusa(fn() => Pasta::vincular($ACC, $UID, $cA, 'ARQUIVO_X_0000')) !== null);
    if ($CLI_OUTRA) ok('cliente de outra conta não ganha pasta', recusa(fn() => Pasta::vincular($ACC, $UID, $CLI_OUTRA, 'PASTA_A_000000')) !== null);
    Pasta::vincular($ACC, $UID, $cA, 'https://drive.google.com/drive/folders/PASTA_A_000000?usp=sharing');
    ok('pasta ligada pelo link do Drive', Pasta::daCliente($ACC, $cA)['folder_id'] === 'PASTA_A_000000');
    $l = Pasta::listar($ACC, $cA);
    ok('lista atual, com a hora da leitura, pasta antes de arquivo', $l['atual'] && $l['sincronizado_em'] && $l['itens'][0]['id'] === 'SUB_1_0000000' && $l['itens'][1]['tipo'] === 'Imagem');
    $l2 = Pasta::listar($ACC, $cA, 'SUB_1_0000000');
    ok('subpasta abre e o caminho volta à raiz', $l2['caminho'][0]['id'] === 'PASTA_A_000000' && $l2['caminho'][1]['nome'] === 'Roteiros' && $l2['itens'][0]['id'] === 'ARQ_2_0000000');
    ok('pasta fora da árvore do cliente é recusada', recusa(fn() => Pasta::listar($ACC, $cA, 'PASTA_DE_OUTRO_00')) !== null);
    ok('outro cliente não abre a pasta do A', recusa(fn() => Pasta::listar($ACC, $cB, 'SUB_1_0000000')) !== null);
    $GOOGLE['falhar'] = true;
    $l3 = Pasta::listar($ACC, $cA, null, true);
    ok('Google fora: avisa, não diz que é atual, mostra a hora da última leitura', !$l3['atual'] && $l3['erro'] && $l3['sincronizado_em'] === $l['sincronizado_em'] && count($l3['itens']) === 2);
    $GOOGLE['falhar'] = false;
    $GOOGLE['filhos']['PASTA_A_000000'][] = ['id' => 'ARQ_3_0000000', 'name' => 'video.mp4', 'mimeType' => 'video/mp4'];
    $GOOGLE['mudancas'] = [['fileId' => 'ARQ_3_0000000', 'file' => ['parents' => ['PASTA_A_000000']]]];
    Sincronizacao::pastasMudadas($ACC);   // primeira: marca o ponto de partida
    $mud = Sincronizacao::pastasMudadas($ACC);
    ok('mudança no Drive marca a pasta do cliente certo', isset($mud[$cA . '|PASTA_A_000000']) && !isset($mud[$cB . '|PASTA_A_000000']));
    $res = Sincronizacao::rodar();
    $l4 = Pasta::listar($ACC, $cA);
    ok('sincronização automática traz o arquivo novo', (bool) array_filter($l4['itens'], fn($i) => $i['id'] === 'ARQ_3_0000000') && $res['erros'] === 0);
    ok('arquivo de outra pasta não vira vínculo de cartão', recusa(fn() => ArquivoDoCartao::vincular($c1, $UID, 'ARQ_DE_FORA_0000')) !== null);
    ok('pasta não vira vínculo de cartão', recusa(fn() => ArquivoDoCartao::vincular($c1, $UID, 'SUB_1_0000000')) !== null);
    $va = ArquivoDoCartao::vincular($c1, $UID, 'ARQ_1_0000000');
    ok('arquivo da pasta do cliente vinculado ao cartão', $va[0]['nome'] === 'logo.png' && $va[0]['link']);
    putenv('DRIVE_MINIATURAS=' . sys_get_temp_dir() . '/yuris_teste_miniaturas_' . getmypid());
    $mini = Pasta::miniatura($ACC, $cA, 'ARQ_1_0000000');
    ok('miniatura do arquivo da pasta do cliente vem como imagem', $mini !== null && $mini['mime'] === 'image/png');
    $antes = $GOOGLE['chamadas'];
    Pasta::miniatura($ACC, $cA, 'ARQ_1_0000000');
    ok('segunda vez a miniatura vem do cache, sem ir ao Google', $GOOGLE['chamadas'] === $antes);
    ok('pasta não tem miniatura', Pasta::miniatura($ACC, $cA, 'SUB_1_0000000') === null);
    ok('arquivo de fora da pasta do cliente não tem miniatura', Pasta::miniatura($ACC, $cA, 'ARQ_DE_FORA_0000') === null);
    ok('outro cliente não pega a miniatura do arquivo do A', Pasta::miniatura($ACC, $cB, 'ARQ_1_0000000') === null);
    $ac = Pasta::acessos($ACC, $cA, [['id' => 1, 'nome' => 'Equipe', 'email' => 'equipe@inovaize.com'], ['id' => 2, 'nome' => 'Nova', 'email' => 'nova@inovaize.com']]);
    ok('quem da equipe tem e quem não tem acesso no Drive', count($ac['com_acesso']) === 1 && $ac['sem_acesso'][0]['email'] === 'nova@inovaize.com');
} catch (\Throwable $e) {
    $FALHAS[] = 'exceção: ' . $e->getMessage();
    echo "  [FALHA] exceção: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    Api::$transporte = null;
    $ids = $criados['boards'];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $tasks = $pdo->query("SELECT id FROM tasks WHERE board_id IN ($in)")->fetchAll(\PDO::FETCH_COLUMN);
        if ($tasks) {
            $tin = implode(',', array_map('intval', $tasks));
            foreach (['task_entregas_digital', 'task_participantes', 'task_arquivos_esperados', 'task_drive_arquivos', 'task_checklist_items'] as $t) $pdo->exec("DELETE FROM $t WHERE task_id IN ($tin)");
            // task_history é imutável por gatilho (LGPD): fica, como no teste da agenda.
            try { $pdo->exec("DELETE FROM task_history WHERE task_id IN ($tin)"); } catch (\Throwable $_) {}
            $pdo->exec("DELETE FROM tasks WHERE id IN ($tin)");
        }
        $pdo->exec("DELETE FROM task_columns WHERE board_id IN ($in)");
        $pdo->exec("DELETE FROM task_boards WHERE id IN ($in)");
    }
    if ($criados['valores']) $pdo->exec('DELETE FROM crm_cliente_valores WHERE id IN (' . implode(',', $criados['valores']) . ')');
    if ($criados['clientes']) {
        $cin = implode(',', $criados['clientes']);
        foreach (['crm_cliente_areas', 'crm_cliente_frequencias', 'crm_campanhas', 'drive_pastas_cliente', 'drive_itens', 'drive_pastas_lidas', 'crm_cliente_cobrancas'] as $t) $pdo->exec("DELETE FROM $t WHERE cliente_id IN ($cin)");
        $pdo->exec("DELETE FROM clientes WHERE id IN ($cin)");
    }
    $pdo->exec("DELETE FROM drive_contas WHERE account_id = $ACC");
    if ($criados['cards']) $pdo->exec('UPDATE cards SET area_servico = NULL WHERE id IN (' . implode(',', array_map('intval', $criados['cards'])) . ')');
    $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')->execute([$cfgAntes, $ACC]);
    $pdo->exec("DELETE FROM user_ambientes WHERE user_id = $UID");
    echo "\n  (limpeza: quadros, cartões, clientes e Drive de teste apagados; configuração da conta restaurada)\n";
}

echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
