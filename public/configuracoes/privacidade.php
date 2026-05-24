<?php
/**
 * /configuracoes/privacidade.php — Centro de Privacidade do user logado (LGPD).
 *
 * Permite ao titular:
 *   • Ver consentimentos ativos e revogados (cookies, marketing, IA, etc)
 *   • Revogar consentimentos a qualquer momento (Art. 18 IX LGPD)
 *   • Acessar links para Política de Privacidade, Termos, DPO
 *
 * Para exportação de dados e solicitação de exclusão (Art. 18 II/VI),
 * encaminhamos ao DPO via /lgpd/solicitar.php.
 *
 * Padrão visual: idêntico às demais páginas do app (page-layout + main-content
 * + page-header, ver configuracoes.php / dashboard.php).
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Models/Consent.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;
use App\Models\Consent;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /sistema_vendas/public/login.php'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();
$csrf      = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

$consentimentos = Consent::listAll($userId, null);
$activePage     = 'privacidade';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Pagina em subpasta /configuracoes/. <base> garante que os links relativos
       da sidebar (dashboard.php, etc) resolvam a partir de /sistema_vendas/public/. -->
  <base href="/sistema_vendas/public/">
  <title>Privacidade — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=27">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=8">
  <style>
    :root {
      --bg-main: #070F1C;
      --line: rgba(160,180,210,0.08);
      --line-strong: rgba(160,180,210,0.14);
      --text: #D8E4F0;
      --muted: #7A8898;
      --primary: #244E7A;
      --radius: 14px;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background-color: #070F1C;
      background-image:
        radial-gradient(ellipse 120% 80% at 15% 40%, rgba(20,50,90,0.18) 0%, transparent 55%),
        radial-gradient(ellipse 80% 60% at 85% 20%, rgba(30,60,100,0.12) 0%, transparent 50%);
      background-attachment: fixed;
      color: var(--text);
      font-family: 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }

    /* ── Panel ── (padrão das demais páginas) */
    .cfg-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }

    /* ── Linhas de consentimento ── */
    .cons-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 12px 0; gap: 14px;
      border-bottom: 1px solid var(--line);
    }
    .cons-row:last-child { border-bottom: none; }
    .cons-fin {
      font-weight: 600; color: #D8E4F0; font-size: .95rem;
    }
    .cons-meta {
      font-size: .78rem; color: var(--muted); margin-top: 3px;
    }

    /* ── Pills ── */
    .pill {
      display: inline-block; padding: 2px 10px;
      border-radius: 999px; font-size: .68rem; font-weight: 700;
      margin-left: 8px; text-transform: uppercase; letter-spacing: .04em;
    }
    .pill-ativo {
      background: rgba(74,144,120,.16); color: #6EE7B7;
      border: 1px solid rgba(74,144,120,.30);
    }
    .pill-revogado {
      background: rgba(160,180,210,.12); color: #94A3B8;
      border: 1px solid rgba(160,180,210,.22);
    }

    /* ── Botões padrão Yuris ── */
    .btn {
      padding: 8px 14px; border-radius: 9px; border: 1px solid transparent;
      font: inherit; cursor: pointer; font-weight: 600; font-size: .82rem;
      transition: background .15s, border-color .15s, transform .12s;
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-revoke {
      background: rgba(220,38,38,.10); color: #FCA5A5;
      border-color: rgba(220,38,38,.28);
    }
    .btn-revoke:hover {
      background: rgba(220,38,38,.18); border-color: rgba(220,38,38,.45);
    }
    .btn-link {
      background: rgba(36,78,122,.20); color: #93C5FD;
      border-color: rgba(96,165,250,.25);
    }
    .btn-link:hover {
      background: rgba(36,78,122,.32); border-color: rgba(96,165,250,.45);
      transform: translateY(-1px);
    }
    .btn-primary {
      background: linear-gradient(135deg, #2563EB, #1D4ED8);
      color: #FFFFFF; border-color: #1D4ED8;
    }
    .btn-primary:hover {
      filter: brightness(1.1);
      transform: translateY(-1px);
    }

    .empty {
      padding: 26px 14px; color: var(--muted);
      text-align: center; font-size: .9rem; font-style: italic;
    }
    .links-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 10px;
    }

    /* Tema claro overrides */
    html[data-theme="light"] body {
      background-color: #EFF3F8;
      color: #0F1F36;
    }
    html[data-theme="light"] .cons-fin { color: #0F1F36; }
    html[data-theme="light"] .pill-ativo {
      background: rgba(22,163,74,.12); color: #15803D;
      border-color: rgba(22,163,74,.30);
    }
    html[data-theme="light"] .pill-revogado {
      background: rgba(100,116,139,.12); color: #475569;
      border-color: rgba(100,116,139,.30);
    }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">

    <!-- ── Sidebar ── (mesmo include de todas as páginas) -->
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <!-- ── Conteúdo principal ── -->
    <section class="main-content space-y-4">

      <!-- Header padrão .page-header (yuris-theme.css) -->
      <div class="cfg-panel page-header">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Privacidade &amp; Consentimentos</h2>
            <p class="page-header-subtitle">
              Gerencie suas autorizações específicas (cookies, marketing, IA etc). Você pode revogar qualquer consentimento a qualquer momento — <strong>LGPD Art. 18 IX</strong>.
            </p>
          </div>
        </div>
      </div>

      <!-- ── Card: Consentimentos ─────────────────────────────────────── -->
      <div class="cfg-panel">
        <h3 style="font-size:1.02rem;font-weight:600;color:#D8E4F0;margin:0 0 14px">Seus consentimentos</h3>

        <?php if (empty($consentimentos)): ?>
          <div class="empty">
            Nenhum consentimento registrado ainda. Quando você responder o banner de cookies
            ou aceitar algo novo, aparece aqui.
          </div>
        <?php else: ?>
          <?php foreach ($consentimentos as $c):
            $status          = $c['status'];
            $pillCls         = $status === 'ativo' ? 'pill-ativo' : 'pill-revogado';
            $finalidadeLabel = ucfirst(str_replace('_', ' ', $c['finalidade']));
          ?>
            <div class="cons-row">
              <div>
                <span class="cons-fin"><?= htmlspecialchars($finalidadeLabel) ?></span>
                <span class="pill <?= $pillCls ?>"><?= htmlspecialchars($status) ?></span>
                <div class="cons-meta">
                  Base: <?= htmlspecialchars($c['base_legal']) ?> ·
                  Concedido em <?= htmlspecialchars($c['concedido_em']) ?>
                  <?php if ($c['revogado_em']): ?>· Revogado em <?= htmlspecialchars($c['revogado_em']) ?><?php endif; ?>
                  <?php if ($c['fonte']): ?>· Origem: <?= htmlspecialchars($c['fonte']) ?><?php endif; ?>
                </div>
              </div>
              <?php if ($status === 'ativo'): ?>
                <button class="btn btn-revoke" type="button"
                        onclick="revoke('<?= htmlspecialchars($c['finalidade'], ENT_QUOTES) ?>')">Revogar</button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- ── Card: Direitos do titular ─────────────────────────────────── -->
      <div class="cfg-panel">
        <h3 style="font-size:1.02rem;font-weight:600;color:#D8E4F0;margin:0 0 8px">Seus direitos como titular (LGPD Art. 18)</h3>
        <p style="color:var(--muted);font-size:.86rem;line-height:1.55;margin:0 0 14px">
          Você pode exercer os seguintes direitos. Para solicitações de acesso, correção,
          anonimização, eliminação ou portabilidade de dados, abra uma solicitação ou
          entre em contato com o Encarregado de Dados (DPO).
        </p>
        <div class="links-grid">
          <a class="btn btn-primary" href="/sistema_vendas/public/lgpd/solicitar.php">Abrir solicitação LGPD →</a>
          <a class="btn btn-link" href="/sistema_vendas/public/dpo.php">Falar com o DPO</a>
          <a class="btn btn-link" href="/sistema_vendas/public/privacidade.php">Política de Privacidade</a>
          <a class="btn btn-link" href="/sistema_vendas/public/termos.php">Termos de Uso</a>
          <a class="btn btn-link" href="/sistema_vendas/public/cookies.php">Política de Cookies</a>
          <a class="btn btn-link" href="/sistema_vendas/public/lgpd.php">LGPD &amp; Segurança</a>
          <a class="btn btn-link" href="javascript:if(window.YurisCookies){YurisCookies.open()}">Gerenciar cookies</a>
        </div>
      </div>

    </section>
  </div>
</main>

<script>
  const CSRF = <?= json_encode($csrf) ?>;
  async function revoke(finalidade) {
    if (!confirm('Tem certeza? Vamos revogar o consentimento para "' + finalidade + '".')) return;
    try {
      const res = await fetch('/sistema_vendas/public/api/legal/consent.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        credentials: 'same-origin',
        body: JSON.stringify({ csrf_token: CSRF, finalidade })
      });
      const j = await res.json();
      if (j && j.ok) location.reload();
      else (window.Yuris && Yuris.notify ? Yuris.notify('Erro: ' + (j.error || 'desconhecido'), {type:'error'}) : alert('Erro: ' + (j.error || 'desconhecido')));
    } catch (e) {
      (window.Yuris && Yuris.notify ? Yuris.notify('Erro de rede: ' + e.message, {type:'error'}) : alert('Erro de rede: ' + e.message));
    }
  }
</script>
</body>
</html>
