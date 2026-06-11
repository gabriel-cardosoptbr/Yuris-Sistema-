<?php
/**
 * /blog/ — índice do blog do Yuris.
 *
 * ESTADO ATUAL: estrutura pronta, SEM posts publicados → noindex,follow
 * e FORA do sitemap.xml de propósito (página fina não deve competir no índice).
 *
 * QUANDO PUBLICAR O 1º ARTIGO:
 *   1. Criar o post como pasta: public/blog/<slug-do-post>/index.php
 *      usando o template seo_page.php (mesmo padrão das páginas de soluções)
 *      + schema BlogPosting no campo 'jsonld'.
 *   2. Adicionar o post ao array $POSTS abaixo.
 *   3. Trocar 'robots' => 'noindex,follow' por 'index,follow' AQUI.
 *   4. Incluir /blog/ e o post no sitemap.xml.
 * Pautas prontas: docs/seo/pautas-blog.md (30 pautas por categoria).
 */

$POSTS = [
    // ['href' => '/blog/exemplo-slug/', 'titulo' => '...', 'desc' => '...', 'data' => '2026-06-20', 'categoria' => 'Gestão Jurídica'],
];

$listaPosts = '';
if ($POSTS) {
    foreach ($POSTS as $p) {
        $listaPosts .= '<a class="lp-card" href="' . htmlspecialchars($p['href']) . '"><h3>' . htmlspecialchars($p['titulo']) . '</h3><p>' . htmlspecialchars($p['desc']) . '</p></a>';
    }
}

$SEO_PAGE = [
    'title'       => 'Blog do Yuris — Gestão Jurídica e Legal Tech',
    'description' => 'Conteúdo sobre gestão de escritórios de advocacia, automação jurídica, CRM jurídico, financeiro, LGPD e legal tech. Em breve, os primeiros artigos.',
    'path'        => '/blog/',
    'robots'      => 'noindex,follow',
    'breadcrumb'  => 'Blog',
    'eyebrow'     => 'Blog',
    'h1'          => 'Blog do Yuris: gestão jurídica na prática',
    'lede'        => 'Conteúdo direto sobre a operação de escritórios de advocacia: gestão, automação, CRM, financeiro, LGPD e legal tech — escrito para quem toca a rotina jurídica todos os dias.',
    'cta_msg'     => 'Olá Bruno, quero uma demonstração do Yuris!',
    'cta_label'   => 'Solicitar demonstração',
    'cta_titulo'  => 'Enquanto os artigos não chegam, veja o Yuris funcionando',
    'cta_texto'   => 'A demonstração pelo WhatsApp mostra o sistema aplicado à rotina do seu escritório.',

    'corpo_html' => <<<HTML
<h2>O que você vai encontrar aqui</h2>
<p>Os primeiros artigos estão em produção. O blog vai cobrir estas frentes:</p>
<ul>
  <li><strong>Gestão Jurídica</strong> — organização da operação, rotina e equipe do escritório.</li>
  <li><strong>Automação Jurídica</strong> — o que automatizar, como e com quais garantias de rastreabilidade.</li>
  <li><strong>CRM Jurídico</strong> — funil comercial, relacionamento e conversão do lead em cliente.</li>
  <li><strong>Prospecção para Advogados</strong> — organização da captação dentro das regras da advocacia.</li>
  <li><strong>Financeiro para Escritórios</strong> — DRE, honorários, recorrências e visão por unidade.</li>
  <li><strong>LGPD e Segurança</strong> — proteção de dados na prática da advocacia.</li>
  <li><strong>Produtividade Jurídica</strong> — menos retrabalho, mais controle.</li>
  <li><strong>Legal Tech</strong> — o mercado de tecnologia jurídica no Brasil.</li>
  <li><strong>Inteligência Artificial no Direito</strong> — usos reais, limites e responsabilidade.</li>
</ul>
<p>Enquanto isso, as páginas de soluções já respondem as principais dúvidas: <a href="/sistema-juridico/">sistema jurídico</a>, <a href="/crm-juridico/">CRM jurídico</a>, <a href="/automacao-juridica/">automação jurídica</a>, <a href="/controle-de-processos/">controle de processos</a>, <a href="/financeiro-juridico/">financeiro jurídico</a> e <a href="/lgpd-escritorios-advocacia/">LGPD para escritórios</a>.</p>
{$listaPosts}
HTML,

    'relacionados' => [
        ['href' => '/sistema-juridico/', 'titulo' => 'Sistema Jurídico',  'desc' => 'O que é e o que o Yuris centraliza na operação do escritório.'],
        ['href' => '/planos.php',        'titulo' => 'Planos',            'desc' => 'Preços públicos, tudo incluído, a partir de R$ 220/mês.'],
        ['href' => '/demonstracao/',     'titulo' => 'Demonstração',      'desc' => 'Veja o sistema aplicado à rotina do seu escritório.'],
    ],
];

require __DIR__ . '/../includes/seo_page.php';
