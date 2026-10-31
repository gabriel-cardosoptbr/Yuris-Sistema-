<?php
/**
 * fila.php: Operação › Fila (App\WhatsAppAgente\FilaDaProspeccao).
 *
 * O painel único da prospecção para o admin: a ORDEM de quem vai ser abordado (aberturas
 * do robô da planilha e do disparo das listas) e de quem vai receber follow-up, com o dia
 * e uma FAIXA aproximada de horário (as esperas são sorteadas, então nunca há minuto
 * exato), como a fila está (faltam, saíram hoje, ritmo, término) e quem deu erro: follow-ups
 * reprogramados, os que não serão refeitos e as aberturas que os fluxos pularam.
 *
 * QUEM VÊ: só owner/admin, e só na conta que os fluxos atendem (a dona do token da
 * prospecção). Os dados vêm de /api/whatsapp/fila.php (aberturas, do retrato que o n8n
 * manda) e de /api/followup.php (follow-ups e seus erros).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\WhatsAppAgente\SdrFleetiflow;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if (!$ctx->isOwnerOrAdmin() || !SdrFleetiflow::contaDoTokenGlobal((int) $ctx->getAccountId())) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403</title>';
    echo '<style>body{font-family:system-ui;background:#0a0f1e;color:#cbd5e1;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>';
    echo '<div><h1 style="margin:0 0 8px">Acesso negado</h1><p>A fila da prospecção é vista pela administração da conta.</p></div>';
    exit;
}
$isFleetiflow = $ctx->getProduto() === 'fleetiflow';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$activePage = 'fila';
function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $isFleetiflow ? 'Fila · ' . e($ctx->getMarca()['nome']) . ' CRM' : 'Fila · Yuris' ?></title>
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
      --o-ruim:#F87171; --o-ruim-bg:rgba(248,113,113,.14); --o-raio:14px;
      --o-fonte:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    html[data-theme="light"]{
      --o-fundo:#F4F6FA; --o-card:#FFFFFF; --o-borda:rgba(15,31,54,.10); --o-sutil:rgba(15,31,54,.06);
      --o-texto:#1E293B; --o-forte:#0F1F36; --o-fraco:#5A6B7E; --o-apagado:#8B97A8;
      --o-acento:#2563EB; --o-acento-suave:#DBEAFE; --o-trilho:#EEF1F5;
      --o-bom:#047857; --o-bom-bg:#DCFCE7; --o-atencao:#92400E; --o-atencao-bg:#FEF3C7;
      --o-ruim:#B91C1C; --o-ruim-bg:#FEE2E2;
    }
    body{ margin:0; background:var(--o-fundo); color:var(--o-texto); font-family:var(--o-fonte); }
    .o-wrap{ padding:22px 26px 60px; }
    .fi-topo h1{ margin:0; font-size:22px; font-weight:800; color:var(--o-forte); letter-spacing:-.01em; }
    .fi-topo p{ margin:4px 0 16px; font-size:13px; color:var(--o-fraco); max-width:860px; line-height:1.5; }
    .o-card{ background:var(--o-card); border:1px solid var(--o-borda); border-radius:var(--o-raio); padding:16px 18px; min-width:0; }
    .o-card h2{ margin:0; font-size:15px; font-weight:800; color:var(--o-forte); }
    .fraco{ color:var(--o-fraco); font-size:12px; font-weight:500; }

    /* Resumo no topo */
    .fi-resumo{ display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr)); gap:12px; margin-bottom:16px; }
    .fi-res .rot{ font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--o-apagado); }
    .fi-res .num{ font-size:24px; font-weight:800; color:var(--o-forte); margin:4px 0 2px; font-variant-numeric:tabular-nums; }
    .fi-res .num small{ font-size:13px; font-weight:600; color:var(--o-fraco); }
    .fi-res.alerta{ border-color:var(--o-atencao); }
    .fi-res.ruim{ border-color:var(--o-ruim); }

    .fi-abas{ display:flex; gap:4px; padding:4px; border-radius:999px; background:var(--o-sutil); width:max-content; max-width:100%; overflow-x:auto; margin-bottom:16px; }
    .fi-abas button{ height:34px; padding:0 16px; border:0; border-radius:999px; background:transparent; color:var(--o-fraco); font:inherit; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; display:inline-flex; align-items:center; gap:8px; }
    .fi-abas button.ativa{ background:var(--o-card); color:var(--o-acento); box-shadow:0 1px 3px rgba(15,31,54,.12); }
    .fi-abas .cont{ min-width:20px; height:20px; padding:0 6px; border-radius:999px; background:var(--o-acento-suave); color:var(--o-acento); font-size:11px; display:inline-grid; place-items:center; }
    .fi-abas .cont.ruim{ background:var(--o-ruim-bg); color:var(--o-ruim); }
    .fi-painel[hidden]{ display:none; }
    .fi-grade{ display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,520px),1fr)); gap:16px; align-items:start; }

    .fi-chips{ display:flex; flex-wrap:wrap; gap:6px; margin:10px 0 12px; }
    .fi-chip{ display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; background:var(--o-sutil); color:var(--o-texto); font-size:12px; font-weight:600; }
    .fi-chip b{ color:var(--o-forte); }
    .fi-aviso{ margin:0 0 12px; padding:10px 14px; border-radius:12px; font-size:12.5px; background:var(--o-atencao-bg); color:var(--o-atencao); line-height:1.5; }
    .fi-aviso[hidden]{ display:none; }

    .fi-rolagem{ overflow-x:auto; }
    .fi-tabela{ width:100%; border-collapse:collapse; font-size:13px; }
    .fi-tabela th{ text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--o-apagado); padding:8px 10px; border-bottom:1px solid var(--o-borda); white-space:nowrap; }
    .fi-tabela td{ padding:10px; border-bottom:1px solid var(--o-sutil); vertical-align:top; }
    .fi-tabela td.n{ white-space:nowrap; font-weight:800; color:var(--o-forte); font-variant-numeric:tabular-nums; }
    .fi-tabela td.lead a{ color:var(--o-forte); font-weight:700; text-decoration:none; }
    .fi-tabela td.lead a:hover{ color:var(--o-acento); }
    .fi-quando{ font-weight:700; color:var(--o-forte); white-space:nowrap; }
    .fi-selo{ display:inline-flex; align-items:center; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
    .fi-selo.bom{ background:var(--o-bom-bg); color:var(--o-bom); }
    .fi-selo.ruim{ background:var(--o-ruim-bg); color:var(--o-ruim); }
    .fi-selo.atencao{ background:var(--o-atencao-bg); color:var(--o-atencao); }
    .fi-selo.neutro{ background:var(--o-acento-suave); color:var(--o-acento); }
    .fi-vazio{ padding:26px 12px; text-align:center; color:var(--o-fraco); font-size:13px; line-height:1.6; }
    .fi-sec{ margin:0 0 18px; }
    .fi-sec > h2{ margin:0 0 8px; }
    @media (max-width:700px){ .o-wrap{ padding:16px 14px 50px; } }
  </style>
  <?php if ($isFleetiflow): ?>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html[data-theme="light"]{
      --o-fundo:#F6F7F9; --o-borda:rgba(17,29,45,.08); --o-sutil:rgba(17,29,45,.05);
      --o-texto:#575757; --o-forte:#3D3D3D; --o-fraco:#676767; --o-apagado:#767676;
      --o-acento:var(--ff-marca, #015DFC); --o-acento-suave:var(--ff-marca-suave, #D6E4FF); --o-trilho:#E9EAEC;
      --o-bom:#017801; --o-bom-bg:#DCFFDC; --o-atencao:#8F6000; --o-atencao-bg:#FFF6E6;
      --o-ruim:#B00000; --o-ruim-bg:#FFCCCC;
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

    <div class="fi-topo">
      <h1>Fila da prospecção</h1>
      <p>Quem vai ser abordado e quem vai receber follow-up, na ordem em que o sistema anda, com o dia e uma faixa de horário. Tudo aproximado de propósito: a espera entre um envio e outro é sorteada, então ninguém tem horário exato. Quem deu erro aparece na aba Erros.</p>
    </div>

    <div class="fi-resumo" id="fiResumo"></div>

    <div class="fi-abas" role="tablist">
      <button type="button" class="ativa" data-aba="aberturas" role="tab">Aberturas <span class="cont" id="fiContAb">0</span></button>
      <button type="button" data-aba="followups" role="tab">Follow-ups <span class="cont" id="fiContFu">0</span></button>
      <button type="button" data-aba="erros" role="tab">Erros <span class="cont" id="fiContEr">0</span></button>
    </div>

    <section class="fi-painel" data-painel="aberturas"><div class="fi-grade" id="fiAberturas"><div class="o-card fi-vazio">Carregando…</div></div></section>
    <section class="fi-painel" data-painel="followups" hidden><div class="o-card"><div class="fi-rolagem" id="fiFollowups"></div></div></section>
    <section class="fi-painel" data-painel="erros" hidden><div id="fiErros"></div></section>

      </div>
    </div>
  </main>

  <script>
  (function () {
    'use strict';
    var A = null;   // /api/whatsapp/fila.php
    var F = null;   // /api/followup.php
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

    var STATUS = {
      sem_whatsapp: 'Sem WhatsApp', numero_repetido: 'Número repetido', fora_do_perfil: 'Fora do perfil',
      ja_tinha_conversa: 'Já tinha conversa', enviando: 'Preso em "enviando"'
    };

    function hoje0() { var d = new Date(); d.setHours(0, 0, 0, 0); return d; }
    function diaTxt(d, curto) {
      var dia = new Date(d); dia.setHours(0, 0, 0, 0);
      var diff = Math.round((dia - hoje0()) / 86400000);
      if (diff === 0) return 'hoje';
      if (diff === 1) return 'amanhã';
      if (diff === -1) return 'ontem';
      return d.toLocaleDateString('pt-BR', { weekday: curto ? 'short' : 'long', day: '2-digit', month: '2-digit' }).replace('.', '');
    }
    function hh(d) { return d.getHours() + 'h'; }
    // "hoje, 10h às 11h": a faixa da hora em que o envio deve cair (nunca minuto exato).
    function faixa(deIso, ateIso) {
      var a = new Date(deIso), b = new Date(ateIso);
      return diaTxt(a) + ', ' + hh(a) + ' às ' + hh(b);
    }
    // Quando o dado tem faixa de minutos (follow-up): "hoje 09:51 a 10:03".
    function hora(iso) { return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }
    function faixaFu(p) {
      var de = p.sai_de || p.previsto, ate = p.sai_ate || de;
      var a = new Date(de);
      var h = hora(de) === hora(ate) ? hora(de) : hora(de) + ' a ' + hora(ate);
      return diaTxt(a) + ' ' + h;
    }
    function quandoCurto(iso) { var d = new Date(iso); return diaTxt(d, true) + ' ' + hora(iso); }
    function atrasoTxt(iso) {
      var m = Math.round((Date.now() - new Date(iso)) / 60000);
      return m < 1 ? 'agora há pouco' : m < 60 ? 'há ' + m + ' min' : 'há ' + Math.floor(m / 60) + ' h';
    }
    function rotuloNome(p) { return p.nome ? p.nome : (p.fone ? '+' + p.fone : 'Lead ' + p.id); }

    async function pegar(url) {
      try { var r = await fetch(url, { credentials: 'same-origin' }); return await r.json(); }
      catch (e) { return { ok: false, error: 'Não foi possível carregar.' }; }
    }

    function falhasAtivas() { return ((F && F.proximos) || []).filter(function (p) { return p.falha; }); }
    function problemasAb() {
      var out = [];
      ((A && A.aberturas && A.aberturas.fluxos) || []).forEach(function (f) { (f.problemas || []).forEach(function (p) { out.push(Object.assign({ fluxo: f.nome }, p)); }); });
      return out;
    }

    function desenharResumo() {
      var fl = (A && A.aberturas && A.aberturas.fluxos) || [];
      var faltam = 0, saiHoje = 0;
      fl.forEach(function (f) { if (!f.sem_dados) { faltam += f.restantes; saiHoje += f.enviados_hoje; } });
      var seg = ((F && F.historico) || []).filter(function (h) { return h.status === 'enviado' && diaTxt(new Date(h.quando)) === 'hoje'; }).length;
      var fu = (F && F.proximos) || [];
      var rep = falhasAtivas().filter(function (p) { return !p.falha.esgotada; }).length;
      var def = falhasAtivas().filter(function (p) { return p.falha.esgotada; }).length;
      var vez = A && A.aberturas && A.aberturas.da_vez;
      var prox = null;
      fl.forEach(function (f) { if (!f.sem_dados && f.proximos && f.proximos.length) { var c = f.proximos[0]; if (!prox || c.de < prox.de) prox = Object.assign({ fluxo: f.nome }, c); } });
      var erros = rep + def;
      $('fiResumo').innerHTML =
        '<div class="o-card fi-res"><div class="rot">Próxima abertura</div><div class="num" style="font-size:18px">' + (prox ? esc(rotuloNome(prox)) : '—') + '</div>' +
          '<div class="fraco">' + (prox ? esc(faixa(prox.de_iso, prox.ate_iso)) : 'sem fila agora') + (vez ? ' · vendedor da vez: <b>' + esc(vez.atendente) + '</b>' : '') + '</div></div>' +
        '<div class="o-card fi-res"><div class="rot">Saíram hoje</div><div class="num">' + (saiHoje + seg) + '</div><div class="fraco">' + saiHoje + ' aberturas · ' + seg + ' follow-ups</div></div>' +
        '<div class="o-card fi-res"><div class="rot">Na fila</div><div class="num">' + (faltam + fu.length) + '</div><div class="fraco">' + faltam + ' aberturas · ' + fu.length + ' follow-ups</div></div>' +
        '<div class="o-card fi-res' + (erros ? ' alerta' : '') + '"><div class="rot">Erros</div><div class="num">' + erros + '</div><div class="fraco">' + rep + ' reprogramados · ' + def + ' sem nova tentativa · ' + problemasAb().length + ' aberturas puladas</div></div>';
      $('fiContAb').textContent = faltam;
      $('fiContFu').textContent = fu.length;
      var ce = $('fiContEr'); ce.textContent = erros + problemasAb().length; ce.className = 'cont' + (erros ? ' ruim' : '');
    }

    function cartaoFluxo(f) {
      if (f.sem_dados) {
        return '<div class="o-card"><h2>' + esc(f.nome) + '</h2><div class="fi-vazio">Este fluxo ainda não mandou o retrato da fila. Assim que mandar, a ordem e as faixas aparecem aqui.</div></div>';
      }
      var janela = f.janela || {};
      var dias = (janela.dias || []).length === 6 && (janela.dias || []).indexOf(7) < 0 ? 'seg a sáb' : (janela.dias || []).join(', ');
      var chips = '<span class="fi-chip">Faltam <b>' + f.restantes + '</b></span>' +
        '<span class="fi-chip">Saíram hoje <b>' + f.enviados_hoje + '</b></span>' +
        '<span class="fi-chip">Ritmo <b>~' + f.ritmo_dia + '/dia</b></span>' +
        '<span class="fi-chip">Término <b>' + (f.restantes === 0 ? 'fila vazia' : f.termino ? '≈ ' + diaTxt(new Date(f.termino + 'T12:00:00'), true) : '—') + '</b></span>';
      Object.keys(f.por_grupo || {}).forEach(function (g) { chips += '<span class="fi-chip">' + esc(g) + ' <b>' + f.por_grupo[g] + '</b></span>'; });
      var linhas = (f.proximos || []).map(function (p, i) {
        return '<tr><td class="n">' + (i + 1) + 'º</td><td class="lead">' + esc(rotuloNome(p)) + (p.nome && p.fone ? '<div class="fraco">+' + esc(p.fone) + '</div>' : '') + '</td>' +
          '<td>' + esc(p.grupo) + '</td><td><div class="fi-quando">' + esc(faixa(p.de_iso, p.ate_iso)) + '</div></td></tr>';
      }).join('');
      return '<div class="o-card"><h2>' + esc(f.nome) + '</h2>' +
        '<div class="fraco" style="margin-top:3px">' + esc(dias) + ', ' + esc(janela.de) + ' às ' + esc(janela.ate) + ' · espera de ' + janela.espera_min + ' a ' + janela.espera_max + ' min entre um e outro</div>' +
        '<div class="fi-chips">' + chips + '</div>' +
        '<p class="fi-aviso"' + (f.desatualizado ? '' : ' hidden') + '>O retrato desta fila foi atualizado ' + esc(atrasoTxt(f.gerado_em)) + ': a ordem pode ter andado.</p>' +
        (linhas ? '<div class="fi-rolagem"><table class="fi-tabela"><thead><tr><th>Ordem</th><th>Lead</th><th>Lista</th><th>Sai</th></tr></thead><tbody>' + linhas + '</tbody></table></div>' +
          (f.restantes > (f.proximos || []).length ? '<div class="fraco" style="margin-top:8px">Mostrando os ' + f.proximos.length + ' primeiros de ' + f.restantes + '.</div>' : '')
          : '<div class="fi-vazio">Fila vazia: ninguém esperando abordagem neste fluxo.</div>') + '</div>';
    }

    function desenharAberturas() {
      if (!A || !A.ok) { $('fiAberturas').innerHTML = '<div class="o-card fi-vazio">' + esc((A && A.error) || 'Não foi possível carregar.') + '</div>'; return; }
      $('fiAberturas').innerHTML = (A.aberturas.fluxos || []).map(cartaoFluxo).join('');
    }

    function desenharFollowups() {
      var fu = (F && F.proximos) || [];
      if (!F || !F.ok) { $('fiFollowups').innerHTML = '<div class="fi-vazio">' + esc((F && F.error) || 'Não foi possível carregar.') + '</div>'; return; }
      if (!fu.length) { $('fiFollowups').innerHTML = '<div class="fi-vazio"><b>Nada na fila de follow-up</b><br>Quando um lead ficar em silêncio numa etapa da cadência, ele aparece aqui.</div>'; return; }
      $('fiFollowups').innerHTML = '<table class="fi-tabela"><thead><tr><th>Ordem</th><th>Lead</th><th>Cadência</th><th>Mensagem</th><th>Sai</th></tr></thead><tbody>' +
        fu.map(function (p) {
          var f = p.falha, selo = '';
          if (f) selo = f.esgotada ? '<div><span class="fi-selo ruim">Sem nova tentativa</span></div>' : '<div><span class="fi-selo atencao">Reprogramado</span></div>';
          return '<tr><td class="n">' + (p.ordem ? p.ordem + 'º' : '—') + '</td>' +
            '<td class="lead"><a href="/prospeccao?open=' + p.card_id + '">' + esc(p.lead) + '</a><div class="fraco">' + esc(p.etapa) + '</div></td>' +
            '<td>' + esc(p.cadencia) + '</td><td>' + p.passo + ' de ' + p.total + '</td>' +
            '<td>' + (f && f.esgotada ? '<div class="fi-quando">—</div>' : '<div class="fi-quando">' + esc(faixaFu(p)) + '</div>') + selo + '</td></tr>';
        }).join('') + '</tbody></table>';
    }

    function tabelaFollowupErros(lista, definitivo) {
      if (!lista.length) return '<div class="fi-vazio">Nenhum.</div>';
      return '<div class="fi-rolagem"><table class="fi-tabela"><thead><tr><th>Lead</th><th>Mensagem</th><th>Motivo</th><th>Falhou em</th><th>Tentativas</th><th>' + (definitivo ? 'Situação' : 'Nova tentativa') + '</th></tr></thead><tbody>' +
        lista.map(function (p) {
          var f = p.falha;
          return '<tr><td class="lead"><a href="/prospeccao?open=' + p.card_id + '">' + esc(p.lead) + '</a><div class="fraco">' + esc(p.cadencia) + '</div></td>' +
            '<td>' + p.passo + ' de ' + p.total + '</td><td>' + esc(f.erro) + '</td><td class="fi-quando">' + esc(quandoCurto(f.falhou_em)) + '</td>' +
            '<td>' + f.tentativas + ' de ' + (f.teto || 3) + '</td>' +
            '<td>' + (definitivo ? '<span class="fi-selo ruim">Não será refeito</span>'
              : '<span class="fi-selo atencao">Reprogramado</span><div class="fi-quando" style="margin-top:4px">' + esc(faixaFu(p)) + '</div>') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }

    function desenharErros() {
      var ativas = falhasAtivas();
      var rep = ativas.filter(function (p) { return !p.falha.esgotada; });
      var def = ativas.filter(function (p) { return p.falha.esgotada; });
      var pul = problemasAb();
      var porStatus = {};
      pul.forEach(function (p) { porStatus[p.status] = (porStatus[p.status] || 0) + 1; });
      var chips = Object.keys(porStatus).map(function (s) { return '<span class="fi-chip">' + esc(STATUS[s] || s) + ' <b>' + porStatus[s] + '</b></span>'; }).join('');
      $('fiErros').innerHTML =
        '<div class="o-card fi-sec"><h2>Follow-ups reprogramados <span class="fi-selo atencao">' + rep.length + '</span></h2>' +
          '<div class="fraco" style="margin:3px 0 8px">O WhatsApp não aceitou a mensagem. Ela fica guardada e é refeita no dia seguinte, no horário da cadência, até 3 vezes.</div>' + tabelaFollowupErros(rep, false) + '</div>' +
        '<div class="o-card fi-sec"><h2>Follow-ups que não serão refeitos <span class="fi-selo ruim">' + def.length + '</span></h2>' +
          '<div class="fraco" style="margin:3px 0 8px">Esgotaram as 3 tentativas. Vale olhar o lead e mandar à mão, se ainda fizer sentido.</div>' + tabelaFollowupErros(def, true) + '</div>' +
        '<div class="o-card fi-sec"><h2>Aberturas que os fluxos pularam ou deixaram presas <span class="fi-selo neutro">' + pul.length + '</span></h2>' +
          '<div class="fraco" style="margin:3px 0 0">Sem WhatsApp, número repetido, fora do perfil, conversa que já existia e reservas presas em "enviando".</div>' +
          '<div class="fi-chips">' + (chips || '<span class="fraco">Nenhuma.</span>') + '</div>' +
          (pul.length ? '<div class="fi-rolagem"><table class="fi-tabela"><thead><tr><th>Lead</th><th>Fluxo</th><th>O que houve</th><th>Quando</th></tr></thead><tbody>' +
            pul.map(function (p) { return '<tr><td class="lead">' + esc(rotuloNome(p)) + '</td><td>' + esc(p.fluxo) + '</td><td><span class="fi-selo neutro">' + esc(STATUS[p.status] || p.status) + '</span>' + (p.detalhe ? '<div class="fraco">' + esc(p.detalhe) + '</div>' : '') + '</td><td class="fi-quando">' + esc(p.quando || '') + '</td></tr>'; }).join('') +
            '</tbody></table></div>' : '') + '</div>';
    }

    async function carregar() {
      var r = await Promise.all([pegar('/api/whatsapp/fila.php'), pegar('/api/followup.php')]);
      A = r[0]; F = r[1];
      if (A && A.ok && A.disponivel === false) { $('fiAberturas').innerHTML = '<div class="o-card fi-vazio">A fila não está disponível para esta conta.</div>'; return; }
      desenharResumo(); desenharAberturas(); desenharFollowups(); desenharErros();
    }

    document.querySelectorAll('.fi-abas button').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.fi-abas button').forEach(function (x) { x.classList.toggle('ativa', x === b); });
        document.querySelectorAll('.fi-painel').forEach(function (p) { p.hidden = p.dataset.painel !== b.dataset.aba; });
      });
    });

    carregar();
    setInterval(function () { if (document.visibilityState === 'visible') carregar(); }, 60000);
  })();
  </script>
</body>
</html>
