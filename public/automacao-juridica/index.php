<?php
/**
 * /automacao-juridica/ — página estratégica de SEO.
 * Palavra-chave principal: "automação jurídica" · secundárias: "automação para advogados",
 * "automação de escritório de advocacia", "webhooks jurídicos".
 * Intenção: comercial/informacional — escritório buscando automatizar rotinas repetitivas.
 * Conteúdo baseado no fact sheet do produto (sem claims não confirmados).
 */

$SEO_PAGE = [
    'title'       => 'Automação Jurídica para Escritórios de Advocacia | Yuris',
    'description' => 'Automação jurídica na prática: intimações DJEN, DataJud e AASP viram prazos, webhooks seguros disparam fluxos em n8n, Make e Zapier. Conheça o Yuris.',
    'path'        => '/automacao-juridica/',
    'breadcrumb'  => 'Automação Jurídica',
    'eyebrow'     => 'Automação Jurídica',
    'h1'          => 'Automação jurídica: tire o trabalho repetitivo da equipe sem perder o controle',
    'lede'        => 'Automação jurídica é tirar trabalho repetitivo do humano mantendo rastreabilidade. No Yuris, isso significa três frentes prontas hoje: intimações de DJEN, DataJud e AASP monitoradas automaticamente e convertidas em prazos e tarefas; webhooks seguros que disparam fluxos em n8n, Make e Zapier; e recorrências do financeiro lançadas sem digitação manual.',
    'cta_msg'     => 'Olá Bruno, quero conhecer as automações do Yuris!',
    'cta_label'   => 'Solicitar demonstração',
    'cta_titulo'  => 'Veja as automações do Yuris rodando com a rotina do seu escritório',
    'cta_texto'   => 'Uma conversa rápida no WhatsApp e a equipe mostra intimações, webhooks e recorrências aplicados ao volume de processos e às ferramentas que o seu escritório já usa.',

    'corpo_html' => <<<'HTML'
<h2>O que é automação jurídica?</h2>
<div class="sp-definicao">
  <p><strong>Automação jurídica</strong> é o uso de software para executar as tarefas repetitivas da rotina de advogados e escritórios de advocacia — conferir publicações, criar prazos, avisar a equipe, lançar cobranças recorrentes — sem intervenção manual e sem perder a rastreabilidade. O objetivo não é substituir o advogado: é tirar o trabalho mecânico do humano e deixar registro auditável de cada ação executada.</p>
</div>
<p>Automação jurídica séria não é promessa de inteligência artificial mágica. É regra clara, executada de forma consistente, com trilha de auditoria: o sistema faz o que foi configurado para fazer, todas as vezes, e o escritório consegue verificar quando, como e sobre qual processo cada automação rodou. Essa é a abordagem do Yuris, descrita em detalhe na página do <a href="/sistema-juridico/">sistema jurídico</a>.</p>

<h2>O que dá para automatizar hoje com o Yuris</h2>
<p>Na automação de escritório de advocacia, três frentes concentram o maior ganho de tempo: intimações, integrações por webhooks e recorrências financeiras.</p>

<h3>1. Intimações: da publicação ao prazo, sem conferência manual</h3>
<p>O Yuris monitora publicações judiciais em múltiplas fontes — DJEN, DataJud e AASP (esta última com chave de acesso do próprio escritório). As intimações encontradas passam por deduplicação por hash: a mesma publicação capturada em mais de uma fonte aparece uma única vez, sem ruído. A partir daí, a intimação pode ser vinculada ao processo e convertida em prazo ou tarefa com responsável definido. O ciclo que antes exigia conferência diária de diários passa a ser: o sistema encontra, a equipe decide. Veja como isso se encaixa no <a href="/controle-de-processos/">controle de processos</a>.</p>

<h3>2. Webhooks: eventos do sistema disparam fluxos externos</h3>
<p>Webhooks jurídicos são avisos automáticos que o sistema envia para ferramentas externas quando algo acontece dentro dele. No Yuris, eventos como card criado no funil, processo atualizado ou intimação vinculada podem disparar fluxos em n8n, Make e Zapier. Na prática, o escritório monta os próprios encadeamentos:</p>
<ul>
  <li>Um card novo no <a href="/crm-juridico/">CRM jurídico</a> avisa o responsável comercial no canal que a equipe já usa.</li>
  <li>Uma intimação vinculada alimenta um fluxo de comunicação interna ou um painel próprio do escritório.</li>
  <li>Uma atualização de processo registra o evento em planilhas ou sistemas mantidos em paralelo.</li>
</ul>
<p>O desenho do fluxo é decisão do escritório; o papel do Yuris é garantir que o evento chegue ao destino de forma íntegra e segura.</p>

<h3>3. Financeiro: recorrências sem digitação</h3>
<p>Honorários mensais e contratos recorrentes não precisam ser lançados à mão todo mês. O módulo de <a href="/financeiro-juridico/">financeiro jurídico</a> trabalha com recorrências: o lançamento se repete conforme a regra definida, e a equipe acompanha contas, impostos e DRE com escopo por unidade — em vez de reconstruir a cobrança do zero a cada ciclo.</p>

<h2>Rotina manual vs. rotina automatizada</h2>
<p>O efeito da automação para advogados fica claro quando se compara o mesmo dia de trabalho nas duas versões:</p>
<div class="sp-tabela-wrap">
<table class="sp-tabela">
  <thead>
    <tr><th>Rotina</th><th>Manual</th><th>Automatizada com o Yuris</th></tr>
  </thead>
  <tbody>
    <tr><td><strong>Conferência de intimações</strong></td><td>Abrir cada fonte e buscar nomes, todos os dias</td><td>Monitoramento automático em DJEN, DataJud e AASP</td></tr>
    <tr><td><strong>Publicação repetida em duas fontes</strong></td><td>Lida duas vezes, com risco de prazo duplicado</td><td>Deduplicação por hash: registro único</td></tr>
    <tr><td><strong>Criação do prazo</strong></td><td>Digitada à mão depois da leitura</td><td>Intimação vira prazo ou tarefa com responsável</td></tr>
    <tr><td><strong>Aviso à equipe</strong></td><td>Mensagem enviada quando alguém lembra</td><td>Webhook dispara o fluxo em n8n, Make ou Zapier</td></tr>
    <tr><td><strong>Honorários recorrentes</strong></td><td>Lançados um a um, todo mês</td><td>Recorrência gera o lançamento sozinha</td></tr>
    <tr><td><strong>Registro do que foi feito</strong></td><td>Depende de quem executou anotar</td><td>Trilha de auditoria imutável</td></tr>
  </tbody>
</table>
</div>

<h2>Automação com segurança de verdade</h2>
<p>Automatizar significa deixar dados de clientes e processos circularem entre sistemas — e é exatamente aí que muitas integrações falham. Os webhooks do Yuris foram construídos com controles de segurança explícitos:</p>
<ul>
  <li><strong>Assinatura HMAC com timestamp</strong> — o destino verifica que o evento veio mesmo do Yuris e não foi adulterado nem reenviado.</li>
  <li><strong>Proteção anti-SSRF</strong> — o sistema valida os destinos das chamadas para impedir que o mecanismo de webhooks seja usado contra a própria infraestrutura.</li>
  <li><strong>Mascaramento de PII</strong> — dados pessoais sensíveis podem ser mascarados no conteúdo enviado, antes de sair do sistema.</li>
  <li><strong>Retry com backoff</strong> — se o destino estiver fora do ar, o Yuris reenvia o evento em intervalos progressivos, sem perdê-lo.</li>
  <li><strong>Rotação de secret</strong> — a chave de assinatura pode ser trocada sem derrubar a integração.</li>
</ul>
<p>Cada ação relevante também fica registrada na trilha de auditoria imutável do sistema. O Yuris adota medidas técnicas e organizacionais voltadas à proteção de dados pessoais e segue em processo contínuo de adequação à LGPD — o tema é aprofundado em <a href="/lgpd-escritorios-advocacia/">LGPD para escritórios de advocacia</a>.</p>

<h2>E a inteligência artificial?</h2>
<p>As automações desta página funcionam por regras, sem depender de IA. À parte delas, o Yuris oferece um módulo de agente de IA para o WhatsApp configurável pelo próprio escritório, usando chave de API própria (OpenAI, Anthropic ou Gemini) — o comportamento e os limites são definidos por quem configura, e nada responde clientes sem que o escritório tenha decidido como.</p>

<h2>Por onde começar</h2>
<p>As automações não são módulos vendidos à parte: intimações, webhooks e recorrências fazem parte da plataforma, em planos com tudo incluído a partir de R$ 220/mês — a tabela completa está na <a href="/planos.php">página de planos</a>. O ponto de partida prático é identificar onde o trabalho repetitivo mais custa hoje no seu escritório: conferência de publicações, avisos à equipe ou lançamentos financeiros — e automatizar primeiro o que dói mais.</p>
HTML,

    'faq' => [
        [
            'q' => 'O que é automação jurídica?',
            'a' => 'Automação jurídica é o uso de software para executar tarefas repetitivas da rotina de um escritório de advocacia — monitorar publicações, criar prazos a partir de intimações, avisar a equipe sobre eventos e lançar cobranças recorrentes — sem intervenção manual. A automação bem feita mantém rastreabilidade: cada ação executada fica registrada em trilha de auditoria, e o advogado continua no controle das decisões.',
        ],
        [
            'q' => 'Como o Yuris automatiza o acompanhamento de intimações?',
            'a' => 'O Yuris monitora publicações judiciais em três fontes — DJEN, DataJud e AASP — e aplica deduplicação por hash, de modo que a mesma publicação capturada em mais de uma fonte apareça uma única vez. A intimação encontrada pode ser vinculada ao processo e convertida em prazo ou tarefa com responsável definido. A fonte AASP exige chave de acesso do próprio escritório.',
        ],
        [
            'q' => 'O que são webhooks jurídicos e para que servem?',
            'a' => 'Webhooks jurídicos são notificações automáticas que o sistema envia para ferramentas externas quando um evento acontece — por exemplo, card criado no funil, processo atualizado ou intimação vinculada. No Yuris, esses eventos disparam fluxos em n8n, Make e Zapier, permitindo que o escritório conecte o sistema às ferramentas que já usa, sem digitação manual entre elas.',
        ],
        [
            'q' => 'Os webhooks do Yuris são seguros?',
            'a' => 'Sim. Eles foram construídos com controles explícitos: assinatura HMAC com timestamp para verificar origem e integridade, proteção anti-SSRF na validação dos destinos, mascaramento de dados pessoais (PII) no conteúdo enviado, reenvio automático com backoff quando o destino está indisponível e rotação de secret sem derrubar a integração. As ações também ficam registradas na trilha de auditoria do sistema.',
        ],
        [
            'q' => 'A automação jurídica do Yuris usa inteligência artificial?',
            'a' => 'As automações principais — intimações, webhooks e recorrências financeiras — funcionam por regras, sem depender de IA. Existe, à parte, um módulo de agente de IA para o WhatsApp, configurável pelo próprio escritório com chave de API própria (OpenAI, Anthropic ou Gemini). O comportamento desse agente é definido por quem configura; nada responde clientes sem que o escritório tenha decidido como.',
        ],
        [
            'q' => 'Quanto custa automatizar o escritório com o Yuris?',
            'a' => 'As automações fazem parte da plataforma, sem cobrança por módulo: intimações, webhooks e recorrências estão incluídos nos planos, que começam em R$ 220/mês e variam conforme o número de usuários. A tabela completa e atualizada está na página de planos do site, e a demonstração é agendada pelo WhatsApp com a equipe do Yuris.',
        ],
    ],

    'relacionados' => [
        ['href' => '/controle-de-processos/', 'titulo' => 'Controle de Processos', 'desc' => 'Prazos, histórico auditado e intimações vinculadas ao processo certo.'],
        ['href' => '/crm-juridico/',          'titulo' => 'CRM Jurídico',          'desc' => 'Funil comercial em Kanban cujos eventos podem disparar webhooks.'],
        ['href' => '/financeiro-juridico/',   'titulo' => 'Financeiro Jurídico',   'desc' => 'DRE, impostos e recorrências com escopo por unidade.'],
    ],
];

require __DIR__ . '/../includes/seo_page.php';
