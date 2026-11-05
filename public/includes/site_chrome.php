<?php
/**
 * site_chrome.php — cabeçalho, rodapé e helpers do SITE PÚBLICO (design system
 * "Editorial LegalTech", assets/site/site.css).
 *
 * Usado por: home (v2/), páginas de soluções (seo_page.php), planos, sobre,
 * blog, páginas legais e 404. NÃO é usado pelo sistema interno (login e
 * painel têm layout próprio) nem pela home antiga (index-v1-legacy.php, que
 * continua com landing.css como plano de volta).
 *
 * Funções (todas com guard, o arquivo pode ser incluído mais de uma vez):
 *   ys_head_tags(array $SEO)   → define $SEO['fonts'] e as folhas do site
 *   ys_header(array $o)        → <header> + drawer mobile
 *   ys_footer(array $o)        → <footer>
 *   ys_check() / ys_arrow()    → ícones usados nas listas
 *   ys_wa_btn($msg,$label,$cls)
 *
 * Os links e destinos são os que já existiam em cada página: o redesign não
 * troca endereço, número de WhatsApp nem mensagem pré-preenchida.
 */

require_once __DIR__ . '/lp_helpers.php';   // wa() + $waSvg

if (!defined('YS_FONT_URL')) {
    define('YS_FONT_URL', 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap');
}

if (!function_exists('ys_asset_ver')) {
    /** Versão por mtime (cache-busting sem build). */
    function ys_asset_ver(string $relPath): int
    {
        return @filemtime(__DIR__ . '/../' . ltrim($relPath, '/')) ?: 1;
    }
}

if (!function_exists('ys_css')) {
    /** Lista de folhas do site com ?v= por mtime. $extras: ['site-home.css', ...] */
    function ys_css(array $extras = []): array
    {
        $out = [];
        foreach (array_merge(['site.css', 'site-motion.css'], $extras) as $f) {
            $out[] = '/assets/site/' . $f . '?v=' . ys_asset_ver('assets/site/' . $f);
        }
        return $out;
    }
}

if (!function_exists('ys_check')) {
    function ys_check(): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    }
}

if (!function_exists('ys_arrow')) {
    function ys_arrow(): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
    }
}

if (!function_exists('ys_wa_btn')) {
    /** Botão de WhatsApp (mesmo destino e mensagem de sempre). */
    function ys_wa_btn(string $msg, string $label, string $cls = 'ys-btn'): string
    {
        global $waSvg;
        return '<a href="' . htmlspecialchars(wa($msg), ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" class="' . $cls . '">'
            . $waSvg . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}

if (!function_exists('ys_nav_paginas')) {
    /** Navegação das páginas internas (a mesma do seo_page.php antigo). */
    function ys_nav_paginas(): array
    {
        return [
            ['/', 'Início'],
            ['/sistema-juridico/', 'Sistema Jurídico'],
            ['/crm-juridico/', 'CRM'],
            ['/automacao-juridica/', 'Automação'],
            ['/planos', 'Planos'],
            ['/blog/', 'Blog'],
        ];
    }
    function ys_drawer_paginas(): array
    {
        return [
            ['/', 'Início'],
            ['/sistema-juridico/', 'Sistema Jurídico'],
            ['/crm-juridico/', 'CRM Jurídico'],
            ['/automacao-juridica/', 'Automação Jurídica'],
            ['/controle-de-processos/', 'Controle de Processos'],
            ['/planos', 'Planos'],
            ['/blog/', 'Blog'],
        ];
    }
}

if (!function_exists('ys_header')) {
    /**
     * $o = [
     *   'nav'       => [[href,label],...]   (default: ys_nav_paginas())
     *   'drawer'    => [[href,label],...]   (default: igual a nav)
     *   'logoHref'  => '/'
     *   'ctaMsg'    => mensagem do WhatsApp
     *   'ctaLabel'  => 'Solicitar demonstração'
     *   'active'    => href do item ativo (opcional)
     * ]
     */
    function ys_header(array $o = []): void
    {
        global $waSvg;
        $nav      = $o['nav']    ?? ys_nav_paginas();
        $drawer   = $o['drawer'] ?? $nav;
        $logoHref = $o['logoHref'] ?? '/';
        $msg      = $o['ctaMsg']   ?? 'Olá Bruno, quero uma demonstração do Yuris!';
        $label    = $o['ctaLabel'] ?? 'Solicitar demonstração';
        $active   = $o['active']   ?? '';
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        ?>
<a href="#conteudo" class="ys-skip">Ir para o conteúdo</a>
<header class="ys-header" role="banner">
  <div class="ys-container ys-header-inner">
    <a href="<?= $h($logoHref) ?>" class="ys-logo" aria-label="Yuris, Sistema Jurídico Inteligente">
      <img src="/assets/img/logo-144.webp" alt="" width="42" height="42">
      <span class="ys-logo-text">YURIS</span>
    </a>

    <nav class="ys-nav" aria-label="Navegação principal">
      <?php foreach ($nav as [$href, $text]): ?>
      <a href="<?= $h($href) ?>"<?= $href === $active ? ' class="is-active" aria-current="page"' : '' ?>><?= $h($text) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="ys-header-cta">
      <a href="/login" class="ys-btn ys-btn-ghost ys-btn-sm ys-entrar">Entrar</a>
      <a href="<?= $h(wa($msg)) ?>" target="_blank" rel="noopener" class="ys-btn ys-btn-sm ys-btn-primary-wa"><?= $waSvg ?> <?= $h($label) ?></a>
      <button class="ys-menu-toggle" aria-label="Abrir menu" aria-expanded="false" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="7" x2="21" y2="7"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="17" x2="21" y2="17"/></svg>
      </button>
    </div>
  </div>
</header>

<div class="ys-drawer-backdrop" aria-hidden="true"></div>
<aside class="ys-drawer" aria-label="Menu mobile">
  <button class="ys-drawer-close" aria-label="Fechar menu" type="button">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
  <div class="ys-drawer-title">Navegação</div>
  <?php foreach ($drawer as [$href, $text]): ?>
  <a href="<?= $h($href) ?>"><?= $h($text) ?></a>
  <?php endforeach; ?>
  <div class="ys-drawer-title">Acesso</div>
  <a href="/login" class="ys-btn ys-btn-ghost">Entrar</a>
  <a href="<?= $h(wa($msg)) ?>" target="_blank" rel="noopener" class="ys-btn"><?= $waSvg ?> <?= $h($label) ?></a>
</aside>
        <?php
    }
}

if (!function_exists('ys_footer')) {
    /**
     * $o = ['brandText' => '...', 'cookies' => bool (mostra "Gerenciar cookies")]
     */
    function ys_footer(array $o = []): void
    {
        $brand = $o['brandText'] ?? 'Sistema Jurídico Inteligente. Controle, segurança e automação para a rotina de advogados e escritórios.';
        $cookies = !empty($o['cookies']);
        ?>
<footer class="ys-footer">
  <div class="ys-container">
    <div class="ys-footer-grid">
      <div class="ys-footer-brand">
        <a href="/" class="ys-logo" aria-label="Yuris, página inicial">
          <img src="/assets/img/logo-144.webp" alt="" width="46" height="46" loading="lazy" decoding="async">
          <span class="ys-logo-text">YURIS</span>
        </a>
        <p><?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <div>
        <h5>Soluções</h5>
        <ul>
          <li><a href="/sistema-juridico/">Sistema Jurídico</a></li>
          <li><a href="/crm-juridico/">CRM Jurídico</a></li>
          <li><a href="/gestao-escritorio-advocacia/">Gestão de Escritório</a></li>
          <li><a href="/automacao-juridica/">Automação Jurídica</a></li>
          <li><a href="/controle-de-processos/">Controle de Processos</a></li>
          <li><a href="/prospeccao-juridica/">Prospecção Jurídica</a></li>
          <li><a href="/financeiro-juridico/">Financeiro Jurídico</a></li>
          <li><a href="/lgpd-escritorios-advocacia/">LGPD para Escritórios</a></li>
        </ul>
      </div>
      <div>
        <h5>Produto</h5>
        <ul>
          <li><a href="/#recursos">Recursos</a></li>
          <li><a href="/planos">Planos</a></li>
          <li><a href="/demonstracao/">Demonstração</a></li>
          <li><a href="/blog/">Blog</a></li>
        </ul>
      </div>
      <div>
        <h5>Empresa</h5>
        <ul>
          <li><a href="/sobre/">Sobre o Yuris</a></li>
          <li><a href="/#para-quem">Para quem é</a></li>
          <li><a href="/login">Entrar</a></li>
        </ul>
      </div>
      <div>
        <h5>Legal</h5>
        <ul>
          <li><a href="/privacidade">Privacidade</a></li>
          <li><a href="/termos">Termos</a></li>
          <li><a href="/lgpd">LGPD</a></li>
          <li><a href="/cookies">Cookies</a></li>
          <li><a href="/dpo">Encarregado (DPO)</a></li>
<?php if ($cookies): ?>
          <li><a href="#" onclick="if(window.YurisCookies){YurisCookies.open();}return false;">Gerenciar cookies</a></li>
<?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="ys-footer-bottom">
      <span>&copy; <span data-year>2026</span> Yuris &middot; Sistema Jurídico Inteligente.</span>
      <span>O Yuris adota medidas técnicas e organizacionais voltadas à proteção de dados pessoais e segue em processo contínuo de adequação à LGPD.</span>
    </div>
  </div>
</footer>
        <?php
    }
}
