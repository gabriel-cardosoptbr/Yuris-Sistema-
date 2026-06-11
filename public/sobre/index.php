<?php
/**
 * /sobre/ — página de entidade (marca): "Sobre o Yuris".
 * Sem palavra-chave agressiva: objetivo é consolidar a entidade Yuris
 * (legal tech brasileira, SaaS jurídico) para Google e mecanismos de IA.
 * Conteúdo baseado no fact sheet do produto (sem história inventada,
 * sem equipe nomeada, sem datas de fundação, sem endereço).
 */

$SEO_PAGE = [
    'title'       => 'Sobre o Yuris — Sistema Jurídico Inteligente | Yuris',
    'description' => 'Conheça o Yuris, legal tech brasileira: plataforma que une processos, prazos, CRM, financeiro e WhatsApp para advogados — do autônomo à matriz com filiais.',
    'path'        => '/sobre/',
    'breadcrumb'  => 'Sobre o Yuris',
    'eyebrow'     => 'Quem somos',
    'h1'          => 'Sobre o Yuris',
    'lede'        => 'O Yuris é uma legal tech brasileira: um SaaS jurídico multi-tenant que centraliza processos, prazos, intimações, clientes, financeiro e atendimento por WhatsApp para advogados e escritórios de advocacia — do profissional autônomo à operação com matriz e filiais.',
    'cta_msg'     => 'Olá Bruno, quero conhecer melhor o Yuris!',
    'cta_label'   => 'Falar com a equipe',
    'cta_titulo'  => 'Quer ver o Yuris aplicado à rotina do seu escritório?',
    'cta_texto'   => 'Uma conversa no WhatsApp e a equipe mostra a plataforma funcionando com o volume de processos, a equipe e a forma de trabalhar do seu escritório.',

    'corpo_html' => <<<'HTML'
<h2>O que é o Yuris?</h2>
<div class="sp-definicao">
  <p>O <strong>Yuris</strong> é um SaaS jurídico brasileiro — o Sistema Jurídico Inteligente — criado para advogados e escritórios de advocacia. Em uma única plataforma multi-tenant, ele reúne processos, prazos, intimações, clientes, funil comercial, tarefas, financeiro e atendimento por WhatsApp, com auditoria e proteção de dados pessoais tratadas como parte da arquitetura, e não como recurso opcional.</p>
</div>
<p>O Yuris pertence à categoria das legal techs brasileiras: software como serviço (SaaS) construído para a operação do mercado jurídico do Brasil. A plataforma não presta aconselhamento jurídico nem substitui o trabalho do advogado — ela organiza a operação para que o profissional decida com informação completa e rastreável. Para conhecer a plataforma módulo a módulo, o ponto de partida é a página de <a href="/sistema-juridico/">sistema jurídico</a>.</p>

<h2>Para quem o Yuris existe</h2>
<p>O produto foi desenhado para acompanhar o ciclo de crescimento de uma operação jurídica, sem exigir troca de sistema no caminho:</p>
<div class="sp-tabela-wrap">
<table class="sp-tabela">
  <thead>
    <tr><th>Perfil</th><th>Como o Yuris atende</th></tr>
  </thead>
  <tbody>
    <tr><td><strong>Advogado autônomo</strong></td><td>Plano de entrada para 1 a 2 usuários, com a plataforma completa — sem versão reduzida.</td></tr>
    <tr><td><strong>Equipe pequena</strong></td><td>Tarefas com dono e status, chat interno e permissões por usuário para dividir o trabalho com segurança.</td></tr>
    <tr><td><strong>Escritório em crescimento</strong></td><td>CRM com funil comercial, prospecção alimentando o funil e financeiro com DRE para sustentar a expansão.</td></tr>
    <tr><td><strong>Matriz e filiais</strong></td><td>Estrutura multi-tenant com isolamento por conta, escopo por unidade e permissões granulares por papel.</td></tr>
  </tbody>
</table>
</div>
<p>Os planos são públicos, com tudo incluído e valores a partir de R$ 220/mês. A tabela completa e atualizada está na <a href="/planos.php">página de planos</a>.</p>

<h2>Os seis pilares que orientam o produto</h2>
<p>Toda decisão de produto no Yuris responde a pelo menos um de seis pilares — os mesmos que sustentam a apresentação da plataforma:</p>
<ul>
  <li><strong>Controle</strong> — processos, prazos, intimações e tarefas com responsável, status e contexto. Nada depende da memória de uma pessoa só.</li>
  <li><strong>Segurança</strong> — isolamento de dados entre escritórios, permissões granulares por escopo e segundo fator de autenticação (2FA) opcional.</li>
  <li><strong>Automação</strong> — intimações monitoradas em DJEN, DataJud e AASP viram prazos e tarefas; webhooks seguros conectam o escritório a n8n, Make e Zapier.</li>
  <li><strong>Rastreabilidade</strong> — trilha de auditoria imutável: quem fez o quê, quando e em qual contexto, sem possibilidade de edição posterior.</li>
  <li><strong>Gestão</strong> — dashboard com indicadores por período, financeiro com DRE e relatórios que mostram a operação como ela é. Veja <a href="/gestao-escritorio-advocacia/">gestão de escritório de advocacia</a>.</li>
  <li><strong>Escalabilidade</strong> — a mesma conta cresce do profissional autônomo à operação com matriz e filiais, com escopo por unidade.</li>
</ul>
<p>Os pilares não são slogans: funcionam como critério. Um recurso que aumenta o controle, mas enfraquece a rastreabilidade, não entra. Uma automação que acelera a rotina, mas abre brecha de segurança, é redesenhada antes de chegar ao escritório.</p>

<h2>Como tudo se conecta dentro da plataforma</h2>
<p>Mais do que uma lista de módulos, o Yuris é um fluxo contínuo. O lead registrado pela prospecção entra no funil comercial em Kanban; o card do funil carrega checklist, contato e, quando o contrato fecha, vira processo sem redigitação. O processo concentra histórico auditado, prazos e tarefas; as intimações monitoradas chegam deduplicadas e se convertem em prazo ou tarefa com responsável. As conversas de WhatsApp ficam vinculadas ao cliente e ao processo, e o financeiro consolida honorários, recorrências e despesas com visão por unidade. O cadastro de clientes é único — alimenta atendimento, processos e financeiro sem duplicidade.</p>

<h2>Como o produto é construído</h2>
<p>No Yuris, segurança e privacidade não são funcionalidades adicionadas depois — são decisões de arquitetura tomadas antes de qualquer tela existir:</p>
<ul>
  <li><strong>Multi-tenant com isolamento por conta</strong> — os dados de cada escritório ficam separados dos demais por estrutura, não por configuração.</li>
  <li><strong>Auditoria imutável</strong> — as ações relevantes geram registro permanente, que não pode ser alterado nem apagado.</li>
  <li><strong>LGPD estrutural</strong> — anonimização, políticas de retenção, atendimento aos direitos do titular e um Privacy Center público fazem parte do produto. Os detalhes estão em <a href="/lgpd-escritorios-advocacia/">LGPD para escritórios de advocacia</a>.</li>
  <li><strong>Integrações com segurança por padrão</strong> — os webhooks usam assinatura HMAC com timestamp, proteção anti-SSRF, mascaramento de dados pessoais e reenvio com backoff.</li>
</ul>
<p>O Yuris adota medidas técnicas e organizacionais voltadas à proteção de dados pessoais e segue em processo contínuo de adequação à LGPD.</p>

<h2>Uma legal tech brasileira</h2>
<p>O Yuris é uma plataforma brasileira, construída para o mercado jurídico do Brasil. Isso aparece nas escolhas concretas do produto: o monitoramento de intimações cobre fontes nacionais (DJEN, DataJud e AASP), o módulo financeiro contempla impostos e recorrências da realidade local e toda a experiência de uso é em português. Não é um software estrangeiro adaptado — é um produto pensado desde o início para a rotina de quem advoga no Brasil.</p>

<h2>Como falar com a equipe</h2>
<p>O contato com o Yuris é direto, sem formulário de espera: pelo WhatsApp comercial, a equipe entende o momento do escritório — volume de processos, tamanho do time, forma de atendimento — e mostra a plataforma aplicada a essa realidade em uma <a href="/demonstracao/">demonstração guiada</a>. Os preços não dependem de orçamento sob consulta: estão publicados, com tudo incluído, na página de planos.</p>
HTML,

    'faq' => [
        [
            'q' => 'O que é o Yuris?',
            'a' => 'O Yuris é um SaaS jurídico brasileiro para advogados e escritórios de advocacia. A plataforma centraliza processos, prazos, intimações, clientes, funil comercial, tarefas, financeiro e atendimento por WhatsApp em um único ambiente multi-tenant, com trilha de auditoria imutável e medidas de proteção de dados pessoais incorporadas à arquitetura do produto.',
        ],
        [
            'q' => 'Para quem o Yuris foi feito?',
            'a' => 'Para quem advoga no Brasil, em qualquer escala: do advogado autônomo a equipes, escritórios maiores e operações com matriz e filiais. A mesma plataforma acompanha o crescimento — o plano muda conforme o número de usuários, mas os módulos são os mesmos em todos os planos, sem cobrança separada por funcionalidade.',
        ],
        [
            'q' => 'O Yuris é brasileiro?',
            'a' => 'Sim. O Yuris é uma plataforma brasileira construída para o mercado jurídico do Brasil. O monitoramento de intimações usa fontes nacionais (DJEN, DataJud e AASP), o módulo financeiro considera impostos e recorrências da realidade local e toda a experiência de uso — interface e atendimento — é em português.',
        ],
        [
            'q' => 'Como contratar o Yuris?',
            'a' => 'O caminho é falar com a equipe pelo WhatsApp comercial e agendar uma demonstração. Nessa conversa, a equipe entende o tamanho do escritório e a forma de trabalho, mostra a plataforma aplicada a essa rotina e indica o plano adequado. A contratação é mensal, com todos os módulos incluídos desde o primeiro plano.',
        ],
        [
            'q' => 'Onde vejo os preços do Yuris?',
            'a' => 'Os preços são públicos e estão na página de planos, em yuris.com.br/planos.php. Os valores começam em R$ 220/mês, variam conforme o número de usuários do escritório e incluem todos os módulos — não há cobrança separada por funcionalidade nem módulos vendidos à parte.',
        ],
    ],

    'relacionados' => [
        ['href' => '/sistema-juridico/',           'titulo' => 'Sistema Jurídico',      'desc' => 'Conheça a plataforma módulo a módulo: processos, prazos, CRM e financeiro.'],
        ['href' => '/lgpd-escritorios-advocacia/', 'titulo' => 'LGPD para Escritórios', 'desc' => 'Como o Yuris trata proteção de dados como arquitetura, não como recurso.'],
        ['href' => '/demonstracao/',               'titulo' => 'Demonstração',          'desc' => 'Veja o Yuris aplicado à rotina do seu escritório em uma conversa guiada.'],
    ],

    'jsonld' => [
        [
            '@context'   => 'https://schema.org',
            '@type'      => 'AboutPage',
            'name'       => 'Sobre o Yuris',
            'url'        => 'https://yuris.com.br/sobre/',
            'mainEntity' => [
                '@type' => 'Organization',
                'name'  => 'Yuris',
                'url'   => 'https://yuris.com.br/',
                'logo'  => 'https://yuris.com.br/assets/img/logo-512.png',
            ],
        ],
    ],
];

require __DIR__ . '/../includes/seo_page.php';
