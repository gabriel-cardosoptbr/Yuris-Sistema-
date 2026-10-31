<?php
/**
 * meu-nome.php: Operação › Meu nome (App\WhatsAppAgente\VendedorDoLead).
 *
 * Cada vendedor escolhe COMO ASSINA as mensagens do WhatsApp: a abertura do disparo, os
 * follow-ups, a apresentação da IA e os rótulos da equipe ("Souza (IA)"). Vazio, vale o
 * primeiro nome do cadastro. A mudança vale para as próximas mensagens; o que já saiu não muda.
 *
 * QUEM VÊ: qualquer usuário da conta que os fluxos do n8n atendem (a dona do token da
 * prospecção). A administração também muda o nome de cada pessoa na tela Roleta.
 * A API (/api/whatsapp/meu_nome.php) confere de novo.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\WhatsAppAgente\SdrFleetiflow;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if (!SdrFleetiflow::contaDoTokenGlobal((int) $ctx->getAccountId())) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>404</title>';
    echo '<style>body{font-family:system-ui;background:#0a0f1e;color:#cbd5e1;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>';
    echo '<div><h1 style="margin:0 0 8px">Página não encontrada</h1><p>Este recurso não está disponível para esta conta.</p></div>';
    exit;
}
$isFleetiflow = $ctx->getProduto() === 'fleetiflow';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$activePage = 'meu-nome';
function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $isFleetiflow ? 'Meu nome · ' . e($ctx->getMarca()['nome']) . ' CRM' : 'Meu nome · Yuris' ?></title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=20">
  <style>
    :root{
      --o-fundo:#070F1C; --o-card:#0D1C30; --o-borda:rgba(160,180,210,.14); --o-sutil:rgba(160,180,210,.08);
      --o-texto:#E2E8F0; --o-forte:#F1F5F9; --o-fraco:#94A3B8;
      --o-acento:#3B82F6; --o-input:#0A1626; --o-raio:14px;
      --o-fonte:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    html[data-theme="light"]{
      --o-fundo:#F4F6FA; --o-card:#FFFFFF; --o-borda:rgba(15,31,54,.10); --o-sutil:rgba(15,31,54,.06);
      --o-texto:#1E293B; --o-forte:#0F1F36; --o-fraco:#5A6B7E; --o-acento:#2563EB; --o-input:#FFFFFF;
    }
    body{ margin:0; background:var(--o-fundo); color:var(--o-texto); font-family:var(--o-fonte); }
    .o-wrap{ padding:22px 26px 60px; }
    .mn-topo h1{ margin:0; font-size:22px; font-weight:800; color:var(--o-forte); letter-spacing:-.01em; }
    .mn-topo p{ margin:4px 0 18px; font-size:13px; color:var(--o-fraco); max-width:720px; line-height:1.5; }
    .o-card{ background:var(--o-card); border:1px solid var(--o-borda); border-radius:var(--o-raio); padding:18px 20px; max-width:560px; }
    .o-card label{ display:block; font-size:12px; font-weight:700; color:var(--o-fraco); margin-bottom:6px; }
    .mn-linha{ display:flex; gap:8px; flex-wrap:wrap; }
    .mn-linha input{ flex:1 1 220px; min-width:0; min-height:40px; padding:6px 12px; border-radius:10px; border:1px solid var(--o-borda); background:var(--o-input); color:var(--o-texto); font:inherit; font-size:14px; box-sizing:border-box; }
    .o-btn{ display:inline-flex; align-items:center; height:40px; padding:0 18px; border-radius:999px; border:1px solid var(--o-acento); background:var(--o-acento); color:#fff; font:inherit; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
    .o-btn[disabled]{ opacity:.5; cursor:not-allowed; }
    .mn-ajuda{ margin:10px 0 0; font-size:12px; color:var(--o-fraco); line-height:1.5; }
    .mn-previa{ margin:16px 0 0; padding-top:14px; border-top:1px solid var(--o-sutil); font-size:13px; color:var(--o-fraco); line-height:1.6; }
    .mn-previa b{ color:var(--o-forte); font-weight:600; }
    @media (max-width:700px){ .o-wrap{ padding:16px 14px 50px; } }
  </style>
  <?php if ($isFleetiflow): ?>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html[data-theme="light"]{
      --o-fundo:#F6F7F9; --o-borda:rgba(17,29,45,.08); --o-sutil:rgba(17,29,45,.05);
      --o-texto:#575757; --o-forte:#3D3D3D; --o-fraco:#676767;
      --o-acento:var(--ff-marca, #015DFC); --o-input:#F6F7F9;
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

    <div class="mn-topo">
      <h1>Meu nome nas mensagens</h1>
      <p>É assim que você aparece para o cliente: na primeira mensagem do disparo, nos follow-ups e quando a IA se apresenta em seu nome. Vale para as próximas mensagens; as que já saíram não mudam.</p>
    </div>

    <section class="o-card">
      <label for="mnNome">Como você quer ser chamado</label>
      <div class="mn-linha">
        <input type="text" id="mnNome" maxlength="40" autocomplete="off" disabled>
        <button type="button" class="o-btn" id="mnSalvar" disabled>Salvar</button>
      </div>
      <p class="mn-ajuda" id="mnAjuda">Pode ser o nome, o sobrenome ou os dois, só com letras. Deixe vazio para usar o primeiro nome do seu cadastro.</p>
      <p class="mn-previa" id="mnPrevia">Carregando…</p>
    </section>

      </div>
    </div>
  </main>

  <script>
  (function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    var D = null;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var toast = function (m, t) { if (window.Yuris && Yuris.toast) Yuris.toast(m, t || 'info'); };
    function primeiroNome(n) { return String(n || '').trim().split(/\s+/)[0] || ''; }

    function frases(nome) {
      return 'Abertura: <b>“Oi, tudo certo? 🙂 ' + esc(nome) + ', da Fleetiflow.”</b><br>'
        + 'IA: <b>“Meu nome é ' + esc(nome) + ', da Fleetiflow.”</b><br>'
        + 'Para a equipe: <b>' + esc(nome) + ' (IA)</b>';
    }
    function pintar() {
      $('mnNome').disabled = false;
      $('mnNome').value = D.nome_mensagens || '';
      $('mnNome').placeholder = primeiroNome(D.nome_cadastro);
      $('mnNome').dataset.salvo = D.nome_mensagens || '';
      $('mnSalvar').disabled = true;
      $('mnPrevia').innerHTML = frases(D.aparece_como);
      if (D.pronta === false) $('mnAjuda').textContent = 'Este recurso ainda não está ativo neste servidor.';
    }
    async function api(corpo) {
      var r = await fetch('/api/whatsapp/meu_nome.php', corpo ? {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ _csrf: CSRF }, corpo))
      } : { credentials: 'same-origin' });
      try { return await r.json(); } catch (e) { return { error: 'Resposta inválida do servidor.' }; }
    }

    $('mnNome').addEventListener('input', function () {
      var v = this.value.trim();
      $('mnPrevia').innerHTML = frases(v || primeiroNome(D.nome_cadastro));
      $('mnSalvar').disabled = (v === (this.dataset.salvo || '')) || D.pronta === false;
    });
    $('mnNome').addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && !$('mnSalvar').disabled) $('mnSalvar').click(); });
    $('mnSalvar').addEventListener('click', async function () {
      this.disabled = true;
      var j = await api({ nome_mensagens: $('mnNome').value });
      if (!j.ok) { toast(j.error || 'Não foi possível salvar.', 'error'); this.disabled = false; return; }
      D = j; pintar();
      toast('Nome salvo: as próximas mensagens saem com “' + D.aparece_como + '”.', 'success');
    });

    api().then(function (j) {
      if (!j.ok || !j.disponivel) { $('mnPrevia').textContent = j.error || 'Não foi possível carregar.'; return; }
      D = j; pintar();
    });
  })();
  </script>
</body>
</html>
