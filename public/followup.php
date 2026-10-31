<?php
/**
 * followup.php: Operação › Follow-up (App\FollowUp).
 *
 * Onde a conta controla o follow-up automático do WhatsApp: as cadências (quando
 * começa, em quais etapas do funil, com quem, horário, mensagens e esperas), a
 * fila dos próximos envios com data e hora, e o histórico do que saiu.
 *
 * QUEM VÊ O QUÊ
 *   - vê quem enxerga a Prospecção, menos o vendedor da edição CRM (a fila é da
 *     conta inteira, e ele só vê os leads dele)
 *   - muda dono/admin: decide o que o sistema manda sozinho para os leads
 * Os dados vêm de /api/followup.php; esta página só desenha.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$isFleetiflow = $ctx->getProduto() === 'fleetiflow';
$perfilAdmin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
$perms = (array) ($_SESSION['user_permissions'] ?? []);
if ((!$perfilAdmin && !in_array('*', $perms, true) && !in_array('prospeccao', $perms, true)) || $ctx->vendedorCrm()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403</title>';
    echo '<style>body{font-family:system-ui;background:#0a0f1e;color:#cbd5e1;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>';
    echo '<div><h1 style="margin:0 0 8px">Acesso negado</h1><p>O follow-up automático é configurado pela administração da conta.</p></div>';
    exit;
}
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$activePage = 'followup';
function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
// Vendedor da vez da Roleta (quem recebe o próximo lead do disparo): só para owner/admin.
$daVez = null;
if ($ctx->isOwnerOrAdmin() && \App\WhatsAppAgente\SdrFleetiflow::contaDoTokenGlobal((int) $ctx->getAccountId())) {
    try { $daVez = \App\WhatsAppAgente\Roleta::daVez((int) $ctx->getAccountId()); } catch (\Throwable $e) { $daVez = null; }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $isFleetiflow ? 'Follow-up · ' . e($ctx->getMarca()['nome']) . ' CRM' : 'Follow-up · Yuris' ?></title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=20">
  <style>
    :root{
      --o-fundo:#070F1C; --o-card:#0D1C30; --o-borda:rgba(160,180,210,.14); --o-sutil:rgba(160,180,210,.08);
      --o-texto:#E2E8F0; --o-forte:#F1F5F9; --o-fraco:#94A3B8; --o-apagado:#64748B;
      --o-acento:#3B82F6; --o-acento-suave:rgba(59,130,246,.16); --o-trilho:rgba(148,163,184,.14);
      --o-bom:#34D399; --o-bom-bg:rgba(52,211,153,.14); --o-atencao:#FBBF24; --o-atencao-bg:rgba(251,191,36,.14);
      --o-ruim:#F87171; --o-ruim-bg:rgba(248,113,113,.14); --o-input:#0A1626; --o-raio:14px;
      --o-balao:#134D37; --o-balao-txt:#E7FFF3;
      --o-fonte:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    html[data-theme="light"]{
      --o-fundo:#F4F6FA; --o-card:#FFFFFF; --o-borda:rgba(15,31,54,.10); --o-sutil:rgba(15,31,54,.06);
      --o-texto:#1E293B; --o-forte:#0F1F36; --o-fraco:#5A6B7E; --o-apagado:#8B97A8;
      --o-acento:#2563EB; --o-acento-suave:#DBEAFE; --o-trilho:#EEF1F5;
      --o-bom:#047857; --o-bom-bg:#DCFCE7; --o-atencao:#92400E; --o-atencao-bg:#FEF3C7;
      --o-ruim:#B91C1C; --o-ruim-bg:#FEE2E2; --o-input:#FFFFFF; --o-balao:#DCF8C6; --o-balao-txt:#173B22;
    }
    body{ margin:0; background:var(--o-fundo); color:var(--o-texto); font-family:var(--o-fonte); }
    .o-wrap{ padding:22px 26px 60px; }
    .fu-topo{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .fu-topo h1{ margin:0; font-size:22px; font-weight:800; color:var(--o-forte); letter-spacing:-.01em; }
    .fu-topo p{ margin:4px 0 0; font-size:13px; color:var(--o-fraco); max-width:720px; line-height:1.5; }
    .fu-topo-acoes{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .o-card{ background:var(--o-card); border:1px solid var(--o-borda); border-radius:var(--o-raio); padding:18px 20px; min-width:0; }
    .o-btn{ display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:999px; border:1px solid var(--o-borda); background:var(--o-card); color:var(--o-texto); font:inherit; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; white-space:nowrap; }
    .o-btn:hover{ border-color:var(--o-acento); color:var(--o-acento); }
    .o-btn.primario{ background:var(--o-acento); border-color:var(--o-acento); color:#fff; }
    .o-btn.primario:hover{ filter:brightness(1.08); color:#fff; }
    .o-btn.perigo:hover{ border-color:var(--o-ruim); color:var(--o-ruim); }
    .o-btn.mini{ height:30px; padding:0 12px; font-size:12px; }
    .o-btn[disabled]{ opacity:.5; cursor:not-allowed; }

    /* Chave liga/desliga */
    .fu-chave{ display:inline-flex; align-items:center; gap:10px; cursor:pointer; user-select:none; font-size:13px; font-weight:700; color:var(--o-forte); }
    .fu-chave input{ position:absolute; opacity:0; pointer-events:none; }
    .fu-chave .trilho{ width:40px; height:22px; border-radius:999px; background:var(--o-trilho); position:relative; transition:background .2s; flex:none; }
    .fu-chave .trilho::after{ content:''; position:absolute; top:3px; left:3px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.25); transition:transform .2s; }
    .fu-chave input:checked + .trilho{ background:var(--o-bom); }
    .fu-chave input:checked + .trilho::after{ transform:translateX(18px); }
    .fu-chave input:disabled + .trilho{ opacity:.55; }
    .fu-geral{ padding:10px 16px; border-radius:999px; border:1px solid var(--o-borda); background:var(--o-card); }

    /* Abas */
    .fu-abas{ display:flex; gap:4px; padding:4px; border-radius:999px; background:var(--o-sutil); width:max-content; max-width:100%; overflow-x:auto; margin-bottom:16px; }
    .fu-abas button{ height:34px; padding:0 16px; border:0; border-radius:999px; background:transparent; color:var(--o-fraco); font:inherit; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; display:inline-flex; align-items:center; gap:8px; }
    .fu-abas button.ativa{ background:var(--o-card); color:var(--o-acento); box-shadow:0 1px 3px rgba(15,31,54,.12); }
    .fu-abas .cont{ min-width:20px; height:20px; padding:0 6px; border-radius:999px; background:var(--o-acento-suave); color:var(--o-acento); font-size:11px; display:inline-grid; place-items:center; }
    .fu-painel[hidden]{ display:none; }

    .fu-aviso{ margin:0 0 16px; padding:12px 16px; border-radius:12px; font-size:13px; background:var(--o-atencao-bg); color:var(--o-atencao); line-height:1.5; }

    /* Cadências */
    .fu-lista{ display:grid; grid-template-columns:repeat(auto-fill,minmax(min(100%,460px),1fr)); gap:16px; }
    .fu-cad{ display:flex; flex-direction:column; gap:14px; }
    .fu-cad-topo{ display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .fu-cad h2{ margin:0; font-size:16px; font-weight:800; color:var(--o-forte); }
    .fu-cad .sub{ margin:3px 0 0; font-size:12.5px; color:var(--o-fraco); }
    .fu-chips{ display:flex; flex-wrap:wrap; gap:6px; }
    .fu-chip{ display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; background:var(--o-sutil); color:var(--o-texto); font-size:12px; font-weight:600; }
    .fu-chip svg{ width:13px; height:13px; flex:none; color:var(--o-fraco); }
    .fu-estado{ display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .fu-estado.on{ background:var(--o-bom-bg); color:var(--o-bom); }
    .fu-estado.off{ background:var(--o-sutil); color:var(--o-apagado); }
    .fu-estado.on::before{ content:''; width:7px; height:7px; border-radius:50%; background:currentColor; animation:fuPulso 1.6s infinite; }
    @keyframes fuPulso{ 0%{ box-shadow:0 0 0 0 currentColor; } 70%{ box-shadow:0 0 0 6px transparent; } 100%{ box-shadow:0 0 0 0 transparent; } }

    /* Linha do tempo dos passos */
    .fu-tempo{ list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0; }
    .fu-tempo li{ display:grid; grid-template-columns:28px minmax(0,1fr); gap:10px; position:relative; padding-bottom:14px; }
    .fu-tempo li:not(:last-child)::before{ content:''; position:absolute; left:13px; top:28px; bottom:0; width:2px; background:var(--o-trilho); }
    .fu-tempo .num{ width:28px; height:28px; border-radius:50%; background:var(--o-acento-suave); color:var(--o-acento); display:grid; place-items:center; font-size:12px; font-weight:800; }
    .fu-tempo .num.inicio{ background:var(--o-sutil); color:var(--o-fraco); }
    .fu-tempo .espera{ font-size:12px; font-weight:700; color:var(--o-fraco); margin:5px 0 6px; display:flex; align-items:center; gap:6px; }
    .fu-tempo .espera svg{ width:13px; height:13px; }
    .fu-balao{ background:var(--o-balao); color:var(--o-balao-txt); padding:9px 12px; border-radius:4px 12px 12px 12px; font-size:13px; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; max-width:100%; }
    .fu-cad-rodape{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; border-top:1px solid var(--o-sutil); padding-top:12px; }

    .fu-vazio{ padding:40px 16px; text-align:center; color:var(--o-fraco); font-size:13.5px; line-height:1.6; }
    .fu-vazio b{ color:var(--o-forte); display:block; font-size:16px; margin-bottom:6px; }

    /* Tabelas */
    .fu-rolagem{ overflow-x:auto; }
    .fu-tabela{ width:100%; border-collapse:collapse; font-size:13px; }
    .fu-tabela th{ text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--o-apagado); padding:8px 10px; border-bottom:1px solid var(--o-borda); white-space:nowrap; }
    .fu-tabela td{ padding:11px 10px; border-bottom:1px solid var(--o-sutil); vertical-align:top; }
    .fu-tabela td.lead a{ color:var(--o-forte); font-weight:700; text-decoration:none; }
    .fu-tabela td.lead a:hover{ color:var(--o-acento); }
    .fraco, .fu-tabela .fraco{ color:var(--o-fraco); font-size:12px; }
    .fu-quando{ display:flex; align-items:center; gap:8px; white-space:nowrap; font-weight:700; color:var(--o-forte); }
    .fu-quando svg{ width:15px; height:15px; color:var(--o-acento); flex:none; }
    .fu-selo{ display:inline-flex; align-items:center; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
    .fu-selo.bom{ background:var(--o-bom-bg); color:var(--o-bom); }
    .fu-selo.ruim{ background:var(--o-ruim-bg); color:var(--o-ruim); }
    .fu-selo.atencao{ background:var(--o-atencao-bg); color:var(--o-atencao); }
    .fu-selo.neutro{ background:var(--o-acento-suave); color:var(--o-acento); }
    .fu-passo{ display:inline-flex; gap:3px; align-items:center; }
    .fu-passo i{ width:16px; height:6px; border-radius:3px; background:var(--o-trilho); }
    .fu-passo i.feito{ background:var(--o-bom); }
    .fu-passo i.agora{ background:var(--o-acento); }
    .fu-texto-hist{ white-space:pre-wrap; overflow-wrap:anywhere; color:var(--o-fraco); font-size:12.5px; line-height:1.5; min-width:220px; }

    /* Editor (modal próprio, sem diálogo do navegador) */
    .fu-modal{ position:fixed; inset:0; z-index:1200; display:flex; align-items:flex-start; justify-content:center; padding:32px 16px; background:rgba(4,10,20,.6); overflow-y:auto; }
    .fu-modal[hidden]{ display:none; }
    .fu-modal-caixa{ width:min(860px,100%); background:var(--o-card); border:1px solid var(--o-borda); border-radius:18px; box-shadow:0 24px 60px rgba(0,0,0,.35); }
    .fu-modal-topo{ display:flex; justify-content:space-between; align-items:center; gap:12px; padding:18px 22px; border-bottom:1px solid var(--o-sutil); }
    .fu-modal-topo h2{ margin:0; font-size:18px; font-weight:800; color:var(--o-forte); }
    .fu-fechar{ width:34px; height:34px; border-radius:50%; border:0; background:var(--o-sutil); color:var(--o-fraco); cursor:pointer; font-size:18px; line-height:1; }
    .fu-modal-corpo{ padding:20px 22px; display:flex; flex-direction:column; gap:18px; }
    .fu-modal-rodape{ display:flex; justify-content:flex-end; gap:10px; padding:16px 22px; border-top:1px solid var(--o-sutil); flex-wrap:wrap; }
    .fu-grade{ display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr)); gap:14px; }
    .fu-campo{ display:flex; flex-direction:column; gap:6px; min-width:0; }
    .fu-campo > label, .fu-campo > .rot{ font-size:11px; font-weight:700; color:var(--o-fraco); text-transform:uppercase; letter-spacing:.05em; }
    .fu-campo input[type=text], .fu-campo input[type=number], .fu-campo input[type=time], .fu-campo select, .fu-campo textarea{
      min-height:38px; padding:8px 11px; border-radius:10px; border:1px solid var(--o-borda); background:var(--o-input); color:var(--o-texto); font:inherit; font-size:13.5px; box-sizing:border-box; width:100%; }
    .fu-campo textarea{ min-height:96px; resize:vertical; line-height:1.5; }
    .fu-campo .ajuda{ font-size:12px; color:var(--o-apagado); line-height:1.45; }
    .fu-opcoes{ display:flex; flex-wrap:wrap; gap:8px; }
    .fu-opcao{ display:inline-flex; align-items:center; gap:7px; padding:8px 12px; border-radius:10px; border:1px solid var(--o-borda); font-size:13px; cursor:pointer; color:var(--o-texto); }
    .fu-opcao:has(input:checked){ border-color:var(--o-acento); background:var(--o-acento-suave); color:var(--o-acento); font-weight:700; }
    .fu-opcao input{ accent-color:var(--o-acento); margin:0; }
    .fu-linha{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .fu-linha input[type=time]{ width:120px; }
    .fu-passo-ed{ border:1px solid var(--o-borda); border-radius:14px; padding:14px; display:flex; flex-direction:column; gap:10px; }
    .fu-passo-ed-topo{ display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .fu-passo-ed-topo b{ font-size:13.5px; color:var(--o-forte); }
    .fu-espera{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:13px; color:var(--o-fraco); }
    .fu-espera input{ width:90px !important; }
    .fu-espera select{ width:auto !important; }
    .fu-erro{ padding:10px 14px; border-radius:10px; background:var(--o-ruim-bg); color:var(--o-ruim); font-size:13px; font-weight:600; }
    .fu-erro[hidden]{ display:none; }
    .fu-vars{ font-size:12px; color:var(--o-apagado); }
    .fu-vars code{ background:var(--o-sutil); padding:1px 6px; border-radius:6px; color:var(--o-texto); }
    @media (max-width:700px){ .o-wrap{ padding:16px 14px 50px; } .fu-cad-topo{ flex-direction:column; } }
  </style>
  <?php if ($isFleetiflow): ?>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html[data-theme="light"]{
      --o-fundo:#F6F7F9; --o-borda:rgba(17,29,45,.08); --o-sutil:rgba(17,29,45,.05);
      --o-texto:#575757; --o-forte:#3D3D3D; --o-fraco:#676767; --o-apagado:#767676;
      --o-acento:var(--ff-marca, #015DFC); --o-acento-suave:var(--ff-marca-suave, #D6E4FF); --o-trilho:#E9EAEC;
      --o-bom:#017801; --o-bom-bg:#DCFFDC; --o-atencao:#8F6000; --o-atencao-bg:#FFF6E6;
      --o-ruim:#B00000; --o-ruim-bg:#FFCCCC; --o-input:#F6F7F9;
      --o-fonte:'Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;
    }
  </style>
  <?php endif; ?>
</head>
<body>
  <main class="o-wrap">
    <div class="page-layout">
      <?php include __DIR__ . '/includes/sidebar.php'; ?>
      <div class="main-content">

    <div class="fu-topo">
      <div>
        <h1>Follow-up automático</h1>
        <p>As mensagens que o sistema manda sozinho pelo WhatsApp quando o lead fica em silêncio. Aqui você decide quando cada cadência começa, em quais etapas do funil ela vale, os textos e o tempo entre uma mensagem e outra. O card de cada lead mostra quando sai a próxima.</p>
      </div>
      <div class="fu-topo-acoes">
        <?php if ($daVez): ?>
        <a class="o-btn" href="/roleta" title="Recebe o próximo lead que o disparo abordar; a mensagem sai com o nome dele">Vendedor da vez: <b style="margin-left:4px"><?= e($daVez['atendente']) ?></b></a>
        <?php endif; ?>
        <label class="fu-chave fu-geral" title="Chave geral: desligada, nenhuma cadência da conta manda nada">
          <input type="checkbox" id="fuGeral"><span class="trilho"></span><span id="fuGeralTxt">Follow-up ligado</span>
        </label>
        <button type="button" class="o-btn primario" id="fuNova" hidden>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nova cadência
        </button>
      </div>
    </div>

    <p class="fu-aviso" id="fuAvisoDesligado" hidden>A chave geral está desligada: nenhuma cadência manda mensagem até você ligar de novo. A fila abaixo mostra o que sairia.</p>

    <div class="fu-abas" role="tablist">
      <button type="button" class="ativa" data-aba="cadencias" role="tab">Cadências <span class="cont" id="fuContCad">0</span></button>
      <button type="button" data-aba="proximos" role="tab">Próximos envios <span class="cont" id="fuContProx">0</span></button>
      <button type="button" data-aba="historico" role="tab">Enviados</button>
    </div>

    <section class="fu-painel" data-painel="cadencias"><div class="fu-lista" id="fuLista"><div class="o-card fu-vazio">Carregando…</div></div></section>
    <section class="fu-painel" data-painel="proximos" hidden><div class="o-card"><div class="fu-rolagem" id="fuProximos"></div></div></section>
    <section class="fu-painel" data-painel="historico" hidden><div class="o-card"><div class="fu-rolagem" id="fuHistorico"></div></div></section>

      </div>
    </div>
  </main>

  <div class="fu-modal" id="fuModal" hidden role="dialog" aria-modal="true" aria-labelledby="fuModalTitulo">
    <form class="fu-modal-caixa" id="fuForm" novalidate>
      <div class="fu-modal-topo"><h2 id="fuModalTitulo">Nova cadência</h2><button type="button" class="fu-fechar" data-fechar aria-label="Fechar">×</button></div>
      <div class="fu-modal-corpo">
        <div class="fu-erro" id="fuErro" hidden></div>
        <div class="fu-campo"><label for="fNome">Nome da cadência</label><input type="text" id="fNome" maxlength="120" placeholder="Ex.: Não respondeu à abertura"></div>
        <div class="fu-campo"><span class="rot">Quando começa</span><div class="fu-opcoes" id="fGatilho"></div>
          <span class="ajuda">O relógio conta da nossa última mensagem. Se o lead responder, a cadência para na hora.</span></div>
        <div class="fu-campo"><span class="rot">Em quais etapas do funil (o card precisa estar numa delas)</span><div class="fu-opcoes" id="fEtapas"></div>
          <span class="ajuda">Nenhuma marcada vale para todas as etapas.</span></div>
        <div class="fu-grade">
          <div class="fu-campo"><label for="fQuem">Para quais conversas</label><select id="fQuem"></select></div>
          <div class="fu-campo"><label for="fMover">Ao mandar a primeira, mover o card para</label><select id="fMover"></select></div>
          <div class="fu-campo"><label for="fDias">Não começar se o silêncio passar de (dias)</label><input type="number" id="fDias" min="0" max="365">
            <span class="ajuda">Evita mandar para conversas antigas quando a cadência é ligada. 0 = sem limite.</span></div>
        </div>
        <div class="fu-campo"><span class="rot">Horário de envio (Brasília)</span>
          <div class="fu-opcoes" id="fDiasSemana"></div>
          <div class="fu-linha" style="margin-top:6px"><span class="fraco">das</span><input type="time" id="fDe"><span class="fraco">às</span><input type="time" id="fAte"></div>
          <span class="ajuda">Mensagem que vencer fora do horário sai no começo do próximo período.</span></div>
        <div class="fu-campo"><span class="rot">Mensagens</span>
          <div id="fPassos" style="display:flex;flex-direction:column;gap:12px"></div>
          <div class="fu-linha" style="justify-content:space-between">
            <span class="fu-vars">Campos: <code>{nome}</code> primeiro nome do contato, <code>{empresa}</code> empresa do card, <code>{vendedor}</code> primeiro nome do vendedor responsável pelo card (do cadastro). Campo vazio some do texto.</span>
            <button type="button" class="o-btn mini" id="fAddPasso">+ Adicionar mensagem</button>
          </div></div>
      </div>
      <div class="fu-modal-rodape">
        <button type="button" class="o-btn" data-fechar>Cancelar</button>
        <button type="submit" class="o-btn primario" id="fSalvar">Salvar cadência</button>
      </div>
    </form>
  </div>

  <script>
  (function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    var D = null;           // resposta da API
    var editando = null;    // id da cadência no editor (null = nova)
    var DIAS = [[1, 'Seg'], [2, 'Ter'], [3, 'Qua'], [4, 'Qui'], [5, 'Sex'], [6, 'Sáb'], [7, 'Dom']];
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var toast = function (m, t) { if (window.Yuris && Yuris.toast) Yuris.toast(m, t || 'info'); };
    var ICO = {
      relogio: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>',
      etapa: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="10" y="4" width="5" height="11" rx="1.5"/><rect x="17" y="4" width="4" height="7" rx="1.5"/></svg>',
      gatilho: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
      quem: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></svg>',
      cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>'
    };

    function espera(min) {
      min = Number(min) || 0;
      if (min % 1440 === 0) { var d = min / 1440; return d + (d === 1 ? ' dia' : ' dias'); }
      if (min % 60 === 0) { var h = min / 60; return h + (h === 1 ? ' hora' : ' horas'); }
      if (min > 60) return Math.floor(min / 60) + ' h ' + (min % 60) + ' min';
      return min + ' min';
    }
    function quando(iso) {
      if (!iso) return '';
      var d = new Date(iso), hoje = new Date(); hoje.setHours(0, 0, 0, 0);
      var dia = new Date(d); dia.setHours(0, 0, 0, 0);
      var diff = Math.round((dia - hoje) / 86400000);
      var hora = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
      if (diff === 0) return 'Hoje ' + hora;
      if (diff === 1) return 'Amanhã ' + hora;
      if (diff === -1) return 'Ontem ' + hora;
      return d.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' }).replace('.', '') + ' ' + hora;
    }
    function falta(iso) {
      var s = Math.round((new Date(iso) - Date.now()) / 1000);
      if (s <= 0) return 'na fila agora';
      var m = Math.round(s / 60);
      if (m < 60) return 'em ' + m + ' min';
      var h = Math.floor(m / 60);
      if (h < 24) return 'em ' + h + ' h ' + String(m % 60).padStart(2, '0');
      var d = Math.floor(h / 24);
      return 'em ' + d + (d === 1 ? ' dia' : ' dias') + (h % 24 ? ' e ' + (h % 24) + ' h' : '');
    }
    function nomeEtapa(id) { var c = (D.colunas || []).find(function (x) { return x.id === id; }); return c ? c.nome : 'Etapa removida'; }
    function diasTxt(h) {
      var ds = h.dias || [];
      var nomes = DIAS.filter(function (x) { return ds.indexOf(x[0]) >= 0; }).map(function (x) { return x[1].toLowerCase(); });
      var seg = ds.join(',');
      var txt = seg === '1,2,3,4,5' ? 'seg a sex' : seg === '1,2,3,4,5,6' ? 'seg a sáb' : seg === '1,2,3,4,5,6,7' ? 'todos os dias' : nomes.join(', ');
      return txt + ', ' + h.de + ' às ' + h.ate;
    }
    function trilha(passo, total, feitoAte) {
      var h = '<span class="fu-passo" aria-label="mensagem ' + passo + ' de ' + total + '">';
      for (var i = 1; i <= total; i++) h += '<i class="' + (i < passo ? 'feito' : i === passo ? 'agora' : '') + '"></i>';
      return h + '</span>';
    }

    async function api(corpo) {
      var r = await fetch('/api/followup.php', corpo ? {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(corpo)
      } : { credentials: 'same-origin' });
      var j = {};
      try { j = await r.json(); } catch (e) { j = { error: 'Resposta inválida do servidor.' }; }
      if (!r.ok && !j.error) j.error = 'Não foi possível concluir.';
      return j;
    }

    async function carregar() {
      var j = await api();
      if (!j.ok) { $('fuLista').innerHTML = '<div class="o-card fu-vazio">' + esc(j.error || 'Não foi possível carregar.') + '</div>'; return; }
      D = j;
      $('fuNova').hidden = !D.pode_editar;
      $('fuGeral').checked = !!D.ligado;
      $('fuGeral').disabled = !D.pode_editar;
      $('fuGeralTxt').textContent = D.ligado ? 'Follow-up ligado' : 'Follow-up desligado';
      $('fuAvisoDesligado').hidden = !!D.ligado;
      $('fuContCad').textContent = D.cadencias.length;
      $('fuContProx').textContent = D.proximos.length;
      desenharCadencias(); desenharProximos(); desenharHistorico();
    }

    function desenharCadencias() {
      var el = $('fuLista');
      if (!D.cadencias.length) {
        el.innerHTML = '<div class="o-card fu-vazio" style="grid-column:1/-1"><b>Nenhuma cadência ainda</b>Crie uma para o sistema mandar as mensagens de follow-up sozinho, no horário certo.' +
          (D.pode_editar ? '<div style="margin-top:14px"><button type="button" class="o-btn primario" data-nova>Criar a primeira cadência</button></div>' : '') + '</div>';
        return;
      }
      el.innerHTML = D.cadencias.map(function (c, i) {
        var etapas = c.etapas.length ? c.etapas.map(nomeEtapa).join(', ') : 'Todas as etapas';
        var passos = '<li><span class="num inicio">' + ICO.gatilho.replace('<svg', '<svg width="13" height="13"') + '</span><div><div class="espera" style="margin-top:6px">' +
          esc(c.gatilho === 'sem_resposta' ? 'Nossa primeira mensagem, sem resposta' : c.gatilho === 'parou' ? 'O lead respondeu e parou' : 'Lead em silêncio') + '</div></div></li>' +
          c.passos.map(function (p, n) {
            return '<li><span class="num">' + (n + 1) + '</span><div><div class="espera">' + ICO.relogio + 'Espera ' + esc(espera(p.espera_min)) + '</div><div class="fu-balao">' + esc(p.texto) + '</div></div></li>';
          }).join('');
        return '<article class="o-card fu-cad" data-id="' + c.id + '">' +
          '<div class="fu-cad-topo"><div><h2>' + esc(c.nome) + '</h2><p class="sub">' + c.passos.length + (c.passos.length === 1 ? ' mensagem' : ' mensagens') + '</p></div>' +
            '<label class="fu-chave" title="' + (c.ativo ? 'Ligada: está mandando' : 'Desligada: não manda nada') + '"><span class="fu-estado ' + (c.ativo ? 'on' : 'off') + '">' + (c.ativo ? 'Ligada' : 'Desligada') + '</span>' +
            '<input type="checkbox" data-ligar="' + c.id + '"' + (c.ativo ? ' checked' : '') + (D.pode_editar ? '' : ' disabled') + '><span class="trilho"></span></label></div>' +
          '<div class="fu-chips">' +
            '<span class="fu-chip">' + ICO.gatilho + esc(D.gatilhos[c.gatilho]) + '</span>' +
            '<span class="fu-chip">' + ICO.etapa + esc(etapas) + '</span>' +
            '<span class="fu-chip">' + ICO.quem + esc(D.com_quem[c.com_quem]) + '</span>' +
            '<span class="fu-chip">' + ICO.cal + esc(diasTxt(c.horario)) + '</span>' +
            (c.mover_para ? '<span class="fu-chip">' + ICO.etapa + 'Move para ' + esc(nomeEtapa(c.mover_para)) + '</span>' : '') +
            (c.inicio_max_dias ? '<span class="fu-chip">' + ICO.relogio + 'Começa só com silêncio de até ' + c.inicio_max_dias + (c.inicio_max_dias === 1 ? ' dia' : ' dias') + '</span>' : '') +
          '</div>' +
          '<ol class="fu-tempo">' + passos + '</ol>' +
          (D.pode_editar ? '<div class="fu-cad-rodape">' +
            (D.cadencias.length > 1 ? '<button type="button" class="o-btn mini" data-subir="' + c.id + '"' + (i === 0 ? ' disabled' : '') + ' title="Vem antes: ganha quando a conversa cabe em duas cadências">Subir</button>' : '') +
            '<button type="button" class="o-btn mini perigo" data-excluir="' + c.id + '">Excluir</button>' +
            '<button type="button" class="o-btn mini" data-editar="' + c.id + '">Editar</button></div>' : '') +
        '</article>';
      }).join('');
    }

    function desenharProximos() {
      var el = $('fuProximos');
      if (!D.proximos.length) {
        el.innerHTML = '<div class="fu-vazio"><b>Nada na fila</b>' + (D.cadencias.some(function (c) { return c.ativo; })
          ? 'Nenhuma conversa está esperando follow-up agora. Quando um lead ficar em silêncio numa etapa da cadência, ele aparece aqui com a data e a hora.'
          : 'Ligue uma cadência para ver aqui quem recebe a próxima mensagem e quando.') + '</div>';
        return;
      }
      el.innerHTML = '<table class="fu-tabela"><thead><tr><th title="A ordem na fila do número">Ordem</th><th>Lead</th><th>Etapa</th><th>Cadência</th><th>Mensagem</th><th>Sai</th></tr></thead><tbody>' +
        D.proximos.map(function (p) {
          return '<tr><td>' + ordem(p) + '</td><td class="lead"><a href="/prospeccao?open=' + p.card_id + '">' + esc(p.lead) + '</a><div class="fraco">' +
              (p.passo === 1 ? 'Nossa mensagem: ' : 'Último follow-up: ') + esc(quando(p.ultima_saida)) +
              ' · <a href="/chat?numero=' + p.numero + '&jid=' + encodeURIComponent(p.jid) + '" style="font-weight:600">Abrir conversa</a></div></td>' +
            '<td>' + esc(p.etapa) + '</td><td>' + esc(p.cadencia) + '</td>' +
            '<td><div style="display:flex;flex-direction:column;gap:5px">' + trilha(p.passo, p.total) + '<span class="fraco">' + p.passo + ' de ' + p.total + '</span></div></td>' +
            '<td>' + saida(p) + '</td></tr>';
        }).join('') + '</tbody></table>' +
        '<p class="fraco" style="margin:12px 2px 0">Cada número manda um follow-up por vez, com 30 a 40 minutos entre um e outro, para o WhatsApp não bloquear, e só no horário de envio da cadência. Quem já está na fila aparece na ordem em que vai sair, com a faixa de horário prevista (a espera é sorteada, por isso é uma faixa).</p>';
    }

    // Está na fila do número: já venceu ou a fila segura (sai depois da hora da cadência).
    function naFila(p) { return p.na_fila || (p.sai_ate && new Date(p.sai_ate) - new Date(p.previsto) > 60000); }
    function hora(iso) { return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }
    // "em 35 a 50 min", "em 1 h 05 a 1 h 30": a espera entre envios é sorteada (30 a 40 min).
    function faixa(de, ate) {
      var m = function (iso) { return Math.max(0, Math.round((new Date(iso) - Date.now()) / 60000)); };
      var txt = function (n) { return n < 60 ? String(n) : Math.floor(n / 60) + ' h ' + String(n % 60).padStart(2, '0'); };
      var a = m(de), b = m(ate);
      if (b <= 1) return 'saindo agora';
      if (a === b) return 'em ' + txt(a) + (a < 60 ? ' min' : '');
      return 'em ' + (a <= 1 ? 'instantes' : txt(a)) + ' a ' + txt(b) + (b < 60 ? ' min' : '');
    }

    // Quando sai: na fila do número, a faixa de horário (sai_de a sai_ate) e a ordem;
    // fora dela, a data da cadência e quanto falta.
    function saida(p) {
      if (!p.sai_de || !naFila(p)) {
        return '<div class="fu-quando">' + ICO.relogio + esc(quando(p.sai_de || p.previsto)) + '</div><div class="fraco">' + esc(falta(p.sai_de || p.previsto)) + '</div>';
      }
      var mesmo = hora(p.sai_de) === hora(p.sai_ate);
      return '<div class="fu-quando">' + ICO.relogio + esc(quando(p.sai_de) + (mesmo ? '' : ' a ' + hora(p.sai_ate))) + '</div>' +
        '<div class="fraco">' + esc(faixa(p.sai_de, p.sai_ate)) + '</div>' +
        '<div class="fraco" style="margin-top:3px">pela cadência: ' + esc(quando(p.previsto)) + '</div>';
    }
    function ordem(p) {
      // A ordem de saída pelo número; destaque para quem já está na fila.
      if (!p.ordem) return '<span class="fraco">—</span>';
      if (!naFila(p)) return '<span class="fraco" style="font-weight:700">' + p.ordem + 'º</span>';
      return '<span class="fu-selo ' + (p.ordem === 1 ? 'neutro' : 'atencao') + '">' + p.ordem + 'º</span>';
    }

    function desenharHistorico() {
      var el = $('fuHistorico');
      if (!D.historico.length) { el.innerHTML = '<div class="fu-vazio"><b>Nenhum envio ainda</b>Os follow-ups que o sistema mandar aparecem aqui, com o texto que saiu.</div>'; return; }
      var selo = { enviado: ['bom', 'Enviado'], falhou: ['ruim', 'Falhou'], enviando: ['neutro', 'Enviando'] };
      el.innerHTML = '<table class="fu-tabela"><thead><tr><th>Quando</th><th>Lead</th><th>Cadência</th><th>Mensagem</th><th>Situação</th></tr></thead><tbody>' +
        D.historico.map(function (h) {
          var s = selo[h.status] || ['neutro', h.status];
          return '<tr><td style="white-space:nowrap">' + esc(quando(h.quando)) + '</td>' +
            '<td class="lead">' + (h.card_id ? '<a href="/prospeccao?open=' + h.card_id + '">' + esc(h.lead) + '</a>' : esc(h.lead)) + '</td>' +
            '<td>' + esc(h.cadencia) + '<div class="fraco">mensagem ' + h.passo + '</div></td>' +
            '<td><div class="fu-texto-hist">' + esc(h.texto) + '</div></td>' +
            '<td><span class="fu-selo ' + s[0] + '">' + esc(s[1]) + '</span>' + (h.erro ? '<div class="fraco" style="margin-top:4px">' + esc(h.erro) + (h.tentativas > 1 ? ' (' + h.tentativas + ' tentativas)' : '') + '</div>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
    }

    // ── Editor ───────────────────────────────────────────────────────────
    function unidade(min) { return min % 1440 === 0 ? [min / 1440, 1440] : min % 60 === 0 ? [min / 60, 60] : [min, 1]; }
    function passoHtml(p, n) {
      var u = unidade(p.espera_min || 1440);
      return '<div class="fu-passo-ed" data-passo>' +
        '<div class="fu-passo-ed-topo"><b>Mensagem ' + (n + 1) + '</b><button type="button" class="o-btn mini perigo" data-tirar>Remover</button></div>' +
        '<div class="fu-espera">Mandar depois de <input type="number" min="1" data-qtd value="' + u[0] + '">' +
          '<select data-un><option value="1"' + (u[1] === 1 ? ' selected' : '') + '>minutos</option><option value="60"' + (u[1] === 60 ? ' selected' : '') + '>horas</option><option value="1440"' + (u[1] === 1440 ? ' selected' : '') + '>dias</option></select>' +
          '<span>' + (n === 0 ? 'da nossa última mensagem' : 'da mensagem ' + n) + '</span></div>' +
        '<div class="fu-campo"><textarea data-texto maxlength="2000" placeholder="O texto que vai para o lead">' + esc(p.texto || '') + '</textarea></div></div>';
    }
    function renumerar() {
      var ps = [].slice.call($('fPassos').querySelectorAll('[data-passo]'));
      var dados = ps.map(lerPasso);
      $('fPassos').innerHTML = dados.map(passoHtml).join('');
    }
    function lerPasso(el) {
      return { espera_min: (parseInt(el.querySelector('[data-qtd]').value, 10) || 0) * parseInt(el.querySelector('[data-un]').value, 10), texto: el.querySelector('[data-texto]').value };
    }
    function abrirEditor(c) {
      editando = c ? c.id : null;
      $('fuModalTitulo').textContent = c ? 'Editar cadência' : 'Nova cadência';
      $('fuErro').hidden = true;
      var v = c || { nome: '', gatilho: 'sem_resposta', com_quem: 'todas', etapas: [], mover_para: null, inicio_max_dias: 3,
        horario: { dias: [1, 2, 3, 4, 5, 6], de: '08:00', ate: '18:00' }, passos: [{ espera_min: 300, texto: '' }] };
      $('fNome').value = v.nome;
      $('fGatilho').innerHTML = Object.keys(D.gatilhos).map(function (k) {
        return '<label class="fu-opcao"><input type="radio" name="gatilho" value="' + k + '"' + (v.gatilho === k ? ' checked' : '') + '>' + esc(D.gatilhos[k]) + '</label>';
      }).join('');
      $('fEtapas').innerHTML = D.colunas.map(function (col) {
        return '<label class="fu-opcao"><input type="checkbox" name="etapa" value="' + col.id + '"' + (v.etapas.indexOf(col.id) >= 0 ? ' checked' : '') + '>' + esc(col.nome) + '</label>';
      }).join('') || '<span class="fraco">O funil ainda não tem etapas.</span>';
      $('fQuem').innerHTML = Object.keys(D.com_quem).map(function (k) { return '<option value="' + k + '"' + (v.com_quem === k ? ' selected' : '') + '>' + esc(D.com_quem[k]) + '</option>'; }).join('');
      $('fMover').innerHTML = '<option value="">Não mover</option>' + D.colunas.map(function (col) {
        return '<option value="' + col.id + '"' + (v.mover_para === col.id ? ' selected' : '') + '>' + esc(col.nome) + '</option>';
      }).join('');
      $('fDias').value = v.inicio_max_dias;
      $('fDiasSemana').innerHTML = DIAS.map(function (d) {
        return '<label class="fu-opcao"><input type="checkbox" name="dia" value="' + d[0] + '"' + (v.horario.dias.indexOf(d[0]) >= 0 ? ' checked' : '') + '>' + d[1] + '</label>';
      }).join('');
      $('fDe').value = v.horario.de; $('fAte').value = v.horario.ate;
      $('fPassos').innerHTML = v.passos.map(passoHtml).join('');
      $('fuModal').hidden = false;
      setTimeout(function () { $('fNome').focus(); }, 30);
    }
    function fecharEditor() { $('fuModal').hidden = true; }

    $('fAddPasso').addEventListener('click', function () {
      var n = $('fPassos').querySelectorAll('[data-passo]').length;
      if (n >= 10) { toast('No máximo 10 mensagens por cadência.', 'error'); return; }
      $('fPassos').insertAdjacentHTML('beforeend', passoHtml({ espera_min: 1440, texto: '' }, n));
      $('fPassos').lastElementChild.querySelector('textarea').focus();
    });
    $('fPassos').addEventListener('click', function (e) {
      if (!e.target.closest('[data-tirar]')) return;
      if ($('fPassos').querySelectorAll('[data-passo]').length <= 1) { toast('A cadência precisa de pelo menos uma mensagem.', 'error'); return; }
      e.target.closest('[data-passo]').remove();
      renumerar();
    });
    $('fuForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      var marcados = function (nome) { return [].slice.call(document.querySelectorAll('input[name="' + nome + '"]:checked')).map(function (i) { return parseInt(i.value, 10); }); };
      var g = document.querySelector('input[name="gatilho"]:checked');
      var corpo = {
        acao: 'salvar', id: editando, nome: $('fNome').value, gatilho: g ? g.value : '', com_quem: $('fQuem').value,
        etapas: marcados('etapa'), mover_para: $('fMover').value || null, inicio_max_dias: parseInt($('fDias').value, 10) || 0,
        horario: { dias: marcados('dia'), de: $('fDe').value, ate: $('fAte').value },
        passos: [].slice.call($('fPassos').querySelectorAll('[data-passo]')).map(lerPasso)
      };
      $('fSalvar').disabled = true;
      var r = await api(corpo);
      $('fSalvar').disabled = false;
      if (!r.ok) { $('fuErro').textContent = r.error || 'Não foi possível salvar.'; $('fuErro').hidden = false; $('fuErro').scrollIntoView({ block: 'nearest' }); return; }
      fecharEditor();
      toast(editando ? 'Cadência salva.' : 'Cadência criada. Ela nasce desligada: ligue quando os textos estiverem certos.', 'success');
      carregar();
    });
    document.querySelectorAll('[data-fechar]').forEach(function (b) { b.addEventListener('click', fecharEditor); });
    $('fuModal').addEventListener('mousedown', function (e) { if (e.target === $('fuModal')) fecharEditor(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('fuModal').hidden) fecharEditor(); });

    // ── Ações da lista ───────────────────────────────────────────────────
    $('fuNova').addEventListener('click', function () { abrirEditor(null); });
    $('fuLista').addEventListener('click', async function (e) {
      var b;
      if (e.target.closest('[data-nova]')) return abrirEditor(null);
      if ((b = e.target.closest('[data-editar]'))) return abrirEditor(D.cadencias.find(function (c) { return c.id === +b.dataset.editar; }));
      if ((b = e.target.closest('[data-excluir]'))) {
        var c = D.cadencias.find(function (x) { return x.id === +b.dataset.excluir; });
        var ok = await Yuris.confirm('A cadência "' + c.nome + '" deixa de mandar mensagens e some da lista. O histórico do que já saiu continua.', { title: 'Excluir cadência', danger: true });
        if (!ok) return;
        var r = await api({ acao: 'excluir', id: c.id });
        toast(r.ok ? 'Cadência excluída.' : (r.error || 'Não foi possível excluir.'), r.ok ? 'success' : 'error');
        return carregar();
      }
      if ((b = e.target.closest('[data-subir]'))) {
        var ids = D.cadencias.map(function (c) { return c.id; }), i = ids.indexOf(+b.dataset.subir);
        if (i <= 0) return;
        ids.splice(i - 1, 0, ids.splice(i, 1)[0]);
        await api({ acao: 'ordenar', ids: ids });
        return carregar();
      }
    });
    $('fuLista').addEventListener('change', async function (e) {
      var inp = e.target.closest('[data-ligar]');
      if (!inp) return;
      var ligar = inp.checked;
      var c = D.cadencias.find(function (x) { return x.id === +inp.dataset.ligar; });
      if (ligar) {
        var ok = await Yuris.confirm('A partir de agora o sistema manda as ' + c.passos.length + ' mensagens de "' + c.nome + '" sozinho para os leads que se encaixam. Confira os textos antes.', { title: 'Ligar cadência', okLabel: 'Ligar' });
        if (!ok) { inp.checked = false; return; }
      }
      var r = await api({ acao: 'ligar', id: c.id, ativo: ligar ? 1 : 0 });
      toast(r.ok ? (ligar ? 'Cadência ligada.' : 'Cadência desligada: não manda mais nada.') : (r.error || 'Não foi possível mudar.'), r.ok ? 'success' : 'error');
      carregar();
    });
    $('fuGeral').addEventListener('change', async function () {
      var ligar = this.checked;
      if (!ligar) {
        var ok = await Yuris.confirm('Nenhuma cadência manda mensagem enquanto a chave geral estiver desligada. É a mesma chave do botão "Follow-up" do Chat.', { title: 'Desligar o follow-up', okLabel: 'Desligar' });
        if (!ok) { this.checked = true; return; }
      }
      var r = await api({ acao: 'chave', ligado: ligar ? 1 : 0 });
      toast(r.ok ? (r.ligado ? 'Follow-up ligado.' : 'Follow-up desligado.') : (r.error || 'Não foi possível mudar.'), r.ok ? 'success' : 'error');
      carregar();
    });

    document.querySelectorAll('.fu-abas button').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.fu-abas button').forEach(function (x) { x.classList.toggle('ativa', x === b); });
        document.querySelectorAll('.fu-painel').forEach(function (p) { p.hidden = p.dataset.painel !== b.dataset.aba; });
      });
    });

    carregar();
    setInterval(function () { if (document.visibilityState === 'visible' && $('fuModal').hidden) carregar(); }, 60000);
  })();
  </script>
</body>
</html>
