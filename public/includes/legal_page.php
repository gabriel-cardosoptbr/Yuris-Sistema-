<?php
/**
 * legal_page.php — layout reutilizado pelas páginas legais públicas.
 *
 * Uso:
 *   $LEGAL_PAGE = [
 *     'titulo'      => 'Política de Privacidade',
 *     'descricao'   => 'Como tratamos seus dados pessoais',
 *     'versao'      => '2026-05-23',
 *     'corpo_html'  => '<h2>...</h2><p>...</p>',
 *   ];
 *   require __DIR__ . '/includes/legal_page.php';
 *
 * Visual: simples, sem sidebar, mobile-friendly. Cabeçalho com logo +
 * footer com links pra todas as páginas legais e contato DPO.
 *
 * Tema: respeita preferência salva em localStorage('yuris_theme'). Variáveis
 * CSS dinâmicas via html[data-theme="light"]. Padrão = escuro (Yuris).
 */
if (!isset($LEGAL_PAGE)) {
    http_response_code(500);
    exit('legal_page.php: var $LEGAL_PAGE não definida');
}
$titulo    = htmlspecialchars($LEGAL_PAGE['titulo']    ?? 'Documento Legal');
$descricao = htmlspecialchars($LEGAL_PAGE['descricao'] ?? '');
$versao    = htmlspecialchars($LEGAL_PAGE['versao']    ?? '');
$corpo     = $LEGAL_PAGE['corpo_html'] ?? '<p>Conteúdo em revisão.</p>';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $titulo ?> — Yuris</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <!-- Boot do tema ANTES de qualquer render — evita flash de tema errado. -->
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <style>
    /* ── Variáveis: TEMA ESCURO (padrão) ─────────────────────────────────── */
    :root {
      --bg:#0a1224;
      --bg-grad-1: rgba(37,99,235,.10);
      --bg-grad-2: rgba(96,165,250,.08);
      --panel:#0f1c33;
      --panel-border: rgba(96,165,250,.18);
      --text:#e8edf5;
      --text-soft: #c9d4e6;
      --text-medium: #d8e3f3;
      --muted:#8b9cb7;
      --accent:#7eb8f7;
      --heading: #ffffff;
      --strong: #ffffff;
      --code-bg: rgba(96,165,250,.10);
      --version-bg: rgba(96,165,250,.10);
      --version-border: rgba(96,165,250,.20);
      --disclaimer-bg: rgba(251,191,36,.07);
      --disclaimer-border: rgba(251,191,36,.30);
      --disclaimer-text: #fde68a;
      --footer-border: rgba(96,165,250,.10);
      --shadow: 0 20px 60px rgba(0,0,0,.35);
      /* Formulários e alertas (usados por solicitar.php / acompanhar.php) */
      --input-bg: rgba(8,12,24,.7);
      --input-border: rgba(96,165,250,.25);
      --input-text: #fff;
      --alert-success-bg: rgba(16,185,129,.13);
      --alert-success-border: rgba(16,185,129,.30);
      --alert-success-text: #a7f3d0;
      --alert-error-bg: rgba(239,68,68,.13);
      --alert-error-border: rgba(239,68,68,.30);
      --alert-error-text: #fca5a5;
      --alert-info-bg: rgba(96,165,250,.05);
      --alert-info-border: rgba(96,165,250,.18);
      --history-bg: rgba(8,12,24,.4);
      --history-border: rgba(96,165,250,.10);
      --row-border: rgba(96,165,250,.10);
      --meta-label: #94a3b8;
      --meta-value: #cbd5e1;
      --btn-primary-bg: linear-gradient(135deg,#2563eb,#1d4ed8);
      --btn-primary-text: #fff;
    }
    /* ── Variáveis: TEMA CLARO ───────────────────────────────────────────── */
    html[data-theme="light"] {
      --bg:#EFF3F8;
      --bg-grad-1: rgba(37,99,235,.06);
      --bg-grad-2: rgba(96,165,250,.04);
      --panel:#FFFFFF;
      --panel-border: rgba(15,31,54,.10);
      --text:#0F1F36;
      --text-soft: #475569;
      --text-medium: #1E293B;
      --muted:#5A6B7E;
      --accent:#2563EB;
      --heading: #0F1F36;
      --strong: #0F1F36;
      --code-bg: rgba(37,99,235,.08);
      --version-bg: rgba(37,99,235,.08);
      --version-border: rgba(37,99,235,.20);
      --disclaimer-bg: rgba(217,119,6,.06);
      --disclaimer-border: rgba(217,119,6,.30);
      --disclaimer-text: #92400E;
      --footer-border: rgba(15,31,54,.08);
      --shadow: 0 8px 24px rgba(15,31,54,.08);
      --input-bg: #FFFFFF;
      --input-border: rgba(15,31,54,.18);
      --input-text: #0F1F36;
      --alert-success-bg: rgba(22,163,74,.10);
      --alert-success-border: rgba(22,163,74,.30);
      --alert-success-text: #15803D;
      --alert-error-bg: rgba(220,38,38,.06);
      --alert-error-border: rgba(220,38,38,.30);
      --alert-error-text: #B91C1C;
      --alert-info-bg: rgba(37,99,235,.06);
      --alert-info-border: rgba(37,99,235,.18);
      --history-bg: rgba(247,249,252,.95);
      --history-border: rgba(15,31,54,.08);
      --row-border: rgba(15,31,54,.08);
      --meta-label: #5A6B7E;
      --meta-value: #1E293B;
      /* btn-primary-bg/text mantidos (azul universal) */
    }

    /* ── Layout ──────────────────────────────────────────────────────────── */
    html, body { margin:0; padding:0; }
    body {
      background: var(--bg);
      background-image:
        radial-gradient(ellipse at 20% 0%, var(--bg-grad-1) 0%, transparent 55%),
        radial-gradient(ellipse at 80% 100%, var(--bg-grad-2) 0%, transparent 60%);
      background-attachment: fixed;
      color: var(--text);
      font-family: Inter, system-ui, sans-serif;
      line-height: 1.6;
      min-height: 100vh;
    }
    .legal-shell { max-width: 820px; margin: 0 auto; padding: 32px 22px 80px; }
    .legal-header { display:flex; align-items:center; justify-content:space-between; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; }
    .legal-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
    .legal-brand img { height: 36px; width: auto; }
    .legal-brand-name { font-weight: 700; font-size: 1.1rem; color: var(--text); }
    .legal-back { color: var(--muted); text-decoration: none; font-size: .9rem; }
    .legal-back:hover { color: var(--accent); }

    .legal-card {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 14px;
      padding: 36px 36px 30px;
      box-shadow: var(--shadow);
    }
    .legal-title { font-size: 1.9rem; font-weight: 700; margin: 0 0 4px; color: var(--heading); }
    .legal-sub { color: var(--muted); font-size: .92rem; margin-bottom: 22px; }
    .legal-version {
      display:inline-block; padding:3px 10px;
      background: var(--version-bg); border:1px solid var(--version-border);
      color: var(--accent); font-size:.74rem; border-radius:999px;
    }

    .legal-disclaimer {
      background: var(--disclaimer-bg); border:1px solid var(--disclaimer-border);
      color: var(--disclaimer-text);
      padding:12px 16px; border-radius:10px; font-size:.86rem; margin-bottom: 22px;
    }
    .legal-content h2 { color: var(--heading); font-size: 1.25rem; margin: 28px 0 10px; }
    .legal-content h3 { color: var(--text-medium); font-size: 1.02rem; margin: 18px 0 8px; }
    .legal-content p, .legal-content ul, .legal-content ol { color: var(--text-soft); }
    .legal-content ul, .legal-content ol { padding-left: 22px; }
    .legal-content li { margin: 4px 0; }
    .legal-content strong { color: var(--strong); }
    .legal-content a { color: var(--accent); }
    .legal-content code {
      background: var(--code-bg); padding: 1px 6px; border-radius: 4px; font-size: .9em;
      color: var(--text);
    }

    .legal-footer {
      margin-top: 30px; padding-top: 22px;
      border-top: 1px solid var(--footer-border);
      display:flex; flex-wrap: wrap; gap: 14px 22px; justify-content: space-between;
      font-size: .82rem; color: var(--muted);
    }
    .legal-footer a { color: var(--accent); text-decoration: none; }
    .legal-footer a:hover { text-decoration: underline; }
    .legal-footer-links { display: flex; gap: 16px; flex-wrap: wrap; }

    /* ── Componentes utilitários (form, alerts, status, history) ─────────── */
    /* Usados por /lgpd/solicitar.php e /lgpd/acompanhar.php; respeitam tema. */
    .legal-form { display:flex; flex-direction:column; gap:14px; margin-top:24px; }
    .legal-form-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap:12px; }
    .legal-field { display:flex; flex-direction:column; gap:4px; font-size:.85rem; color: var(--text-soft); }
    .legal-field-row { display:flex; align-items:flex-start; gap:8px; font-size:.84rem; color: var(--text-soft); cursor:pointer; }
    .legal-input {
      padding:9px 12px;
      border:1px solid var(--input-border);
      border-radius:7px;
      background: var(--input-bg);
      color: var(--input-text);
      font: inherit;
    }
    .legal-input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(37,99,235,.18);
    }
    textarea.legal-input { resize: vertical; min-height: 80px; }
    .legal-btn-primary {
      padding:12px;
      background: var(--btn-primary-bg);
      color: var(--btn-primary-text);
      border:none;
      border-radius:8px;
      font-weight:700;
      font-size:.92rem;
      cursor:pointer;
      transition: filter .15s, transform .12s;
    }
    .legal-btn-primary:hover:not(:disabled) { filter: brightness(1.10); transform: translateY(-1px); }
    .legal-btn-primary:disabled { opacity:.6; cursor:not-allowed; }

    .legal-alert {
      padding: 14px;
      border-radius: 8px;
      margin-top: 10px;
      border: 1px solid;
      font-size: .88rem;
      line-height: 1.55;
    }
    .legal-alert-success { background: var(--alert-success-bg); border-color: var(--alert-success-border); color: var(--alert-success-text); }
    .legal-alert-error   { background: var(--alert-error-bg);   border-color: var(--alert-error-border);   color: var(--alert-error-text); }
    .legal-alert-info    { background: var(--alert-info-bg);    border-color: var(--alert-info-border);    color: var(--text-soft); }
    .legal-alert a { color: var(--accent); }

    .legal-status-pill {
      padding:6px 14px;
      border-radius:999px;
      background: rgba(127,127,127,.06);
      border:1px solid;
      font-weight:600;
      font-size:.82rem;
      display:inline-block;
    }

    .legal-meta-grid {
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
      gap:10px;
      font-size:.82rem;
      color: var(--meta-label);
      margin-bottom:20px;
    }
    .legal-meta-value { color: var(--meta-value); }

    .legal-info-card {
      background: var(--alert-info-bg);
      border: 1px solid var(--alert-info-border);
      padding: 12px;
      border-radius: 8px;
      white-space: pre-wrap;
      color: var(--text-soft);
    }
    .legal-history-box {
      background: var(--history-bg);
      border: 1px solid var(--history-border);
      padding: 6px 14px;
      border-radius: 8px;
    }
    .legal-event {
      padding: 10px 0;
      border-bottom: 1px solid var(--row-border);
    }
    .legal-event:last-child { border-bottom: none; }
    .legal-event-title { font-size:.85rem; color: var(--text); font-weight:600; }
    .legal-event-obs   { font-size:.82rem; color: var(--text-soft); margin-top:3px; }
    .legal-event-date  { font-size:.74rem; color: var(--muted); margin-top:3px; }

    .legal-header-row {
      display:flex; justify-content:space-between; align-items:flex-start;
      gap:14px; flex-wrap:wrap; margin-bottom:18px;
    }
    .legal-header-meta-label { font-size:.78rem; color: var(--muted); }
    .legal-header-meta-title { font-size:1.1rem; color: var(--heading); font-weight:600; margin-top:3px; }
    .legal-header-meta-sub   { font-size:.85rem; color: var(--text-soft); margin-top:5px; }
  </style>
</head>
<body>
  <div class="legal-shell">
    <div class="legal-header">
      <a class="legal-brand" href="/planos.php">
        <img src="/sistema_vendas/Imagens/Logo.png" alt="Yuris">
        <span class="legal-brand-name">Yuris</span>
      </a>
      <a class="legal-back" href="javascript:history.length>1?history.back():(location.href='/planos.php')">← Voltar</a>
    </div>

    <article class="legal-card">
      <h1 class="legal-title"><?= $titulo ?></h1>
      <?php if ($descricao): ?>
        <div class="legal-sub"><?= $descricao ?></div>
      <?php endif; ?>
      <?php if ($versao): ?>
        <div class="legal-version">Versão <?= $versao ?></div>
      <?php endif; ?>

      <?php /* Disclaimer "modelo inicial / pendente revisao" REMOVIDO da
               interface publica em 2026-05-23. Status de revisao agora fica
               em /api/master/reviews.php (aba "Revisoes" no Painel Master),
               para uso interno do DPO + Diretoria. Cliente nao deve ver
               status de rascunho. */ ?>
      <div class="legal-content"><?= $corpo /* HTML já preparado */ ?></div>

      <div class="legal-footer">
        <div class="legal-footer-links">
          <a href="/privacidade.php">Privacidade</a>
          <a href="/termos.php">Termos</a>
          <a href="/cookies.php">Cookies</a>
          <a href="/lgpd.php">LGPD & Segurança</a>
          <a href="/dpo.php">Contato DPO</a>
        </div>
        <div>© <?= date('Y') ?> Yuris — Sistema jurídico SaaS</div>
      </div>
    </article>
  </div>
  <!-- LGPD Etapa 5: banner de cookies -->
  <script src="/assets/cookie-consent.js?v=1"></script>
</body>
</html>
