<?php
/**
 * roleta.php: Operação › Roleta (App\WhatsAppAgente\Roleta).
 *
 * Quem recebe os leads que a prospecção automática abre, e em que proporção
 * ("70% Cauê e 30% a Vitória"). A cada abertura o lead é sorteado e o card e a
 * conversa passam a ser do sorteado. Embaixo, quantos leads cada um já recebeu,
 * para conferir que a divisão está sendo seguida.
 *
 * QUEM VÊ: só owner/admin (o atendente não vê nem acessa), e só na conta que os
 * fluxos do n8n atendem. A API (/api/whatsapp/roleta.php) confere de novo.
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
    echo '<div><h1 style="margin:0 0 8px">Acesso negado</h1><p>A roleta dos leads é configurada pela administração da conta.</p></div>';
    exit;
}
$isFleetiflow = $ctx->getProduto() === 'fleetiflow';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$activePage = 'roleta';
function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $isFleetiflow ? 'Roleta · ' . e($ctx->getMarca()['nome']) . ' CRM' : 'Roleta · Yuris' ?></title>
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
      --o-fonte:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    html[data-theme="light"]{
      --o-fundo:#F4F6FA; --o-card:#FFFFFF; --o-borda:rgba(15,31,54,.10); --o-sutil:rgba(15,31,54,.06);
      --o-texto:#1E293B; --o-forte:#0F1F36; --o-fraco:#5A6B7E; --o-apagado:#8B97A8;
      --o-acento:#2563EB; --o-acento-suave:#DBEAFE; --o-trilho:#EEF1F5;
      --o-bom:#047857; --o-bom-bg:#DCFCE7; --o-atencao:#92400E; --o-atencao-bg:#FEF3C7;
      --o-ruim:#B91C1C; --o-ruim-bg:#FEE2E2; --o-input:#FFFFFF;
    }
    body{ margin:0; background:var(--o-fundo); color:var(--o-texto); font-family:var(--o-fonte); }
    .o-wrap{ padding:22px 26px 60px; }
    .ro-topo{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .ro-topo h1{ margin:0; font-size:22px; font-weight:800; color:var(--o-forte); letter-spacing:-.01em; }
    .ro-topo p{ margin:4px 0 0; font-size:13px; color:var(--o-fraco); max-width:720px; line-height:1.5; }
    .o-card{ background:var(--o-card); border:1px solid var(--o-borda); border-radius:var(--o-raio); padding:18px 20px; min-width:0; }
    .o-card h2{ margin:0 0 4px; font-size:15px; font-weight:800; color:var(--o-forte); }
    .o-card .sub{ margin:0 0 14px; font-size:12.5px; color:var(--o-fraco); line-height:1.5; }
    .o-btn{ display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:999px; border:1px solid var(--o-borda); background:var(--o-card); color:var(--o-texto); font:inherit; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
    .o-btn.primario{ background:var(--o-acento); border-color:var(--o-acento); color:#fff; }
    .o-btn.primario:hover{ filter:brightness(1.08); }
    .o-btn[disabled]{ opacity:.5; cursor:not-allowed; }

    .ro-chave{ display:inline-flex; align-items:center; gap:10px; cursor:pointer; user-select:none; font-size:13px; font-weight:700; color:var(--o-forte); padding:10px 16px; border-radius:999px; border:1px solid var(--o-borda); background:var(--o-card); }
    .ro-chave input{ position:absolute; opacity:0; pointer-events:none; }
    .ro-chave .trilho{ width:40px; height:22px; border-radius:999px; background:var(--o-trilho); position:relative; transition:background .2s; flex:none; }
    .ro-chave .trilho::after{ content:''; position:absolute; top:3px; left:3px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.25); transition:transform .2s; }
    .ro-chave input:checked + .trilho{ background:var(--o-bom); }
    .ro-chave input:checked + .trilho::after{ transform:translateX(18px); }

    .ro-aviso{ margin:0 0 16px; padding:12px 16px; border-radius:12px; font-size:13px; background:var(--o-atencao-bg); color:var(--o-atencao); line-height:1.5; }
    .ro-aviso[hidden]{ display:none; }
    .ro-grade{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:16px; align-items:start; }
    .ro-tabela td:first-child, .ro-tabela td.quem{ white-space:nowrap; }
    .ro-tabela td.lead{ max-width:150px; overflow:hidden; text-overflow:ellipsis; }
    @media (max-width:980px){ .ro-grade{ grid-template-columns:minmax(0,1fr); } }

    .ro-pessoas{ display:flex; flex-direction:column; gap:8px; }
    .ro-pessoa{ display:grid; grid-template-columns:minmax(0,1fr) 110px; gap:10px 12px; align-items:center; padding:10px 12px; border:1px solid var(--o-borda); border-radius:12px; }
    .ro-quem{ display:grid; grid-template-columns:auto minmax(0,1fr); gap:12px; align-items:center; cursor:pointer; min-width:0; }
    .ro-assina{ grid-column:1 / -1; display:grid; grid-template-columns:minmax(0,1fr) auto; gap:6px 8px; align-items:center; padding-top:8px; border-top:1px solid var(--o-sutil); }
    .ro-assina label{ grid-column:1 / -1; font-size:11.5px; font-weight:700; color:var(--o-fraco); }
    .ro-assina input{ min-width:0; min-height:34px; padding:5px 10px; border-radius:10px; border:1px solid var(--o-borda); background:var(--o-input); color:var(--o-texto); font:inherit; font-size:13px; box-sizing:border-box; }
    .ro-assina .o-btn{ height:34px; padding:0 12px; font-size:12px; }
    .ro-previa{ grid-column:1 / -1; margin:0; font-size:12px; color:var(--o-fraco); line-height:1.5; }
    .ro-previa b{ color:var(--o-forte); font-weight:600; }
    .ro-vez{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; border-color:var(--o-acento); }
    .ro-vez[hidden]{ display:none; }
    .ro-vez .rotulo{ font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--o-acento); }
    .ro-vez .quem{ font-size:18px; font-weight:800; color:var(--o-forte); display:flex; align-items:center; gap:8px; }
    .ro-vez .quem i{ width:12px; height:12px; border-radius:50%; display:inline-block; }
    .ro-vez .frase{ font-size:12.5px; color:var(--o-fraco); }
    .ro-pessoa.dentro{ border-color:var(--o-acento); background:var(--o-acento-suave); }
    .ro-pessoa input[type=checkbox]{ accent-color:var(--o-acento); width:17px; height:17px; margin:0; }
    .ro-pessoa .nome{ font-size:13.5px; font-weight:700; color:var(--o-forte); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ro-pessoa .papel{ font-size:11.5px; color:var(--o-fraco); font-weight:500; }
    .ro-pct{ display:flex; align-items:center; gap:6px; }
    .ro-pct input{ width:72px; min-height:36px; padding:6px 10px; border-radius:10px; border:1px solid var(--o-borda); background:var(--o-input); color:var(--o-texto); font:inherit; font-size:14px; font-weight:700; text-align:right; box-sizing:border-box; }
    .ro-pct input:disabled{ opacity:.35; }
    .ro-pct span{ font-size:13px; color:var(--o-fraco); font-weight:700; }
    .ro-soma{ display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px; padding-top:14px; border-top:1px solid var(--o-sutil); }
    .ro-soma .total{ font-size:13px; font-weight:700; }
    .ro-soma .total.bom{ color:var(--o-bom); }
    .ro-soma .total.ruim{ color:var(--o-ruim); }

    .ro-barra{ display:flex; height:12px; border-radius:999px; overflow:hidden; background:var(--o-trilho); margin:6px 0 14px; }
    .ro-barra i{ display:block; height:100%; }
    .ro-tabela{ width:100%; border-collapse:collapse; font-size:13px; }
    .ro-tabela th{ text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--o-apagado); padding:8px 10px; border-bottom:1px solid var(--o-borda); white-space:nowrap; }
    .ro-tabela td{ padding:10px; border-bottom:1px solid var(--o-sutil); vertical-align:middle; }
    .ro-tabela td.num{ text-align:right; font-variant-numeric:tabular-nums; font-weight:700; color:var(--o-forte); white-space:nowrap; }
    .ro-tabela th.num{ text-align:right; }
    .ro-ponto{ display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:8px; vertical-align:-1px; }
    .fraco{ color:var(--o-fraco); font-size:12px; font-weight:500; }
    .ro-rolagem{ overflow-x:auto; }
    .ro-vazio{ padding:22px 8px; text-align:center; color:var(--o-fraco); font-size:13px; }
    .ro-ultimos{ margin-top:16px; }
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

    <div class="ro-topo">
      <div>
        <h1>Roleta dos leads</h1>
        <p>Cada lead que a prospecção automática aborda é sorteado entre as pessoas abaixo, na proporção que você escolher. A primeira mensagem já sai assinada por quem foi sorteado, e o card e a conversa do WhatsApp já chegam com o nome dele. Lead que alguém já atende não muda de mão.</p>
      </div>
      <label class="ro-chave" title="Desligada, o lead segue a regra de antes (o dono do número)">
        <input type="checkbox" id="roLigada"><span class="trilho"></span><span id="roLigadaTxt">Roleta desligada</span>
      </label>
    </div>

    <p class="ro-aviso" id="roAvisoMigration" hidden>A tabela da roleta ainda não foi criada neste servidor (migration 170). Dá para salvar a divisão, mas o sorteio só começa depois dela.</p>
    <p class="ro-aviso" id="roAvisoDesligada" hidden>A roleta está desligada: os leads novos seguem a regra de antes. Ligue e salve para começar a dividir.</p>

    <section class="o-card ro-vez" id="roVez" hidden>
      <div><div class="rotulo">Vendedor da vez</div><div class="quem"><i id="roVezPonto"></i><span id="roVezNome"></span></div></div>
      <div class="frase">Recebe o próximo lead que o disparo abordar, e a mensagem já sai com o nome dele: <b id="roVezFrase"></b> Depois dela, o seguinte é sorteado.</div>
    </section>

    <div class="ro-grade">
      <section class="o-card">
        <h2>Quem participa</h2>
        <p class="sub">Marque quem recebe leads e o percentual de cada um. A soma tem de dar 100%.</p>
        <div class="ro-pessoas" id="roPessoas"><div class="ro-vazio">Carregando…</div></div>
        <div class="ro-soma">
          <span class="total" id="roTotal">Total: 0%</span>
          <button type="button" class="o-btn primario" id="roSalvar" disabled>Salvar roleta</button>
        </div>
      </section>

      <section class="o-card">
        <h2>Quantos leads cada um recebeu</h2>
        <p class="sub" id="roDesde">Desde que esta divisão foi salva.</p>
        <div class="ro-barra" id="roBarra"></div>
        <div class="ro-rolagem"><table class="ro-tabela">
          <thead><tr><th>Pessoa</th><th class="num">Meta</th><th class="num" title="Desde que esta divisão foi salva">Recebeu</th><th class="num" title="Desde que a roleta existe">Total</th></tr></thead>
          <tbody id="roContagem"></tbody>
        </table></div>
        <div class="ro-ultimos">
          <h2 style="font-size:13px">Últimos sorteios</h2>
          <div class="ro-rolagem"><table class="ro-tabela"><tbody id="roUltimos"></tbody></table></div>
        </div>
      </section>
    </div>

      </div>
    </div>
  </main>

  <script>
  (function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    var CORES = ['#015DFC', '#F59E0B', '#10B981', '#EF4444', '#8B5CF6', '#EC4899', '#14B8A6'];
    var D = null;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var toast = function (m, t) { if (window.Yuris && Yuris.toast) Yuris.toast(m, t || 'info'); };
    var PAPEL = { owner: 'Dono da conta', admin: 'Administrador', user: 'Atendente' };

    function dataBr(s) {
      if (!s) return '';
      var d = new Date(String(s).replace(' ', 'T') + 'Z');
      if (isNaN(d)) return s;
      return d.toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }
    function corDe(uid) {
      // Quem está na roleta pega as primeiras cores, na ordem da divisão; os demais, depois.
      var ids = (D.participantes || []).map(function (p) { return p.user_id; });
      (D.equipe || []).forEach(function (u) { if (ids.indexOf(u.id) < 0) ids.push(u.id); });
      var i = ids.indexOf(uid);
      return CORES[(i < 0 ? uid : i) % CORES.length];
    }

    async function api(corpo) {
      var r = await fetch('/api/whatsapp/roleta.php', corpo ? {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ _csrf: CSRF }, corpo))
      } : { credentials: 'same-origin' });
      var j = {};
      try { j = await r.json(); } catch (e) { j = { error: 'Resposta inválida do servidor.' }; }
      if (!r.ok && !j.error) j.error = 'Não foi possível concluir.';
      return j;
    }

    function primeiroNome(n) { return String(n || '').trim().split(/\s+/)[0] || ''; }

    function pintarPessoas() {
      var por = {};
      (D.participantes || []).forEach(function (p) { por[p.user_id] = p; });
      $('roPessoas').innerHTML = (D.equipe || []).map(function (u) {
        var p = por[u.id], dentro = !!p;
        return '<div class="ro-pessoa' + (dentro ? ' dentro' : '') + '" data-uid="' + u.id + '" data-primeiro="' + esc(primeiroNome(u.nome)) + '">'
          + '<label class="ro-quem"><input type="checkbox"' + (dentro ? ' checked' : '') + '>'
          + '<span style="min-width:0"><span class="nome">' + esc(u.nome) + '</span><br><span class="papel">' + esc(PAPEL[u.role] || u.role) + '</span></span></label>'
          + '<span class="ro-pct"><input type="number" min="1" max="100" step="1" inputmode="numeric" value="' + (dentro ? p.percentual : '') + '"' + (dentro ? '' : ' disabled') + ' aria-label="Percentual de ' + esc(u.nome) + '"><span>%</span></span>'
          + '<div class="ro-assina"><label for="roNome' + u.id + '">Nome nas mensagens</label>'
          + '<input type="text" id="roNome' + u.id + '" maxlength="40" autocomplete="off" value="' + esc(u.nome_mensagens || '') + '" placeholder="' + esc(primeiroNome(u.nome)) + '" data-salvo="' + esc(u.nome_mensagens || '') + '">'
          + '<button type="button" class="o-btn ro-salvar-nome" disabled>Salvar nome</button>'
          + '<p class="ro-previa">Na mensagem: <b>“Aqui é ' + esc(u.aparece_como || primeiroNome(u.nome)) + ', da Fleetiflow.”</b> ' + (u.nome_mensagens ? '(nome escolhido)' : '(primeiro nome do cadastro; deixe vazio para usar ele)') + '</p></div>'
          + '</div>';
      }).join('') || '<div class="ro-vazio">Nenhum usuário ativo na conta.</div>';
      somar();
    }

    // O vendedor da vez: quem recebe o próximo lead aberto pelo disparo (já sorteado).
    function pintarVez() {
      var v = D.da_vez;
      $('roVez').hidden = !D.ligada || !v;
      if (!v) return;
      $('roVezNome').textContent = v.atendente;
      $('roVezFrase').textContent = '“Aqui é ' + v.vendedor + ', da Fleetiflow.”';
      $('roVezPonto').style.background = corDe(v.user_id);
    }

    function lerParticipantes() {
      return Array.prototype.map.call(document.querySelectorAll('.ro-pessoa'), function (el) {
        var cb = el.querySelector('input[type=checkbox]');
        if (!cb.checked) return null;
        return { user_id: Number(el.dataset.uid), percentual: Number(el.querySelector('input[type=number]').value || 0) };
      }).filter(Boolean);
    }

    function somar() {
      var ps = lerParticipantes();
      var total = ps.reduce(function (a, p) { return a + (p.percentual || 0); }, 0);
      var inteiros = ps.every(function (p) { return Number.isInteger(p.percentual) && p.percentual >= 1 && p.percentual <= 100; });
      var ok = ps.length > 0 && total === 100 && inteiros;
      var t = $('roTotal');
      t.textContent = ps.length ? ('Total: ' + total + '%' + (ok ? '' : (total !== 100 ? ' (precisa dar 100%)' : ' (cada um de 1 a 100)'))) : 'Ninguém marcado';
      t.className = 'total ' + (ok ? 'bom' : 'ruim');
      $('roSalvar').disabled = !ok;
    }

    function pintarContagem() {
      var linhas = D.contagem || [];
      var somaAtual = linhas.reduce(function (a, l) { return a + l.atual; }, 0);
      $('roDesde').textContent = D.desde ? ('Nesta divisão: desde ' + dataBr(D.desde) + '. ' + somaAtual + ' lead' + (somaAtual === 1 ? '' : 's') + ' sorteado' + (somaAtual === 1 ? '' : 's') + '.') : 'A divisão ainda não foi salva.';
      $('roBarra').innerHTML = somaAtual ? linhas.filter(function (l) { return l.atual; }).map(function (l) {
        return '<i style="width:' + (l.atual / somaAtual * 100) + '%;background:' + corDe(l.user_id) + '" title="' + esc(l.nome) + '"></i>';
      }).join('') : '';
      $('roContagem').innerHTML = linhas.map(function (l) {
        var real = somaAtual ? Math.round(l.atual / somaAtual * 100) : 0;
        return '<tr><td><span class="ro-ponto" style="background:' + corDe(l.user_id) + '"></span>' + esc(l.nome) + '</td>'
          + '<td class="num">' + (l.percentual != null ? l.percentual + '%' : '<span class="fraco">fora</span>') + '</td>'
          + '<td class="num">' + l.atual + (somaAtual ? ' <span class="fraco">(' + real + '%)</span>' : '') + '</td>'
          + '<td class="num">' + l.total + '</td></tr>';
      }).join('') || '<tr><td colspan="4" class="ro-vazio">Ninguém na roleta ainda.</td></tr>';
      $('roUltimos').innerHTML = (D.ultimos || []).map(function (s) {
        // Sorteado antes do envio e o card ainda não nasceu: mostra o telefone.
        var lead = s.card_id
          ? '<a href="/prospeccao?open=' + Number(s.card_id) + '" style="color:inherit;font-weight:700;text-decoration:none">' + esc(s.titulo || ('Lead #' + s.card_id)) + '</a>'
          : '<span style="font-weight:700">' + esc(s.telefone || 'Lead') + '</span> <span class="fraco">enviando…</span>';
        return '<tr><td class="lead">' + lead + '</td>'
          + '<td class="quem"><span class="ro-ponto" style="background:' + corDe(Number(s.user_id)) + '"></span>' + esc(s.nome || '') + '</td>'
          + '<td class="num"><span class="fraco">' + esc(dataBr(s.created_at)) + '</span></td></tr>';
      }).join('') || '<tr><td class="ro-vazio">Nenhum lead sorteado ainda.</td></tr>';
    }

    function pintar() {
      $('roLigada').checked = !!D.ligada;
      $('roLigadaTxt').textContent = D.ligada ? 'Roleta ligada' : 'Roleta desligada';
      $('roAvisoMigration').hidden = D.pronta !== false;
      $('roAvisoDesligada').hidden = !!D.ligada || !(D.participantes || []).length;
      pintarPessoas();
      pintarContagem();
      pintarVez();
    }

    async function carregar() {
      var j = await api();
      if (!j.ok) { $('roPessoas').innerHTML = '<div class="ro-vazio">' + esc(j.error || 'Não foi possível carregar.') + '</div>'; return; }
      if (!j.disponivel) { $('roPessoas').innerHTML = '<div class="ro-vazio">A roleta não está disponível para esta conta.</div>'; return; }
      D = j; pintar();
    }

    async function salvar(ligada) {
      $('roSalvar').disabled = true;
      var j = await api({ ligada: ligada ? 1 : 0, participantes: lerParticipantes() });
      if (!j.ok) { toast(j.error || 'Não foi possível salvar.', 'error'); somar(); $('roLigada').checked = !!(D && D.ligada); return; }
      D = j; pintar();
      toast(D.ligada ? 'Roleta salva e ligada.' : 'Roleta salva (desligada).', 'success');
    }

    $('roPessoas').addEventListener('change', function (ev) {
      var el = ev.target.closest('.ro-pessoa'); if (!el) return;
      if (ev.target.type === 'checkbox') {
        var n = el.querySelector('input[type=number]');
        n.disabled = !ev.target.checked;
        el.classList.toggle('dentro', ev.target.checked);
        if (ev.target.checked && !n.value) n.focus();
      }
      somar();
    });
    $('roPessoas').addEventListener('input', function (ev) {
      var el = ev.target.closest('.ro-pessoa'); if (!el) return;
      if (ev.target.type === 'text') {
        // O nome muda a prévia na hora; só vale depois de "Salvar nome".
        var u = (D.equipe || []).filter(function (x) { return x.id === Number(el.dataset.uid); })[0] || {};
        var v = ev.target.value.trim();
        el.querySelector('.ro-previa b').textContent = '“Aqui é ' + (v || primeiroNome(u.nome)) + ', da Fleetiflow.”';
        el.querySelector('.ro-salvar-nome').disabled = (v === (ev.target.dataset.salvo || ''));
        return;
      }
      somar();
    });
    $('roPessoas').addEventListener('click', async function (ev) {
      var bt = ev.target.closest('.ro-salvar-nome'); if (!bt) return;
      var el = bt.closest('.ro-pessoa'), campo = el.querySelector('input[type=text]');
      bt.disabled = true;
      var r = await fetch('/api/whatsapp/meu_nome.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: CSRF, user_id: Number(el.dataset.uid), nome_mensagens: campo.value })
      });
      var j = {};
      try { j = await r.json(); } catch (e) { j = { error: 'Resposta inválida do servidor.' }; }
      if (!j.ok) { toast(j.error || 'Não foi possível salvar o nome.', 'error'); bt.disabled = false; return; }
      var u = (D.equipe || []).filter(function (x) { return x.id === j.user_id; })[0];
      if (u) { u.nome_mensagens = j.nome_mensagens; u.aparece_como = j.aparece_como; }
      campo.value = j.nome_mensagens; campo.dataset.salvo = j.nome_mensagens;
      el.querySelector('.ro-previa').innerHTML = 'Na mensagem: <b>“Aqui é ' + esc(j.aparece_como) + ', da Fleetiflow.”</b> ' + (j.nome_mensagens ? '(nome escolhido)' : '(primeiro nome do cadastro; deixe vazio para usar ele)');
      toast('Nome salvo: as próximas mensagens saem com “' + j.aparece_como + '”.', 'success');
      var e = await api(); if (e.ok && e.disponivel) { D.da_vez = e.da_vez; pintarVez(); }
    });
    $('roSalvar').addEventListener('click', function () { salvar($('roLigada').checked); });
    $('roLigada').addEventListener('change', function () {
      if (!lerParticipantes().length || $('roSalvar').disabled) {
        $('roLigada').checked = !!(D && D.ligada);
        toast('Monte a divisão (soma de 100%) antes de ligar.', 'error');
        return;
      }
      salvar($('roLigada').checked);
    });

    carregar();
    setInterval(function () { if (document.visibilityState === 'visible' && D) api().then(function (j) { if (j.ok && j.disponivel) { D.contagem = j.contagem; D.ultimos = j.ultimos; D.desde = j.desde; D.da_vez = j.da_vez; pintarContagem(); pintarVez(); } }); }, 60000);
  })();
  </script>
</body>
</html>
