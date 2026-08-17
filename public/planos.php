<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Planos | Yuris Sistema Jurídico</title>
  <meta name="description" content="Planos do Yuris: CRM, processos, intimações automáticas, financeiro e WhatsApp com agente de IA. Fale com a gente e encontre o plano ideal para o seu escritório.">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="https://yuris.com.br/planos">
  <meta name="theme-color" content="#070F1C">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Yuris">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:url" content="https://yuris.com.br/planos">
  <meta property="og:title" content="Planos | Yuris Sistema Jurídico">
  <meta property="og:description" content="CRM, processos, intimações automáticas, financeiro e WhatsApp com IA em um só sistema. Conheça os planos do Yuris.">
  <meta property="og:image" content="https://yuris.com.br/assets/img/og-image.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Planos | Yuris Sistema Jurídico">
  <meta name="twitter:description" content="Conheça os planos do Yuris, com agente de IA no WhatsApp incluído.">
  <meta name="twitter:image" content="https://yuris.com.br/assets/img/og-image.jpg">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "BreadcrumbList",
        "itemListElement": [
          { "@type": "ListItem", "position": 1, "name": "Início", "item": "https://yuris.com.br/" },
          { "@type": "ListItem", "position": 2, "name": "Planos", "item": "https://yuris.com.br/planos" }
        ]
      },
      {
        "@type": "Product",
        "name": "Yuris — Sistema Jurídico Inteligente",
        "description": "Sistema jurídico para advogados e escritórios: processos, prazos, intimações, CRM, financeiro, tarefas e WhatsApp em uma única plataforma.",
        "image": "https://yuris.com.br/assets/img/og-image.jpg",
        "brand": { "@type": "Brand", "name": "Yuris" },
        "url": "https://yuris.com.br/planos"
      }
    ]
  }
  </script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #070F1C;
      --card:      #0D1C30;
      --border:    rgba(160,180,210,0.10);
      --border-md: rgba(160,180,210,0.18);
      --accent:    #244E7A;
      --accent-h:  #2D63A0;
      --gold:      #C89B3C;
      --gold-dim:  rgba(200,155,60,0.12);
      --text:      #D8E4F0;
      --muted:     #7A8898;
      --dim:       #4A5568;
      --green:     #2A7A58;
      --green-dim: rgba(42,122,88,0.15);
    }

    body {
      background: var(--bg);
      background-image:
        radial-gradient(ellipse 90% 60% at 10% 20%, rgba(20,50,90,0.22) 0%, transparent 55%),
        radial-gradient(ellipse 70% 50% at 90% 80%, rgba(30,60,100,0.14) 0%, transparent 50%);
      background-attachment: fixed;
      color: var(--text);
      font-family: 'Poppins', system-ui, sans-serif;
      min-height: 100vh;
      padding: 60px 20px 80px;
    }

    /* ── Header ── */
    .header {
      text-align: center;
      margin-bottom: 56px;
    }
    .header-logo {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 32px;
    }
    .header-logo img {
      height: 44px;
      object-fit: contain;
    }
    .header h1 {
      font-size: clamp(1.8rem, 4vw, 2.6rem);
      font-weight: 800;
      color: #E8F4FF;
      letter-spacing: -0.5px;
      line-height: 1.15;
    }
    .header h1 span {
      color: #5B9BD5;
    }
    .header p {
      margin-top: 14px;
      color: var(--muted);
      font-size: 1rem;
      max-width: 480px;
      margin-left: auto;
      margin-right: auto;
      line-height: 1.6;
    }
    .badge-launch {
      display: inline-block;
      background: rgba(200,155,60,0.15);
      border: 1px solid rgba(200,155,60,0.35);
      color: #C89B3C;
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .08em;
      padding: 4px 12px;
      border-radius: 20px;
      margin-bottom: 20px;
    }

    /* ── Grid de planos ── */
    .plans-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
      max-width: 1060px;
      margin: 0 auto 56px;
    }

    /* ── Card de plano ── */
    .plan-card {
      background: linear-gradient(165deg, rgba(14,35,65,.95), rgba(10,23,43,.97));
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 32px 28px;
      position: relative;
      transition: transform .25s, border-color .25s, box-shadow .25s;
      display: flex;
      flex-direction: column;
    }
    .plan-card:hover {
      transform: translateY(-5px);
      border-color: var(--border-md);
      box-shadow: 0 20px 50px rgba(0,0,0,.45);
    }
    .plan-card.featured {
      border-color: rgba(91,155,213,0.40);
      box-shadow: 0 0 0 1px rgba(91,155,213,0.18), 0 16px 48px rgba(0,0,0,.40);
      background: linear-gradient(165deg, rgba(18,42,76,.97), rgba(12,26,50,.98));
    }
    .plan-card.featured:hover {
      border-color: rgba(91,155,213,0.60);
      box-shadow: 0 0 0 1px rgba(91,155,213,0.28), 0 24px 60px rgba(0,0,0,.50);
    }

    /* badge popular */
    .popular-badge {
      position: absolute;
      top: -13px;
      left: 50%;
      transform: translateX(-50%);
      background: linear-gradient(90deg, #1A4A7A, #2D6FAD);
      border: 1px solid rgba(91,155,213,0.45);
      color: #A8CFEE;
      font-size: .68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .1em;
      padding: 4px 16px;
      border-radius: 20px;
      white-space: nowrap;
    }

    .plan-name {
      font-size: .75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: var(--muted);
      margin-bottom: 12px;
    }
    .plan-card.featured .plan-name {
      color: #5B9BD5;
    }

    .plan-users {
      font-size: .85rem;
      color: var(--muted);
      margin-bottom: 6px;
    }

    .plan-price {
      display: flex;
      align-items: baseline;
      gap: 4px;
      margin-bottom: 6px;
    }
    .plan-currency {
      font-size: .95rem;
      font-weight: 600;
      color: var(--muted);
      margin-top: 4px;
    }
    .plan-amount {
      font-size: 2.6rem;
      font-weight: 800;
      color: #E8F4FF;
      line-height: 1;
      letter-spacing: -1px;
    }
    .plan-period {
      font-size: .8rem;
      color: var(--dim);
      margin-bottom: 24px;
    }

    .plan-extra {
      font-size: .78rem;
      color: var(--muted);
      background: rgba(36,78,122,0.18);
      border: 1px solid rgba(36,78,122,0.30);
      border-radius: 8px;
      padding: 8px 12px;
      margin-bottom: 24px;
      line-height: 1.5;
    }
    .plan-extra strong {
      color: #A8CFEE;
    }

    .plan-divider {
      border: none;
      border-top: 1px solid var(--border);
      margin-bottom: 20px;
    }

    .plan-features {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 10px;
      flex: 1;
      margin-bottom: 28px;
    }
    .plan-features li {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: .83rem;
      color: #A8BDD4;
      line-height: 1.4;
    }
    .plan-features li .check {
      flex-shrink: 0;
      width: 17px;
      height: 17px;
      margin-top: 1px;
      color: #2A9D6E;
    }

    .plan-btn {
      display: block;
      text-align: center;
      padding: 13px;
      border-radius: 10px;
      font-size: .88rem;
      font-weight: 700;
      text-decoration: none;
      transition: background .2s, transform .15s, box-shadow .2s;
      cursor: pointer;
      border: none;
      width: 100%;
      letter-spacing: .02em;
    }
    .plan-btn-outline {
      background: transparent;
      border: 1px solid var(--border-md);
      color: var(--text);
    }
    .plan-btn-outline:hover {
      background: rgba(160,180,210,0.06);
      border-color: rgba(160,180,210,0.28);
    }
    .plan-btn-primary {
      background: linear-gradient(135deg, #1E5299, #2D6FAD);
      color: #E8F4FF;
      box-shadow: 0 4px 16px rgba(29,82,153,0.35);
    }
    .plan-btn-primary:hover {
      background: linear-gradient(135deg, #2460B0, #3580C0);
      transform: translateY(-1px);
      box-shadow: 0 6px 22px rgba(29,82,153,0.45);
    }

    /* ── Preço por usuário ── */
    .per-user {
      font-size: .7rem;
      color: var(--dim);
      text-align: center;
      margin-top: 6px;
    }

    /* ── Linha do plano anual ── */
    .plan-annual {
      font-size: .74rem;
      color: var(--dim);
      margin-bottom: 18px;
      line-height: 1.4;
    }
    .plan-annual strong { color: #8FB8DC; font-weight: 700; }

    /* ── Limites do plano (monitores / triagens) ── */
    .plan-limits {
      background: rgba(36,78,122,0.16);
      border: 1px solid rgba(36,78,122,0.28);
      border-radius: 9px;
      padding: 10px 12px;
      margin-bottom: 20px;
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    .plan-limits div {
      font-size: .74rem;
      color: #A8BDD4;
      line-height: 1.35;
    }
    .plan-limits strong { color: #E8F4FF; font-weight: 700; }

    /* ── Faixa Enterprise ── */
    .enterprise-band {
      max-width: 1060px;
      margin: 0 auto 56px;
      background: linear-gradient(150deg, rgba(20,40,68,.95), rgba(10,22,40,.97));
      border: 1px solid rgba(176,141,87,0.32);
      border-radius: 18px;
      padding: 34px 32px;
      display: grid;
      grid-template-columns: 1.15fr 1fr;
      gap: 32px;
      align-items: center;
    }
    .ent-tag {
      display: inline-block;
      font-size: .66rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .12em;
      color: #C9A227;
      border: 1px solid rgba(201,162,39,0.35);
      background: rgba(201,162,39,0.08);
      padding: 4px 12px;
      border-radius: 20px;
      margin-bottom: 14px;
    }
    .enterprise-band h2 {
      font-size: 1.5rem;
      font-weight: 800;
      color: #E8F4FF;
      margin-bottom: 10px;
      letter-spacing: -.3px;
    }
    .enterprise-band > div > p {
      font-size: .87rem;
      color: var(--muted);
      line-height: 1.65;
      margin-bottom: 22px;
    }
    .ent-price {
      font-size: 1.15rem;
      font-weight: 800;
      color: #C9A227;
      margin-bottom: 20px;
    }
    .ent-list {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 11px;
    }
    .ent-list li {
      display: flex;
      align-items: flex-start;
      gap: 11px;
      font-size: .84rem;
      color: #A8BDD4;
      line-height: 1.45;
    }
    .ent-list li .check {
      flex-shrink: 0;
      width: 17px;
      height: 17px;
      margin-top: 1px;
      color: #C9A227;
    }
    .ent-btn {
      display: inline-block;
      margin-top: 4px;
      background: transparent;
      border: 1px solid rgba(201,162,39,0.45);
      color: #E4C766;
      font-weight: 700;
      font-size: .88rem;
      padding: 13px 28px;
      border-radius: 10px;
      text-decoration: none;
      transition: background .2s, border-color .2s, transform .15s;
    }
    .ent-btn:hover {
      background: rgba(201,162,39,0.10);
      border-color: rgba(201,162,39,0.65);
      transform: translateY(-1px);
    }

    /* ── Add-ons ── */
    .addons-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
      gap: 14px;
      margin-top: 26px;
      text-align: left;
    }
    .addon-item {
      background: rgba(13,28,48,.8);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 18px 20px;
    }
    .addon-price {
      font-size: 1.25rem;
      font-weight: 800;
      color: #E8F4FF;
      margin-bottom: 4px;
    }
    .addon-price span { font-size: .72rem; font-weight: 600; color: var(--dim); }
    .addon-title { font-size: .82rem; font-weight: 700; color: #C8D4E0; margin-bottom: 3px; }
    .addon-desc  { font-size: .74rem; color: var(--muted); line-height: 1.45; }

    /* ── Tabela simples ── */
    .simple-section {
      max-width: 560px;
      margin: 0 auto 56px;
      text-align: center;
    }
    .simple-section h2 {
      font-size: 1.1rem;
      font-weight: 700;
      color: #C8D4E0;
      margin-bottom: 6px;
    }
    .simple-section p {
      font-size: .85rem;
      color: var(--muted);
      margin-bottom: 24px;
    }
    .price-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .85rem;
    }
    .price-table th {
      color: var(--muted);
      font-weight: 600;
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .07em;
      padding: 8px 16px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    .price-table td {
      padding: 10px 16px;
      border-bottom: 1px solid var(--border);
      color: #A8BDD4;
    }
    .price-table td:last-child {
      font-weight: 700;
      color: #E8F4FF;
      text-align: right;
    }
    .price-table tr:last-child td {
      border-bottom: none;
    }
    .price-table tbody tr:hover td {
      background: rgba(36,78,122,0.08);
    }

    /* ── Features section ── */
    .features-section {
      max-width: 860px;
      margin: 0 auto 56px;
      text-align: center;
    }
    .features-section h2 {
      font-size: 1.3rem;
      font-weight: 700;
      color: #C8D4E0;
      margin-bottom: 6px;
    }
    .features-section p {
      font-size: .85rem;
      color: var(--muted);
      margin-bottom: 32px;
    }
    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
    }
    .feature-item {
      background: rgba(13,28,48,.8);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 18px 20px;
      display: flex;
      align-items: flex-start;
      gap: 14px;
      text-align: left;
    }
    .feature-icon {
      flex-shrink: 0;
      width: 36px;
      height: 36px;
      background: rgba(36,78,122,0.25);
      border: 1px solid rgba(36,78,122,0.35);
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #5B9BD5;
    }
    .feature-icon svg { width: 18px; height: 18px; }
    .feature-title { font-size: .82rem; font-weight: 700; color: #C8D4E0; margin-bottom: 3px; }
    .feature-desc  { font-size: .75rem; color: var(--muted); line-height: 1.45; }

    /* ── CTA ── */
    .cta-section {
      text-align: center;
      max-width: 500px;
      margin: 0 auto;
    }
    .cta-section h2 {
      font-size: 1.4rem;
      font-weight: 800;
      color: #E8F4FF;
      margin-bottom: 10px;
    }
    .cta-section p {
      color: var(--muted);
      font-size: .88rem;
      margin-bottom: 28px;
      line-height: 1.6;
    }
    .cta-btn {
      display: inline-block;
      background: linear-gradient(135deg, #1E5299, #2D6FAD);
      color: #E8F4FF;
      font-weight: 700;
      font-size: .95rem;
      padding: 15px 36px;
      border-radius: 12px;
      text-decoration: none;
      box-shadow: 0 6px 24px rgba(29,82,153,0.4);
      transition: transform .2s, box-shadow .2s;
      letter-spacing: .02em;
    }
    .cta-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 32px rgba(29,82,153,0.52);
    }

    /* ── Footer ── */
    .footer {
      text-align: center;
      margin-top: 60px;
      padding-top: 24px;
      border-top: 1px solid var(--border);
    }
    .footer p {
      font-size: .75rem;
      color: var(--dim);
    }
    .footer strong { color: var(--muted); }

    @media (max-width: 860px) {
      .enterprise-band { grid-template-columns: 1fr; gap: 24px; padding: 28px 24px; }
    }
    @media (max-width: 640px) {
      .plans-grid { grid-template-columns: 1fr; max-width: 400px; }
      .enterprise-band { max-width: 400px; }
    }
  </style>
</head>
<body>

<!-- ── Header ── -->
<div class="header">
  <div class="header-logo">
    <img src="/assets/img/logo-144.webp" alt="Yuris" width="52" height="52">
  </div>
  <h1>Simples, transparente,<br><span>sem surpresas.</span></h1>
  <p>Tudo que seu escritório precisa em um único sistema. CRM, processos, intimações automáticas, financeiro e WhatsApp com IA.</p>
</div>

<!-- ── Cards de plano ── -->
<div class="plans-grid">

  <!-- Solo -->
  <div class="plan-card">
    <div class="plan-name">Solo</div>
    <div class="plan-users">até 2 usuários</div>
    <div class="plan-annual">Valor sob consulta</div>

    <div class="plan-limits">
      <div><strong>1</strong> monitor de intimação</div>
      <div><strong>50</strong> triagens de IA por mês</div>
    </div>

    <hr class="plan-divider">
    <ul class="plan-features">
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Dashboard executivo</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> CRM e prospecção</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Gestão de processos e prazos</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Intimações automáticas (DJEN)</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Painel financeiro / DRE</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> WhatsApp + agente de IA</li>
    </ul>
    <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Solo%20do%20Yuris!" target="_blank" rel="noopener" class="plan-btn plan-btn-outline">Começar agora</a>
  </div>

  <!-- Equipe — DESTAQUE -->
  <div class="plan-card featured">
    <div class="popular-badge">Mais popular</div>
    <div class="plan-name">Equipe</div>
    <div class="plan-users">até 5 usuários</div>
    <div class="plan-annual">Valor sob consulta</div>

    <div class="plan-limits">
      <div><strong>3</strong> monitores de intimação</div>
      <div><strong>200</strong> triagens de IA por mês</div>
    </div>

    <hr class="plan-divider">
    <ul class="plan-features">
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Tudo do plano Solo</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Chat interno da equipe</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Advogados associados</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Planejamento comercial e metas</li>
    </ul>
    <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Equipe%20do%20Yuris!" target="_blank" rel="noopener" class="plan-btn plan-btn-primary">Começar agora</a>
  </div>

  <!-- Escritório -->
  <div class="plan-card">
    <div class="plan-name">Escritório</div>
    <div class="plan-users">até 10 usuários</div>
    <div class="plan-annual">Valor sob consulta</div>

    <div class="plan-limits">
      <div><strong>6</strong> monitores de intimação</div>
      <div><strong>500</strong> triagens de IA por mês</div>
    </div>

    <hr class="plan-divider">
    <ul class="plan-features">
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Tudo do plano Equipe</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Integração AASP</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Filiais vinculadas</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Webhooks e automações</li>
    </ul>
    <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Escrit%C3%B3rio%20do%20Yuris!" target="_blank" rel="noopener" class="plan-btn plan-btn-outline">Começar agora</a>
  </div>

  <!-- Studio -->
  <div class="plan-card">
    <div class="plan-name">Studio</div>
    <div class="plan-users">até 20 usuários</div>
    <div class="plan-annual">Valor sob consulta</div>

    <div class="plan-limits">
      <div><strong>12</strong> monitores de intimação</div>
      <div><strong>1.500</strong> triagens de IA por mês</div>
    </div>

    <hr class="plan-divider">
    <ul class="plan-features">
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Tudo do plano Escritório</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Suporte prioritário</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Estrutura completa de filiais e parceiros</li>
    </ul>
    <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Studio%20do%20Yuris!" target="_blank" rel="noopener" class="plan-btn plan-btn-outline">Começar agora</a>
  </div>

</div>

<!-- ── Enterprise (sob consulta) ── -->
<div class="enterprise-band">
  <div>
    <div class="ent-tag">Enterprise</div>
    <h2>Para operações que precisam do Yuris moldado a elas.</h2>
    <p>Acima de 20 usuários, com implantação assistida, migração dos seus dados e integração com os sistemas que seu escritório já usa. Escopo e mensalidade definidos junto com você.</p>
    <div class="ent-price">Sob consulta</div>
    <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20falar%20sobre%20o%20plano%20Enterprise%20do%20Yuris!" target="_blank" rel="noopener" class="ent-btn">Falar com um especialista</a>
  </div>
  <div>
    <ul class="ent-list">
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Tudo do plano Studio, sem limite prático de usuários</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Implementação assistida, com onboarding guiado</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Migração de dados de outra plataforma ou planilhas</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Integrações sob medida com os sistemas que você já usa</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Treinamento da equipe</li>
      <li><svg class="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Suporte dedicado com SLA</li>
    </ul>
  </div>
</div>

<!-- ── Add-ons ── -->
<div class="simple-section" style="max-width:860px;">
  <h2>Precisa de um pouco mais?</h2>
  <p>Amplie qualquer plano sem precisar subir de faixa. Contrate só o que faltar.</p>
  <div class="addons-grid">

    <div class="addon-item">
      <div class="addon-title">Usuário adicional</div>
      <div class="addon-desc">Para quando a equipe cresce além do limite do plano.</div>
    </div>

    <div class="addon-item">
      <div class="addon-title">Monitor de intimação extra</div>
      <div class="addon-desc">Uma OAB e UF a mais sendo monitorada automaticamente.</div>
    </div>

    <div class="addon-item">
      <div class="addon-title">Mais 200 triagens de IA</div>
      <div class="addon-desc">Para escritórios com alto volume de contato pelo WhatsApp.</div>
    </div>

    <div class="addon-item">
      <div class="addon-title">+ Implantação opcional</div>
      <div class="addon-desc">A gente traz os dados do sistema atual do seu escritório para dentro do Yuris e deixa tudo pronto para usar.</div>
    </div>

  </div>
</div>

<!-- ── O que está incluído ── -->
<div class="features-section">
  <h2>Presente em todos os planos</h2>
  <p>A base do sistema é a mesma desde o plano Solo. O que muda entre os planos é o tamanho da equipe, o volume e os recursos avançados.</p>
  <div class="features-grid">

    <div class="feature-item">
      <div class="feature-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      </div>
      <div>
        <div class="feature-title">Dashboard Executivo</div>
        <div class="feature-desc">Visão consolidada de comercial, jurídico e financeiro em tempo real.</div>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div>
        <div class="feature-title">CRM e Prospecção</div>
        <div class="feature-desc">Pipeline Kanban de clientes, funil de vendas e simulador de metas.</div>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </div>
      <div>
        <div class="feature-title">Gestão de Processos</div>
        <div class="feature-desc">Kanban jurídico, prazos urgentes, tarefas e histórico por processo.</div>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3v4"/><path d="M6 7h12"/><path d="M8 10v4"/><path d="M16 10v4"/><path d="M5 18c1.5-1 3.5-1 5 0"/><path d="M19 18c-1.5-1-3.5-1-5 0"/></svg>
      </div>
      <div>
        <div class="feature-title">Painel Jurídico</div>
        <div class="feature-desc">Carga por advogado, tipos de ação, taxa de encerramento e processos vencidos.</div>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg>
      </div>
      <div>
        <div class="feature-title">Financeiro / DRE</div>
        <div class="feature-desc">Lançamentos, impostos, P&L completo e análise de margem do escritório.</div>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
      </div>
      <div>
        <div class="feature-title">Chat WhatsApp + IA</div>
        <div class="feature-desc">Atendimento integrado no número que o escritório já usa, com agente de IA fazendo a triagem inicial.</div>
      </div>
    </div>

    <div class="feature-item">
      <div class="feature-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      </div>
      <div>
        <div class="feature-title">Intimações Automáticas</div>
        <div class="feature-desc">Monitoramento diário do DJEN pela OAB. A publicação chega pronta para virar prazo ou tarefa no processo.</div>
      </div>
    </div>

  </div>
</div>

<!-- ── CTA ── -->
<div class="cta-section">
  <h2>Pronto para começar?</h2>
  <p>Fale com a gente pelo WhatsApp e tenha o Yuris funcionando no seu escritório hoje mesmo.</p>
  <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20conhecer%20o%20Yuris!" target="_blank" rel="noopener" class="cta-btn">Falar com a equipe</a>
</div>

<!-- ── Footer ── -->
<div class="footer">
  <p><strong>Yuris</strong> — Sistema Jurídico Inteligente &nbsp;·&nbsp; Todos os direitos reservados</p>
</div>

<!-- LGPD Etapa 5: footer com links legais + banner de cookies -->
<?php include __DIR__ . '/includes/legal_footer.php'; ?>
<script src="/assets/cookie-consent.js?v=1"></script>

</body>
</html>
