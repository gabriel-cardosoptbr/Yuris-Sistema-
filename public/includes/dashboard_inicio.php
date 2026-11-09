<?php
/**
 * A HOME NOVA DO YURIS (prévia, 07/11/2026): o dia do escritório.
 *
 * Incluída por public/dashboard.php quando a conta é Yuris (módulo jurídico),
 * a pessoa ligou a interface nova (App\Inicio\InterfaceNova) e não pediu o
 * painel de indicadores (?gestao=1). Espera do dashboard.php: $ctx, $tenantIds,
 * $dre_receita, $vendedorCrm e fmtBRL(). Os dados vêm de App\Inicio\Painel,
 * que lê as fontes de cada módulo sem copiar nada.
 *
 * A estrutura segue a tela de referência do dono do produto: faixa de
 * boas-vindas, quatro cartões do que está acontecendo, a agenda jurídica com o
 * calendário do mês e o dia escolhido, prazos e quem espera resposta ao lado,
 * e embaixo publicações, processos com movimentação e a equipe. Os números de
 * gestão (receita, metas) ficam em Gestão › Indicadores.
 *
 * Desenho: menu em painel com a pílula azul (assets/interface-nova.css, que o
 * sidebar.php carrega) e a home em assets/inicio.css, nos dois temas, com as
 * bordas pouco arredondadas. A faixa de boas-vindas tem uma biblioteca jurídica
 * ao fundo (assets/img/inicio-biblioteca.jpg, foto de Planet Volumes no
 * Unsplash, licença Unsplash). As peças de madeira em CSS da primeira versão
 * foram reprovadas ("retire essas madeiras") e saíram.
 */
if (!isset($ctx)) { http_response_code(403); exit; }

use App\Inicio\InterfaceNova;
use App\Inicio\Painel;

$activePage = 'dashboard';
$_userNome  = (string) ($_SESSION['user_nome'] ?? '');

$escopoCentral = [
    'user_id'          => $ctx->getUserId(),
    'is_admin'         => $ctx->isOwnerOrAdmin(),
    'contas_tarefas'   => $ctx->getAccessibleAccountIds('tarefas'),
    'contas_processos' => $ctx->getAccessibleAccountIds('processos'),
];
try {
    $painel = Painel::montar($escopoCentral, $tenantIds);
} catch (\Throwable $e) {
    error_log('[inicio] ' . $e->getMessage());
    $agora = Painel::agora();
    $painel = [
        'agora' => $agora, 'hoje' => $agora->format('Y-m-d'), 'saudacao' => Painel::saudacao((int) $agora->format('G')),
        'data' => Painel::dataPorExtenso($agora), 'resumo' => 'Não consegui ler o dia do escritório agora. Tente recarregar.',
        'agenda' => [], 'contagem' => ['hoje' => 0, 'amanha' => 0, 'semana' => 0, 'atrasadas' => 0], 'prazos' => [],
        'publicacoes' => ['total' => 0, 'itens' => []], 'equipe' => [], 'processos' => [], 'whatsapp' => null,
        'numeros' => ['ativos' => 0, 'novos_mes' => 0, 'publicacoes_mes' => 0, 'clientes' => 0, 'audiencias_semana' => 0],
        'kpis' => ['urgentes' => 0, 'ativos' => 0, 'novos_mes' => 0, 'publicacoes' => 0, 'audiencias_semana' => 0],
        'calendario' => ['mes' => $agora->format('Y-m'), 'por_dia' => []],
    ];
}
$hoje = $painel['hoje'];
$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$temWhats = $painel['whatsapp'] !== null;
$seta = '<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$porDia = $painel['calendario']['por_dia'];
$mes = $painel['calendario']['mes'];
$doDia = $porDia[$hoje] ?? [];

/** Os dados que o pop-up de detalhe mostra, no próprio cartão (data-det). */
$det = static fn(array $a): string => ' data-det="' . htmlspecialchars(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '"';
$detEvento = static fn(array $i): array => ['t' => 'evento', 'origem' => $i['origem'] ?? '', 'id' => (int) ($i['id'] ?? 0),
    'processo_id' => !empty($i['processo_id']) ? (int) $i['processo_id'] : null, 'titulo' => (string) ($i['titulo'] ?? ''),
    'data' => isset($i['data']) ? substr((string) $i['data'], 0, 10) : null, 'hora' => $i['hora'] ?? null,
    'tipo' => $i['tipo'] ?? Painel::tipoDoItem($i), 'resp' => $i['responsavel_nome'] ?? null,
    'num' => $i['processo_numero'] ?? null, 'cliente' => $i['cliente_nome'] ?? null];

/** Um compromisso na lista do dia (o JS desenha igual ao trocar o dia). */
// Cada compromisso é um cartão que leva ao lugar dele: o processo, ou o quadro de Tarefas.
$evento = static function (array $i) use ($h, $det, $detEvento): string {
    $num = !empty($i['processo_numero']) ? '<em class="ini-num">' . $h($i['processo_numero']) . '</em>' : '';
    $sub = implode(' · ', array_filter([$num, !empty($i['cliente_nome']) ? $h($i['cliente_nome']) : '']));
    $href = !empty($i['processo_id']) ? '/processos?open=' . (int) $i['processo_id'] : '/tarefas';
    return '<a class="ini-ev ini-cartao ' . $h($i['tipo']) . '" href="' . $href . '"' . $det($detEvento($i)) . '>'
         . '<span class="ini-ev-hora">' . ($i['hora'] ? $h($i['hora']) : '<small>dia<br>todo</small>') . '</span>'
         . '<span class="ini-ev-t"><b>' . $h($i['titulo']) . ' <i class="ini-tag ' . $h($i['tipo']) . '">' . $h(Painel::TIPOS[$i['tipo']]) . '</i></b>'
         . ($sub !== '' ? '<span>' . $sub . '</span>' : '')
         . (!empty($i['responsavel_nome']) ? '<small><em class="ini-av">' . $h(Painel::iniciais($i['responsavel_nome'])) . '</em>' . $h($i['responsavel_nome']) . '</small>' : '')
         . '</span><span class="ini-chev" aria-hidden="true"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span></a>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Início — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=45">
  <link rel="stylesheet" href="/assets/sidebar.css?v=20">
  <link rel="stylesheet" href="/assets/inicio.css?v=<?= @filemtime(__DIR__ . '/../assets/inicio.css') ?: 1 ?>">
  <link rel="stylesheet" href="/assets/inicio-movimento.css?v=<?= @filemtime(__DIR__ . '/../assets/inicio-movimento.css') ?: 1 ?>">
<link rel="expect" href="#yuris-menu-pronto" blocking="render"><!-- só pinta com o menu inteiro: ver o fim do menu em includes/sidebar.php -->
</head>
<body class="ini-body">
<main class="w-full">
  <div class="page-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <section class="main-content ini">

      <!-- ── Topo ── -->
      <div class="ini-topo">
        <div class="ini-data">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M3 10h18M8 2v4M16 2v4"/></svg>
          <?= $h(ucfirst($painel['data'])) ?>
        </div>
        <span class="ini-sp"></span>
        <a class="ini-link-topo" href="/dashboard?gestao=1" title="O painel de indicadores de sempre">
          <svg viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 15 4-5 4 3 5-7"/></svg> Indicadores
        </a>
        <button type="button" class="ini-tema" id="iniTema" title="Trocar entre claro e escuro" aria-label="Trocar tema">
          <span class="ini-tema-bola"></span>
          <svg class="ini-lua" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
          <svg class="ini-sol" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
        <a class="ini-sair-previa" href="<?= $h(InterfaceNova::enderecoCom((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard'), '0')) ?>" title="Voltar ao desenho de sempre">Sair da prévia</a>
      </div>

      <!-- ── Boas-vindas ── -->
      <section class="ini-sala">
        <div class="ini-sala-texto">
          <h1><span id="iniSaudacao"><?= $h($painel['saudacao']) ?></span>, <span class="ini-nome"><?= $h(Painel::primeiroNome($_userNome)) ?></span></h1>
          <p><?= $painel['resumo'] ?></p>
          <div class="ini-acoes">
            <a class="ini-btn primario" href="/processos" data-novo="processo"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Novo processo</a>
            <a class="ini-btn" href="/tarefas" data-novo="tarefa"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Nova tarefa</a>
            <?php if ($painel['publicacoes']['total'] > 0): ?>
            <a class="ini-btn" href="/intimacoes" data-novo="publicacoes"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Tratar publicações</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="ini-foto" aria-hidden="true"></div>
      </section>

      <!-- ── O que está acontecendo: quatro cartões ── -->
      <div class="ini-grade ini-g4">
        <a class="ini-kpi <?= $painel['kpis']['urgentes'] > 0 ? 'alerta' : '' ?>" href="/tarefas">
          <span class="ini-kpi-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
          <span class="ini-kpi-t">Prazos urgentes</span>
          <b><?= (int) $painel['kpis']['urgentes'] ?></b>
          <small>vencem em até 3 dias<?= (int) $painel['contagem']['atrasadas'] > 0 ? ' · ' . (int) $painel['contagem']['atrasadas'] . ' atrasados' : '' ?></small>
        </a>
        <a class="ini-kpi" href="/processos">
          <span class="ini-kpi-ic"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
          <span class="ini-kpi-t">Processos ativos</span>
          <b><?= (int) $painel['kpis']['ativos'] ?></b>
          <small><?= (int) $painel['kpis']['novos_mes'] > 0 ? '+' . (int) $painel['kpis']['novos_mes'] . ' novos neste mês' : 'nenhum novo neste mês' ?></small>
        </a>
        <a class="ini-kpi <?= $painel['kpis']['publicacoes'] > 0 ? 'atencao' : '' ?>" href="/intimacoes">
          <span class="ini-kpi-ic"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg></span>
          <span class="ini-kpi-t">Publicações</span>
          <b><?= (int) $painel['kpis']['publicacoes'] ?></b>
          <small>sem tratamento no Diário</small>
        </a>
        <a class="ini-kpi" href="/tarefas">
          <span class="ini-kpi-ic"><svg viewBox="0 0 24 24"><path d="M16 16h6l-3-7-3 7zM2 16h6l-3-7-3 7zM12 3v19M3 22h18M5 9h14"/></svg></span>
          <span class="ini-kpi-t">Audiências</span>
          <b><?= (int) $painel['kpis']['audiencias_semana'] ?></b>
          <small>nesta semana</small>
        </a>
      </div>

      <!-- ── Agenda jurídica com o calendário; ao lado, prazos e quem espera ── -->
      <div class="ini-grade ini-g-agenda">

        <section class="ini-card ini-agenda">
          <header class="ini-card-h">
            <a class="ini-ic" href="/tarefas" title="Ver a agenda completa" aria-label="Ver a agenda completa"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M3 10h18M8 2v4M16 2v4"/></svg></a>
            <div class="ini-card-tit">
              <h3>Agenda jurídica</h3>
              <span class="ini-conta"><?= count($doDia) ?> <?= count($doDia) === 1 ? 'compromisso hoje' : 'compromissos hoje' ?></span>
            </div>
          </header>
          <div class="ini-agenda-corpo">
            <div class="ini-cal" id="iniCal">
              <div class="ini-cal-topo">
                <button type="button" class="ini-cal-nav" data-cal="-1" aria-label="Mês anterior"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
                <b id="iniCalMes"><?= $h(Painel::nomeDoMes($mes)) ?></b>
                <button type="button" class="ini-cal-nav" data-cal="1" aria-label="Mês seguinte"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
              </div>
              <div class="ini-cal-sem"><span>D</span><span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span></div>
              <div class="ini-cal-dias" id="iniCalDias">
                <?php foreach (Painel::gradeDoMes($mes) as $semana): foreach ($semana as $c): $n = count($porDia[$c['data']] ?? []); ?>
                <button type="button" class="ini-cal-dia<?= $c['fora'] ? ' fora' : '' ?><?= $c['data'] === $hoje ? ' hoje' : '' ?><?= $n ? ' tem' : '' ?><?= $c['data'] === $hoje ? ' sel' : '' ?>" data-dia="<?= $h($c['data']) ?>"><?= (int) $c['dia'] ?><?= $n ? '<i></i>' : '' ?></button>
                <?php endforeach; endforeach; ?>
              </div>
              <!-- Atalhos de período: listam a faixa à direita e marcam os dias na grade. -->
              <div class="ini-cal-atalhos" role="group" aria-label="Períodos">
                <button type="button" class="ini-atalho sel" data-faixa="hoje">Hoje</button>
                <button type="button" class="ini-atalho" data-faixa="amanha">Amanhã</button>
                <button type="button" class="ini-atalho" data-faixa="semana">Esta semana</button>
                <button type="button" class="ini-atalho" data-faixa="proxima-semana">Próxima semana</button>
                <button type="button" class="ini-atalho" data-faixa="semana-passada">Semana passada</button>
                <button type="button" class="ini-atalho" data-faixa="mes">Este mês</button>
                <!-- Período livre: um clique no primeiro dia e outro no último. Também dá para
                     arrastar sobre os dias, ou clicar com Shift a partir do dia escolhido. -->
                <button type="button" class="ini-atalho ini-atalho-periodo" id="iniPeriodo" aria-pressed="false">Escolher datas</button>
                <!-- As datas escolhidas aparecem aqui, logo abaixo do botão. -->
                <div class="ini-periodo-escolhido" id="iniPeriodoEscolhido" hidden>
                  <span class="ini-periodo-campo"><small>De</small><b id="iniPeriodoDe">--/--/----</b></span>
                  <span class="ini-periodo-seta" aria-hidden="true">→</span>
                  <span class="ini-periodo-campo"><small>Até</small><b id="iniPeriodoAte">--/--/----</b></span>
                  <button type="button" class="ini-periodo-limpar" id="iniPeriodoLimpar" title="Voltar para hoje" aria-label="Limpar as datas">×</button>
                </div>
              </div>
            </div>
            <div class="ini-dia">
              <div class="ini-dia-h"><b id="iniDiaTitulo"><?= $h(Painel::tituloDoDia($hoje, $hoje)) ?></b><span class="ini-pill" id="iniDiaConta"><?= count($doDia) ?> <?= count($doDia) === 1 ? 'compromisso' : 'compromissos' ?></span></div>
              <div class="ini-dia-lista" id="iniDiaLista">
                <?php if (!$doDia): ?>
                <div class="ini-vazio curto"><p>Nada marcado para este dia.</p></div>
                <?php else: foreach ($doDia as $i) echo $evento($i); endif; ?>
              </div>
            </div>
          </div>
        </section>

        <div class="ini-coluna">
          <section class="ini-card">
            <header class="ini-card-h">
              <a class="ini-ic" href="/tarefas" title="Ver todos os prazos" aria-label="Ver todos os prazos"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></a>
              <div class="ini-card-tit">
                <h3>Prazos</h3>
              </div>
            </header>
            <div class="ini-contadores">
              <?php foreach ([
                  ['hoje', 'hoje', '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M3 9h18M8 2v4M16 2v4"/><circle cx="12" cy="15" r="2"/>'],
                  ['amanha', 'amanhã', '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M3 9h18M8 2v4M16 2v4M9 15h6M13 13l2 2-2 2"/>'],
                  ['semana', 'na semana', '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M3 9h18M8 2v4M16 2v4M7 13h2M11 13h2M15 13h2M7 17h2M11 17h2"/>'],
              ] as [$_ck, $_cr, $_ci]): $_cn = (int) $painel['contagem'][$_ck]; ?>
              <a class="ini-ctr <?= $_ck ?><?= $_cn === 0 ? ' zero' : '' ?>" href="/tarefas" title="Ver os prazos">
                <span class="ini-ctr-ic"><svg viewBox="0 0 24 24"><?= $_ci ?></svg></span>
                <span class="ini-ctr-t"><b><?= $_cn ?></b><span><?= $_cr ?></span></span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php if ((int) $painel['contagem']['atrasadas'] > 0): ?>
            <div class="ini-atraso"><?= (int) $painel['contagem']['atrasadas'] ?> <?= (int) $painel['contagem']['atrasadas'] === 1 ? 'item atrasado' : 'itens atrasados' ?> · <a href="/tarefas">resolver</a></div>
            <?php endif; ?>
            <?php if (!$painel['prazos']): ?>
            <div class="ini-vazio curto"><p>Nenhum prazo de processo em aberto.</p></div>
            <?php else: ?>
            <div class="ini-lista">
              <?php foreach ($painel['prazos'] as $pz): $r = Painel::rotuloDeVencimento($pz['data'], $hoje); ?>
              <a class="ini-item ini-cartao" href="/processos?open=<?= (int) $pz['processo_id'] ?>" title="Ver o prazo"<?= $det($detEvento($pz)) ?>>
                <span class="ini-av"><?= $h(Painel::iniciais((string) ($pz['responsavel_nome'] ?: '?'))) ?></span>
                <span class="ini-item-t"><b><?= $h($pz['titulo']) ?></b><span><?php if (!empty($pz['processo_numero'])): ?><em class="ini-num"><?= $h($pz['processo_numero']) ?></em><?php endif; ?><?= !empty($pz['cliente_nome']) ? ' · ' . $h($pz['cliente_nome']) : '' ?></span><?php if (!empty($pz['responsavel_nome'])): ?><small><?= $h($pz['responsavel_nome']) ?></small><?php endif; ?></span>
                <span class="ini-vence <?= $h($r['tom']) ?>"><?= $h($r['texto']) ?></span>
                <span class="ini-chev" aria-hidden="true"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </section>

          <?php if ($temWhats): ?>
          <section class="ini-card">
            <header class="ini-card-h">
              <a class="ini-ic" href="/chat" title="Abrir o chat" aria-label="Abrir o chat"><svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></a>
              <div class="ini-card-tit">
                <h3>Aguardando retorno</h3>
                <span class="ini-conta"><?= (int) $painel['whatsapp']['total'] ?> no WhatsApp</span>
              </div>
            </header>
            <?php if (!$painel['whatsapp']['itens']): ?>
            <div class="ini-vazio curto"><p>Ninguém esperando resposta.</p></div>
            <?php else: ?>
            <div class="ini-lista">
              <?php foreach ($painel['whatsapp']['itens'] as $w): ?>
              <a class="ini-wa ini-cartao" href="/chat?jid=<?= $h(rawurlencode($w['jid'])) ?>" title="Ver a conversa"<?= $det(['t' => 'wa', 'jid' => $w['jid'], 'nome' => $w['nome'], 'texto' => $w['texto'], 'desde' => $w['desde'], 'minutos' => $w['minutos']]) ?>>
                <span class="ini-av cinza"><?= $h($w['iniciais']) ?></span>
                <span class="ini-wa-t"><b><?= $h($w['nome']) ?></b><span><?= $h($w['texto']) ?></span><small class="ini-acao">Responder no WhatsApp</small></span>
                <span class="ini-desde <?= $w['minutos'] >= 120 ? 'r' : '' ?>"><?= $h($w['desde']) ?></span>
                <span class="ini-chev" aria-hidden="true"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Publicações, processos e equipe ── -->
      <div class="ini-grade ini-g3">

        <section class="ini-card">
          <header class="ini-card-h">
            <a class="ini-ic" href="/intimacoes" title="Abrir o Diário" aria-label="Abrir o Diário"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg></a>
            <div class="ini-card-tit">
              <h3>Publicações</h3>
              <span class="ini-conta"><?= (int) $painel['publicacoes']['total'] ?> sem tratamento</span>
            </div>
          </header>
          <?php if (!$painel['publicacoes']['itens']): ?>
          <div class="ini-vazio curto"><p>Tudo tratado. Nenhuma publicação pendente.</p></div>
          <?php else: ?>
          <div class="ini-lista">
            <?php foreach ($painel['publicacoes']['itens'] as $pb): ?>
            <a class="ini-pub ini-cartao" href="/intimacoes" title="Ler a publicação"<?= $det(['t' => 'pub', 'id' => (int) $pb['id'], 'tribunal' => $pb['tribunal'], 'orgao' => $pb['orgao'] ?? null, 'data' => substr((string) $pb['data_disponibilizacao'], 0, 10), 'tipo' => $pb['tipo_comunicacao'] ?: ($pb['titulo'] ?: 'Publicação'), 'cliente' => $pb['cliente_nome'], 'cliente_id' => !empty($pb['cliente_id']) ? (int) $pb['cliente_id'] : null, 'num' => $pb['numero'], 'processo_id' => $pb['processo_id'] ? (int) $pb['processo_id'] : null, 'texto' => $pb['texto'] ?? '', 'estrutura' => $pb['estrutura'] ?? null]) ?>>
              <div class="ini-pub-trib"><?= $h($pb['tribunal']) ?> · <?= $h(Painel::diaRelativo((string) $pb['data_disponibilizacao'], $hoje)) ?></div>
              <div class="ini-pub-txt"><b><?= $h($pb['tipo_comunicacao'] ?: ($pb['titulo'] ?: 'Publicação')) ?></b><?= $pb['cliente_nome'] ? ' · ' . $h($pb['cliente_nome']) : '' ?><?php if (!empty($pb['numero'])): ?><br><em class="ini-num"><?= $h($pb['numero']) ?></em><?php endif; ?></div>
              <span class="ini-mini">Tratar</span>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="ini-card">
          <header class="ini-card-h">
            <a class="ini-ic" href="/processos" title="Ver todos os processos" aria-label="Ver todos os processos"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></a>
            <div class="ini-card-tit">
              <h3>Processos com movimentação</h3>
            </div>
          </header>
          <?php if (!$painel['processos']): ?>
          <div class="ini-vazio curto"><p>Nenhum processo cadastrado ainda.</p></div>
          <?php else: ?>
          <div class="ini-lista">
              <?php foreach ($painel['processos'] as $pr): [$rot, $tom] = Painel::situacaoDoProcesso((string) $pr['status'], $pr['proximo_prazo'] ?: null, $hoje);
                $mov = $pr['ultima_publicacao'] ?: ($pr['tipo_acao'] ?: 'Processo atualizado');
                $quando = $pr['publicada_em'] && $pr['publicada_em'] >= substr((string) $pr['movimentado_em'], 0, 10) ? $pr['publicada_em'] : substr((string) $pr['movimentado_em'], 0, 10); ?>
              <a class="ini-proc ini-cartao" href="/processos?open=<?= (int) $pr['id'] ?>" title="Ver o processo"<?= $det(['t' => 'proc', 'processo_id' => (int) $pr['id'], 'cliente' => $pr['cliente_nome'], 'num' => $pr['numero'], 'mov' => $mov, 'quando' => $quando, 'situacao' => $rot, 'tom' => $tom]) ?>>
                <span class="ini-proc-t"><b><?= $h($pr['cliente_nome'] ?: 'Sem cliente') ?></b><em class="ini-num"><?= $h($pr['numero']) ?></em><span><?= $h($mov) ?></span><small><?= $h(Painel::diaRelativo($quando, $hoje)) ?><?= $pr['vara_comarca'] ? ' · ' . $h($pr['vara_comarca']) : '' ?></small></span>
                <span class="ini-situ <?= $h($tom) ?>"><?= $h($rot) ?></span>
                <span class="ini-chev" aria-hidden="true"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
              </a>
              <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="ini-card">
          <header class="ini-card-h">
            <a class="ini-ic" href="/desempenho" title="Ver o desempenho da equipe" aria-label="Ver o desempenho da equipe"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></a>
            <div class="ini-card-tit">
              <h3>Equipe hoje</h3>
            </div>
          </header>
          <div class="ini-equipe">
            <?php foreach (array_slice($painel['equipe'], 0, 6) as $pe): ?>
            <a class="ini-pessoa ini-cartao" href="/desempenho" title="Ver a pessoa"<?= $det(['t' => 'pessoa', 'nome' => $pe['nome'], 'papel' => $pe['papel'], 'hoje' => $pe['hoje'], 'semana' => $pe['semana'], 'vencidas' => $pe['vencidas'], 'abertas' => $pe['abertas'], 'carga' => $pe['carga'], 'estado' => $pe['estado']]) ?>>
              <span class="ini-av grande"><?= $h($pe['iniciais']) ?><i class="ini-estado <?= $h($pe['estado']) ?>"></i></span>
              <div class="ini-pessoa-t"><div class="ini-pessoa-n"><?= $h($pe['nome']) ?></div><div class="ini-pessoa-w"><span class="ini-papel"><?= $h($pe['papel']) ?></span> · <?= $h($pe['texto']) ?></div></div>
              <div class="ini-carga"><div class="ini-barra"><i class="<?= $pe['vencidas'] > 0 ? 'quente' : '' ?>" style="width:<?= (int) $pe['pct'] ?>%"></i></div><span><?= (int) $pe['carga'] ?> na mão</span></div>
            </a>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <p class="ini-rodape">Prévia da interface nova · os dados são os do escritório · <a href="<?= $h(InterfaceNova::enderecoCom((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard'), '0')) ?>">voltar ao desenho de sempre</a></p>
    </section>
  </div>
</main>
<script>
// A saudação acompanha o relógio de Brasília (o mesmo fuso do servidor, Painel::FUSO):
// a home aberta da tarde para a noite troca "Boa tarde" por "Boa noite" sozinha.
(function () {
  var el = document.getElementById('iniSaudacao'); if (!el) return;
  function hora() {
    try { return parseInt(new Intl.DateTimeFormat('pt-BR', { hour: 'numeric', hourCycle: 'h23', timeZone: 'America/Sao_Paulo' }).format(new Date()), 10); }
    catch (e) { return new Date().getHours(); }
  }
  function atualizar() { var h = hora(); el.textContent = (h >= 5 && h < 12) ? 'Bom dia' : (h >= 12 && h < 18) ? 'Boa tarde' : 'Boa noite'; }
  atualizar(); setInterval(atualizar, 60000);
})();
window.INI_CAL = <?= json_encode(['hoje' => $hoje, 'mes' => $mes, 'porDia' => $porDia, 'tipos' => Painel::TIPOS], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
(function () {
  var b = document.getElementById('iniTema');
  if (b) b.addEventListener('click', function () {
    var r = document.documentElement, claro = r.getAttribute('data-theme') === 'light';
    if (claro) { r.removeAttribute('data-theme'); try { localStorage.setItem('yuris_theme', 'dark'); } catch (e) {} }
    else       { r.setAttribute('data-theme', 'light'); try { localStorage.setItem('yuris_theme', 'light'); } catch (e) {} }
  });

  // O calendário: trocar de mês e de dia sem recarregar. Os dados já vieram na página.
  var C = window.INI_CAL, MESES = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'],
      DIAS = ['dom','seg','ter','qua','qui','sex','sáb'], mesAtual = C.mes, sel = C.hoje;
  var elDias = document.getElementById('iniCalDias'), elMes = document.getElementById('iniCalMes'),
      elTit = document.getElementById('iniDiaTitulo'), elConta = document.getElementById('iniDiaConta'), elLista = document.getElementById('iniDiaLista');
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function iniciais(n) { var p = String(n || '').trim().split(/\s+/).filter(function (x) { return x && !/^(d[aeo]s?|e)$/i.test(x); }); if (!p.length) return '?'; return (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function desenhaMes(mes) {
    var y = +mes.slice(0, 4), m = +mes.slice(5, 7) - 1, primeiro = new Date(y, m, 1), d = new Date(y, m, 1 - primeiro.getDay()), html = '';
    do {
      for (var k = 0; k < 7; k++) {
        var key = ymd(d), n = (C.porDia[key] || []).length, fora = d.getMonth() !== m;
        html += '<button type="button" class="ini-cal-dia' + (fora ? ' fora' : '') + (key === C.hoje ? ' hoje' : '') + (n ? ' tem' : '') + (key === sel ? ' sel' : '') + '" data-dia="' + key + '">' + d.getDate() + (n ? '<i></i>' : '') + '</button>';
        d.setDate(d.getDate() + 1);
      }
    } while (d.getMonth() === m);
    elDias.innerHTML = html;
    elMes.textContent = MESES[m].charAt(0).toUpperCase() + MESES[m].slice(1) + ' de ' + y;
  }
  // Um compromisso, igual ao que o PHP desenhou.
  function htmlEvento(i) {
    var sub = [i.processo_numero ? '<em class="ini-num">' + esc(i.processo_numero) + '</em>' : '', i.cliente_nome ? esc(i.cliente_nome) : ''].filter(Boolean).join(' · ');
    var href = i.processo_id ? '/processos?open=' + i.processo_id : '/tarefas';
    var det = { t: 'evento', origem: i.origem || '', id: i.id || 0, processo_id: i.processo_id || null, titulo: i.titulo || '', data: i.data || null, hora: i.hora || null, tipo: i.tipo, resp: i.responsavel_nome || null, num: i.processo_numero || null, cliente: i.cliente_nome || null };
    return '<a class="ini-ev ini-cartao ' + esc(i.tipo) + '" href="' + href + '" data-det="' + esc(JSON.stringify(det)) + '"><span class="ini-ev-hora">' + (i.hora ? esc(i.hora) : '<small>dia<br>todo</small>') + '</span>'
         + '<span class="ini-ev-t"><b>' + esc(i.titulo) + ' <i class="ini-tag ' + esc(i.tipo) + '">' + esc(C.tipos[i.tipo] || i.tipo) + '</i></b>'
         + (sub ? '<span>' + sub + '</span>' : '')
         + (i.responsavel_nome ? '<small><em class="ini-av">' + esc(iniciais(i.responsavel_nome)) + '</em>' + esc(i.responsavel_nome) + '</small>' : '')
         + '</span><span class="ini-chev" aria-hidden="true"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span></a>';
  }
  function marcaDias(de, ate, unico) {
    elDias.querySelectorAll('.ini-cal-dia').forEach(function (x) {
      var k = x.getAttribute('data-dia');
      x.classList.toggle('sel', unico !== null && k === unico);
      x.classList.toggle('faixa', unico === null && k >= de && k <= ate);
    });
  }
  var faixaAtual = null; // null = um dia só (sel); senão {de, ate}
  // Sem barra de rolagem: o esmaecimento no fim da lista aparece só enquanto há mais embaixo.
  function ajustaRolagem() { var d = elLista.parentNode; d.classList.toggle('rola', elLista.scrollHeight - elLista.scrollTop - elLista.clientHeight > 6); }
  function rotuloDia(key) { var d = new Date(key + 'T12:00:00'); return (key === C.hoje ? 'Hoje' : DIAS[d.getDay()].charAt(0).toUpperCase() + DIAS[d.getDay()].slice(1)) + ', ' + d.getDate() + ' de ' + MESES[d.getMonth()].slice(0, 3); }
  // Uma faixa de dias: lista agrupada por dia, e os dias marcados na grade.
  function desenhaFaixa(de, ate, titulo) {
    faixaAtual = { de: de, ate: ate }; sel = null;
    var dias = Object.keys(C.porDia).filter(function (k) { return k >= de && k <= ate; }).sort(), total = 0, html = '';
    dias.forEach(function (k) { var its = C.porDia[k]; total += its.length; html += '<div class="ini-dia-grupo">' + esc(rotuloDia(k)) + '</div>' + its.map(htmlEvento).join(''); });
    elTit.textContent = titulo;
    elConta.textContent = total + (total === 1 ? ' compromisso' : ' compromissos');
    elLista.innerHTML = html || '<div class="ini-vazio curto"><p>Nada marcado neste período.</p></div>';
    marcaDias(de, ate, null);
    ajustaRolagem();
  }
  function faixa(nome) {
    if (typeof sairModo === 'function') sairModo();
    if (typeof mostraPeriodo === 'function') mostraPeriodo(null);
    var h = new Date(C.hoje + 'T12:00:00'), seg = new Date(h); seg.setDate(h.getDate() - ((h.getDay() + 6) % 7));
    var dom = new Date(seg); dom.setDate(seg.getDate() + 6);
    var a = new Date(h); a.setDate(h.getDate() + 1);
    var segP = new Date(seg); segP.setDate(seg.getDate() - 7); var domP = new Date(dom); domP.setDate(dom.getDate() - 7);
    var segN = new Date(seg); segN.setDate(seg.getDate() + 7); var domN = new Date(dom); domN.setDate(dom.getDate() + 7);
    var ini = new Date(h.getFullYear(), h.getMonth(), 1), fim = new Date(h.getFullYear(), h.getMonth() + 1, 0);
    switch (nome) {
      case 'hoje': desenhaDia(C.hoje); break;
      case 'amanha': desenhaDia(ymd(a)); break;
      case 'semana': desenhaFaixa(ymd(seg), ymd(dom), 'Esta semana'); break;
      case 'semana-passada': desenhaFaixa(ymd(segP), ymd(domP), 'Semana passada'); break;
      case 'proxima-semana': desenhaFaixa(ymd(segN), ymd(domN), 'Próxima semana'); break;
      case 'mes': desenhaFaixa(ymd(ini), ymd(fim), MESES[h.getMonth()].charAt(0).toUpperCase() + MESES[h.getMonth()].slice(1)); break;
    }
    document.querySelectorAll('.ini-atalho').forEach(function (b) { b.classList.toggle('sel', b.getAttribute('data-faixa') === nome); });
  }
  function desenhaDia(key) {
    sel = key; faixaAtual = null;
    if (typeof sairModo === 'function' && !inicioPeriodo) sairModo();
    document.querySelectorAll('.ini-atalho').forEach(function (b) { b.classList.toggle('sel', (b.getAttribute('data-faixa') === 'hoje' && key === C.hoje)); });
    var itens = C.porDia[key] || [], d = new Date(key + 'T12:00:00'), pre = key === C.hoje ? 'Hoje' : DIAS[d.getDay()].charAt(0).toUpperCase() + DIAS[d.getDay()].slice(1);
    elTit.textContent = pre + ', ' + d.getDate() + ' de ' + MESES[d.getMonth()].slice(0, 3);
    elConta.textContent = itens.length + (itens.length === 1 ? ' compromisso' : ' compromissos');
    // Depois do dia, os próximos compromissos: a lista sempre preenche a coluna.
    var proximos = '', n = 0;
    Object.keys(C.porDia).filter(function (k) { return k > key; }).sort().forEach(function (k) {
      if (n >= 12) return;
      var its = C.porDia[k].slice(0, 12 - n); n += its.length;
      proximos += '<div class="ini-dia-grupo">' + esc(rotuloDia(k)) + '</div>' + its.map(htmlEvento).join('');
    });
    if (proximos) proximos = '<div class="ini-dia-sep">Próximos dias</div>' + proximos;
    elLista.innerHTML = (itens.length ? itens.map(htmlEvento).join('') : '<div class="ini-vazio curto"><p>Nada marcado para este dia.</p></div>') + proximos;
    marcaDias(key, key, key);
    ajustaRolagem();
  }
  if (elDias) {
    // ── Escolher as datas: clique (um dia), arraste (um período), Shift+clique
    //    (do dia escolhido até este) ou o botão "Escolher datas" (primeiro e último dia).
    var btnPeriodo = document.getElementById('iniPeriodo'), modoPeriodo = false, inicioPeriodo = null, arrasto = null, arrastoFim = null, moveu = false;
    var MES3 = function (d) { return MESES[d.getMonth()].slice(0, 3); };
    function tituloFaixa(de, ate) {
      var a = new Date(de + 'T12:00:00'), b = new Date(ate + 'T12:00:00');
      return a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear()
        ? a.getDate() + ' a ' + b.getDate() + ' de ' + MES3(b)
        : a.getDate() + ' de ' + MES3(a) + ' a ' + b.getDate() + ' de ' + MES3(b);
    }
    var boxPeriodo = document.getElementById('iniPeriodoEscolhido'), elDe = document.getElementById('iniPeriodoDe'), elAte = document.getElementById('iniPeriodoAte');
    function br(k) { return k ? k.slice(8, 10) + '/' + k.slice(5, 7) + '/' + k.slice(0, 4) : '--/--/----'; }
    function mostraPeriodo(de, ate) { if (!boxPeriodo) return; if (!de) { boxPeriodo.hidden = true; return; } boxPeriodo.hidden = false; elDe.textContent = br(de); elAte.textContent = br(ate); boxPeriodo.classList.toggle('incompleto', !ate); }
    var lim = document.getElementById('iniPeriodoLimpar'); if (lim) lim.addEventListener('click', function () { mostraPeriodo(null); faixa('hoje'); });
    function sairModo() { modoPeriodo = false; inicioPeriodo = null; if (btnPeriodo) { btnPeriodo.classList.remove('sel'); btnPeriodo.setAttribute('aria-pressed', 'false'); btnPeriodo.textContent = 'Escolher datas'; } }
    function intervalo(a, b) {
      var de = a < b ? a : b, ate = a < b ? b : a;
      sairModo();
      if (de === ate) { desenhaDia(de); mostraPeriodo(de, de); return; }
      desenhaFaixa(de, ate, tituloFaixa(de, ate));
      mostraPeriodo(de, ate);
      document.querySelectorAll('.ini-atalho').forEach(function (x) { x.classList.remove('sel'); });
    }
    function diaEm(x, y) { var el = document.elementFromPoint(x, y); el = el && el.closest ? el.closest('.ini-cal-dia') : null; return el && elDias.contains(el) ? el.getAttribute('data-dia') : null; }
    if (btnPeriodo) btnPeriodo.addEventListener('click', function () {
      if (modoPeriodo) { sairModo(); return; }
      modoPeriodo = true; inicioPeriodo = null;
      btnPeriodo.classList.add('sel'); btnPeriodo.setAttribute('aria-pressed', 'true'); btnPeriodo.textContent = 'Clique no primeiro dia';
    });
    elDias.addEventListener('pointerdown', function (e) {
      var x = e.target.closest('.ini-cal-dia'); if (!x || e.button > 0) return;
      var k = x.getAttribute('data-dia');
      if (modoPeriodo) {
        if (!inicioPeriodo) { inicioPeriodo = k; marcaDias(k, k, k); btnPeriodo.textContent = 'Agora o último dia'; mostraPeriodo(k, null); }
        else intervalo(inicioPeriodo, k);
        e.preventDefault(); return;
      }
      if (e.shiftKey && (sel || faixaAtual)) { intervalo(sel || faixaAtual.de, k); e.preventDefault(); return; }
      arrasto = k; arrastoFim = k; moveu = false; e.preventDefault();
    });
    document.addEventListener('pointermove', function (e) {
      if (!arrasto) return;
      var k = diaEm(e.clientX, e.clientY); if (!k || k === arrastoFim) return;
      arrastoFim = k; moveu = true;
      marcaDias(arrasto < k ? arrasto : k, arrasto < k ? k : arrasto, null);
    });
    document.addEventListener('pointerup', function () {
      if (!arrasto) return;
      var a = arrasto, b = arrastoFim; arrasto = null;
      if (moveu && a !== b) intervalo(a, b); else { desenhaDia(a); mostraPeriodo(null); }
    });
    elDias.addEventListener('keydown', function (e) { var x = e.target.closest('.ini-cal-dia'); if (x && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); desenhaDia(x.getAttribute('data-dia')); } });
    document.querySelectorAll('.ini-cal-nav').forEach(function (nav) {
      nav.addEventListener('click', function () {
        var y = +mesAtual.slice(0, 4), m = +mesAtual.slice(5, 7) - 1 + (+nav.getAttribute('data-cal')), d = new Date(y, m, 1);
        mesAtual = d.getFullYear() + '-' + pad(d.getMonth() + 1);
        desenhaMes(mesAtual);
        // A grade nova precisa reganhar a marcação do dia ou da faixa.
        if (faixaAtual) marcaDias(faixaAtual.de, faixaAtual.ate, null); else if (sel) marcaDias(sel, sel, sel);
      });
    });
    document.querySelectorAll('.ini-atalho[data-faixa]').forEach(function (b) { b.addEventListener('click', function () { faixa(b.getAttribute('data-faixa')); }); });
    elLista.addEventListener('scroll', ajustaRolagem); window.addEventListener('resize', ajustaRolagem); desenhaDia(C.hoje);
  }
})();
</script>
<!-- O pop-up de detalhe: clicar num cartão abre a janela aqui, sem sair da home. -->
<script>window.INI_DET_CFG = { csrf: <?= json_encode((string) ($_SESSION['csrf_token'] ?? '')) ?>, hoje: <?= json_encode($hoje) ?>, tipos: <?= json_encode(Painel::TIPOS, JSON_UNESCAPED_UNICODE) ?> };</script>
<script src="/assets/inicio-detalhe.js?v=<?= @filemtime(__DIR__ . '/../assets/inicio-detalhe.js') ?: 1 ?>" defer></script>
<!-- O movimento da home: entrada, preenchimento, o pop-up que nasce do cartão, os números que crescem, a saída. -->
<script src="/assets/inicio-movimento.js?v=<?= @filemtime(__DIR__ . '/../assets/inicio-movimento.js') ?: 1 ?>" defer></script>
</body>
</html>
