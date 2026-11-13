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
 * Visual: design system do site (assets/site/site.css, body.ys), com cabeçalho
 * e rodapé de site_chrome.php e coluna de leitura em assets/site/site-legal.css.
 * As classes utilitárias de solicitar.php e acompanhar.php moram nessa folha.
 *
 * Tema: o site público é claro. A preferência yuris_theme vale só para o
 * sistema interno e não é lida aqui.
 */
if (!isset($LEGAL_PAGE)) {
    http_response_code(500);
    exit('legal_page.php: var $LEGAL_PAGE não definida');
}
// Por __DIR__ porque este layout também é incluído de app/Lgpd/Paginas/, e pode
// rodar sem o bootstrap. site_chrome.php não depende dele.
require_once __DIR__ . '/site_chrome.php';      // ys_header(), ys_footer(), ys_css(), YS_FONT_URL

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
<?php if ($descricao !== ''): ?>
  <meta name="description" content="<?= $descricao ?>">
<?php endif; ?>
  <meta name="robots" content="index,follow">
  <!-- Canonical na forma limpa (sem .php) — em produção o nginx serve /privacidade etc. -->
<?php
    /*
     * O caminho vem do Router quando a pagina e servida por rota: ali
     * SCRIPT_NAME vale '/index.php' (o front controller), e derivar dele daria
     * canonical "/index" em TODA pagina legal. Fora de rota, SCRIPT_NAME vale.
     *
     * class_exists porque metade das paginas legais (cookies, termos, lgpd,
     * privacidade, lgpd/solicitar) nao carrega o bootstrap: sem a guarda, o
     * include fatalava nelas.
     */
    $__canonica = class_exists('App\\Core\\Router') ? \App\Core\Router::caminhoAtual() : null;
    if ($__canonica === null) {
        // Caminho INTEIRO, nao so o basename. Com basename, /lgpd/solicitar.php
        // publicava canonical "/solicitar", endereco que nao existe.
        $__s = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $__canonica = preg_replace('/\.php$/', '', $__s);
        if ($__canonica === '' || $__canonica[0] !== '/') {
            $__canonica = '/' . $__canonica;
        }
    }
?>
  <link rel="canonical" href="https://yuris.com.br<?= htmlspecialchars($__canonica) ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="preload" href="<?= htmlspecialchars(YS_FONT_PRELOAD, ENT_QUOTES, 'UTF-8') ?>" as="font" type="font/woff2" crossorigin>
<?php foreach (ys_css(['site-legal.css']) as $__folha): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($__folha, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
</head>
<body class="ys">

<?php ys_header(['drawer' => ys_drawer_paginas()]); ?>

<main id="conteudo">
  <section class="ys-pagehero legal-hero">
    <div class="ys-container">
      <nav class="ys-breadcrumb" aria-label="Você está aqui">
        <ol>
          <li><a href="/">Início</a></li>
          <li aria-current="page"><?= $titulo ?></li>
        </ol>
      </nav>
      <h1 class="legal-title"><?= $titulo ?></h1>
      <?php if ($descricao): ?>
        <p class="ys-lede legal-sub"><?= $descricao ?></p>
      <?php endif; ?>
      <?php if ($versao): ?>
        <div class="legal-version">Versão <?= $versao ?></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="ys-article legal-article">
    <div class="ys-container">
      <?php /* Disclaimer "modelo inicial / pendente revisao" REMOVIDO da
               interface publica em 2026-05-23. Status de revisao agora fica
               em /api/master/reviews.php (aba "Revisoes" no Painel Master),
               para uso interno do DPO + Diretoria. Cliente nao deve ver
               status de rascunho. */ ?>
      <article class="legal-content ys-prose"><?= $corpo /* HTML já preparado */ ?></article>
    </div>
  </section>
</main>

<?php ys_footer(['cookies' => true]); ?>

<script src="/assets/site/site.js?v=<?= ys_asset_ver('assets/site/site.js') ?>" defer></script>
  <!-- LGPD Etapa 5: banner de cookies -->
  <script src="/assets/cookie-consent.js?v=3"></script>
</body>
</html>
