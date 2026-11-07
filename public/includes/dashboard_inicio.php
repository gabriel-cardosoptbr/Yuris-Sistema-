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
 * Desenho: menu em painel com a pílula azul (assets/interface-nova.css, que o
 * sidebar.php carrega) e a home em assets/inicio.css, nos dois temas. A faixa
 * de boas-vindas tem as peças de madeira com a luz azul por trás, em CSS, sem
 * foto: madeira como moldura, nunca atrás de texto.
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
    ];
}
$hoje = $painel['hoje'];
$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$temWhats = $painel['whatsapp'] !== null;
$iconeTipo = [
    'audiencia' => '<svg viewBox="0 0 24 24"><path d="M16 16h6l-3-7-3 7zM2 16h6l-3-7-3 7zM12 3v19M3 22h18M5 9h14"/></svg>',
    'reuniao'   => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'prazo'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'tarefa'    => '<svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
];
$seta = '<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
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
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=20">
  <link rel="stylesheet" href="/assets/inicio.css?v=<?= @filemtime(__DIR__ . '/../assets/inicio.css') ?: 1 ?>">
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

      <!-- ── Boas-vindas: a sala ── -->
      <section class="ini-sala">
        <div class="ini-sala-texto">
          <h1><?= $h($painel['saudacao']) ?>, <span><?= $h(Painel::primeiroNome($_userNome)) ?></span></h1>
          <p><?= $painel['resumo'] ?></p>
          <div class="ini-acoes">
            <a class="ini-btn primario" href="/processos"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Novo processo</a>
            <a class="ini-btn" href="/tarefas"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Nova tarefa</a>
            <?php if ($painel['publicacoes']['total'] > 0): ?>
            <a class="ini-btn" href="/intimacoes"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Tratar publicações</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="ini-parede" aria-hidden="true">
          <div class="ini-luz">
            <i class="ini-peca escura p1"></i><i class="ini-peca clara p2"></i><i class="ini-peca p3"></i><i class="ini-peca cinza p4"></i>
            <i class="ini-peca p5"></i><i class="ini-peca escura p6"></i><i class="ini-peca clara p7"></i><i class="ini-peca p8"></i>
          </div>
        </div>
      </section>

      <!-- ── Linha 1: agenda, prazos, publicações ── -->
      <div class="ini-grade ini-g1">

        <section class="ini-card">
          <header class="ini-card-h">
            <span class="ini-ic"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M3 10h18M8 2v4M16 2v4"/></svg></span>
            <h3>Agenda de hoje</h3>
            <span class="ini-conta"><?= count($painel['agenda']) ?> <?= count($painel['agenda']) === 1 ? 'compromisso' : 'compromissos' ?></span>
            <a class="ini-mais" href="/tarefas">Ver semana <?= $seta ?></a>
          </header>
          <?php if (!$painel['agenda']): ?>
          <div class="ini-vazio">
            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M3 10h18M8 2v4M16 2v4"/><path d="m9 16 2 2 4-4"/></svg>
            <p>Nenhum compromisso para hoje.</p>
            <small>O que estiver em Tarefas, nos prazos dos processos ou nas tarefas de processo com data de hoje aparece aqui.</small>
          </div>
          <?php else: ?>
          <div class="ini-linha-tempo">
            <?php foreach (array_slice($painel['agenda'], 0, 7) as $i): ?>
            <div class="ini-tl <?= $h($i['tipo']) ?>">
              <div class="ini-hr"><?= $i['hora'] ? $h($i['hora']) : '<small>dia todo</small>' ?></div>
              <div class="ini-trilho"><i></i></div>
              <div class="ini-tl-corpo">
                <div class="ini-tl-titulo">
                  <?php if (!empty($i['processo_id'])): ?><a href="/processos?open=<?= (int) $i['processo_id'] ?>"><?= $h($i['titulo']) ?></a><?php else: ?><?= $h($i['titulo']) ?><?php endif; ?>
                  <span class="ini-tag <?= $h($i['tipo']) ?>"><?= $h(Painel::TIPOS[$i['tipo']]) ?></span>
                </div>
                <?php if (!empty($i['processo_numero']) || !empty($i['cliente_nome']) || !empty($i['quadro_nome'])): ?>
                <div class="ini-tl-sub"><?= $h(implode(' · ', array_filter([$i['processo_numero'] ?? null, $i['cliente_nome'] ?? null, $i['quadro_nome'] ?? null]))) ?></div>
                <?php endif; ?>
                <?php if (!empty($i['responsavel_nome'])): ?>
                <div class="ini-tl-meta"><span class="ini-av"><?= $h(Painel::iniciais($i['responsavel_nome'])) ?></span><?= $h($i['responsavel_nome']) ?></div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($painel['agenda']) > 7): ?>
            <a class="ini-mais-itens" href="/tarefas">e mais <?= count($painel['agenda']) - 7 ?> em Tarefas <?= $seta ?></a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="ini-card">
          <header class="ini-card-h">
            <span class="ini-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
            <h3>Prazos</h3>
            <a class="ini-mais" href="/tarefas">Todos <?= $seta ?></a>
          </header>
          <div class="ini-contadores">
            <div class="ini-ctr hoje"><b><?= (int) $painel['contagem']['hoje'] ?></b><span>hoje</span></div>
            <div class="ini-ctr amanha"><b><?= (int) $painel['contagem']['amanha'] ?></b><span>amanhã</span></div>
            <div class="ini-ctr semana"><b><?= (int) $painel['contagem']['semana'] ?></b><span>na semana</span></div>
          </div>
          <?php if ((int) $painel['contagem']['atrasadas'] > 0): ?>
          <div class="ini-atraso"><?= (int) $painel['contagem']['atrasadas'] ?> <?= (int) $painel['contagem']['atrasadas'] === 1 ? 'item atrasado' : 'itens atrasados' ?> · <a href="/tarefas">resolver</a></div>
          <?php endif; ?>
          <?php if (!$painel['prazos']): ?>
          <div class="ini-vazio curto"><p>Nenhum prazo de processo em aberto.</p></div>
          <?php else: ?>
          <div class="ini-lista">
            <?php foreach ($painel['prazos'] as $pz): $r = Painel::rotuloDeVencimento($pz['data'], $hoje); ?>
            <a class="ini-item" href="/processos?open=<?= (int) $pz['processo_id'] ?>">
              <span class="ini-av"><?= $h(Painel::iniciais((string) ($pz['responsavel_nome'] ?: '?'))) ?></span>
              <span class="ini-item-t"><b><?= $h($pz['titulo']) ?></b><span><?= $h(implode(' · ', array_filter([$pz['processo_numero'] ?? null, $pz['cliente_nome'] ?? null]))) ?></span></span>
              <span class="ini-vence <?= $h($r['tom']) ?>"><?= $h($r['texto']) ?></span>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="ini-card">
          <header class="ini-card-h">
            <span class="ini-ic"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg></span>
            <h3>Publicações</h3>
            <span class="ini-conta"><?= (int) $painel['publicacoes']['total'] ?> sem tratamento</span>
            <a class="ini-mais" href="/intimacoes">Diário <?= $seta ?></a>
          </header>
          <?php if (!$painel['publicacoes']['itens']): ?>
          <div class="ini-vazio curto"><p>Tudo tratado. Nenhuma publicação pendente.</p></div>
          <?php else: ?>
          <div class="ini-lista">
            <?php foreach ($painel['publicacoes']['itens'] as $pb): ?>
            <div class="ini-pub">
              <div class="ini-pub-trib"><?= $h($pb['tribunal']) ?> · <?= $h(Painel::diaRelativo((string) $pb['data_disponibilizacao'], $hoje)) ?></div>
              <div class="ini-pub-txt"><b><?= $h($pb['tipo_comunicacao'] ?: ($pb['titulo'] ?: 'Publicação')) ?></b><?= $pb['cliente_nome'] ? ' · ' . $h($pb['cliente_nome']) : ($pb['numero'] ? ' · ' . $h($pb['numero']) : '') ?></div>
              <a class="ini-mini" href="/intimacoes">Tratar</a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>
      </div>

      <!-- ── Linha 2: equipe, processos, retorno ── -->
      <div class="ini-grade <?= $temWhats ? 'ini-g2' : 'ini-g2-sem-whats' ?>">

        <section class="ini-card">
          <header class="ini-card-h">
            <span class="ini-ic"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
            <h3>Equipe hoje</h3>
            <a class="ini-mais" href="/desempenho">Desempenho <?= $seta ?></a>
          </header>
          <div class="ini-equipe">
            <?php foreach (array_slice($painel['equipe'], 0, 6) as $pe): ?>
            <div class="ini-pessoa">
              <span class="ini-av grande"><?= $h($pe['iniciais']) ?><i class="ini-estado <?= $h($pe['estado']) ?>"></i></span>
              <div class="ini-pessoa-t"><div class="ini-pessoa-n"><?= $h($pe['nome']) ?></div><div class="ini-pessoa-w"><?= $h($pe['papel']) ?> · <?= $h($pe['texto']) ?></div></div>
              <div class="ini-carga"><div class="ini-barra"><i class="<?= $pe['vencidas'] > 0 ? 'quente' : '' ?>" style="width:<?= (int) $pe['pct'] ?>%"></i></div><span><?= (int) $pe['carga'] ?> na mão</span></div>
            </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="ini-card">
          <header class="ini-card-h">
            <span class="ini-ic"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
            <h3>Processos com movimentação</h3>
            <a class="ini-mais" href="/processos">Todos <?= $seta ?></a>
          </header>
          <?php if (!$painel['processos']): ?>
          <div class="ini-vazio curto"><p>Nenhum processo cadastrado ainda.</p></div>
          <?php else: ?>
          <table class="ini-tabela">
            <thead><tr><th>Cliente</th><th>Última movimentação</th><th class="dir">Situação</th></tr></thead>
            <tbody>
              <?php foreach ($painel['processos'] as $pr): [$rot, $tom] = Painel::situacaoDoProcesso((string) $pr['status'], $pr['proximo_prazo'] ?: null, $hoje);
                $mov = $pr['ultima_publicacao'] ?: ($pr['tipo_acao'] ?: 'Processo atualizado');
                $quando = $pr['publicada_em'] && $pr['publicada_em'] >= substr((string) $pr['movimentado_em'], 0, 10) ? $pr['publicada_em'] : substr((string) $pr['movimentado_em'], 0, 10); ?>
              <tr onclick="location.href='/processos?open=<?= (int) $pr['id'] ?>'">
                <td class="c"><b><?= $h($pr['cliente_nome'] ?: 'Sem cliente') ?></b><span><?= $h($pr['numero']) ?></span></td>
                <td class="m"><?= $h($mov) ?><small><?= $h(Painel::diaRelativo($quando, $hoje)) ?><?= $pr['vara_comarca'] ? ' · ' . $h($pr['vara_comarca']) : '' ?></small></td>
                <td class="dir"><span class="ini-situ <?= $h($tom) ?>"><?= $h($rot) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </section>

        <?php if ($temWhats): ?>
        <section class="ini-card">
          <header class="ini-card-h">
            <span class="ini-ic"><svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>
            <h3>Aguardando retorno</h3>
            <a class="ini-mais" href="/chat">Abrir chat <?= $seta ?></a>
          </header>
          <?php if (!$painel['whatsapp']['itens']): ?>
          <div class="ini-vazio curto"><p>Ninguém esperando resposta.</p></div>
          <?php else: ?>
          <div class="ini-lista">
            <?php foreach ($painel['whatsapp']['itens'] as $w): ?>
            <a class="ini-wa" href="/chat?jid=<?= $h(rawurlencode($w['jid'])) ?>">
              <span class="ini-av cinza"><?= $h($w['iniciais']) ?></span>
              <span class="ini-wa-t"><b><?= $h($w['nome']) ?></b><span><?= $h($w['texto']) ?></span></span>
              <span class="ini-desde <?= $w['minutos'] >= 120 ? 'r' : '' ?>"><?= $h($w['desde']) ?></span>
            </a>
            <?php endforeach; ?>
            <?php if ($painel['whatsapp']['total'] > count($painel['whatsapp']['itens'])): ?>
            <a class="ini-mais-itens" href="/chat">e mais <?= (int) $painel['whatsapp']['total'] - count($painel['whatsapp']['itens']) ?> no chat <?= $seta ?></a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </section>
        <?php endif; ?>
      </div>

      <!-- ── Números: a porta para Gestão ── -->
      <section class="ini-card ini-faixa">
        <div class="ini-num"><b><?= (int) $painel['numeros']['ativos'] ?></b><span>processos ativos</span></div>
        <div class="ini-num"><b><?= (int) $painel['numeros']['novos_mes'] ?></b><span>novos no mês</span></div>
        <div class="ini-num"><b><?= (int) $painel['numeros']['audiencias_semana'] ?></b><span>audiências na semana</span></div>
        <div class="ini-num"><b><?= (int) $painel['numeros']['publicacoes_mes'] ?></b><span>publicações no mês</span></div>
        <?php if (!$vendedorCrm): ?>
        <div class="ini-num"><b><?= $h(fmtBRL((float) $dre_receita)) ?></b><span>receita operacional</span></div>
        <?php else: ?>
        <div class="ini-num"><b><?= (int) $painel['numeros']['clientes'] ?></b><span>clientes</span></div>
        <?php endif; ?>
        <a class="ini-btn" href="/dashboard?gestao=1">Ver indicadores <?= $seta ?></a>
      </section>

      <p class="ini-rodape">Prévia da interface nova · os dados são os do escritório · <a href="<?= $h(InterfaceNova::enderecoCom((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard'), '0')) ?>">voltar ao desenho de sempre</a></p>
    </section>
  </div>
</main>
<script>
(function () {
  var b = document.getElementById('iniTema'); if (!b) return;
  b.addEventListener('click', function () {
    var r = document.documentElement, claro = r.getAttribute('data-theme') === 'light';
    if (claro) { r.removeAttribute('data-theme'); try { localStorage.setItem('yuris_theme', 'dark'); } catch (e) {} }
    else       { r.setAttribute('data-theme', 'light'); try { localStorage.setItem('yuris_theme', 'light'); } catch (e) {} }
  });
})();
</script>
</body>
</html>
