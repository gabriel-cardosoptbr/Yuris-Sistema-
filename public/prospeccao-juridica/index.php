<?php
/**
 * /prospeccao-juridica/ — página estratégica de SEO.
 * Palavra-chave principal: "prospecção jurídica" · secundárias: "captação de
 * clientes na advocacia", "funil comercial para advogados", "gestão de leads advocacia".
 * Intenção: comercial/informacional — advogado buscando organizar a captação.
 * Ângulo ético: organização do fluxo de quem JÁ procura o escritório (regras da
 * OAB sobre captação de clientela) — sem prometer "mais clientes" nem táticas ativas.
 * Conteúdo baseado no fact sheet do produto (sem claims não confirmados).
 */

$SEO_PAGE = [
    'title'       => 'Prospecção Jurídica e Gestão de Leads na Advocacia | Yuris',
    'description' => 'Prospecção jurídica organizada: registre leads, acompanhe o funil comercial e não perca oportunidades por desorganização. Conheça o módulo do Yuris.',
    'path'        => '/prospeccao-juridica/',
    'breadcrumb'  => 'Prospecção Jurídica',
    'eyebrow'     => 'Prospecção Jurídica',
    'h1'          => 'Prospecção jurídica: organize quem já procura o seu escritório',
    'lede'        => 'Prospecção jurídica, no contexto de gestão, é a organização do fluxo de pessoas que já procuram o escritório — por indicação, site ou WhatsApp — em um funil comercial claro. No Yuris, o módulo de Prospecção registra cada lead, alimenta o funil em Kanban e mantém o follow-up visível até a decisão, sem oportunidade esquecida por desorganização.',
    'cta_msg'     => 'Olá Bruno, quero conhecer a prospecção jurídica do Yuris!',
    'cta_label'   => 'Solicitar demonstração',
    'cta_titulo'  => 'Veja o funil de prospecção do Yuris com o fluxo real do seu escritório',
    'cta_texto'   => 'Uma conversa rápida no WhatsApp e a equipe mostra como os contatos que chegam ao seu escritório viram um funil organizado — do registro ao processo.',

    'corpo_html' => <<<'HTML'
<h2>O que é prospecção jurídica?</h2>
<div class="sp-definicao">
  <p><strong>Prospecção jurídica</strong>, no contexto de gestão de escritórios, é o processo de registrar e organizar as pessoas que procuram o escritório — por indicação, pelo site ou pelo WhatsApp — e conduzir cada contato por um funil comercial claro, do primeiro atendimento até a decisão de contratar. Não se trata de sair atrás de clientes: trata-se de não perder quem já chegou.</p>
</div>
<p>A distinção importa porque a advocacia tem regras próprias da OAB sobre publicidade e captação de clientela. O que o escritório pode — e deve — fazer é tratar com profissionalismo o fluxo de interessados que já o procuram: responder em tempo razoável, registrar o contato, acompanhar a conversa e dar um desfecho claro a cada caso. É exatamente esse fluxo que o módulo de Prospecção do Yuris organiza.</p>

<h2>Onde a captação de clientes na advocacia se perde</h2>
<p>Na prática, os interessados chegam por canais diferentes e caem em lugares diferentes:</p>
<ul>
  <li><strong>Indicações</strong> — um cliente antigo passa o contato e a conversa começa no telefone pessoal de um sócio.</li>
  <li><strong>Site e formulários</strong> — o pedido de contato chega por e-mail e fica na caixa de entrada de uma pessoa só.</li>
  <li><strong>WhatsApp</strong> — a primeira mensagem chega fora do horário e ninguém registra de quem era nem sobre o quê.</li>
  <li><strong>Balcão e telefone</strong> — o recado vira anotação em papel, sem responsável nem retorno combinado.</li>
</ul>
<p>O problema raramente é falta de procura — é falta de registro. Sem um lugar único para esses contatos, o follow-up depende da memória de quem atendeu, dois advogados respondem a mesma pessoa sem saber um do outro e o interessado que pediu "uma semana para pensar" nunca mais é procurado. A oportunidade não foi recusada; foi esquecida.</p>

<h2>Como funciona a prospecção no Yuris</h2>
<p>No Yuris, o módulo de Prospecção capta os leads que chegam ao escritório e os transforma em registros que alimentam o funil comercial. A partir daí, a gestão de leads acontece em três movimentos encadeados:</p>

<h3>Do lead ao funil</h3>
<p>Cada interessado vira um card no <a href="/crm-juridico/">CRM jurídico</a> em Kanban: colunas que refletem as etapas do escritório, checklist do que precisa ser feito antes de avançar e responsável definido. Quem olha o quadro sabe, em segundos, quantos contatos estão em conversa, quantos aguardam retorno e quantos pararam — e há quanto tempo.</p>

<h3>Do funil ao cadastro único</h3>
<p>O lead é vinculado a um contato no cadastro central do sistema — o mesmo usado por processos, atendimento e financeiro. Isso elimina a duplicidade clássica: a pessoa que chegou como interessada não é recadastrada quando fecha contrato; o histórico comercial a acompanha. Se a conversa acontece pelo WhatsApp integrado, as mensagens ficam vinculadas a esse mesmo contato, dentro do sistema, e não no aparelho pessoal de alguém.</p>

<h3>Do funil ao processo</h3>
<p>Quando o interessado decide contratar, o card vira processo sem retrabalho: os dados do contato e o contexto da negociação seguem juntos. A operação jurídica começa onde a comercial terminou — no mesmo <a href="/sistema-juridico/">sistema jurídico</a>, com histórico preservado.</p>

<h2>Controle improvisado vs. funil organizado</h2>
<div class="sp-tabela-wrap">
<table class="sp-tabela">
  <thead>
    <tr><th>Momento</th><th>Improviso (caderno, e-mail, memória)</th><th>Funil no Yuris</th></tr>
  </thead>
  <tbody>
    <tr><td><strong>Chegada do contato</strong></td><td>Cada canal cai em um lugar diferente</td><td>Lead registrado e visível no funil</td></tr>
    <tr><td><strong>Follow-up</strong></td><td>Depende de alguém lembrar</td><td>Card com etapa, responsável e checklist</td></tr>
    <tr><td><strong>Conversa</strong></td><td>WhatsApp pessoal, sem registro</td><td>Atendimento vinculado ao contato no sistema</td></tr>
    <tr><td><strong>Visão do conjunto</strong></td><td>Ninguém sabe quantas conversas estão abertas</td><td>Kanban mostra o funil inteiro de uma vez</td></tr>
    <tr><td><strong>Fechamento</strong></td><td>Recadastro manual e dados perdidos</td><td>Card vira processo com histórico junto</td></tr>
    <tr><td><strong>Quem não fechou</strong></td><td>Some sem desfecho registrado</td><td>Registro encerrado com motivo e contexto</td></tr>
  </tbody>
</table>
</div>

<h2>Prospecção organizada não é captação ativa</h2>
<p>Vale repetir o enquadramento: o módulo de Prospecção do Yuris não é uma ferramenta de publicidade nem promete trazer clientes novos. Ele organiza o relacionamento com quem já procurou o escritório — o que é, antes de tudo, uma questão de gestão e de respeito com o interessado. A decisão sobre como o escritório se divulga, dentro das normas da OAB, continua sendo do próprio escritório; o sistema cuida para que nenhum contato legítimo se perca por desorganização interna.</p>

<h2>Sinais de que a prospecção precisa de organização</h2>
<ul>
  <li>O escritório não sabe dizer quantas conversas comerciais estão abertas neste momento.</li>
  <li>Interessados que pediram um tempo para decidir nunca recebem retorno.</li>
  <li>O primeiro contato acontece no WhatsApp pessoal de um sócio e fica só lá.</li>
  <li>A mesma pessoa é cadastrada duas vezes: uma como lead, outra como cliente.</li>
  <li>Quando alguém sai de férias, ninguém sabe em que pé estavam as conversas dela.</li>
</ul>
<p>Se dois ou mais itens soam familiares, o gargalo não está na procura — está no processo interno. Organizar esse fluxo é parte da <a href="/gestao-escritorio-advocacia/">gestão do escritório de advocacia</a> como um todo: o funil comercial alimenta a operação, e a operação devolve previsibilidade ao funil.</p>

<h2>Quanto custa a prospecção no Yuris?</h2>
<p>O módulo de Prospecção não é vendido à parte: ele faz parte da plataforma, junto com CRM, processos, tarefas, financeiro e WhatsApp integrado. Os planos são públicos, têm tudo incluído e começam em R$ 220/mês — consulte a <a href="/planos.php">página de planos</a> para a tabela completa por número de usuários.</p>
HTML,

    'faq' => [
        [
            'q' => 'O que é prospecção jurídica?',
            'a' => 'No contexto de gestão de escritórios, prospecção jurídica é o processo de registrar, organizar e acompanhar as pessoas que procuram o escritório — por indicação, site ou WhatsApp — em um funil comercial claro, do primeiro contato à decisão de contratar. O foco é não perder oportunidades por desorganização, e não sair em busca ativa de clientes.',
        ],
        [
            'q' => 'O Yuris traz clientes novos para o escritório?',
            'a' => 'Não é essa a proposta. O Yuris não é ferramenta de publicidade: o módulo de Prospecção organiza o fluxo de quem já procura o escritório, garantindo registro, follow-up e desfecho para cada contato. A forma de divulgação do escritório, dentro das normas da OAB, é decisão do próprio escritório.',
        ],
        [
            'q' => 'A prospecção do Yuris respeita as regras da OAB?',
            'a' => 'O módulo foi pensado para gestão de relacionamento, não para captação ativa de clientela. Ele registra e organiza contatos de pessoas que já procuraram o escritório e estrutura o acompanhamento dessas conversas. A responsabilidade pela publicidade e pela conduta comercial permanece com o escritório, conforme as normas aplicáveis da OAB.',
        ],
        [
            'q' => 'Como um lead vira processo no Yuris?',
            'a' => 'O lead entra no funil como card do CRM em Kanban, vinculado a um contato do cadastro central. O card avança pelas etapas com checklist e responsável e, quando o interessado contrata, é convertido em processo — sem recadastro. Os dados do contato e o histórico da negociação seguem junto para a operação jurídica.',
        ],
        [
            'q' => 'Os leads ficam duplicados com os clientes no sistema?',
            'a' => 'Não. O Yuris usa um cadastro central de contatos, reaproveitado por prospecção, processos, atendimento e financeiro. A pessoa que chega como interessada é o mesmo registro que depois aparece como cliente — sem duplicidade. Isso preserva o histórico comercial e evita o retrabalho de recadastrar dados a cada etapa.',
        ],
        [
            'q' => 'A prospecção é cobrada à parte nos planos?',
            'a' => 'Não. Os planos do Yuris têm tudo incluído — prospecção, CRM, processos, tarefas, financeiro, WhatsApp integrado e demais módulos — sem cobrança por módulo. Os valores começam em R$ 220/mês e variam conforme o número de usuários do escritório; a tabela completa está pública na página de planos.',
        ],
    ],

    'relacionados' => [
        ['href' => '/crm-juridico/',                'titulo' => 'CRM Jurídico',                'desc' => 'Funil comercial em Kanban: do lead ao contrato, integrado ao processo.'],
        ['href' => '/sistema-juridico/',            'titulo' => 'Sistema Jurídico',            'desc' => 'Processos, prazos, intimações, financeiro e WhatsApp em uma plataforma.'],
        ['href' => '/gestao-escritorio-advocacia/', 'titulo' => 'Gestão de Escritório',        'desc' => 'Equipe, tarefas e operação organizadas do comercial ao financeiro.'],
    ],
];

require __DIR__ . '/../includes/seo_page.php';
