<?php
/**
 * content.php, FONTE ÚNICA de conteúdo da landing YURIS v2.
 *
 * Toda a copy visível e os dados que alimentam os schemas (FAQ etc.) saem
 * daqui. Assim o HTML visível e o JSON-LD nunca divergem, corrige o drift
 * latente da v1 (onde FAQ e schema eram strings separadas).
 *
 * Retorna um array associativo consumido por v2/index.php e pelos partials.
 * Os schemas Organization/WebSite/SoftwareApplication são portados VERBATIM
 * de public/index.php (linhas 71-138).
 */

$BASE = 'https://yuris.com.br';

return [

    // ── Meta / SEO ──────────────────────────────────────────────────────────
    'meta' => [
        'title'       => 'Yuris, Sistema Jurídico Inteligente para Advogados',
        'description' => 'Controle processos, prazos, intimações, tarefas, clientes e comunicação em uma plataforma jurídica inteligente para advogados e escritórios.',
        'og_image'    => '/assets/img/og-image.jpg',
    ],

    // CTA padrão (WhatsApp), header, hero e CTA final
    'cta_demo' => 'Olá Bruno, quero uma demonstração do Yuris!',

    // ── HERO (#inicio) ──────────────────────────────────────────────────────
    // O H1 preserva as palavras-chave de SEO (processos, prazos, intimações,
    // clientes, sistema jurídico) e ganha a "raiz" do manual de marca: estrutura.
    'hero' => [
        'eyebrow'           => 'Sistema Jurídico Inteligente',
        'h1_html'           => 'A <em>estrutura</em> que sustenta processos, prazos, intimações e clientes, em um só sistema jurídico inteligente.',
        'sub'               => 'O Yuris centraliza processos, tarefas, intimações, comunicação, equipe e automações em uma plataforma moderna para advogados e escritórios jurídicos.',
        'cta_primary_label' => 'Solicitar demonstração',
        'cta_ghost_label'   => 'Conhecer funcionalidades',
        'cta_ghost_href'    => '#recursos',
        'trust' => [
            'Isolamento multi-tenant',
            'Logs de auditoria',
            '2FA e controle de acesso',
            'Preparado para apoiar a LGPD',
        ],
        'chips' => [
            ['n' => '7',  'label' => '<strong>intimações</strong> monitoradas'],
            ['n' => '12', 'label' => '<strong>prazos</strong> organizados'],
            ['n' => '',   'label' => 'Tarefas em dia'],
            ['n' => '',   'label' => 'Atendimento centralizado'],
        ],
    ],

    // ── FAQ (#faq), alimenta o HTML visível E o schema FAQPage (sem drift) ──
    'faq' => [
        ['q' => 'O que é o Yuris?', 'a' => 'O Yuris é um sistema jurídico para advogados e escritórios de advocacia que centraliza processos, prazos, intimações, clientes, tarefas, financeiro e atendimento por WhatsApp em uma única plataforma, com LGPD e auditoria como parte da estrutura.'],
        ['q' => 'Quanto custa o Yuris?', 'a' => 'Os planos são públicos e começam em R$ 220 por mês, com tudo incluído, sem cobrança separada por módulo. O valor varia conforme o número de usuários do escritório. A tabela completa está na página de planos.'],
        ['q' => 'O Yuris serve para advogado autônomo ou só para escritórios?', 'a' => 'Os dois. O plano de entrada atende de 1 a 2 usuários, e a mesma plataforma escala para equipes e estruturas com matriz e filiais, sem trocar de sistema no caminho.'],
        ['q' => 'O Yuris monitora intimações automaticamente?', 'a' => 'Sim. O sistema acompanha as publicações oficiais e identifica novas intimações automaticamente. Cada intimação pode ser vinculada ao processo correspondente e convertida em prazo ou tarefa com responsável, sem depender de conferência manual.'],
        ['q' => 'Como o Yuris trata a LGPD?', 'a' => 'Como camada estrutural: isolamento de dados entre escritórios, permissões por escopo, trilha de auditoria imutável, anonimização e atendimento aos direitos do titular. O Yuris adota medidas técnicas e organizacionais de proteção de dados e segue em processo contínuo de adequação à LGPD.'],
        ['q' => 'Quando a prospecção vira cliente, o histórico se perde?', 'a' => 'Não. A conversão é um evento na linha do tempo, não um recomeço. A ficha do cliente mostra tudo desde o primeiro contato, e os processos, as conversas de WhatsApp, as tarefas e os documentos daquela prospecção continuam ligados a ela. O sistema ainda avisa quando aquele cliente já existe, antes de criar um registro duplicado.'],
        ['q' => 'Dá para guardar documentos e criar campos próprios do meu escritório?', 'a' => 'Sim. Cliente e prospecção aceitam documentos anexados, etiquetas de classificação e campos personalizados criados pelo próprio escritório, como NIT, data de admissão ou área de atuação. Ligações, reuniões e notas internas também ficam registradas com autor e data, dentro da mesma linha do tempo. Os downloads passam por autenticação: o arquivo nunca fica acessível por URL direta.'],
        ['q' => 'Como faço para conhecer o sistema?', 'a' => 'Pelo WhatsApp: a equipe agenda uma demonstração e apresenta o Yuris aplicado à rotina do seu escritório, volume de processos, tamanho da equipe e forma de atendimento.'],
    ],

    // ── Schemas JSON-LD (portados verbatim de public/index.php:71-138) ───────
    'schemas' => [
        [
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            '@id'         => $BASE . '/#org',
            'name'        => 'Yuris',
            'url'         => $BASE . '/',
            'logo'        => ['@type' => 'ImageObject', 'url' => $BASE . '/assets/img/logo-512.png', 'width' => 512, 'height' => 512],
            'description' => 'Yuris, Sistema Jurídico Inteligente: SaaS de gestão para advogados e escritórios de advocacia no Brasil.',
            'contactPoint' => ['@type' => 'ContactPoint', 'contactType' => 'sales', 'url' => 'https://wa.me/5511991170602', 'availableLanguage' => 'Portuguese'],
        ],
        [
            '@context'   => 'https://schema.org',
            '@type'      => 'WebSite',
            '@id'        => $BASE . '/#website',
            'url'        => $BASE . '/',
            'name'       => 'Yuris',
            'inLanguage' => 'pt-BR',
            'publisher'  => ['@id' => $BASE . '/#org'],
        ],
        [
            '@context'               => 'https://schema.org',
            '@type'                  => 'SoftwareApplication',
            '@id'                    => $BASE . '/#app',
            'name'                   => 'Yuris, Sistema Jurídico Inteligente',
            'url'                    => $BASE . '/',
            'image'                  => $BASE . '/assets/img/og-image.jpg',
            'applicationCategory'    => 'BusinessApplication',
            'applicationSubCategory' => 'Sistema jurídico / gestão para escritórios de advocacia',
            'operatingSystem'        => 'Web',
            'inLanguage'             => 'pt-BR',
            'description'            => 'Sistema jurídico para advogados e escritórios: processos, prazos, intimações, CRM, financeiro, tarefas e WhatsApp em uma única plataforma com LGPD e auditoria.',
            'audience'               => ['@type' => 'Audience', 'audienceType' => 'Advogados e escritórios de advocacia'],
            'featureList'            => [
                'Gestão de processos com histórico auditado',
                'Monitoramento de intimações nas publicações oficiais',
                'CRM jurídico em Kanban',
                'Conversão de prospecção em cliente com histórico preservado',
                'Linha do tempo única do primeiro contato em diante',
                'Clientes e contatos centralizados',
                'Documentos anexados ao cliente e à prospecção',
                'Etiquetas e classificações por escritório',
                'Campos personalizados por escritório',
                'Registro de ligações, reuniões e notas internas',
                'Canal de aquisição do lead',
                'Prevenção de cliente duplicado',
                'Atendimento por WhatsApp integrado',
                'Financeiro com DRE e recorrências',
                'Tarefas em Kanban, lista e calendário',
                'Webhooks para n8n, Make e Zapier',
                'LGPD e auditoria estruturais',
                'Multi-tenant com matriz e filial',
            ],
            'offers'                 => ['@type' => 'AggregateOffer', 'priceCurrency' => 'BRL', 'lowPrice' => '220', 'highPrice' => '670', 'offerCount' => 4, 'url' => $BASE . '/planos'],
            'provider'               => ['@id' => $BASE . '/#org'],
        ],
    ],
];
