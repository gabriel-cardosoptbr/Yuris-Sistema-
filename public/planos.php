<?php
/**
 * planos.php, página pública de planos (site "Editorial LegalTech").
 *
 * Cabeçalho e rodapé vêm de includes/site_chrome.php; o visual próprio está em
 * assets/site/site-planos.css.
 *
 * O HTML dos planos fica escrito à mão de propósito: o plan_feature_test lê este
 * fonte e confere os limites no formato <strong>N</strong>, a contagem de planos
 * sob consulta e as classes enterprise-band e addon-item. Nada de valor em reais
 * aqui, nem em comentário.
 */
require_once __DIR__ . '/includes/site_chrome.php';

/** 20 lugares, com os do plano acesos. Decorativo: o texto do plano já diz o número. */
?>
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
        "name": "Yuris, Sistema Jurídico Inteligente",
        "description": "Sistema jurídico para advogados e escritórios: processos, prazos, intimações, CRM, financeiro, tarefas e WhatsApp em uma única plataforma.",
        "image": "https://yuris.com.br/assets/img/og-image.jpg",
        "brand": { "@type": "Brand", "name": "Yuris" },
        "url": "https://yuris.com.br/planos"
      }
    ]
  }
  </script>
  <!-- Marca <html class="js"> antes do CSS: .ys-reveal só esconde com JS ligado. -->
  <script>document.documentElement.classList.add('js')</script>
  <link rel="preload" href="<?= htmlspecialchars(YS_FONT_PRELOAD, ENT_QUOTES, 'UTF-8') ?>" as="font" type="font/woff2" crossorigin>
<?php foreach (ys_css(['site-planos.css']) as $folha): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($folha, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
</head>
<body class="ys">

<?php ys_header([
    'drawer' => ys_drawer_paginas(),
    'ctaMsg' => 'Olá Bruno, quero conhecer o Yuris!',
    'active' => '/planos',
]); ?>

<main id="conteudo">

<!-- Hero -->
<section class="ys-pagehero pl-hero">
  <div class="ys-container pl-hero-grade">
    <h1>Simples, transparente,<br><span class="ys-hl">sem surpresas.</span></h1>
    <p class="ys-lede">Tudo que seu escritório precisa em um único sistema. CRM, processos, intimações automáticas, financeiro e WhatsApp com IA.</p>
  </div>
</section>

<!-- Planos: uma grade só, com as linhas alinhadas de coluna a coluna, e o Enterprise como base -->
<section class="pl-planos">
  <div class="ys-container">
    <div class="pl-grade">

      <!-- Solo -->
      <article class="pl-card">
        <div class="pl-topo">
          <h2 class="pl-nome">Solo</h2>
        </div>
        <div class="pl-equipe">
          <div class="pl-usuarios">até <strong>2</strong> usuários</div>
        </div>
        <div class="pl-valor">Valor sob consulta</div>
        <div class="pl-limites">
          <div><strong>1</strong> monitor de intimação</div>
          <div><strong>50</strong> triagens de IA por mês</div>
        </div>
        <ul class="ys-checks pl-recursos">
          <li><?= ys_check() ?><span>Dashboard executivo</span></li>
          <li><?= ys_check() ?><span>CRM, prospecção e ficha completa do cliente</span></li>
          <li><?= ys_check() ?><span>Gestão de processos e prazos</span></li>
          <li><?= ys_check() ?><span>Intimações automáticas (DJEN)</span></li>
          <li><?= ys_check() ?><span>Painel financeiro / DRE</span></li>
          <li><?= ys_check() ?><span>WhatsApp + agente de IA</span></li>
        </ul>
        <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Solo%20do%20Yuris!" target="_blank" rel="noopener" class="ys-btn ys-btn-ghost pl-botao"><?= $waSvg ?> Começar agora</a>
      </article>

      <!-- Equipe: destaque -->
      <article class="pl-card is-popular ys-on-navy">
        <div class="pl-topo">
          <span class="ys-badge pl-selo">Mais popular</span>
          <h2 class="pl-nome">Equipe</h2>
        </div>
        <div class="pl-equipe">
          <div class="pl-usuarios">até <strong>5</strong> usuários</div>
        </div>
        <div class="pl-valor">Valor sob consulta</div>
        <div class="pl-limites">
          <div><strong>3</strong> monitores de intimação</div>
          <div><strong>200</strong> triagens de IA por mês</div>
        </div>
        <ul class="ys-checks pl-recursos">
          <li class="is-base"><?= ys_check() ?><span>Tudo do plano Solo</span></li>
          <li><?= ys_check() ?><span>Chat interno da equipe</span></li>
          <li><?= ys_check() ?><span>Advogados associados</span></li>
          <li><?= ys_check() ?><span>Planejamento comercial e metas</span></li>
        </ul>
        <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Equipe%20do%20Yuris!" target="_blank" rel="noopener" class="ys-btn ys-btn-light pl-botao"><?= $waSvg ?> Começar agora</a>
      </article>

      <!-- Escritório -->
      <article class="pl-card">
        <div class="pl-topo">
          <h2 class="pl-nome">Escritório</h2>
        </div>
        <div class="pl-equipe">
          <div class="pl-usuarios">até <strong>10</strong> usuários</div>
        </div>
        <div class="pl-valor">Valor sob consulta</div>
        <div class="pl-limites">
          <div><strong>6</strong> monitores de intimação</div>
          <div><strong>500</strong> triagens de IA por mês</div>
        </div>
        <ul class="ys-checks pl-recursos">
          <li class="is-base"><?= ys_check() ?><span>Tudo do plano Equipe</span></li>
          <li><?= ys_check() ?><span>Integração AASP</span></li>
          <li><?= ys_check() ?><span>Filiais vinculadas</span></li>
          <li><?= ys_check() ?><span>Webhooks e automações</span></li>
        </ul>
        <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Escrit%C3%B3rio%20do%20Yuris!" target="_blank" rel="noopener" class="ys-btn ys-btn-ghost pl-botao"><?= $waSvg ?> Começar agora</a>
      </article>

      <!-- Studio -->
      <article class="pl-card">
        <div class="pl-topo">
          <h2 class="pl-nome">Studio</h2>
        </div>
        <div class="pl-equipe">
          <div class="pl-usuarios">até <strong>20</strong> usuários</div>
        </div>
        <div class="pl-valor">Valor sob consulta</div>
        <div class="pl-limites">
          <div><strong>12</strong> monitores de intimação</div>
          <div><strong>1.500</strong> triagens de IA por mês</div>
        </div>
        <ul class="ys-checks pl-recursos">
          <li class="is-base"><?= ys_check() ?><span>Tudo do plano Escritório</span></li>
          <li><?= ys_check() ?><span>Suporte prioritário</span></li>
          <li><?= ys_check() ?><span>Estrutura completa de filiais e parceiros</span></li>
        </ul>
        <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20o%20plano%20Studio%20do%20Yuris!" target="_blank" rel="noopener" class="ys-btn ys-btn-ghost pl-botao"><?= $waSvg ?> Começar agora</a>
      </article>

    </div>

    <!-- Enterprise (sob consulta) -->
    <div class="enterprise-band">
      <div>
        <div class="pl-ent-rotulo">Enterprise</div>
        <h2>Para operações que precisam do Yuris moldado a elas.</h2>
        <p>Acima de 20 usuários, com implantação assistida, migração dos seus dados e integração com os sistemas que seu escritório já usa. Escopo e mensalidade definidos junto com você.</p>
        <div class="pl-ent-acao">
          <div class="pl-ent-preco">Sob consulta</div>
          <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20falar%20sobre%20o%20plano%20Enterprise%20do%20Yuris!" target="_blank" rel="noopener" class="ys-btn"><?= $waSvg ?> Falar com um especialista</a>
        </div>
      </div>
      <ul class="ys-checks pl-ent-lista">
        <li><?= ys_check() ?><span>Tudo do plano Studio, sem limite prático de usuários</span></li>
        <li><?= ys_check() ?><span>Implementação assistida, com onboarding guiado</span></li>
        <li><?= ys_check() ?><span>Migração de dados de outra plataforma ou planilhas</span></li>
        <li><?= ys_check() ?><span>Integrações sob medida com os sistemas que você já usa</span></li>
        <li><?= ys_check() ?><span>Treinamento da equipe</span></li>
        <li><?= ys_check() ?><span>Suporte dedicado com SLA</span></li>
      </ul>
    </div>
  </div>
</section>

<!-- Complementos -->
<section class="ys-section is-tight">
  <div class="ys-container pl-compl-grade">
    <div class="pl-compl-cab ys-reveal">
      <h2 class="ys-h2">Precisa de um pouco mais?</h2>
      <p class="ys-lede">Amplie qualquer plano sem precisar subir de faixa. Contrate só o que faltar.</p>
    </div>
    <ul class="pl-addons ys-reveal">
      <li class="addon-item">
        <h3 class="addon-title">Usuário adicional</h3>
        <p class="addon-desc">Para quando a equipe cresce além do limite do plano.</p>
      </li>
      <li class="addon-item">
        <h3 class="addon-title">Monitor de intimação extra</h3>
        <p class="addon-desc">Uma OAB e UF a mais sendo monitorada automaticamente.</p>
      </li>
      <li class="addon-item">
        <h3 class="addon-title">Mais 200 triagens de IA</h3>
        <p class="addon-desc">Para escritórios com alto volume de contato pelo WhatsApp.</p>
      </li>
      <li class="addon-item">
        <h3 class="addon-title">+ Implantação opcional</h3>
        <p class="addon-desc">A gente traz os dados do sistema atual do seu escritório para dentro do Yuris e deixa tudo pronto para usar.</p>
      </li>
    </ul>
  </div>
</section>

<!-- O que está incluído -->
<section class="ys-section is-tight ys-bg-2">
  <div class="ys-container">
    <div class="ys-head is-split pl-base-cab ys-reveal">
      <div><h2 class="ys-h2">Presente em todos os planos</h2></div>
      <p class="ys-lede">A base do sistema é a mesma desde o plano Solo. O que muda entre os planos é o tamanho da equipe, o volume e os recursos avançados.</p>
    </div>
    <div class="pl-base ys-reveal">

      <div class="pl-base-item">
        <span class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></span>
        <div>
          <h3>Dashboard Executivo</h3>
          <p>Visão consolidada de comercial, jurídico e financeiro em tempo real.</p>
        </div>
      </div>

      <div class="pl-base-item">
        <span class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
        <div>
          <h3>CRM, Prospecção e Clientes</h3>
          <p>Funil Kanban, conversão em cliente sem perder o histórico, documentos, etiquetas e campos do escritório.</p>
        </div>
      </div>

      <div class="pl-base-item">
        <span class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
        <div>
          <h3>Gestão de Processos</h3>
          <p>Kanban jurídico, prazos urgentes, tarefas e histórico por processo.</p>
        </div>
      </div>

      <div class="pl-base-item">
        <span class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3v4"/><path d="M6 7h12"/><path d="M8 10v4"/><path d="M16 10v4"/><path d="M5 18c1.5-1 3.5-1 5 0"/><path d="M19 18c-1.5-1-3.5-1-5 0"/></svg></span>
        <div>
          <h3>Painel Jurídico</h3>
          <p>Carga por advogado, tipos de ação, taxa de encerramento e processos vencidos.</p>
        </div>
      </div>

      <div class="pl-base-item">
        <span class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg></span>
        <div>
          <h3>Financeiro / DRE</h3>
          <p>Lançamentos, impostos, P&amp;L completo e análise de margem do escritório.</p>
        </div>
      </div>

      <div class="pl-base-item">
        <span class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>
        <div>
          <h3>Chat WhatsApp + IA</h3>
          <p>Atendimento integrado no número que o escritório já usa, com agente de IA fazendo a triagem inicial.</p>
        </div>
      </div>

      <div class="pl-base-item">
        <span class="ys-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
        <div>
          <h3>Intimações Automáticas</h3>
          <p>Monitoramento diário do DJEN pela OAB. A publicação chega pronta para virar prazo ou tarefa no processo.</p>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- CTA -->
<section class="ys-section is-tight">
  <div class="ys-container">
    <div class="ys-cta pl-cta ys-reveal">
      <div>
        <h2>Pronto para começar?</h2>
        <p>Fale com a gente pelo WhatsApp e tenha o Yuris funcionando no seu escritório hoje mesmo.</p>
      </div>
      <a href="https://wa.me/5511991170602?text=Ol%C3%A1%20Bruno%2C%20quero%20conhecer%20o%20Yuris!" target="_blank" rel="noopener" class="ys-btn"><?= $waSvg ?> Falar com a equipe</a>
    </div>
  </div>
</section>

</main>

<?php ys_footer(['cookies' => true]); ?>

<script src="/assets/site/site.js?v=<?= ys_asset_ver('assets/site/site.js') ?>" defer></script>
<!-- Cookie consent: banner e o link "Gerenciar cookies" do rodapé -->
<script src="/assets/cookie-consent.js?v=3"></script>

</body>
</html>
