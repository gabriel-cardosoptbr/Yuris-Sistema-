<?php
/**
 * /index.php
 *
 * Landing page institucional do Yuris — Sistema Jurídico Inteligente.
 * Página pública (sem sessão exigida). Quem clica "Entrar" vai pro /login.php.
 *
 * Estrutura: one-page com 20 seções e navegação por âncora.
 * Tema: dark institucional (cores de yuris-theme.css).
 * Estilo isolado em body.lp (não polui o app interno).
 *
 * Mockups: SVG inline elegantes. Substituir por PNGs reais no futuro é trivial
 * (basta trocar o conteúdo dos blocos .lp-mockup-card / .lp-tab-pane).
 */
$cssVer = @filemtime(__DIR__ . '/assets/landing.css') ?: 1;
$jsVer  = @filemtime(__DIR__ . '/assets/landing.js')  ?: 1;

/**
 * wa($mensagem) — gera o link do WhatsApp do comercial Yuris com mensagem
 * contextual já pré-preenchida. Cada CTA da landing chama esta função com
 * um texto específico da seção, pra a conversa já começar com contexto.
 *   Número: 55 11 99117-0602 (Bruno)
 */
function wa(string $mensagem): string {
    return 'https://wa.me/5511991170602?text=' . rawurlencode($mensagem);
}

/* SVG do ícone WhatsApp — usado em todos os CTAs contextuais da landing.
   Path original do logo oficial, simplificado. */
$waSvg = '<span class="lp-wa-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg></span>';
$cssVerSp = @filemtime(__DIR__ . '/assets/seo-pages.css') ?: 1;
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Marca <html class="js"> ANTES do CSS: o estado oculto do .lp-reveal
       só se aplica com JS ativo (resiliência no-JS + LCP do hero). -->
  <script>document.documentElement.classList.add('js');</script>
  <title>Yuris — Sistema Jurídico Inteligente para Advogados</title>
  <meta name="description" content="Controle processos, prazos, intimações, tarefas, clientes e comunicação em uma plataforma jurídica inteligente para advogados e escritórios.">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="https://yuris.com.br/">
  <meta name="theme-color" content="#060D1A">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Yuris">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:url" content="https://yuris.com.br/">
  <meta property="og:title" content="Yuris — Sistema Jurídico Inteligente para Advogados">
  <meta property="og:description" content="Centralize processos, prazos, intimações e clientes em um único sistema jurídico moderno.">
  <meta property="og:image" content="https://yuris.com.br/assets/img/og-image.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Yuris — Sistema Jurídico Inteligente para Advogados">
  <meta name="twitter:description" content="Centralize processos, prazos, intimações e clientes em um único sistema jurídico moderno.">
  <meta name="twitter:image" content="https://yuris.com.br/assets/img/og-image.jpg">

  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/landing.css?v=<?= $cssVer ?>">
  <link rel="stylesheet" href="/assets/seo-pages.css?v=<?= $cssVerSp ?>">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        "@id": "https://yuris.com.br/#org",
        "name": "Yuris",
        "url": "https://yuris.com.br/",
        "logo": {
          "@type": "ImageObject",
          "url": "https://yuris.com.br/assets/img/logo-512.png",
          "width": 512,
          "height": 512
        },
        "description": "Yuris — Sistema Jurídico Inteligente: SaaS de gestão para advogados e escritórios de advocacia no Brasil.",
        "contactPoint": {
          "@type": "ContactPoint",
          "contactType": "sales",
          "url": "https://wa.me/5511991170602",
          "availableLanguage": "Portuguese"
        }
      },
      {
        "@type": "WebSite",
        "@id": "https://yuris.com.br/#website",
        "url": "https://yuris.com.br/",
        "name": "Yuris",
        "inLanguage": "pt-BR",
        "publisher": { "@id": "https://yuris.com.br/#org" }
      },
      {
        "@type": "SoftwareApplication",
        "@id": "https://yuris.com.br/#app",
        "name": "Yuris — Sistema Jurídico Inteligente",
        "url": "https://yuris.com.br/",
        "image": "https://yuris.com.br/assets/img/og-image.jpg",
        "applicationCategory": "BusinessApplication",
        "applicationSubCategory": "Sistema jurídico / gestão para escritórios de advocacia",
        "operatingSystem": "Web",
        "inLanguage": "pt-BR",
        "description": "Sistema jurídico para advogados e escritórios: processos, prazos, intimações, CRM, financeiro, tarefas e WhatsApp em uma única plataforma com LGPD e auditoria.",
        "audience": { "@type": "Audience", "audienceType": "Advogados e escritórios de advocacia" },
        "featureList": [
          "Gestão de processos com histórico auditado",
          "Monitoramento de intimações (DJEN, DataJud e AASP)",
          "CRM jurídico em Kanban",
          "Clientes e contatos centralizados",
          "Atendimento por WhatsApp integrado",
          "Financeiro com DRE e recorrências",
          "Tarefas em Kanban, lista e calendário",
          "Webhooks para n8n, Make e Zapier",
          "LGPD e auditoria estruturais",
          "Multi-tenant com matriz e filial"
        ],
        "offers": {
          "@type": "AggregateOffer",
          "priceCurrency": "BRL",
          "lowPrice": "220",
          "highPrice": "670",
          "offerCount": 4,
          "url": "https://yuris.com.br/planos"
        },
        "provider": { "@id": "https://yuris.com.br/#org" }
      }
    ]
  }
  </script>
</head>
<body class="lp">

<!-- ════════════════════════════════════════════════════════════════════════
     HEADER FIXO
     ════════════════════════════════════════════════════════════════════════ -->
<header class="lp-header" role="banner">
  <div class="lp-container lp-header-inner">
    <a href="#inicio" class="lp-logo" aria-label="Yuris — Sistema Jurídico Inteligente">
      <img src="/assets/img/logo-144.webp" alt="" width="40" height="40">
      <span class="lp-logo-text">YURIS</span>
    </a>

    <nav class="lp-nav" aria-label="Navegação principal">
      <a href="#inicio">Início</a>
      <a href="#recursos">Recursos</a>
      <a href="#juridico">Jurídico</a>
      <a href="#automacao">Automação</a>
      <a href="#seguranca">Segurança</a>
      <a href="/planos.php">Planos</a>
      <a href="#demonstracao">Demonstração</a>
    </nav>

    <div class="lp-header-cta">
      <a href="/login.php" class="lp-btn lp-btn-ghost">Entrar</a>
      <a href="<?= wa('Olá Bruno, quero uma demonstração do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Solicitar demonstração
      </a>
      <button class="lp-menu-toggle" aria-label="Abrir menu" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
</header>

<!-- Drawer mobile -->
<div class="lp-drawer-backdrop" aria-hidden="true"></div>
<aside class="lp-drawer" aria-label="Menu mobile">
  <button class="lp-drawer-close" aria-label="Fechar menu" type="button">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
  <div class="lp-drawer-title">Navegação</div>
  <a href="#inicio">Início</a>
  <a href="#recursos">Recursos</a>
  <a href="#juridico">Jurídico</a>
  <a href="#automacao">Automação</a>
  <a href="#seguranca">Segurança</a>
  <a href="/planos.php">Planos</a>
  <a href="#demonstracao">Demonstração</a>
  <div class="lp-drawer-title">Acesso</div>
  <a href="/login.php" class="lp-btn lp-btn-ghost">Entrar</a>
  <a href="<?= wa('Olá Bruno, quero uma demonstração do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
    <?= $waSvg ?>
    Solicitar demonstração
  </a>
</aside>

<main>

<!-- ════════════════════════════════════════════════════════════════════════
     1. HERO
     ════════════════════════════════════════════════════════════════════════ -->
<section id="inicio" class="lp-hero">
  <div class="lp-container">
    <div class="lp-hero-grid">

      <!-- Hero SEM .lp-reveal de propósito: a primeira dobra (LCP) pinta
           imediatamente, sem esperar o JS. O reveal segue nas demais seções. -->
      <div class="lp-hero-text">
        <span class="lp-eyebrow">Sistema jurídico inteligente</span>
        <h1>Controle processos, prazos, intimações e clientes em <strong>um único sistema</strong> jurídico inteligente.</h1>
        <p class="lp-hero-sub">
          O Yuris centraliza processos, tarefas, intimações, comunicação,
          equipe e automações em uma plataforma moderna para advogados e
          escritórios jurídicos.
        </p>
        <div class="lp-hero-ctas">
          <a href="<?= wa('Olá Bruno, quero uma demonstração do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
            <?= $waSvg ?>
            Solicitar demonstração
          </a>
          <a href="#recursos" class="lp-btn lp-btn-ghost">Conhecer funcionalidades</a>
        </div>
        <div class="lp-hero-trust">
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/></svg>Isolamento multi-tenant</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>Logs de auditoria</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>2FA e controle de acesso</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Preparado para apoiar a LGPD</span>
        </div>
      </div>

      <div class="lp-hero-mockup">
        <div class="lp-mockup-frame">
          <div class="lp-mockup-card">
            <!-- Mockup dashboard (SVG inline) -->
            <svg viewBox="0 0 600 380" xmlns="http://www.w3.org/2000/svg">
              <defs>
                <linearGradient id="dashG1" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="#1E5FA8"/><stop offset="1" stop-color="#0F3060"/>
                </linearGradient>
                <linearGradient id="dashG2" x1="0" y1="0" x2="1" y2="0">
                  <stop offset="0" stop-color="#5BBCE0" stop-opacity=".5"/>
                  <stop offset="1" stop-color="#5BBCE0" stop-opacity=".1"/>
                </linearGradient>
              </defs>
              <!-- Sidebar -->
              <rect x="0" y="0" width="120" height="380" fill="#060D1A"/>
              <rect x="14" y="18" width="92" height="32" rx="6" fill="#1E3A5F"/>
              <rect x="14" y="62"  width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="92"  width="92" height="22" rx="5" fill="#1E4A8A"/>
              <rect x="14" y="122" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="152" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="182" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <rect x="14" y="212" width="92" height="22" rx="5" fill="#0D2540" opacity=".55"/>
              <!-- Header -->
              <rect x="140" y="16" width="180" height="14" rx="3" fill="#A8BDD4" opacity=".75"/>
              <rect x="140" y="36" width="120" height="9"  rx="3" fill="#6B7887"/>
              <rect x="490" y="16" width="90"  height="28" rx="6" fill="url(#dashG1)"/>
              <!-- KPI cards -->
              <g>
                <rect x="140" y="64" width="135" height="78" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="152" y="76" width="48" height="9" rx="2" fill="#6B7887"/>
                <text x="152" y="118" fill="#E2EAF2" font-family="Inter" font-size="22" font-weight="700">38</text>
                <text x="190" y="118" fill="#5BBCE0" font-family="Inter" font-size="11">processos</text>

                <rect x="285" y="64" width="135" height="78" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="297" y="76" width="48" height="9" rx="2" fill="#6B7887"/>
                <text x="297" y="118" fill="#E2EAF2" font-family="Inter" font-size="22" font-weight="700">12</text>
                <text x="334" y="118" fill="#5BBCE0" font-family="Inter" font-size="11">prazos hoje</text>

                <rect x="430" y="64" width="150" height="78" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="442" y="76" width="60" height="9" rx="2" fill="#6B7887"/>
                <text x="442" y="118" fill="#E2EAF2" font-family="Inter" font-size="22" font-weight="700">7</text>
                <text x="467" y="118" fill="#5BBCE0" font-family="Inter" font-size="11">intimações</text>
              </g>
              <!-- Gráfico de barras + linha -->
              <g>
                <rect x="140" y="158" width="285" height="200" rx="12" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="156" y="174" width="100" height="11" rx="3" fill="#A8BDD4" opacity=".7"/>
                <!-- Grid -->
                <g stroke="#1A3A5C" stroke-opacity=".3">
                  <line x1="156" y1="220" x2="412" y2="220"/>
                  <line x1="156" y1="260" x2="412" y2="260"/>
                  <line x1="156" y1="300" x2="412" y2="300"/>
                  <line x1="156" y1="340" x2="412" y2="340"/>
                </g>
                <!-- Barras -->
                <g>
                  <rect x="170" y="270" width="16" height="70"  rx="3" fill="url(#dashG1)"/>
                  <rect x="200" y="245" width="16" height="95"  rx="3" fill="url(#dashG1)"/>
                  <rect x="230" y="232" width="16" height="108" rx="3" fill="url(#dashG1)"/>
                  <rect x="260" y="210" width="16" height="130" rx="3" fill="#3A90C4"/>
                  <rect x="290" y="252" width="16" height="88"  rx="3" fill="url(#dashG1)"/>
                  <rect x="320" y="225" width="16" height="115" rx="3" fill="url(#dashG1)"/>
                  <rect x="350" y="200" width="16" height="140" rx="3" fill="#3A90C4"/>
                  <rect x="380" y="240" width="16" height="100" rx="3" fill="url(#dashG1)"/>
                </g>
              </g>
              <!-- Card lateral lista -->
              <g>
                <rect x="435" y="158" width="145" height="200" rx="12" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
                <rect x="447" y="174" width="80" height="10" rx="3" fill="#A8BDD4" opacity=".7"/>
                <g font-family="Inter" font-size="9">
                  <rect x="447" y="198" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="214" r="5" fill="#5BBCE0"/>
                  <rect x="473" y="208" width="70" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="218" width="50" height="5" rx="2" fill="#6B7887"/>

                  <rect x="447" y="238" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="254" r="5" fill="#F59E0B"/>
                  <rect x="473" y="248" width="74" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="258" width="46" height="5" rx="2" fill="#6B7887"/>

                  <rect x="447" y="278" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="294" r="5" fill="#4ADE80"/>
                  <rect x="473" y="288" width="68" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="298" width="56" height="5" rx="2" fill="#6B7887"/>

                  <rect x="447" y="318" width="121" height="32" rx="6" fill="#081526"/>
                  <circle cx="460" cy="334" r="5" fill="#5BBCE0"/>
                  <rect x="473" y="328" width="62" height="6" rx="2" fill="#A8BDD4" opacity=".8"/>
                  <rect x="473" y="338" width="52" height="5" rx="2" fill="#6B7887"/>
                </g>
              </g>
            </svg>
          </div>
        </div>

        <!-- Chips flutuantes -->
        <div class="lp-hero-chips" aria-hidden="true">
          <div class="lp-chip lp-chip-1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="18" cy="6" r="2.5" fill="currentColor" stroke="none"/></svg>
            <span>7 <strong>intimações</strong> monitoradas</span>
          </div>
          <div class="lp-chip lp-chip-2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>12 <strong>prazos</strong> organizados</span>
          </div>
          <div class="lp-chip lp-chip-3">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <span>Tarefas em dia</span>
          </div>
          <div class="lp-chip lp-chip-4">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Atendimento centralizado</span>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     2. PROVA VISUAL — TABS DE MOCKUPS
     ════════════════════════════════════════════════════════════════════════ -->
<section id="produto" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Visão do produto</span>
      <h2>O Yuris por dentro</h2>
      <p>Navegue entre as principais telas para ver como o sistema organiza a rotina jurídica.</p>
    </div>

    <div class="lp-mockup-tabs lp-reveal" data-tabs="mockups">
      <div class="lp-tab-list" role="tablist">
        <button type="button" class="lp-tab active" data-target="dashboard">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
          Dashboard
        </button>
        <button type="button" class="lp-tab" data-target="processos">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Processos
        </button>
        <button type="button" class="lp-tab" data-target="tarefas">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          Tarefas
        </button>
        <button type="button" class="lp-tab" data-target="intimacoes">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Intimações
        </button>
        <button type="button" class="lp-tab" data-target="comunicacao">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
          Comunicação
        </button>
      </div>

      <div class="lp-tab-panel">
        <!-- Dashboard -->
        <div class="lp-tab-pane active" data-pane="dashboard">
          <svg viewBox="0 0 800 480" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="800" height="40" rx="6" fill="#060D1A"/>
            <rect x="16" y="14" width="80" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <!-- KPIs row -->
            <g>
              <rect x="20"  y="60" width="180" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="34"  y="74" width="50"  height="8"   rx="2" fill="#6B7887"/>
              <text x="34"  y="128" fill="#E2EAF2" font-family="Inter" font-size="32" font-weight="700">128</text>
              <text x="34"  y="148" fill="#5BBCE0" font-family="Inter" font-size="11">processos ativos</text>

              <rect x="210" y="60" width="180" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="224" y="74" width="58"  height="8"   rx="2" fill="#6B7887"/>
              <text x="224" y="128" fill="#E2EAF2" font-family="Inter" font-size="32" font-weight="700">42</text>
              <text x="224" y="148" fill="#F59E0B" font-family="Inter" font-size="11">prazos esta semana</text>

              <rect x="400" y="60" width="180" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="414" y="74" width="60"  height="8"   rx="2" fill="#6B7887"/>
              <text x="414" y="128" fill="#E2EAF2" font-family="Inter" font-size="32" font-weight="700">17</text>
              <text x="414" y="148" fill="#5BBCE0" font-family="Inter" font-size="11">intimações novas</text>

              <rect x="590" y="60" width="190" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="604" y="74" width="58"  height="8"   rx="2" fill="#6B7887"/>
              <text x="604" y="128" fill="#E2EAF2" font-family="Inter" font-size="32" font-weight="700">94%</text>
              <text x="604" y="148" fill="#4ADE80" font-family="Inter" font-size="11">tarefas concluídas</text>
            </g>
            <!-- Chart área -->
            <g>
              <rect x="20" y="180" width="510" height="270" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="34" y="198" width="140" height="10" rx="3" fill="#A8BDD4" opacity=".75"/>
              <polyline points="34,400 100,360 160,330 220,295 280,310 340,260 400,235 460,250 520,210"
                fill="none" stroke="#5BBCE0" stroke-width="2.5"/>
              <polyline points="34,400 100,360 160,330 220,295 280,310 340,260 400,235 460,250 520,210 520,430 34,430"
                fill="#5BBCE0" fill-opacity=".10" stroke="none"/>
              <g fill="#1A3A5C" opacity=".4">
                <line x1="34" y1="260" x2="520" y2="260" stroke="#1A3A5C"/>
                <line x1="34" y1="330" x2="520" y2="330" stroke="#1A3A5C"/>
                <line x1="34" y1="400" x2="520" y2="400" stroke="#1A3A5C"/>
              </g>
            </g>
            <!-- Lista lateral -->
            <g>
              <rect x="540" y="180" width="240" height="270" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="554" y="198" width="120" height="10" rx="3" fill="#A8BDD4" opacity=".75"/>
              <g>
                <rect x="554" y="222" width="212" height="46" rx="8" fill="#081526"/>
                <circle cx="572" cy="245" r="6" fill="#5BBCE0"/>
                <rect x="586" y="234" width="140" height="8" rx="2" fill="#A8BDD4" opacity=".8"/>
                <rect x="586" y="250" width="100" height="6" rx="2" fill="#6B7887"/>

                <rect x="554" y="276" width="212" height="46" rx="8" fill="#081526"/>
                <circle cx="572" cy="299" r="6" fill="#F59E0B"/>
                <rect x="586" y="288" width="150" height="8" rx="2" fill="#A8BDD4" opacity=".8"/>
                <rect x="586" y="304" width="92"  height="6" rx="2" fill="#6B7887"/>

                <rect x="554" y="330" width="212" height="46" rx="8" fill="#081526"/>
                <circle cx="572" cy="353" r="6" fill="#4ADE80"/>
                <rect x="586" y="342" width="120" height="8" rx="2" fill="#A8BDD4" opacity=".8"/>
                <rect x="586" y="358" width="86"  height="6" rx="2" fill="#6B7887"/>

                <rect x="554" y="384" width="212" height="46" rx="8" fill="#081526"/>
                <circle cx="572" cy="407" r="6" fill="#5BBCE0"/>
                <rect x="586" y="396" width="130" height="8" rx="2" fill="#A8BDD4" opacity=".8"/>
                <rect x="586" y="412" width="78"  height="6" rx="2" fill="#6B7887"/>
              </g>
            </g>
          </svg>
        </div>

        <!-- Processos -->
        <div class="lp-tab-pane" data-pane="processos">
          <svg viewBox="0 0 800 480" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="800" height="40" rx="6" fill="#060D1A"/>
            <rect x="16" y="14" width="100" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <!-- Filtros -->
            <rect x="20" y="60" width="240" height="38" rx="8" fill="#0D1E35" stroke="#1A3A5C"/>
            <rect x="34" y="74" width="110" height="10" rx="3" fill="#6B7887"/>
            <rect x="270" y="60" width="120" height="38" rx="8" fill="#1E3A5F" stroke="#244E7A"/>
            <rect x="284" y="74" width="60" height="10" rx="3" fill="#A8BDD4" opacity=".85"/>
            <rect x="660" y="60" width="120" height="38" rx="8" fill="#244E7A"/>
            <text x="688" y="83" fill="#F1F5FA" font-family="Inter" font-size="12" font-weight="600">Novo processo</text>
            <!-- Tabela header -->
            <rect x="20" y="116" width="760" height="32" rx="6" fill="#060D1A"/>
            <text x="36" y="136" fill="#6B7887" font-family="Inter" font-size="11" font-weight="600">Nº PROCESSO</text>
            <text x="190" y="136" fill="#6B7887" font-family="Inter" font-size="11" font-weight="600">CLIENTE</text>
            <text x="380" y="136" fill="#6B7887" font-family="Inter" font-size="11" font-weight="600">RESPONSÁVEL</text>
            <text x="560" y="136" fill="#6B7887" font-family="Inter" font-size="11" font-weight="600">STATUS</text>
            <text x="690" y="136" fill="#6B7887" font-family="Inter" font-size="11" font-weight="600">PRAZO</text>
            <!-- Linhas -->
            <g font-family="Inter">
              <rect x="20"  y="158" width="760" height="44" rx="6" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36"  y="184" fill="#A8BDD4" font-size="11">0001234-56.2026.8.26.0100</text>
              <text x="190" y="184" fill="#E2EAF2" font-size="12" font-weight="500">Construtora Aurora S/A</text>
              <text x="380" y="184" fill="#A8BDD4" font-size="11">Dra. Mariana Costa</text>
              <rect x="560" y="170" width="80"  height="22" rx="11" fill="#1E4A3A"/>
              <text x="585" y="185" fill="#6EE7A0" font-size="10" font-weight="600">Em andamento</text>
              <text x="690" y="184" fill="#A8BDD4" font-size="11">07 dias</text>

              <rect x="20"  y="208" width="760" height="44" rx="6" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36"  y="234" fill="#A8BDD4" font-size="11">5008912-34.2025.4.03.6100</text>
              <text x="190" y="234" fill="#E2EAF2" font-size="12" font-weight="500">M. R. Indústria Ltda</text>
              <text x="380" y="234" fill="#A8BDD4" font-size="11">Dr. Felipe Andrade</text>
              <rect x="560" y="220" width="80"  height="22" rx="11" fill="#3D3010"/>
              <text x="592" y="235" fill="#FCD34D" font-size="10" font-weight="600">Aguardando</text>
              <text x="690" y="234" fill="#F59E0B" font-size="11">2 dias</text>

              <rect x="20"  y="258" width="760" height="44" rx="6" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36"  y="284" fill="#A8BDD4" font-size="11">1234567-89.2026.5.02.0011</text>
              <text x="190" y="284" fill="#E2EAF2" font-size="12" font-weight="500">João Pereira Silva</text>
              <text x="380" y="284" fill="#A8BDD4" font-size="11">Dra. Carolina Mendes</text>
              <rect x="560" y="270" width="80"  height="22" rx="11" fill="#1E4A3A"/>
              <text x="585" y="285" fill="#6EE7A0" font-size="10" font-weight="600">Em andamento</text>
              <text x="690" y="284" fill="#A8BDD4" font-size="11">15 dias</text>

              <rect x="20"  y="308" width="760" height="44" rx="6" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36"  y="334" fill="#A8BDD4" font-size="11">0007654-32.2024.8.19.0001</text>
              <text x="190" y="334" fill="#E2EAF2" font-size="12" font-weight="500">Editora Vértice S/A</text>
              <text x="380" y="334" fill="#A8BDD4" font-size="11">Dr. Rafael Toledo</text>
              <rect x="560" y="320" width="80"  height="22" rx="11" fill="#3A1020"/>
              <text x="595" y="335" fill="#F0B8C2" font-size="10" font-weight="600">Urgente</text>
              <text x="690" y="334" fill="#EF4444" font-size="11">Hoje</text>

              <rect x="20"  y="358" width="760" height="44" rx="6" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36"  y="384" fill="#A8BDD4" font-size="11">0002345-67.2026.8.26.0224</text>
              <text x="190" y="384" fill="#E2EAF2" font-size="12" font-weight="500">Fundação Horizonte</text>
              <text x="380" y="384" fill="#A8BDD4" font-size="11">Dra. Patrícia Lima</text>
              <rect x="560" y="370" width="80"  height="22" rx="11" fill="#1E4A3A"/>
              <text x="585" y="385" fill="#6EE7A0" font-size="10" font-weight="600">Em andamento</text>
              <text x="690" y="384" fill="#A8BDD4" font-size="11">22 dias</text>
            </g>
          </svg>
        </div>

        <!-- Tarefas (kanban) -->
        <div class="lp-tab-pane" data-pane="tarefas">
          <svg viewBox="0 0 800 480" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="800" height="40" rx="6" fill="#060D1A"/>
            <rect x="16" y="14" width="100" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <!-- Colunas Kanban -->
            <g font-family="Inter">
              <!-- A fazer -->
              <rect x="20"  y="60" width="180" height="400" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <text x="36"  y="86" fill="#A8BDD4" font-size="12" font-weight="600">A fazer</text>
              <rect x="160" y="72" width="28" height="16" rx="8" fill="#1E3A5F"/>
              <text x="170" y="84" fill="#5BBCE0" font-size="10" font-weight="600">4</text>
              <rect x="32"  y="100" width="156" height="76" rx="8" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <rect x="44"  y="114" width="80"  height="6"  rx="2" fill="#5BBCE0" opacity=".6"/>
              <rect x="44"  y="128" width="130" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="44"  y="142" width="100" height="6"  rx="2" fill="#6B7887"/>
              <circle cx="50" cy="164" r="8" fill="#244E7A"/>
              <rect x="64"  y="160" width="60" height="6" rx="2" fill="#6B7887"/>

              <rect x="32"  y="186" width="156" height="76" rx="8" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <rect x="44"  y="200" width="60"  height="6"  rx="2" fill="#F59E0B" opacity=".7"/>
              <rect x="44"  y="214" width="110" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="44"  y="228" width="90"  height="6"  rx="2" fill="#6B7887"/>
              <circle cx="50" cy="250" r="8" fill="#244E7A"/>
              <rect x="64"  y="246" width="50" height="6" rx="2" fill="#6B7887"/>

              <!-- Em andamento -->
              <rect x="210" y="60" width="180" height="400" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <text x="226" y="86" fill="#A8BDD4" font-size="12" font-weight="600">Em andamento</text>
              <rect x="356" y="72" width="28" height="16" rx="8" fill="#1E3A5F"/>
              <text x="366" y="84" fill="#5BBCE0" font-size="10" font-weight="600">3</text>
              <rect x="222" y="100" width="156" height="84" rx="8" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <rect x="234" y="114" width="70"  height="6"  rx="2" fill="#4ADE80" opacity=".7"/>
              <rect x="234" y="128" width="130" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="234" y="142" width="100" height="6"  rx="2" fill="#6B7887"/>
              <rect x="234" y="156" width="80"  height="6"  rx="2" fill="#6B7887"/>
              <circle cx="240" cy="174" r="8" fill="#244E7A"/>
              <circle cx="254" cy="174" r="8" fill="#1A3A5C" stroke="#244E7A" stroke-width="2"/>

              <rect x="222" y="194" width="156" height="76" rx="8" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <rect x="234" y="208" width="60"  height="6"  rx="2" fill="#EF4444" opacity=".7"/>
              <rect x="234" y="222" width="120" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="234" y="236" width="90"  height="6"  rx="2" fill="#6B7887"/>
              <circle cx="240" cy="258" r="8" fill="#244E7A"/>

              <!-- Em revisão -->
              <rect x="400" y="60" width="180" height="400" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <text x="416" y="86" fill="#A8BDD4" font-size="12" font-weight="600">Em revisão</text>
              <rect x="546" y="72" width="28" height="16" rx="8" fill="#1E3A5F"/>
              <text x="556" y="84" fill="#5BBCE0" font-size="10" font-weight="600">2</text>
              <rect x="412" y="100" width="156" height="76" rx="8" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <rect x="424" y="114" width="50"  height="6"  rx="2" fill="#FCD34D" opacity=".7"/>
              <rect x="424" y="128" width="125" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="424" y="142" width="100" height="6"  rx="2" fill="#6B7887"/>
              <circle cx="430" cy="164" r="8" fill="#244E7A"/>

              <!-- Concluído -->
              <rect x="590" y="60" width="190" height="400" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <text x="606" y="86" fill="#A8BDD4" font-size="12" font-weight="600">Concluído</text>
              <rect x="746" y="72" width="28" height="16" rx="8" fill="#1E4A3A"/>
              <text x="756" y="84" fill="#6EE7A0" font-size="10" font-weight="600">8</text>
              <rect x="602" y="100" width="166" height="66" rx="8" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4" opacity=".7"/>
              <rect x="614" y="114" width="70"  height="6"  rx="2" fill="#4ADE80" opacity=".5"/>
              <rect x="614" y="128" width="130" height="8"  rx="2" fill="#A8BDD4" opacity=".5"/>
              <rect x="614" y="142" width="90"  height="6"  rx="2" fill="#6B7887" opacity=".7"/>

              <rect x="602" y="176" width="166" height="66" rx="8" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4" opacity=".7"/>
              <rect x="614" y="190" width="60"  height="6"  rx="2" fill="#4ADE80" opacity=".5"/>
              <rect x="614" y="204" width="130" height="8"  rx="2" fill="#A8BDD4" opacity=".5"/>
              <rect x="614" y="218" width="80"  height="6"  rx="2" fill="#6B7887" opacity=".7"/>
            </g>
          </svg>
        </div>

        <!-- Intimações -->
        <div class="lp-tab-pane" data-pane="intimacoes">
          <svg viewBox="0 0 800 480" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="800" height="40" rx="6" fill="#060D1A"/>
            <rect x="16" y="14" width="100" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <!-- KPIs -->
            <g>
              <rect x="20"  y="60" width="180" height="68" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="34"  y="86" fill="#6B7887" font-family="Inter" font-size="11">Não lidas</text>
              <text x="34"  y="116" fill="#EF4444" font-family="Inter" font-size="24" font-weight="700">7</text>

              <rect x="210" y="60" width="180" height="68" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="224" y="86" fill="#6B7887" font-family="Inter" font-size="11">Hoje</text>
              <text x="224" y="116" fill="#F59E0B" font-family="Inter" font-size="24" font-weight="700">3</text>

              <rect x="400" y="60" width="180" height="68" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="414" y="86" fill="#6B7887" font-family="Inter" font-size="11">Esta semana</text>
              <text x="414" y="116" fill="#5BBCE0" font-family="Inter" font-size="24" font-weight="700">18</text>

              <rect x="590" y="60" width="190" height="68" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="604" y="86" fill="#6B7887" font-family="Inter" font-size="11">Vinculadas a processo</text>
              <text x="604" y="116" fill="#4ADE80" font-family="Inter" font-size="24" font-weight="700">12</text>
            </g>
            <!-- Cards de intimação -->
            <g font-family="Inter">
              <rect x="20" y="148" width="760" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="32" y="160" width="80"  height="20" rx="10" fill="#3A1020"/>
              <text x="60" y="174" fill="#F0B8C2" font-size="10" font-weight="600">Urgente</text>
              <text x="124" y="174" fill="#A8BDD4" font-size="11">TJ-SP · 1ª Vara Cível · 26/05/2026</text>
              <rect x="32" y="190" width="500" height="10" rx="3" fill="#E2EAF2" opacity=".85"/>
              <rect x="32" y="208" width="700" height="8"  rx="2" fill="#6B7887"/>
              <rect x="32" y="222" width="660" height="8"  rx="2" fill="#6B7887"/>
              <rect x="610" y="200" width="156" height="38" rx="8" fill="#244E7A"/>
              <text x="640" y="223" fill="#F1F5FA" font-size="11" font-weight="600">Vincular processo</text>

              <rect x="20" y="258" width="760" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="32" y="270" width="80"  height="20" rx="10" fill="#1E4A3A"/>
              <text x="56" y="284" fill="#6EE7A0" font-size="10" font-weight="600">Vinculada</text>
              <text x="124" y="284" fill="#A8BDD4" font-size="11">TRT-2 · 5ª Vara · 24/05/2026 · Processo 1234567-89.2026</text>
              <rect x="32" y="300" width="450" height="10" rx="3" fill="#E2EAF2" opacity=".85"/>
              <rect x="32" y="318" width="700" height="8"  rx="2" fill="#6B7887"/>
              <rect x="32" y="332" width="600" height="8"  rx="2" fill="#6B7887"/>

              <rect x="20" y="368" width="760" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="32" y="380" width="80"  height="20" rx="10" fill="#3D3010"/>
              <text x="60" y="394" fill="#FCD34D" font-size="10" font-weight="600">Pendente</text>
              <text x="124" y="394" fill="#A8BDD4" font-size="11">TJ-RJ · 2ª Vara Empresarial · 25/05/2026</text>
              <rect x="32" y="410" width="420" height="10" rx="3" fill="#E2EAF2" opacity=".85"/>
              <rect x="32" y="428" width="680" height="8"  rx="2" fill="#6B7887"/>
              <rect x="32" y="442" width="580" height="8"  rx="2" fill="#6B7887"/>
              <rect x="610" y="420" width="156" height="38" rx="8" fill="#244E7A"/>
              <text x="640" y="443" fill="#F1F5FA" font-size="11" font-weight="600">Vincular processo</text>
            </g>
          </svg>
        </div>

        <!-- Comunicação -->
        <div class="lp-tab-pane" data-pane="comunicacao">
          <svg viewBox="0 0 800 480" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="800" height="40" rx="6" fill="#060D1A"/>
            <rect x="16" y="14" width="100" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <!-- Lista conversas -->
            <g font-family="Inter">
              <rect x="20" y="60" width="260" height="400" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <rect x="34" y="76" width="232" height="34" rx="8" fill="#0D1E35"/>
              <rect x="48" y="86" width="120" height="8" rx="2" fill="#6B7887"/>

              <rect x="34" y="120" width="232" height="62" rx="8" fill="#1E3A5F"/>
              <circle cx="58" cy="151" r="16" fill="#244E7A"/>
              <text x="58" y="156" fill="#F1F5FA" font-family="Inter" font-size="13" font-weight="600" text-anchor="middle">AC</text>
              <rect x="82" y="138" width="120" height="9" rx="2" fill="#F1F5FA"/>
              <rect x="82" y="154" width="160" height="7" rx="2" fill="#A8BDD4" opacity=".8"/>
              <text x="82" y="174" fill="#6B7887" font-family="Inter" font-size="9">14:32</text>

              <rect x="34" y="192" width="232" height="62" rx="8" fill="#0D1E35"/>
              <circle cx="58" cy="223" r="16" fill="#3D6A96"/>
              <text x="58" y="228" fill="#F1F5FA" font-family="Inter" font-size="13" font-weight="600" text-anchor="middle">MR</text>
              <rect x="82" y="210" width="100" height="9" rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="82" y="226" width="140" height="7" rx="2" fill="#6B7887"/>
              <circle cx="252" cy="218" r="8" fill="#4ADE80"/>
              <text x="252" y="222" fill="#0A1628" font-family="Inter" font-size="9" font-weight="700" text-anchor="middle">3</text>

              <rect x="34" y="264" width="232" height="62" rx="8" fill="#0D1E35"/>
              <circle cx="58" cy="295" r="16" fill="#5BBCE0"/>
              <text x="58" y="300" fill="#0A1628" font-family="Inter" font-size="13" font-weight="600" text-anchor="middle">JS</text>
              <rect x="82" y="282" width="110" height="9" rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="82" y="298" width="130" height="7" rx="2" fill="#6B7887"/>

              <rect x="34" y="336" width="232" height="62" rx="8" fill="#0D1E35"/>
              <circle cx="58" cy="367" r="16" fill="#A8BDD4"/>
              <text x="58" y="372" fill="#0A1628" font-family="Inter" font-size="13" font-weight="600" text-anchor="middle">FA</text>
              <rect x="82" y="354" width="115" height="9" rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="82" y="370" width="120" height="7" rx="2" fill="#6B7887"/>
            </g>
            <!-- Chat -->
            <g font-family="Inter">
              <rect x="290" y="60" width="490" height="400" rx="10" fill="#081526" stroke="#1A3A5C"/>
              <!-- header -->
              <rect x="290" y="60" width="490" height="48" rx="10" fill="#0D1E35"/>
              <circle cx="316" cy="84" r="14" fill="#244E7A"/>
              <text x="316" y="89" fill="#F1F5FA" font-family="Inter" font-size="12" font-weight="600" text-anchor="middle">AC</text>
              <rect x="338" y="74" width="140" height="10" rx="3" fill="#F1F5FA"/>
              <rect x="338" y="90" width="80"  height="7"  rx="2" fill="#6EE7A0"/>
              <!-- mensagens -->
              <g>
                <rect x="310" y="130" width="240" height="40" rx="10" fill="#0D1E35"/>
                <rect x="322" y="142" width="180" height="8" rx="2" fill="#E2EAF2" opacity=".85"/>
                <rect x="322" y="156" width="120" height="7" rx="2" fill="#A8BDD4" opacity=".75"/>

                <rect x="500" y="184" width="260" height="50" rx="10" fill="#1E3A5F"/>
                <rect x="512" y="196" width="200" height="8" rx="2" fill="#F1F5FA"/>
                <rect x="512" y="210" width="160" height="7" rx="2" fill="#A8BDD4" opacity=".9"/>
                <rect x="512" y="222" width="60"  height="6" rx="2" fill="#6EE7A0"/>

                <rect x="310" y="248" width="200" height="40" rx="10" fill="#0D1E35"/>
                <rect x="322" y="260" width="140" height="8" rx="2" fill="#E2EAF2" opacity=".85"/>
                <rect x="322" y="274" width="100" height="7" rx="2" fill="#A8BDD4" opacity=".75"/>

                <rect x="500" y="302" width="260" height="58" rx="10" fill="#1E3A5F"/>
                <rect x="512" y="314" width="200" height="8" rx="2" fill="#F1F5FA"/>
                <rect x="512" y="328" width="220" height="7" rx="2" fill="#A8BDD4" opacity=".9"/>
                <rect x="512" y="340" width="140" height="7" rx="2" fill="#A8BDD4" opacity=".9"/>
              </g>
              <!-- Input -->
              <rect x="306" y="408" width="458" height="40" rx="20" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="322" y="422" width="180" height="10" rx="3" fill="#6B7887"/>
              <circle cx="744" cy="428" r="14" fill="#244E7A"/>
            </g>
          </svg>
        </div>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero ver o Yuris rodando!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Quero ver o sistema rodando
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     3. DORES DO ADVOGADO
     ════════════════════════════════════════════════════════════════════════ -->
<section id="dores" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Por que existir</span>
      <h2>Quando a operação jurídica cresce sem sistema, o controle se perde.</h2>
      <p>Com o Yuris, cada processo, tarefa, intimação e atendimento ganha contexto, responsável e histórico.</p>
    </div>

    <div class="lp-grid lp-grid-4 lp-reveal">
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <h3>Prazos espalhados em planilhas</h3>
        <p>Várias abas, várias datas e nenhum responsável claro pelo que vence amanhã.</p>
      </div>
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <h3>Intimações sem rastreabilidade</h3>
        <p>Publicação recebida, processada por quem? Vinculada a qual processo? Ninguém sabe.</p>
      </div>
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
        <h3>Processos sem histórico claro</h3>
        <p>Sem registro de quem editou o quê e quando, qualquer questionamento vira caça ao tesouro.</p>
      </div>
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></div>
        <h3>Clientes perdidos no WhatsApp</h3>
        <p>Conversa importante perdida no privado de um celular pessoal — e o histórico se foi.</p>
      </div>
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
        <h3>Equipe sem visibilidade</h3>
        <p>Quem está fazendo o quê? Quanto está em aberto? A gestão vê o tempo passar, não o trabalho.</p>
      </div>
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></div>
        <h3>Matriz e filial sem controle</h3>
        <p>Cada unidade trabalha do seu jeito, sem padrão e sem o que a matriz precisa enxergar.</p>
      </div>
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        <h3>Falta de auditoria</h3>
        <p>Em qualquer questionamento — interno ou externo — não há registro confiável do que aconteceu.</p>
      </div>
      <div class="lp-pain lp-card">
        <div class="lp-pain-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></div>
        <h3>Retrabalho na operação</h3>
        <p>Mesma informação digitada três vezes em três sistemas. O tempo do advogado merece mais.</p>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero resolver essas dores no meu escritório com o Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Resolver isso no meu escritório
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     4. MÓDULOS PRINCIPAIS
     ════════════════════════════════════════════════════════════════════════ -->
<section id="recursos" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Módulos</span>
      <h2>Tudo o que o escritório jurídico precisa, em um só lugar.</h2>
      <p>Cada módulo foi pensado para uma frente da operação — e todos conversam entre si por padrão.</p>
    </div>

    <div class="lp-grid lp-grid-3 lp-reveal">
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></div>
        <h3>Operação</h3>
        <p>Planejamento, prospecção e tarefas com responsáveis e prazos.</p>
        <ul><li>Planejamento</li><li>Prospecção</li><li>Tarefas com kanban</li></ul>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
        <h3>Jurídico</h3>
        <p>Processos com histórico, prazos, vínculos e auditoria de ações.</p>
        <ul><li>Processos e movimentações</li><li>Intimações e prazos</li><li>Histórico processual</li><li>Vínculos entre advogados</li></ul>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></div>
        <h3>Comunicação</h3>
        <p>Atendimento centralizado, mensagens com contexto e histórico ligado ao cliente.</p>
        <ul><li>WhatsApp</li><li>Chat interno</li><li>Atendimento</li><li>Histórico de conversas</li></ul>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <h3>Gestão</h3>
        <p>Usuários, advogados, matriz e filial com permissões por escopo.</p>
        <ul><li>Usuários e advogados</li><li>Matriz e filial</li><li>Permissões granulares</li><li>Equipe</li></ul>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/><path d="m13 10 3-3-3-3"/><path d="M7.07 7.07A8.35 8.35 0 0 1 16 6c1.55 0 3 .43 4.23 1.17"/></svg></div>
        <h3>Automações</h3>
        <p>Webhooks com HMAC, eventos internos e integração com n8n, Make e Zapier.</p>
        <ul><li>Webhooks por evento</li><li>Integrações externas</li><li>Fluxos automatizados</li></ul>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/></svg></div>
        <h3>Segurança</h3>
        <p>Logs de auditoria, controle de acesso, 2FA e recursos voltados à LGPD.</p>
        <ul><li>LGPD &middot; Privacy Center</li><li>Auditoria imutável</li><li>2FA opcional</li><li>Isolamento multi-tenant</li></ul>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero conhecer todos os módulos do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Conhecer todos os módulos
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     5. VITRINE INTERATIVA
     ════════════════════════════════════════════════════════════════════════ -->
<section id="funcionalidades" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Funcionalidades</span>
      <h2>Cada peça da rotina, no lugar certo.</h2>
      <p>Clique numa funcionalidade para ver como ela aparece dentro do sistema.</p>
    </div>

    <div class="lp-vitrine lp-reveal" data-tabs="vitrine">
      <div class="lp-vitrine-list">
        <button type="button" class="lp-vit-item active" data-target="v-processos">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Processos</span>
          <p>Organize processos com histórico, responsáveis, vínculos e visão clara de andamento.</p>
        </button>
        <button type="button" class="lp-vit-item" data-target="v-intimacoes">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Intimações</span>
          <p>Centralize intimações, origem da consulta, OAB vinculada e associação ao processo.</p>
        </button>
        <button type="button" class="lp-vit-item" data-target="v-tarefas">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Tarefas e prazos</span>
          <p>Transforme prazos em tarefas rastreáveis, com responsáveis, status e alertas.</p>
        </button>
        <button type="button" class="lp-vit-item" data-target="v-prospeccao">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l7 8v6l6 4v-10l7-8z"/></svg>Prospecção</span>
          <p>Acompanhe oportunidades, leads e clientes em um funil visual para a operação jurídica.</p>
        </button>
        <button type="button" class="lp-vit-item" data-target="v-comunicacao">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>Comunicação</span>
          <p>Centralize conversas e mantenha o histórico conectado ao cliente ou processo.</p>
        </button>
        <button type="button" class="lp-vit-item" data-target="v-automacoes">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/></svg>Automações</span>
          <p>Dispare eventos internos para n8n, Make, Zapier ou robôs próprios por meio de webhooks.</p>
        </button>
        <button type="button" class="lp-vit-item" data-target="v-lgpd">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/></svg>LGPD e auditoria</span>
          <p>Registre ações críticas, consentimentos, solicitações e logs para uma operação mais segura.</p>
        </button>
        <button type="button" class="lp-vit-item" data-target="v-matriz">
          <span class="lp-vit-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>Matriz e filial</span>
          <p>Separe unidades, filiais, advogados e permissões sem perder a visão gerencial.</p>
        </button>
      </div>

      <div class="lp-vitrine-mockup">
        <div class="lp-vit-pane active" data-pane="v-processos">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="180" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <rect x="20" y="56" width="560" height="48" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
            <rect x="36" y="72" width="120" height="10" rx="3" fill="#5BBCE0" opacity=".7"/>
            <rect x="36" y="88" width="260" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
            <rect x="480" y="68" width="80" height="24" rx="12" fill="#1E4A3A"/>
            <text x="520" y="84" fill="#6EE7A0" font-family="Inter" font-size="10" font-weight="600" text-anchor="middle">Em andamento</text>
            <rect x="20" y="120" width="560" height="68" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
            <text x="36" y="140" fill="#6B7887" font-family="Inter" font-size="10" font-weight="600">HISTÓRICO</text>
            <rect x="36" y="150" width="380" height="8" rx="2" fill="#A8BDD4" opacity=".7"/>
            <rect x="36" y="166" width="320" height="7" rx="2" fill="#6B7887"/>
            <rect x="20" y="204" width="560" height="68" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
            <text x="36" y="224" fill="#6B7887" font-family="Inter" font-size="10" font-weight="600">VÍNCULOS</text>
            <circle cx="42"  cy="248" r="10" fill="#244E7A"/>
            <circle cx="62"  cy="248" r="10" fill="#3D6A96"/>
            <circle cx="82"  cy="248" r="10" fill="#5BBCE0"/>
            <rect x="100" y="244" width="180" height="8" rx="2" fill="#A8BDD4" opacity=".7"/>
            <rect x="20" y="288" width="560" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
            <text x="36" y="308" fill="#6B7887" font-family="Inter" font-size="10" font-weight="600">PRAZOS</text>
            <rect x="36" y="322" width="200" height="8" rx="2" fill="#F59E0B" opacity=".8"/>
            <rect x="36" y="338" width="280" height="7" rx="2" fill="#6B7887"/>
          </svg>
        </div>
        <div class="lp-vit-pane" data-pane="v-intimacoes">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="180" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <rect x="20" y="56" width="560" height="110" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
            <rect x="36" y="72" width="80" height="20" rx="10" fill="#3A1020"/>
            <text x="76" y="86" fill="#F0B8C2" font-family="Inter" font-size="10" font-weight="600" text-anchor="middle">Urgente</text>
            <rect x="128" y="76" width="240" height="10" rx="3" fill="#A8BDD4" opacity=".75"/>
            <rect x="36" y="102" width="500" height="10" rx="3" fill="#E2EAF2" opacity=".9"/>
            <rect x="36" y="120" width="540" height="8"  rx="2" fill="#6B7887"/>
            <rect x="36" y="136" width="500" height="8"  rx="2" fill="#6B7887"/>
            <rect x="20" y="184" width="560" height="110" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
            <rect x="36" y="200" width="80" height="20" rx="10" fill="#1E4A3A"/>
            <text x="76" y="214" fill="#6EE7A0" font-family="Inter" font-size="10" font-weight="600" text-anchor="middle">Vinculada</text>
            <rect x="128" y="204" width="280" height="10" rx="3" fill="#A8BDD4" opacity=".75"/>
            <rect x="36" y="230" width="500" height="10" rx="3" fill="#E2EAF2" opacity=".9"/>
            <rect x="36" y="248" width="540" height="8"  rx="2" fill="#6B7887"/>
            <rect x="36" y="264" width="500" height="8"  rx="2" fill="#6B7887"/>
            <rect x="20" y="312" width="270" height="64" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
            <text x="36" y="332" fill="#6B7887" font-family="Inter" font-size="10" font-weight="600">OAB CONSULTADA</text>
            <rect x="36" y="346" width="160" height="10" rx="2" fill="#5BBCE0" opacity=".8"/>
            <rect x="310" y="312" width="270" height="64" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
            <text x="326" y="332" fill="#6B7887" font-family="Inter" font-size="10" font-weight="600">PRAZO PROCESSUAL</text>
            <rect x="326" y="346" width="100" height="10" rx="2" fill="#F59E0B" opacity=".8"/>
          </svg>
        </div>
        <div class="lp-vit-pane" data-pane="v-tarefas">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="120" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <g font-family="Inter">
              <rect x="20"  y="56" width="180" height="320" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <text x="34"  y="80" fill="#A8BDD4" font-size="11" font-weight="600">A fazer</text>
              <rect x="34"  y="92"  width="152" height="68" rx="8" fill="#0D1E35"/>
              <rect x="46"  y="106" width="80"  height="6"  rx="2" fill="#5BBCE0" opacity=".6"/>
              <rect x="46"  y="120" width="120" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="46"  y="134" width="80"  height="6"  rx="2" fill="#6B7887"/>
              <rect x="34"  y="172" width="152" height="68" rx="8" fill="#0D1E35"/>
              <rect x="46"  y="186" width="60"  height="6"  rx="2" fill="#F59E0B" opacity=".7"/>
              <rect x="46"  y="200" width="120" height="8"  rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="46"  y="214" width="80"  height="6"  rx="2" fill="#6B7887"/>

              <rect x="210" y="56" width="180" height="320" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <text x="224" y="80" fill="#A8BDD4" font-size="11" font-weight="600">Em andamento</text>
              <rect x="224" y="92"  width="152" height="78" rx="8" fill="#1E3A5F" stroke="#244E7A"/>
              <rect x="236" y="106" width="80"  height="6"  rx="2" fill="#6EE7A0" opacity=".8"/>
              <rect x="236" y="120" width="124" height="8"  rx="2" fill="#F1F5FA"/>
              <rect x="236" y="134" width="80"  height="6"  rx="2" fill="#A8BDD4" opacity=".7"/>
              <circle cx="240" cy="158" r="6" fill="#244E7A"/>
              <circle cx="252" cy="158" r="6" fill="#3D6A96"/>

              <rect x="400" y="56" width="180" height="320" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <text x="414" y="80" fill="#A8BDD4" font-size="11" font-weight="600">Concluído</text>
              <rect x="414" y="92"  width="152" height="68" rx="8" fill="#0D1E35" opacity=".7"/>
              <rect x="426" y="106" width="70"  height="6"  rx="2" fill="#4ADE80" opacity=".5"/>
              <rect x="426" y="120" width="120" height="8"  rx="2" fill="#A8BDD4" opacity=".55"/>
            </g>
          </svg>
        </div>
        <div class="lp-vit-pane" data-pane="v-prospeccao">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="120" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <!-- Funnel -->
            <g font-family="Inter">
              <polygon points="80,72 520,72 470,120 130,120" fill="#1E3A5F" stroke="#244E7A"/>
              <text x="300" y="103" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Leads · 142</text>
              <polygon points="130,128 470,128 432,176 168,176" fill="#244E7A" stroke="#3D6A96"/>
              <text x="300" y="158" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Em contato · 78</text>
              <polygon points="168,184 432,184 396,232 204,232" fill="#3D6A96" stroke="#5BBCE0"/>
              <text x="300" y="213" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Proposta · 34</text>
              <polygon points="204,240 396,240 358,288 242,288" fill="#5BBCE0"/>
              <text x="300" y="269" fill="#0A1628" font-size="13" font-weight="700" text-anchor="middle">Negociação · 18</text>
              <polygon points="242,296 358,296 320,344 280,344" fill="#4ADE80"/>
              <text x="300" y="325" fill="#0A1628" font-size="13" font-weight="700" text-anchor="middle">Cliente · 7</text>
            </g>
          </svg>
        </div>
        <div class="lp-vit-pane" data-pane="v-comunicacao">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="120" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <g font-family="Inter">
              <rect x="20" y="56" width="180" height="320" rx="10" fill="#0A1628" stroke="#1A3A5C"/>
              <rect x="34" y="72" width="152" height="50" rx="8" fill="#1E3A5F"/>
              <circle cx="50" cy="97" r="12" fill="#244E7A"/>
              <text x="50" y="101" fill="#F1F5FA" font-size="11" font-weight="600" text-anchor="middle">AC</text>
              <rect x="70" y="85" width="100" height="9" rx="2" fill="#F1F5FA"/>
              <rect x="70" y="100" width="80" height="6" rx="2" fill="#A8BDD4" opacity=".75"/>

              <rect x="34" y="130" width="152" height="50" rx="8" fill="#0D1E35"/>
              <circle cx="50" cy="155" r="12" fill="#3D6A96"/>
              <text x="50" y="159" fill="#F1F5FA" font-size="11" font-weight="600" text-anchor="middle">MR</text>
              <rect x="70" y="143" width="90" height="9" rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="70" y="158" width="100" height="6" rx="2" fill="#6B7887"/>

              <rect x="210" y="56" width="370" height="320" rx="10" fill="#081526" stroke="#1A3A5C"/>
              <rect x="226" y="76" width="210" height="34" rx="10" fill="#0D1E35"/>
              <rect x="238" y="86" width="170" height="7" rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="238" y="98" width="120" height="6" rx="2" fill="#A8BDD4" opacity=".7"/>

              <rect x="320" y="124" width="244" height="44" rx="10" fill="#1E3A5F"/>
              <rect x="332" y="134" width="200" height="7" rx="2" fill="#F1F5FA"/>
              <rect x="332" y="146" width="160" height="6" rx="2" fill="#A8BDD4" opacity=".9"/>
              <rect x="332" y="156" width="40"  height="5" rx="2" fill="#6EE7A0"/>

              <rect x="226" y="182" width="220" height="40" rx="10" fill="#0D1E35"/>
              <rect x="238" y="194" width="180" height="7" rx="2" fill="#E2EAF2" opacity=".85"/>
              <rect x="238" y="206" width="100" height="6" rx="2" fill="#A8BDD4" opacity=".7"/>

              <rect x="226" y="332" width="338" height="34" rx="17" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="240" y="346" width="160" height="7" rx="2" fill="#6B7887"/>
            </g>
          </svg>
        </div>
        <div class="lp-vit-pane" data-pane="v-automacoes">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="120" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <g font-family="Inter">
              <rect x="20" y="60" width="560" height="50" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="85" r="6" fill="#5BBCE0"/>
              <text x="60" y="79" fill="#E2EAF2" font-size="11" font-weight="600">card.criado</text>
              <rect x="60" y="86" width="280" height="7" rx="2" fill="#6B7887"/>
              <rect x="480" y="74" width="80" height="22" rx="11" fill="#1E4A3A"/>
              <text x="520" y="89" fill="#6EE7A0" font-size="10" font-weight="600" text-anchor="middle">200 OK</text>

              <rect x="20" y="120" width="560" height="50" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="145" r="6" fill="#5BBCE0"/>
              <text x="60" y="139" fill="#E2EAF2" font-size="11" font-weight="600">processo.atualizado</text>
              <rect x="60" y="146" width="280" height="7" rx="2" fill="#6B7887"/>
              <rect x="480" y="134" width="80" height="22" rx="11" fill="#1E4A3A"/>
              <text x="520" y="149" fill="#6EE7A0" font-size="10" font-weight="600" text-anchor="middle">200 OK</text>

              <rect x="20" y="180" width="560" height="50" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="205" r="6" fill="#F59E0B"/>
              <text x="60" y="199" fill="#E2EAF2" font-size="11" font-weight="600">tarefa.concluida</text>
              <rect x="60" y="206" width="280" height="7" rx="2" fill="#6B7887"/>
              <rect x="480" y="194" width="80" height="22" rx="11" fill="#3D3010"/>
              <text x="520" y="209" fill="#FCD34D" font-size="10" font-weight="600" text-anchor="middle">retry 2/5</text>

              <rect x="20" y="240" width="560" height="50" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="265" r="6" fill="#5BBCE0"/>
              <text x="60" y="259" fill="#E2EAF2" font-size="11" font-weight="600">intimacao.vinculada</text>
              <rect x="60" y="266" width="280" height="7" rx="2" fill="#6B7887"/>
              <rect x="480" y="254" width="80" height="22" rx="11" fill="#1E4A3A"/>
              <text x="520" y="269" fill="#6EE7A0" font-size="10" font-weight="600" text-anchor="middle">200 OK</text>

              <rect x="20" y="300" width="560" height="50" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="325" r="6" fill="#5BBCE0"/>
              <text x="60" y="319" fill="#E2EAF2" font-size="11" font-weight="600">lead.convertido</text>
              <rect x="60" y="326" width="280" height="7" rx="2" fill="#6B7887"/>
              <rect x="480" y="314" width="80" height="22" rx="11" fill="#1E4A3A"/>
              <text x="520" y="329" fill="#6EE7A0" font-size="10" font-weight="600" text-anchor="middle">200 OK</text>
            </g>
          </svg>
        </div>
        <div class="lp-vit-pane" data-pane="v-lgpd">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="120" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <g font-family="Inter">
              <rect x="20" y="60" width="270" height="140" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="36" y="86" fill="#A8BDD4" font-size="11" font-weight="600">CONSENTIMENTOS</text>
              <circle cx="42" cy="118" r="8" fill="#1E4A3A"/>
              <text x="56" y="123" fill="#6EE7A0" font-size="11">Termos de uso aceitos</text>
              <circle cx="42" cy="146" r="8" fill="#1E4A3A"/>
              <text x="56" y="151" fill="#6EE7A0" font-size="11">Política de privacidade</text>
              <circle cx="42" cy="174" r="8" fill="#3D3010"/>
              <text x="56" y="179" fill="#FCD34D" font-size="11">Cookies não essenciais</text>

              <rect x="310" y="60" width="270" height="140" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="326" y="86" fill="#A8BDD4" font-size="11" font-weight="600">LOGS DE AUDITORIA</text>
              <rect x="326" y="100" width="240" height="6" rx="2" fill="#5BBCE0" opacity=".7"/>
              <rect x="326" y="114" width="210" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>
              <rect x="326" y="128" width="230" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>
              <rect x="326" y="142" width="180" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>
              <rect x="326" y="156" width="220" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>
              <rect x="326" y="170" width="200" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>

              <rect x="20" y="220" width="560" height="156" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="36" y="246" fill="#A8BDD4" font-size="11" font-weight="600">SOLICITAÇÕES (LGPD)</text>
              <rect x="36" y="262" width="528" height="32" rx="6" fill="#081526"/>
              <text x="50" y="282" fill="#E2EAF2" font-size="11">Acesso aos dados &middot; Solicitação #1247</text>
              <rect x="490" y="270" width="60" height="16" rx="8" fill="#1E4A3A"/>
              <text x="520" y="282" fill="#6EE7A0" font-size="9" font-weight="600" text-anchor="middle">Atendida</text>
              <rect x="36" y="302" width="528" height="32" rx="6" fill="#081526"/>
              <text x="50" y="322" fill="#E2EAF2" font-size="11">Exclusão &middot; Solicitação #1241</text>
              <rect x="490" y="310" width="60" height="16" rx="8" fill="#3D3010"/>
              <text x="520" y="322" fill="#FCD34D" font-size="9" font-weight="600" text-anchor="middle">Em análise</text>
              <rect x="36" y="342" width="528" height="32" rx="6" fill="#081526"/>
              <text x="50" y="362" fill="#E2EAF2" font-size="11">Portabilidade &middot; Solicitação #1238</text>
              <rect x="490" y="350" width="60" height="16" rx="8" fill="#1E4A3A"/>
              <text x="520" y="362" fill="#6EE7A0" font-size="9" font-weight="600" text-anchor="middle">Atendida</text>
            </g>
          </svg>
        </div>
        <div class="lp-vit-pane" data-pane="v-matriz">
          <svg viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" rx="6" fill="#060D1A"/>
            <rect x="16" y="12" width="120" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
            <g font-family="Inter">
              <!-- Matriz -->
              <rect x="220" y="60" width="160" height="80" rx="14" fill="#1E3A5F" stroke="#3D6A96" stroke-width="2"/>
              <text x="300" y="92"  fill="#F1F5FA" font-size="13" font-weight="700" text-anchor="middle">Matriz</text>
              <text x="300" y="112" fill="#A8BDD4" font-size="11" text-anchor="middle">São Paulo · SP</text>
              <text x="300" y="128" fill="#5BBCE0" font-size="11" text-anchor="middle">12 advogados</text>
              <!-- Linhas -->
              <line x1="300" y1="140" x2="100" y2="220" stroke="#244E7A" stroke-width="1.5"/>
              <line x1="300" y1="140" x2="300" y2="220" stroke="#244E7A" stroke-width="1.5"/>
              <line x1="300" y1="140" x2="500" y2="220" stroke="#244E7A" stroke-width="1.5"/>
              <!-- Filiais -->
              <rect x="20"  y="220" width="160" height="76" rx="12" fill="#0D1E35" stroke="#244E7A"/>
              <text x="100" y="248" fill="#F1F5FA" font-size="12" font-weight="600" text-anchor="middle">Filial RJ</text>
              <text x="100" y="266" fill="#A8BDD4" font-size="10" text-anchor="middle">Rio de Janeiro</text>
              <text x="100" y="282" fill="#5BBCE0" font-size="10" text-anchor="middle">6 advogados</text>

              <rect x="220" y="220" width="160" height="76" rx="12" fill="#0D1E35" stroke="#244E7A"/>
              <text x="300" y="248" fill="#F1F5FA" font-size="12" font-weight="600" text-anchor="middle">Filial BH</text>
              <text x="300" y="266" fill="#A8BDD4" font-size="10" text-anchor="middle">Belo Horizonte</text>
              <text x="300" y="282" fill="#5BBCE0" font-size="10" text-anchor="middle">4 advogados</text>

              <rect x="420" y="220" width="160" height="76" rx="12" fill="#0D1E35" stroke="#244E7A"/>
              <text x="500" y="248" fill="#F1F5FA" font-size="12" font-weight="600" text-anchor="middle">Filial POA</text>
              <text x="500" y="266" fill="#A8BDD4" font-size="10" text-anchor="middle">Porto Alegre</text>
              <text x="500" y="282" fill="#5BBCE0" font-size="10" text-anchor="middle">3 advogados</text>
              <!-- Advogados associados -->
              <line x1="300" y1="296" x2="300" y2="324" stroke="#244E7A" stroke-width="1.5" stroke-dasharray="4,3"/>
              <rect x="180" y="324" width="240" height="52" rx="10" fill="#0A1628" stroke="#3D6A96" stroke-dasharray="4,3"/>
              <text x="300" y="346" fill="#A8BDD4" font-size="11" font-weight="600" text-anchor="middle">Advogados associados</text>
              <circle cx="220" cy="362" r="8" fill="#3D6A96"/>
              <circle cx="240" cy="362" r="8" fill="#244E7A"/>
              <circle cx="260" cy="362" r="8" fill="#5BBCE0"/>
              <circle cx="280" cy="362" r="8" fill="#3D6A96"/>
              <text x="300" y="366" fill="#6B7887" font-size="10" text-anchor="middle">+18</text>
            </g>
          </svg>
        </div>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero ver as funcionalidades do Yuris funcionando!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Quero testar as funcionalidades
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     6. JURÍDICO E PROCESSOS
     ════════════════════════════════════════════════════════════════════════ -->
<section id="juridico" class="lp-section">
  <div class="lp-container">
    <div class="lp-split lp-reveal">
      <div>
        <span class="lp-eyebrow">Jurídico</span>
        <h2>Cada processo precisa contar sua própria história.</h2>
        <p>
          No Yuris, processos não são apenas registros. Eles carregam histórico,
          responsáveis, vínculos, permissões e eventos importantes para que o
          escritório saiba exatamente o que aconteceu, quando aconteceu e quem
          realizou cada ação.
        </p>
        <ul class="lp-split-bullets">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Histórico processual com origem de cada ação</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Vínculos com advogados associados e badge de origem</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Permissões por escopo: matriz, filial e advogado</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Movimentações, documentos e auditoria integrados</li>
        </ul>
        <a href="<?= wa('Olá Bruno, quero organizar meus processos com o Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa lp-split-cta">
          <?= $waSvg ?>
          Quero organizar meus processos
        </a>
      </div>
      <div class="lp-mockup-frame">
        <div class="lp-mockup-card">
          <svg viewBox="0 0 600 420" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" fill="#060D1A"/>
            <rect x="16" y="12" width="200" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <g font-family="Inter">
              <rect x="20" y="56"  width="560" height="44" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="36" y="74"  width="200" height="10" rx="3" fill="#5BBCE0" opacity=".8"/>
              <rect x="240" y="76" width="40"  height="20" rx="10" fill="#1E4A3A"/>
              <text x="260" y="89" fill="#6EE7A0" font-size="9" font-weight="600" text-anchor="middle">ATIVO</text>

              <rect x="20" y="112" width="560" height="100" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36" y="134" fill="#6B7887" font-size="10" font-weight="600">HISTÓRICO</text>
              <g>
                <circle cx="44" cy="156" r="5" fill="#5BBCE0"/>
                <line x1="44" y1="161" x2="44" y2="200" stroke="#244E7A" stroke-width="1.2"/>
                <rect x="60" y="150" width="380" height="8" rx="2" fill="#E2EAF2" opacity=".85"/>
                <rect x="60" y="164" width="280" height="6" rx="2" fill="#6B7887"/>
                <text x="500" y="158" fill="#6B7887" font-size="9">Hoje</text>

                <circle cx="44" cy="186" r="5" fill="#244E7A"/>
                <rect x="60" y="180" width="320" height="8" rx="2" fill="#E2EAF2" opacity=".75"/>
                <rect x="60" y="194" width="240" height="6" rx="2" fill="#6B7887"/>
                <text x="500" y="188" fill="#6B7887" font-size="9">Ontem</text>
              </g>

              <rect x="20" y="224" width="270" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36" y="244" fill="#6B7887" font-size="10" font-weight="600">VÍNCULOS</text>
              <circle cx="42"  cy="270" r="10" fill="#244E7A"/>
              <circle cx="62"  cy="270" r="10" fill="#3D6A96"/>
              <circle cx="82"  cy="270" r="10" fill="#5BBCE0"/>
              <circle cx="102" cy="270" r="10" fill="#1A3A5C" stroke="#244E7A"/>
              <text x="105" y="274" fill="#A8BDD4" font-size="9" font-weight="600">+5</text>
              <rect x="36" y="290" width="200" height="6" rx="2" fill="#5BBCE0" opacity=".5"/>

              <rect x="310" y="224" width="270" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="326" y="244" fill="#6B7887" font-size="10" font-weight="600">PRAZOS</text>
              <rect x="326" y="258" width="180" height="10" rx="3" fill="#F59E0B" opacity=".8"/>
              <rect x="326" y="276" width="240" height="6"  rx="2" fill="#6B7887"/>
              <rect x="326" y="288" width="200" height="6"  rx="2" fill="#6B7887"/>

              <rect x="20" y="320" width="560" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36" y="340" fill="#6B7887" font-size="10" font-weight="600">AUDITORIA · ÚLTIMAS 24h</text>
              <rect x="36" y="354" width="380" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>
              <rect x="36" y="366" width="320" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>
              <rect x="36" y="378" width="280" height="6" rx="2" fill="#A8BDD4" opacity=".55"/>
            </g>
          </svg>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     7. INTIMAÇÕES, PRAZOS E TAREFAS
     ════════════════════════════════════════════════════════════════════════ -->
<section id="intimacoes" class="lp-section">
  <div class="lp-container">
    <div class="lp-split reversed lp-reveal">
      <div>
        <span class="lp-eyebrow">Intimações e prazos</span>
        <h2>Intimação recebida não pode virar informação perdida.</h2>
        <p>
          Associe intimações ao processo correto, transforme prazos em tarefas e
          acompanhe responsáveis sem depender de memória, prints ou conversas
          soltas.
        </p>
        <ul class="lp-split-bullets">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Monitoramento por OAB, nome e UF</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Vínculo direto ao processo correspondente</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Conversão de prazo em tarefa com responsável</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Status, alertas e visão do que vence quando</li>
        </ul>
        <a href="<?= wa('Olá Bruno, quero controlar intimações e prazos com o Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa lp-split-cta">
          <?= $waSvg ?>
          Quero controlar minhas intimações
        </a>
      </div>
      <div class="lp-mockup-frame">
        <div class="lp-mockup-card">
          <svg viewBox="0 0 600 420" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" fill="#060D1A"/>
            <rect x="16" y="12" width="200" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <g font-family="Inter">
              <rect x="20" y="56"  width="560" height="130" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="36" y="72"  width="76"  height="22" rx="11" fill="#3A1020"/>
              <text x="74" y="87" fill="#F0B8C2" font-size="11" font-weight="600" text-anchor="middle">Urgente</text>
              <rect x="124" y="76" width="280" height="12" rx="3" fill="#A8BDD4" opacity=".75"/>
              <rect x="36" y="108" width="500" height="11" rx="3" fill="#E2EAF2" opacity=".9"/>
              <rect x="36" y="128" width="540" height="8"  rx="2" fill="#6B7887"/>
              <rect x="36" y="144" width="500" height="8"  rx="2" fill="#6B7887"/>
              <rect x="36" y="160" width="460" height="8"  rx="2" fill="#6B7887"/>

              <rect x="20" y="200" width="180" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36" y="220" fill="#6B7887" font-size="10" font-weight="600">OAB</text>
              <text x="36" y="248" fill="#5BBCE0" font-size="16" font-weight="700">OAB/SP 123.456</text>
              <text x="36" y="266" fill="#A8BDD4" font-size="10">Dra. Mariana Costa</text>

              <rect x="210" y="200" width="180" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="226" y="220" fill="#6B7887" font-size="10" font-weight="600">PRAZO</text>
              <text x="226" y="248" fill="#F59E0B" font-size="16" font-weight="700">15 dias úteis</text>
              <text x="226" y="266" fill="#A8BDD4" font-size="10">Vence em 14/06/2026</text>

              <rect x="400" y="200" width="180" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="416" y="220" fill="#6B7887" font-size="10" font-weight="600">VÍNCULO</text>
              <text x="416" y="248" fill="#E2EAF2" font-size="13" font-weight="600">Processo 1234567</text>
              <rect x="416" y="260" width="60" height="18" rx="9" fill="#1E4A3A"/>
              <text x="446" y="272" fill="#6EE7A0" font-size="9" font-weight="600" text-anchor="middle">Vinculada</text>

              <rect x="20" y="300" width="560" height="100" rx="12" fill="#0D1E35" stroke="#1A3A5C" stroke-opacity=".4"/>
              <text x="36" y="322" fill="#6B7887" font-size="10" font-weight="600">AÇÕES SUGERIDAS</text>
              <rect x="36"  y="334" width="170" height="48" rx="8" fill="#244E7A"/>
              <text x="121" y="362" fill="#F1F5FA" font-size="11" font-weight="600" text-anchor="middle">Criar tarefa</text>
              <rect x="216" y="334" width="170" height="48" rx="8" fill="#1E3A5F"/>
              <text x="301" y="362" fill="#F1F5FA" font-size="11" font-weight="600" text-anchor="middle">Adicionar prazo</text>
              <rect x="396" y="334" width="170" height="48" rx="8" fill="#1E3A5F"/>
              <text x="481" y="362" fill="#F1F5FA" font-size="11" font-weight="600" text-anchor="middle">Marcar como lida</text>
            </g>
          </svg>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     8. COMUNICAÇÃO E WHATSAPP
     ════════════════════════════════════════════════════════════════════════ -->
<section id="comunicacao" class="lp-section">
  <div class="lp-container">
    <div class="lp-split lp-reveal">
      <div>
        <span class="lp-eyebrow">Comunicação</span>
        <h2>O cliente fala pelo WhatsApp. O escritório responde com organização.</h2>
        <p>
          O Yuris ajuda a transformar conversas em histórico útil, conectando
          atendimento, cliente, processo e equipe em um só lugar.
        </p>
        <ul class="lp-split-bullets">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Atendimento centralizado por advogado</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Conversas vinculadas ao cliente e ao processo</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Histórico preservado mesmo com troca de responsável</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Chat interno entre matriz, filial e equipe</li>
        </ul>
        <a href="<?= wa('Olá Bruno, quero centralizar o atendimento do meu escritório no Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa lp-split-cta">
          <?= $waSvg ?>
          Centralizar meu atendimento
        </a>
      </div>
      <div class="lp-mockup-frame">
        <div class="lp-mockup-card">
          <svg viewBox="0 0 600 420" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" fill="#060D1A"/>
            <rect x="16" y="12" width="200" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <g font-family="Inter">
              <rect x="20" y="56" width="560" height="340" rx="14" fill="#081526" stroke="#1A3A5C"/>
              <rect x="20" y="56" width="560" height="48" rx="14" fill="#0D1E35"/>
              <circle cx="46" cy="80" r="14" fill="#244E7A"/>
              <text x="46" y="84" fill="#F1F5FA" font-size="11" font-weight="600" text-anchor="middle">AC</text>
              <rect x="68" y="70" width="160" height="10" rx="3" fill="#F1F5FA"/>
              <rect x="68" y="86" width="80"  height="7"  rx="2" fill="#6EE7A0"/>

              <rect x="40"  y="124" width="240" height="44" rx="12" fill="#0D1E35"/>
              <rect x="52"  y="138" width="200" height="7"  rx="2" fill="#E2EAF2" opacity=".88"/>
              <rect x="52"  y="150" width="160" height="6"  rx="2" fill="#A8BDD4" opacity=".7"/>

              <rect x="280" y="178" width="280" height="58" rx="12" fill="#1E3A5F"/>
              <rect x="292" y="192" width="220" height="7" rx="2" fill="#F1F5FA"/>
              <rect x="292" y="206" width="200" height="6" rx="2" fill="#A8BDD4" opacity=".9"/>
              <rect x="292" y="218" width="60"  height="6" rx="2" fill="#6EE7A0"/>

              <rect x="40"  y="248" width="280" height="58" rx="12" fill="#0D1E35"/>
              <rect x="52"  y="262" width="220" height="7" rx="2" fill="#E2EAF2" opacity=".88"/>
              <rect x="52"  y="276" width="180" height="6" rx="2" fill="#A8BDD4" opacity=".7"/>
              <rect x="52"  y="288" width="120" height="6" rx="2" fill="#A8BDD4" opacity=".7"/>

              <rect x="40"  y="320" width="500" height="48" rx="24" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="60"  y="336" width="180" height="9" rx="2" fill="#6B7887"/>
              <circle cx="520" cy="344" r="16" fill="#244E7A"/>
            </g>
          </svg>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     9. OPERAÇÃO E PROSPECÇÃO
     ════════════════════════════════════════════════════════════════════════ -->
<section id="operacao" class="lp-section">
  <div class="lp-container">
    <div class="lp-split reversed lp-reveal">
      <div>
        <span class="lp-eyebrow">Operação</span>
        <h2>Da primeira conversa ao processo: acompanhe tudo.</h2>
        <p>
          Organize a entrada de novos clientes, acompanhe oportunidades e conecte
          a prospecção à operação jurídica sem perder contexto.
        </p>
        <ul class="lp-split-bullets">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Funil de prospecção com etapas customizáveis</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Cards de leads e clientes com responsável e origem</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Histórico de movimentação no funil</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Conversão direta em processo quando o cliente fecha</li>
        </ul>
        <a href="<?= wa('Olá Bruno, quero estruturar minha prospecção com o Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa lp-split-cta">
          <?= $waSvg ?>
          Estruturar minha prospecção
        </a>
      </div>
      <div class="lp-mockup-frame">
        <div class="lp-mockup-card">
          <svg viewBox="0 0 600 420" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" fill="#060D1A"/>
            <rect x="16" y="12" width="200" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <g font-family="Inter">
              <polygon points="40,72 560,72 510,128 90,128" fill="#1E3A5F" stroke="#244E7A"/>
              <text x="300" y="106" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Leads &middot; 142</text>
              <polygon points="90,134 510,134 470,190 130,190" fill="#244E7A" stroke="#3D6A96"/>
              <text x="300" y="168" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Em contato &middot; 78</text>
              <polygon points="130,196 470,196 432,252 168,252" fill="#3D6A96" stroke="#5BBCE0"/>
              <text x="300" y="230" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Proposta &middot; 34</text>
              <polygon points="168,258 432,258 396,314 204,314" fill="#5BBCE0"/>
              <text x="300" y="292" fill="#0A1628" font-size="13" font-weight="700" text-anchor="middle">Negociação &middot; 18</text>
              <polygon points="204,320 396,320 360,376 240,376" fill="#4ADE80"/>
              <text x="300" y="354" fill="#0A1628" font-size="13" font-weight="700" text-anchor="middle">Cliente &middot; 7</text>
            </g>
          </svg>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     10. GESTÃO DE EQUIPE, MATRIZ E FILIAL
     ════════════════════════════════════════════════════════════════════════ -->
<section id="gestao" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Gestão</span>
      <h2>Cada pessoa vê o que precisa. A gestão enxerga o que importa.</h2>
      <p>
        O Yuris foi pensado para operações com matriz, filiais, advogados e
        equipes diferentes, mantendo controle de acesso, identificação de
        origem e rastreabilidade.
      </p>
    </div>
    <div class="lp-hierarchy lp-reveal">
      <svg viewBox="0 0 1100 360" xmlns="http://www.w3.org/2000/svg" class="lp-hierarchy-svg">
        <g font-family="Inter">
          <rect x="450" y="20" width="200" height="84" rx="14" fill="#1E3A5F" stroke="#3D6A96" stroke-width="2"/>
          <text x="550" y="56" fill="#F1F5FA" font-size="15" font-weight="700" text-anchor="middle">Matriz</text>
          <text x="550" y="78" fill="#A8BDD4" font-size="11" text-anchor="middle">Visão consolidada · controle total</text>

          <line x1="550" y1="104" x2="150" y2="180" stroke="#244E7A" stroke-width="1.5"/>
          <line x1="550" y1="104" x2="550" y2="180" stroke="#244E7A" stroke-width="1.5"/>
          <line x1="550" y1="104" x2="950" y2="180" stroke="#244E7A" stroke-width="1.5"/>

          <rect x="60"   y="180" width="180" height="76" rx="12" fill="#0D1E35" stroke="#244E7A"/>
          <text x="150"  y="208" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Filial RJ</text>
          <text x="150"  y="228" fill="#A8BDD4" font-size="10" text-anchor="middle">Rio de Janeiro</text>
          <text x="150"  y="244" fill="#5BBCE0" font-size="10" text-anchor="middle">6 advogados</text>

          <rect x="460"  y="180" width="180" height="76" rx="12" fill="#0D1E35" stroke="#244E7A"/>
          <text x="550"  y="208" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Filial BH</text>
          <text x="550"  y="228" fill="#A8BDD4" font-size="10" text-anchor="middle">Belo Horizonte</text>
          <text x="550"  y="244" fill="#5BBCE0" font-size="10" text-anchor="middle">4 advogados</text>

          <rect x="860"  y="180" width="180" height="76" rx="12" fill="#0D1E35" stroke="#244E7A"/>
          <text x="950"  y="208" fill="#F1F5FA" font-size="13" font-weight="600" text-anchor="middle">Filial POA</text>
          <text x="950"  y="228" fill="#A8BDD4" font-size="10" text-anchor="middle">Porto Alegre</text>
          <text x="950"  y="244" fill="#5BBCE0" font-size="10" text-anchor="middle">3 advogados</text>

          <line x1="550" y1="256" x2="550" y2="290" stroke="#244E7A" stroke-width="1.5" stroke-dasharray="4,3"/>
          <rect x="340" y="290"  width="420" height="58" rx="12" fill="#0A1628" stroke="#3D6A96" stroke-dasharray="4,3"/>
          <text x="550" y="313"  fill="#A8BDD4" font-size="12" font-weight="600" text-anchor="middle">Advogados associados (vínculo independente)</text>
          <circle cx="430" cy="332" r="9" fill="#3D6A96"/>
          <circle cx="452" cy="332" r="9" fill="#244E7A"/>
          <circle cx="474" cy="332" r="9" fill="#5BBCE0"/>
          <circle cx="496" cy="332" r="9" fill="#3D6A96"/>
          <circle cx="518" cy="332" r="9" fill="#244E7A"/>
          <circle cx="540" cy="332" r="9" fill="#5BBCE0"/>
          <text x="580" y="336" fill="#6B7887" font-size="11" font-weight="500">+ 18 advogados</text>
        </g>
      </svg>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero organizar matriz, filial e permissões no Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Configurar matriz e filial
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     11. AUTOMAÇÕES E WEBHOOKS
     ════════════════════════════════════════════════════════════════════════ -->
<section id="automacao" class="lp-section">
  <div class="lp-container">
    <div class="lp-split lp-reveal">
      <div>
        <span class="lp-eyebrow">Automações</span>
        <h2>O Yuris não precisa trabalhar sozinho.</h2>
        <p>
          Com webhooks e eventos internos, o escritório pode conectar o Yuris a
          automações externas, robôs, CRMs, ERPs e fluxos personalizados.
        </p>
        <div class="lp-event-list">
          <div class="lp-event"><span class="lp-event-dot"></span>card.criado</div>
          <div class="lp-event"><span class="lp-event-dot"></span>processo.atualizado</div>
          <div class="lp-event"><span class="lp-event-dot"></span>tarefa.concluida</div>
          <div class="lp-event"><span class="lp-event-dot"></span>intimacao.vinculada</div>
          <div class="lp-event"><span class="lp-event-dot"></span>lead.convertido</div>
          <div class="lp-event"><span class="lp-event-dot"></span>lgpd.solicitacao.criada</div>
        </div>
        <a href="<?= wa('Olá Bruno, quero automatizar a operação do meu escritório com o Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa lp-split-cta">
          <?= $waSvg ?>
          Automatizar minha operação
        </a>
      </div>
      <div class="lp-mockup-frame">
        <div class="lp-mockup-card">
          <svg viewBox="0 0 600 420" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" fill="#060D1A"/>
            <rect x="16" y="12" width="180" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <g font-family="Inter">
              <rect x="20" y="56" width="560" height="52" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="83" r="6" fill="#5BBCE0"/>
              <text x="60" y="78" fill="#E2EAF2" font-size="12" font-weight="600">card.criado</text>
              <rect x="60" y="86" width="280" height="8" rx="2" fill="#6B7887"/>
              <rect x="490" y="72" width="80" height="24" rx="12" fill="#1E4A3A"/>
              <text x="530" y="88" fill="#6EE7A0" font-size="10" font-weight="600" text-anchor="middle">200 OK</text>

              <rect x="20" y="120" width="560" height="52" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="147" r="6" fill="#5BBCE0"/>
              <text x="60" y="142" fill="#E2EAF2" font-size="12" font-weight="600">processo.atualizado</text>
              <rect x="60" y="150" width="280" height="8" rx="2" fill="#6B7887"/>
              <rect x="490" y="136" width="80" height="24" rx="12" fill="#1E4A3A"/>
              <text x="530" y="152" fill="#6EE7A0" font-size="10" font-weight="600" text-anchor="middle">200 OK</text>

              <rect x="20" y="184" width="560" height="52" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="211" r="6" fill="#F59E0B"/>
              <text x="60" y="206" fill="#E2EAF2" font-size="12" font-weight="600">tarefa.concluida</text>
              <rect x="60" y="214" width="280" height="8" rx="2" fill="#6B7887"/>
              <rect x="490" y="200" width="80" height="24" rx="12" fill="#3D3010"/>
              <text x="530" y="216" fill="#FCD34D" font-size="10" font-weight="600" text-anchor="middle">retry 2/5</text>

              <rect x="20" y="248" width="560" height="52" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <circle cx="42" cy="275" r="6" fill="#5BBCE0"/>
              <text x="60" y="270" fill="#E2EAF2" font-size="12" font-weight="600">intimacao.vinculada</text>
              <rect x="60" y="278" width="280" height="8" rx="2" fill="#6B7887"/>
              <rect x="490" y="264" width="80" height="24" rx="12" fill="#1E4A3A"/>
              <text x="530" y="280" fill="#6EE7A0" font-size="10" font-weight="600" text-anchor="middle">200 OK</text>

              <rect x="20" y="312" width="560" height="92" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="36" y="332" fill="#6B7887" font-size="10" font-weight="600">PAYLOAD</text>
              <rect x="36" y="346" width="540" height="6" rx="2" fill="#5BBCE0" opacity=".5"/>
              <rect x="36" y="360" width="500" height="6" rx="2" fill="#A8BDD4" opacity=".5"/>
              <rect x="36" y="374" width="520" height="6" rx="2" fill="#A8BDD4" opacity=".5"/>
              <rect x="36" y="388" width="380" height="6" rx="2" fill="#A8BDD4" opacity=".5"/>
            </g>
          </svg>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     12. SEGURANÇA, LGPD E AUDITORIA
     ════════════════════════════════════════════════════════════════════════ -->
<section id="seguranca" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Segurança</span>
      <h2>Segurança não é detalhe. É parte da operação jurídica.</h2>
      <p>
        O Yuris adota uma estrutura voltada para controle, rastreabilidade e
        proteção de dados, com logs de auditoria, permissões por escopo e
        recursos preparados para apoiar a adequação à LGPD.
      </p>
    </div>

    <div class="lp-grid lp-grid-3 lp-reveal">
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/></svg></div>
        <h3>Isolamento multi-tenant</h3>
        <p>Cada conta opera em escopo próprio, com dados protegidos por padrão a nível de banco e aplicação.</p>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg></div>
        <h3>Logs de auditoria</h3>
        <p>Ações sensíveis registradas com responsável, hora e contexto — base para qualquer verificação interna.</p>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
        <h3>2FA e controle de acesso</h3>
        <p>Autenticação em dois fatores opcional e permissões granulares por perfil, módulo e escopo.</p>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
        <h3>Privacy Center (LGPD)</h3>
        <p>Solicitações de acesso, retificação e exclusão tratadas em um fluxo dedicado, com registro e prazo.</p>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <h3>Histórico imutável</h3>
        <p>O histórico de processos é registrado de forma protegida contra alteração e exclusão.</p>
      </div>
      <div class="lp-card">
        <div class="lp-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div>
        <h3>Em adequação contínua</h3>
        <p>Política de privacidade, termos, mapeamento e processos em evolução constante junto da legislação.</p>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero saber sobre segurança e LGPD no Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Falar sobre segurança e LGPD
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     13. DASHBOARDS E RELATÓRIOS
     ════════════════════════════════════════════════════════════════════════ -->
<section id="dashboards" class="lp-section">
  <div class="lp-container">
    <div class="lp-split reversed lp-reveal">
      <div>
        <span class="lp-eyebrow">Dashboards</span>
        <h2>O que não é medido vira ruído.</h2>
        <p>
          Tenha uma visão clara da operação jurídica, acompanhe o volume de
          tarefas, processos, clientes e oportunidades em painéis objetivos.
        </p>
        <ul class="lp-split-bullets">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Indicadores de processos, prazos e intimações</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Visão por matriz, filial ou advogado</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Comparativos por período</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Funil de prospecção e produtividade da equipe</li>
        </ul>
        <a href="<?= wa('Olá Bruno, quero ver os dashboards e indicadores do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa lp-split-cta">
          <?= $waSvg ?>
          Ver indicadores em ação
        </a>
      </div>
      <div class="lp-mockup-frame">
        <div class="lp-mockup-card">
          <svg viewBox="0 0 600 420" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="600" height="36" fill="#060D1A"/>
            <rect x="16" y="12" width="160" height="12" rx="3" fill="#A8BDD4" opacity=".8"/>
            <g font-family="Inter">
              <rect x="20" y="56" width="180" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="34" y="76" fill="#6B7887" font-size="10">Processos ativos</text>
              <text x="34" y="116" fill="#E2EAF2" font-size="28" font-weight="700">128</text>
              <rect x="210" y="56" width="180" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="224" y="76" fill="#6B7887" font-size="10">Prazos esta semana</text>
              <text x="224" y="116" fill="#F59E0B" font-size="28" font-weight="700">42</text>
              <rect x="400" y="56" width="180" height="84" rx="10" fill="#0D1E35" stroke="#1A3A5C"/>
              <text x="414" y="76" fill="#6B7887" font-size="10">Conclusão de tarefas</text>
              <text x="414" y="116" fill="#4ADE80" font-size="28" font-weight="700">94%</text>

              <rect x="20" y="156" width="560" height="240" rx="12" fill="#0D1E35" stroke="#1A3A5C"/>
              <rect x="34" y="172" width="200" height="10" rx="3" fill="#A8BDD4" opacity=".7"/>
              <g stroke="#1A3A5C" stroke-opacity=".3">
                <line x1="34" y1="220" x2="566" y2="220"/>
                <line x1="34" y1="270" x2="566" y2="270"/>
                <line x1="34" y1="320" x2="566" y2="320"/>
                <line x1="34" y1="370" x2="566" y2="370"/>
              </g>
              <polyline points="34,360 110,320 186,280 262,300 338,240 414,210 490,230 566,180"
                fill="none" stroke="#5BBCE0" stroke-width="2.5"/>
              <polyline points="34,360 110,320 186,280 262,300 338,240 414,210 490,230 566,180 566,380 34,380"
                fill="#5BBCE0" fill-opacity=".12" stroke="none"/>
              <polyline points="34,378 110,360 186,340 262,330 338,300 414,280 490,290 566,260"
                fill="none" stroke="#3D6A96" stroke-width="2" stroke-dasharray="4,4"/>
            </g>
          </svg>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     14. INTEGRAÇÕES
     ════════════════════════════════════════════════════════════════════════ -->
<section id="integracoes" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Integrações</span>
      <h2>Conecte o Yuris ao ecossistema do seu escritório.</h2>
      <p>O sistema foi pensado para evoluir com integrações e automações, permitindo que o escritório conecte dados e fluxos sem ficar preso a processos manuais.</p>
    </div>
    <div class="lp-grid lp-grid-4 lp-reveal">
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></div>
        <h3>WhatsApp</h3>
        <p>Atendimento e mensagens</p>
      </div>
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/><path d="m13 10 3-3-3-3"/><path d="M7.07 7.07A8.35 8.35 0 0 1 16 6c1.55 0 3 .43 4.23 1.17"/></svg></div>
        <h3>n8n</h3>
        <p>Automação self-hosted</p>
      </div>
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
        <h3>Make</h3>
        <p>Cenários visuais</p>
      </div>
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9z"/></svg></div>
        <h3>Zapier</h3>
        <p>Conexões rápidas</p>
      </div>
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <h3>DJEN</h3>
        <p>Diário oficial eletrônico</p>
      </div>
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <h3>AASP</h3>
        <p>Publicações OAB</p>
      </div>
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg></div>
        <h3>API REST</h3>
        <p>Integrações próprias</p>
      </div>
      <div class="lp-integ">
        <div class="lp-integ-logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
        <h3>Webhooks</h3>
        <p>Eventos com HMAC</p>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero integrar o Yuris com as ferramentas do meu escritório!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Conectar minhas ferramentas
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     15. ANTES × DEPOIS
     ════════════════════════════════════════════════════════════════════════ -->
<section id="comparativo" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Antes &amp; depois</span>
      <h2>A rotina jurídica que existe hoje &mdash; e a que pode existir amanhã.</h2>
    </div>
    <div class="lp-compare lp-reveal">
      <!-- Seta central pulsante (visível em desktop) — indica a transformação -->
      <div class="lp-compare-arrow" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </div>

      <div class="lp-compare-col lp-compare-before">
        <h3>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          Antes do Yuris
        </h3>
        <ul>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Processos espalhados em pastas e planilhas</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Prazos lembrados de memória</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Conversas perdidas no WhatsApp pessoal</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Tarefas sem dono e sem prazo</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Intimações sem histórico de vínculo</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Equipe sem visibilidade da operação</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Baixa rastreabilidade em qualquer auditoria</li>
        </ul>
      </div>
      <div class="lp-compare-col lp-compare-after">
        <h3>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
          Depois do Yuris
        </h3>
        <ul>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Processos centralizados, com vínculos e histórico</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Prazos organizados, com responsáveis e alertas</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Comunicação conectada a cliente e processo</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Tarefas com responsáveis e status claros</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Intimações vinculadas e auditáveis</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Gestão por matriz, filial e advogado</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Histórico e auditoria como parte da operação</li>
        </ul>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero transformar a rotina do meu escritório com o Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Quero essa transformação
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     16. PARA QUEM É
     ════════════════════════════════════════════════════════════════════════ -->
<section id="para-quem" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Para quem é</span>
      <h2>O Yuris é para escritórios que precisam de controle.</h2>
      <p>Diferentes perfis, uma mesma necessidade: organização e rastreabilidade.</p>
    </div>
    <div class="lp-personas lp-reveal">
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Advogado autônomo</span>
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Escritório pequeno</span>
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Escritório em crescimento</span>
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Operação com equipe</span>
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Matriz com filiais</span>
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Jurídico interno</span>
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Alto volume de processos</span>
      <span class="lp-persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Atendimento por WhatsApp</span>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, gostaria de saber se o Yuris atende o perfil do meu escritório!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Falar com a equipe Yuris
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     17. PROVA DE CONFIANÇA
     ════════════════════════════════════════════════════════════════════════ -->
<section id="confianca" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Pilares</span>
      <h2>Seis pilares que sustentam o Yuris.</h2>
      <p>O que orienta cada decisão de produto, do schema do banco à última tela.</p>
    </div>
    <div class="lp-grid lp-grid-3 lp-reveal">
      <div class="lp-pillar lp-card">
        <div class="lp-pillar-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg></div>
        <h3>Controle</h3>
        <p>Cada ação tem responsável, contexto e momento.</p>
      </div>
      <div class="lp-pillar lp-card">
        <div class="lp-pillar-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L4 6v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V6l-8-4z"/></svg></div>
        <h3>Segurança</h3>
        <p>Proteção de dados e separação por escopo desde a fundação.</p>
      </div>
      <div class="lp-pillar lp-card">
        <div class="lp-pillar-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/><path d="m13 10 3-3-3-3"/></svg></div>
        <h3>Automação</h3>
        <p>O trabalho repetitivo do escritório pode acontecer sem você.</p>
      </div>
      <div class="lp-pillar lp-card">
        <div class="lp-pillar-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg></div>
        <h3>Rastreabilidade</h3>
        <p>Histórico imutável para qualquer verificação.</p>
      </div>
      <div class="lp-pillar lp-card">
        <div class="lp-pillar-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></div>
        <h3>Gestão</h3>
        <p>Visão clara do que cada equipe está fazendo.</p>
      </div>
      <div class="lp-pillar lp-card">
        <div class="lp-pillar-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 17 9 11 13 15 21 7"/><polyline points="14 7 21 7 21 14"/></svg></div>
        <h3>Escalabilidade</h3>
        <p>Cresce com o escritório, sem trocar o sistema.</p>
      </div>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, quero conversar com um especialista do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Conversar com um especialista
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════════════════════════
     18. FAQ (perguntas frequentes — rastreável, <details> sem JS)
     Schema FAQPage logo abaixo: manter PERGUNTAS E RESPOSTAS IDÊNTICAS
     ao texto visível, senão o rich result é invalidado.
     ════════════════════════════════════════════════════════════════════════ -->
<section id="faq" class="lp-section">
  <div class="lp-container">
    <div class="lp-section-head lp-reveal">
      <span class="lp-eyebrow">Perguntas frequentes</span>
      <h2>O que todo escritório pergunta antes de conhecer o Yuris.</h2>
    </div>
    <div class="sp-faq">
      <details>
        <summary>O que é o Yuris?</summary>
        <div class="sp-faq-resposta"><p>O Yuris é um sistema jurídico para advogados e escritórios de advocacia que centraliza processos, prazos, intimações, clientes, tarefas, financeiro e atendimento por WhatsApp em uma única plataforma, com LGPD e auditoria como parte da estrutura.</p></div>
      </details>
      <details>
        <summary>Quanto custa o Yuris?</summary>
        <div class="sp-faq-resposta"><p>Os planos são públicos e começam em R$ 220 por mês, com tudo incluído — sem cobrança separada por módulo. O valor varia conforme o número de usuários do escritório. A tabela completa está na <a href="/planos.php">página de planos</a>.</p></div>
      </details>
      <details>
        <summary>O Yuris serve para advogado autônomo ou só para escritórios?</summary>
        <div class="sp-faq-resposta"><p>Os dois. O plano de entrada atende de 1 a 2 usuários, e a mesma plataforma escala para equipes e estruturas com matriz e filiais — sem trocar de sistema no caminho.</p></div>
      </details>
      <details>
        <summary>O Yuris monitora intimações automaticamente?</summary>
        <div class="sp-faq-resposta"><p>Sim. O sistema monitora publicações judiciais em múltiplas fontes — DJEN, DataJud e AASP — com deduplicação automática. A intimação pode ser vinculada ao processo e convertida em prazo ou tarefa com responsável. A fonte AASP requer chave de acesso do próprio escritório.</p></div>
      </details>
      <details>
        <summary>Como o Yuris trata a LGPD?</summary>
        <div class="sp-faq-resposta"><p>Como camada estrutural: isolamento de dados entre escritórios, permissões por escopo, trilha de auditoria imutável, anonimização e atendimento aos direitos do titular. O Yuris adota medidas técnicas e organizacionais de proteção de dados e segue em processo contínuo de adequação à LGPD.</p></div>
      </details>
      <details>
        <summary>Como faço para conhecer o sistema?</summary>
        <div class="sp-faq-resposta"><p>Pelo WhatsApp: a equipe agenda uma demonstração e apresenta o Yuris aplicado à rotina do seu escritório — volume de processos, tamanho da equipe e forma de atendimento.</p></div>
      </details>
    </div>
    <div class="lp-section-cta lp-reveal">
      <a href="<?= wa('Olá Bruno, tenho algumas perguntas sobre o Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
        <?= $waSvg ?>
        Tirar minhas dúvidas no WhatsApp
      </a>
    </div>
  </div>
</section>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "O que é o Yuris?",
      "acceptedAnswer": { "@type": "Answer", "text": "O Yuris é um sistema jurídico para advogados e escritórios de advocacia que centraliza processos, prazos, intimações, clientes, tarefas, financeiro e atendimento por WhatsApp em uma única plataforma, com LGPD e auditoria como parte da estrutura." }
    },
    {
      "@type": "Question",
      "name": "Quanto custa o Yuris?",
      "acceptedAnswer": { "@type": "Answer", "text": "Os planos são públicos e começam em R$ 220 por mês, com tudo incluído — sem cobrança separada por módulo. O valor varia conforme o número de usuários do escritório. A tabela completa está na página de planos." }
    },
    {
      "@type": "Question",
      "name": "O Yuris serve para advogado autônomo ou só para escritórios?",
      "acceptedAnswer": { "@type": "Answer", "text": "Os dois. O plano de entrada atende de 1 a 2 usuários, e a mesma plataforma escala para equipes e estruturas com matriz e filiais — sem trocar de sistema no caminho." }
    },
    {
      "@type": "Question",
      "name": "O Yuris monitora intimações automaticamente?",
      "acceptedAnswer": { "@type": "Answer", "text": "Sim. O sistema monitora publicações judiciais em múltiplas fontes — DJEN, DataJud e AASP — com deduplicação automática. A intimação pode ser vinculada ao processo e convertida em prazo ou tarefa com responsável. A fonte AASP requer chave de acesso do próprio escritório." }
    },
    {
      "@type": "Question",
      "name": "Como o Yuris trata a LGPD?",
      "acceptedAnswer": { "@type": "Answer", "text": "Como camada estrutural: isolamento de dados entre escritórios, permissões por escopo, trilha de auditoria imutável, anonimização e atendimento aos direitos do titular. O Yuris adota medidas técnicas e organizacionais de proteção de dados e segue em processo contínuo de adequação à LGPD." }
    },
    {
      "@type": "Question",
      "name": "Como faço para conhecer o sistema?",
      "acceptedAnswer": { "@type": "Answer", "text": "Pelo WhatsApp: a equipe agenda uma demonstração e apresenta o Yuris aplicado à rotina do seu escritório — volume de processos, tamanho da equipe e forma de atendimento." }
    }
  ]
}
</script>

<!-- ════════════════════════════════════════════════════════════════════════
     19. CTA FINAL
     ════════════════════════════════════════════════════════════════════════ -->
<section id="demonstracao" class="lp-section">
  <div class="lp-container">
    <div class="lp-cta-final lp-reveal">
      <span class="lp-eyebrow">Vamos conversar</span>
      <h2>Pronto para organizar sua operação jurídica em uma única plataforma?</h2>
      <p>Conheça o Yuris e veja como processos, prazos, intimações, comunicação e equipe podem trabalhar no mesmo fluxo.</p>
      <div class="lp-hero-ctas">
        <a href="<?= wa('Olá Bruno, quero uma demonstração do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-primary lp-btn-wa">
          <?= $waSvg ?>
          Solicitar demonstração
        </a>
        <a href="<?= wa('Olá Bruno, gostaria de falar com um especialista do Yuris!') ?>" target="_blank" rel="noopener" class="lp-btn lp-btn-ghost lp-btn-wa">
          <?= $waSvg ?>
          Falar com especialista
        </a>
      </div>
    </div>
  </div>
</section>

</main>

<!-- ════════════════════════════════════════════════════════════════════════
     FOOTER
     ════════════════════════════════════════════════════════════════════════ -->
<footer class="lp-footer">
  <div class="lp-container">
    <div class="lp-footer-grid">
      <div class="lp-footer-brand">
        <img src="/assets/img/logo-144.webp" alt="Yuris" width="52" height="52" loading="lazy" decoding="async">
        <p>Sistema Jurídico Inteligente. Controle, segurança e automação para a rotina de advogados e escritórios.</p>
      </div>
      <div class="lp-footer-col">
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
      <div class="lp-footer-col">
        <h5>Produto</h5>
        <ul>
          <li><a href="#recursos">Recursos</a></li>
          <li><a href="#juridico">Jurídico</a></li>
          <li><a href="#automacao">Automações</a></li>
          <li><a href="#integracoes">Integrações</a></li>
          <li><a href="/planos.php">Planos</a></li>
          <li><a href="/blog/">Blog</a></li>
        </ul>
      </div>
      <div class="lp-footer-col">
        <h5>Empresa</h5>
        <ul>
          <li><a href="/sobre/">Sobre o Yuris</a></li>
          <li><a href="#para-quem">Para quem é</a></li>
          <li><a href="#confianca">Pilares</a></li>
          <li><a href="/demonstracao/">Demonstração</a></li>
          <li><a href="/login.php">Entrar</a></li>
        </ul>
      </div>
      <div class="lp-footer-col">
        <h5>Legal</h5>
        <ul>
          <li><a href="/privacidade.php">Privacidade</a></li>
          <li><a href="/termos.php">Termos</a></li>
          <li><a href="/lgpd.php">LGPD</a></li>
          <li><a href="/cookies.php">Cookies</a></li>
          <li><a href="/dpo.php">Encarregado (DPO)</a></li>
          <li><a href="javascript:if(window.YurisCookies){YurisCookies.open()}else{location.reload()}">Gerenciar cookies</a></li>
        </ul>
      </div>
    </div>
    <div class="lp-footer-bottom">
      <span>&copy; <span data-year>2026</span> Yuris &middot; Sistema Jurídico Inteligente.</span>
      <span>O Yuris adota medidas técnicas e organizacionais voltadas à proteção de dados pessoais e segue em processo contínuo de adequação à LGPD.</span>
    </div>
  </div>
</footer>

<script src="/assets/landing.js?v=<?= $jsVer ?>" defer></script>
<script src="/assets/cookie-consent.js?v=1"></script>
</body>
</html>
