<?php
/**
 * /controle-de-processos/ — página estratégica de SEO.
 * Palavra-chave principal: "controle de processos" · secundárias: "gestão de
 * processos jurídicos", "como organizar processos do escritório",
 * "software de controle processual".
 * Intenção: comercial/informacional — escritório buscando organizar processos.
 * Conteúdo baseado no fact sheet do produto (sem claims não confirmados).
 */

$SEO_PAGE = [
    'title'       => 'Controle de Processos Jurídicos para Escritórios | Yuris',
    'description' => 'Controle de processos com histórico auditado, prazos com responsável, tarefas e intimações DJEN, DataJud e AASP conectadas ao processo. Conheça o Yuris.',
    'path'        => '/controle-de-processos/',
    'breadcrumb'  => 'Controle de Processos',
    'eyebrow'     => 'Controle de Processos',
    'h1'          => 'Controle de processos para escritórios de advocacia',
    'lede'        => 'Controle de processos é manter andamentos, prazos, tarefas e intimações de cada processo em um registro único, com responsável definido e histórico rastreável. No Yuris, o processo é o fio condutor do escritório: tudo o que acontece se vincula a ele, com trilha auditada e permissões por escopo.',
    'cta_msg'     => 'Olá Bruno, quero conhecer o controle de processos do Yuris!',
    'cta_label'   => 'Solicitar demonstração',
    'cta_titulo'  => 'Veja o controle de processos do Yuris aplicado à rotina do seu escritório',
    'cta_texto'   => 'Uma conversa rápida no WhatsApp e a equipe mostra prazos, intimações e histórico auditado funcionando com o seu volume de processos.',

    'corpo_html' => <<<'HTML'
<h2>O que é controle de processos?</h2>
<div class="sp-definicao">
  <p><strong>Controle de processos</strong> é a prática de manter cada processo do escritório em um registro único e rastreável: andamentos, prazos, tarefas, intimações e responsáveis vinculados ao mesmo dossiê. Em um software de controle processual, esse registro ganha histórico auditado e permissões de acesso — qualquer pessoa autorizada consegue saber, em segundos, onde o processo está e quem responde por ele.</p>
</div>
<p>Sem esse controle, a gestão de processos jurídicos vira arqueologia: o andamento está no e-mail de um advogado, o prazo na agenda de outro, a petição em uma pasta da rede. O Yuris inverte essa lógica — o processo é o fio condutor, e tudo o que acontece no escritório se conecta a ele dentro do <a href="/sistema-juridico/">sistema jurídico</a>.</p>

<h2>O processo como fio condutor do escritório</h2>
<p>No Yuris, o cadastro do processo não é uma ficha estática: é o ponto de encontro de prazos, tarefas, intimações, atendimento e equipe. Três mecanismos sustentam isso na prática:</p>

<h3>Histórico auditado: a linha do tempo imutável</h3>
<p>Cada ação relevante sobre o processo — criação, alteração, vínculo, prazo registrado — entra em uma trilha de auditoria imutável. Ninguém apaga nem reescreve o passado: o escritório sempre consegue responder <em>quem fez o quê, quando e em qual contexto</em>. Na troca de responsável, em verificação interna ou em questionamento de cliente, a linha do tempo encerra a discussão.</p>

<h3>Prazos com responsável definido</h3>
<p>Prazo sem dono é prazo em risco. No controle de processos do Yuris, cada prazo nasce vinculado ao processo e a um responsável — e a equipe enxerga o que vence, para quem e em qual processo, sem depender da memória de uma pessoa só.</p>

<h3>Tarefas vinculadas ao processo</h3>
<p>As tarefas do escritório — em Kanban, lista ou calendário — carregam anexos, comentários e checklist, e podem ser vinculadas ao processo e à intimação que as originou. Quem abre a tarefa vê o contexto completo; quem abre o processo vê tudo o que está em andamento sobre ele.</p>

<h2>Intimações conectadas ao processo</h2>
<p>Boa parte da rotina processual começa em uma publicação. O Yuris monitora intimações em múltiplas fontes — DJEN, DataJud e AASP — e as conecta ao fluxo do processo:</p>
<ul>
  <li><strong>Deduplicação automática</strong> — a mesma publicação capturada em fontes diferentes aparece uma única vez, sem ruído na triagem.</li>
  <li><strong>Intimação vira prazo ou tarefa</strong> — em vez de morar em uma lista solta, a publicação se converte em ação com responsável e vínculo com o processo.</li>
  <li><strong>Fontes combinadas</strong> — DJEN e DataJud cobrem a base pública; a AASP entra com a chave de acesso do próprio escritório.</li>
</ul>
<p>O resultado: a conferência manual de diários deixa de ser gargalo e a publicação relevante não fica fora do dossiê. Para fluxos que continuam fora do sistema — avisar a equipe em outra ferramenta, alimentar um painel — os webhooks de <a href="/automacao-juridica/">automação jurídica</a> notificam plataformas como n8n, Make e Zapier.</p>

<h2>Processo na pasta e na planilha vs. processo no sistema</h2>
<p>A diferença entre os dois modelos aparece no dia em que algo dá errado — ou em que alguém sai de férias:</p>
<div class="sp-tabela-wrap">
<table class="sp-tabela">
  <thead>
    <tr><th>Rotina</th><th>Pasta / planilha</th><th>Processo no sistema</th></tr>
  </thead>
  <tbody>
    <tr><td><strong>Andamentos</strong></td><td>Colados manualmente, quando alguém lembra</td><td>Histórico auditado em linha do tempo única</td></tr>
    <tr><td><strong>Prazos</strong></td><td>Coluna na planilha, sem dono claro</td><td>Vinculados ao processo, com responsável</td></tr>
    <tr><td><strong>Intimações</strong></td><td>Conferência manual em cada diário</td><td>Monitoramento DJEN, DataJud e AASP com deduplicação</td></tr>
    <tr><td><strong>Tarefas</strong></td><td>Pedidos por mensagem, sem registro</td><td>Kanban com checklist, anexos e vínculo com o processo</td></tr>
    <tr><td><strong>Quem alterou o quê</strong></td><td>Impossível reconstituir</td><td>Trilha de auditoria imutável</td></tr>
    <tr><td><strong>Acesso</strong></td><td>Pasta de rede aberta a todos</td><td>Permissões por escopo e por usuário</td></tr>
  </tbody>
</table>
</div>

<h2>Do comercial ao jurídico: o card vira processo</h2>
<p>O controle começa antes mesmo de o número do processo existir. No funil comercial do Yuris, cada oportunidade é um card no Kanban, com checklist e vínculo com o contato. Quando a proposta fecha, o card se converte em processo — sem redigitar dados, sem cadastro duplicado. O cliente do funil é o mesmo cliente do processo, do atendimento e do financeiro, porque o cadastro central é único e reaproveitado por todos os módulos.</p>

<h2>Vínculos entre advogados e permissões por escopo</h2>
<p>Processo raramente é trabalho de uma pessoa só. O Yuris permite vincular mais de um advogado ao mesmo processo, cada um enxergando o mesmo histórico, os mesmos prazos e as mesmas tarefas. As permissões por escopo definem quem vê e quem altera o quê — inclusive em estruturas com matriz e filiais, onde o acesso precisa respeitar a unidade. A organização da equipe em torno dos processos é parte da <a href="/gestao-escritorio-advocacia/">gestão do escritório de advocacia</a> como um todo.</p>

<h2>Quanto custa um software de controle processual?</h2>
<p>No Yuris, o controle de processos não é módulo vendido à parte: está incluído em todos os planos, junto com CRM, tarefas, financeiro, WhatsApp integrado e intimações. Os valores começam em R$ 220/mês — a tabela completa e atualizada está na <a href="/planos.php">página de planos</a>. Para ver o fluxo aplicado aos processos do seu escritório, agende uma <a href="/demonstracao/">demonstração</a>.</p>
HTML,

    'faq' => [
        [
            'q' => 'O que é controle de processos?',
            'a' => 'Controle de processos é a organização de andamentos, prazos, tarefas, intimações e responsáveis de cada processo em um registro único e rastreável. Em um software de controle processual como o Yuris, esse registro tem histórico auditado, permissões de acesso e vínculos com a equipe — o escritório sabe onde cada processo está e quem responde por ele.',
        ],
        [
            'q' => 'Como o Yuris registra o histórico do processo?',
            'a' => 'Cada ação relevante sobre o processo entra em uma trilha de auditoria imutável: criação, alterações, vínculos e prazos ficam registrados com autor, data e contexto. O histórico não pode ser apagado nem reescrito, o que permite reconstruir a linha do tempo do processo em verificações internas, trocas de responsável ou questionamentos de clientes.',
        ],
        [
            'q' => 'O sistema acompanha intimações automaticamente?',
            'a' => 'Sim. O Yuris monitora publicações em três fontes — DJEN, DataJud e AASP — com deduplicação automática, para a mesma intimação não aparecer repetida. A publicação encontrada pode ser vinculada ao processo e convertida em prazo ou tarefa com responsável definido. A fonte AASP requer a chave de acesso do próprio escritório.',
        ],
        [
            'q' => 'Mais de um advogado pode trabalhar no mesmo processo?',
            'a' => 'Sim. O Yuris permite vincular vários advogados ao mesmo processo, todos enxergando o mesmo histórico, os mesmos prazos e as mesmas tarefas. As permissões por escopo definem o que cada usuário vê e altera — útil em equipes maiores e em escritórios com matriz e filiais, onde o acesso precisa respeitar a unidade.',
        ],
        [
            'q' => 'Como um lead comercial vira processo no Yuris?',
            'a' => 'No funil comercial em Kanban, cada oportunidade é um card vinculado a um contato. Quando o negócio fecha, o card se converte em processo sem redigitação: o cadastro do cliente é único e reaproveitado pelo processo, pelo atendimento e pelo financeiro. Comercial e jurídico passam a trabalhar sobre os mesmos dados.',
        ],
        [
            'q' => 'Quanto custa o controle de processos do Yuris?',
            'a' => 'O controle de processos está incluído em todos os planos do Yuris, sem cobrança por módulo — junto com CRM, tarefas, financeiro, WhatsApp integrado e intimações. Os planos são públicos, começam em R$ 220/mês e variam conforme o número de usuários do escritório. A tabela completa fica na página de planos do site.',
        ],
    ],

    'relacionados' => [
        ['href' => '/sistema-juridico/',             'titulo' => 'Sistema Jurídico',      'desc' => 'A plataforma completa: processos, prazos, intimações, CRM e financeiro.'],
        ['href' => '/automacao-juridica/',           'titulo' => 'Automação Jurídica',    'desc' => 'Webhooks seguros conectando o escritório a n8n, Make e Zapier.'],
        ['href' => '/gestao-escritorio-advocacia/',  'titulo' => 'Gestão de Escritório',  'desc' => 'Equipe, permissões e rotina organizadas em torno dos processos.'],
    ],
];

require __DIR__ . '/../includes/seo_page.php';
