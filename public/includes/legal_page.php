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
  <link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --bg:#0a1224; --panel:#0f1c33; --text:#e8edf5; --muted:#8b9cb7; --accent:#7eb8f7; }
    html, body { margin:0; padding:0; }
    body {
      background: var(--bg);
      background-image:
        radial-gradient(ellipse at 20% 0%, rgba(37,99,235,.10) 0%, transparent 55%),
        radial-gradient(ellipse at 80% 100%, rgba(96,165,250,.08) 0%, transparent 60%);
      color: var(--text); font-family: Inter, system-ui, sans-serif;
      line-height: 1.6;
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
      border: 1px solid rgba(96,165,250,.18);
      border-radius: 14px;
      padding: 36px 36px 30px;
      box-shadow: 0 20px 60px rgba(0,0,0,.35);
    }
    .legal-title { font-size: 1.9rem; font-weight: 700; margin: 0 0 4px; color: #fff; }
    .legal-sub { color: var(--muted); font-size: .92rem; margin-bottom: 22px; }
    .legal-version { display:inline-block; padding:3px 10px; background: rgba(96,165,250,.10); border:1px solid rgba(96,165,250,.20); color:var(--accent); font-size:.74rem; border-radius:999px; }

    .legal-disclaimer {
      background: rgba(251,191,36,.07); border:1px solid rgba(251,191,36,.30);
      color:#fde68a; padding:12px 16px; border-radius:10px; font-size:.86rem; margin-bottom: 22px;
    }
    .legal-content h2 { color: #fff; font-size: 1.25rem; margin: 28px 0 10px; }
    .legal-content h3 { color: #d8e3f3; font-size: 1.02rem; margin: 18px 0 8px; }
    .legal-content p, .legal-content ul, .legal-content ol { color: #c9d4e6; }
    .legal-content ul, .legal-content ol { padding-left: 22px; }
    .legal-content li { margin: 4px 0; }
    .legal-content strong { color: #fff; }
    .legal-content a { color: var(--accent); }
    .legal-content code { background: rgba(96,165,250,.10); padding: 1px 6px; border-radius: 4px; font-size: .9em; }

    .legal-footer {
      margin-top: 30px; padding-top: 22px; border-top: 1px solid rgba(96,165,250,.10);
      display:flex; flex-wrap: wrap; gap: 14px 22px; justify-content: space-between; font-size: .82rem; color: var(--muted);
    }
    .legal-footer a { color: var(--accent); text-decoration: none; }
    .legal-footer a:hover { text-decoration: underline; }
    .legal-footer-links { display: flex; gap: 16px; flex-wrap: wrap; }
  </style>
</head>
<body>
  <div class="legal-shell">
    <div class="legal-header">
      <a class="legal-brand" href="/sistema_vendas/public/planos.php">
        <img src="/sistema_vendas/Imagens/Logo.png" alt="Yuris">
        <span class="legal-brand-name">Yuris</span>
      </a>
      <a class="legal-back" href="javascript:history.length>1?history.back():(location.href='/sistema_vendas/public/planos.php')">← Voltar</a>
    </div>

    <article class="legal-card">
      <h1 class="legal-title"><?= $titulo ?></h1>
      <?php if ($descricao): ?>
        <div class="legal-sub"><?= $descricao ?></div>
      <?php endif; ?>
      <?php if ($versao): ?>
        <div class="legal-version">Versão <?= $versao ?></div>
      <?php endif; ?>

      <div class="legal-disclaimer">
        <strong>Importante:</strong> este documento é um modelo inicial e deve passar por
        revisão de advogado especialista em proteção de dados antes da publicação definitiva.
        A segurança e a privacidade são tratadas como processo contínuo de adequação à LGPD.
      </div>

      <div class="legal-content"><?= $corpo /* HTML já preparado */ ?></div>

      <div class="legal-footer">
        <div class="legal-footer-links">
          <a href="/sistema_vendas/public/privacidade.php">Privacidade</a>
          <a href="/sistema_vendas/public/termos.php">Termos</a>
          <a href="/sistema_vendas/public/cookies.php">Cookies</a>
          <a href="/sistema_vendas/public/lgpd.php">LGPD & Segurança</a>
          <a href="/sistema_vendas/public/dpo.php">Contato DPO</a>
        </div>
        <div>© <?= date('Y') ?> Yuris — Sistema jurídico SaaS</div>
      </div>
    </article>
  </div>
  <!-- LGPD Etapa 5: banner de cookies -->
  <script src="/sistema_vendas/public/assets/cookie-consent.js?v=1"></script>
</body>
</html>
