<?php
/**
 * /crm-juridico/ — página estratégica de SEO.
 * Palavra-chave principal: "CRM jurídico" · secundárias: "CRM para advogados",
 * "funil jurídico", "captação de clientes na advocacia".
 * Intenção: comercial — escritório buscando organizar o funil de captação.
 * Conteúdo baseado no fact sheet do produto (sem claims não confirmados).
 */

$SEO_PAGE = [
    'title'       => 'CRM Jurídico e Funil Comercial para Advogados | Yuris',
    'description' => 'CRM jurídico com funil em Kanban: o lead vira proposta, cliente e processo sem retrabalho, com WhatsApp e contatos centralizados. Conheça o Yuris.',
    'path'        => '/crm-juridico/',
    'breadcrumb'  => 'CRM Jurídico',
    'eyebrow'     => 'CRM Jurídico',
    'h1'          => 'CRM jurídico: o funil comercial do escritório, do lead ao processo',
    'lede'        => 'O CRM jurídico do Yuris organiza a captação e o funil comercial do escritório em um Kanban: o lead entra como card, avança pelas etapas com checklist e conversa de WhatsApp vinculada e, ao fechar contrato, vira cliente e processo sem retrabalho — com contatos centralizados e sem duplicidade.',
    'cta_msg'     => 'Olá Bruno, quero conhecer o CRM jurídico do Yuris!',
    'cta_label'   => 'Solicitar demonstração',
    'cta_titulo'  => 'Veja o funil do seu escritório rodando no Yuris',
    'cta_texto'   => 'Uma conversa rápida no WhatsApp e a equipe mostra o CRM aplicado ao processo comercial do seu escritório — da captação ao contrato.',

    'corpo_html' => <<<'HTML'
<h2>O que é um CRM jurídico?</h2>
<div class="sp-definicao">
  <p>Um <strong>CRM jurídico</strong> é um sistema de gestão do relacionamento com clientes feito para a advocacia: organiza a captação, o funil de propostas e a conversão de leads em clientes — e, diferente de um CRM genérico, conecta cada etapa comercial à operação do escritório: processos, prazos, tarefas e atendimento. O contato que entra no funil é o mesmo que depois aparece no processo, no financeiro e no WhatsApp do escritório.</p>
</div>
<p>Na prática, o CRM para advogados resolve um problema clássico: o comercial do escritório vive em um lugar (planilha, agenda, WhatsApp pessoal) e a operação jurídica em outro. Quando o lead fecha contrato, alguém redigita tudo — e o histórico da negociação se perde no caminho. O CRM jurídico elimina essa fronteira: comercial e operação trabalham sobre o mesmo cadastro.</p>

<h2>O funil jurídico em Kanban: como funciona no Yuris</h2>
<p>No Yuris, o CRM é o módulo de <strong>Cards</strong>: um quadro Kanban em que cada card representa uma oportunidade — um lead que chegou, uma proposta enviada, um contrato em negociação. O escritório define as colunas conforme o próprio processo comercial e move o card de etapa em etapa, com visão clara de onde cada negociação está e o que falta para avançar.</p>
<p>Cada card carrega o contexto completo da negociação:</p>
<ul>
  <li><strong>Checklist por card</strong> — os passos da etapa (documentos a receber, proposta a enviar, reunião a agendar) ficam registrados no próprio card, com o que já foi feito e o que está pendente.</li>
  <li><strong>Vínculo com o contato</strong> — o card aponta para o cadastro central do cliente; nada de criar um registro paralelo só para o comercial.</li>
  <li><strong>Vínculo com processo</strong> — quando a negociação envolve um caso já existente, o card se liga a ele e o histórico fica conectado.</li>
  <li><strong>Card vira processo</strong> — fechou o contrato? O card é convertido em processo, levando contato e contexto junto. Sem redigitar dados, sem retrabalho.</li>
</ul>
<p>Esse último ponto é o que separa o funil jurídico de um quadro Kanban qualquer: o destino natural do lead que fecha não é uma coluna chamada "ganho" — é um processo com prazos, tarefas e responsável definido. Veja como essa continuidade funciona em <a href="/controle-de-processos/">controle de processos</a>.</p>

<h2>Contatos centralizados, sem duplicidade</h2>
<p>Em muitos escritórios, a mesma pessoa existe três vezes: uma na planilha do comercial, outra no sistema de processos e uma terceira na agenda do financeiro. Cada cópia envelhece de um jeito — telefone desatualizado aqui, e-mail errado ali. No Yuris, o cadastro de <strong>clientes e contatos é único</strong>: o mesmo registro é reaproveitado pelo card do funil, pelo processo, pelo atendimento e pelo <a href="/financeiro-juridico/">financeiro do escritório</a>. Atualizou em um lugar, atualizou em todos — e o sistema evita duplicidade na origem.</p>

<h2>WhatsApp dentro do funil</h2>
<p>Boa parte da negociação na advocacia acontece pelo WhatsApp — e costuma acontecer no celular pessoal de alguém, fora de qualquer registro. No Yuris, o atendimento por WhatsApp roda <strong>dentro do sistema</strong>, e as conversas ficam vinculadas ao cliente e ao processo. Para o CRM, isso muda o jogo: ao abrir um card, a equipe vê a conversa que sustenta aquela negociação, e a história não se perde quando o responsável muda ou sai de férias.</p>

<h2>CRM genérico vs. CRM jurídico</h2>
<p>Um CRM genérico organiza funil — e para aí. Ele não entende o que acontece depois do contrato, porque não conhece as entidades da advocacia: processo, prazo, intimação. A comparação deixa a diferença visível:</p>
<div class="sp-tabela-wrap">
<table class="sp-tabela">
  <thead>
    <tr><th>Aspecto</th><th>CRM genérico</th><th>CRM jurídico (Yuris)</th></tr>
  </thead>
  <tbody>
    <tr><td><strong>Entidades</strong></td><td>Lead, empresa, negócio</td><td>Contato, card, processo, prazo, intimação</td></tr>
    <tr><td><strong>Fim do funil</strong></td><td>Coluna "ganho" — e o resto fica fora</td><td>Card vira processo, com contexto preservado</td></tr>
    <tr><td><strong>Cadastro</strong></td><td>Duplicado entre CRM e sistema jurídico</td><td>Contato único, reaproveitado por toda a operação</td></tr>
    <tr><td><strong>Atendimento</strong></td><td>WhatsApp fora do sistema ou via terceiros</td><td>WhatsApp integrado, vinculado a cliente e processo</td></tr>
    <tr><td><strong>Prazos e intimações</strong></td><td>Não existem como conceito</td><td>Conectados ao processo que nasceu do funil</td></tr>
    <tr><td><strong>Controle de acesso</strong></td><td>Configuração genérica</td><td>Permissões por escopo, auditoria e estrutura voltada à LGPD</td></tr>
  </tbody>
</table>
</div>
<p>Em resumo: o CRM genérico enxerga o cliente até a assinatura; o CRM jurídico acompanha o cliente do primeiro contato ao encerramento do processo, porque faz parte do mesmo <a href="/sistema-juridico/">sistema jurídico</a> que gerencia a operação.</p>

<h2>Captação de clientes na advocacia: alimentando o funil</h2>
<p>O funil só trabalha se houver entrada. No Yuris, o módulo de <a href="/prospeccao-juridica/">prospecção jurídica</a> registra os leads que já procuram o escritório e os leva ao funil comercial — o lead chega como card, com contato registrado, pronto para a equipe qualificar e conduzir pelas etapas. Alguns sinais de que o escritório precisa organizar essa frente:</p>
<ul>
  <li>Leads chegam por canais diferentes e ninguém sabe quantos viraram cliente — nem por quê.</li>
  <li>Propostas enviadas sem follow-up: a negociação esfria por falta de acompanhamento, não por mérito.</li>
  <li>A negociação inteira mora no WhatsApp pessoal de um sócio.</li>
  <li>Quando o contrato fecha, os dados do cliente são digitados de novo no controle de processos.</li>
  <li>O escritório não consegue dizer qual etapa do funil mais perde oportunidades.</li>
</ul>
<p>Dois ou mais itens familiares? O problema não é falta de demanda — é falta de funil.</p>

<h2>Quanto custa um CRM jurídico?</h2>
<p>No Yuris, o CRM não é módulo vendido à parte: ele já vem incluído na plataforma, junto com processos, intimações, tarefas, financeiro e WhatsApp. Os planos têm tudo incluído e começam em R$ 220/mês, variando conforme o número de usuários. Consulte a <a href="/planos.php">página de planos</a> para a tabela completa e atualizada.</p>
HTML,

    'faq' => [
        [
            'q' => 'O que é um CRM jurídico?',
            'a' => 'CRM jurídico é um sistema de gestão do relacionamento com clientes feito para a advocacia: organiza a captação de leads, o funil de propostas e a conversão em clientes, conectando cada etapa comercial à operação do escritório — processos, prazos, tarefas e atendimento. No Yuris, o CRM é o módulo de Cards, um funil em Kanban integrado ao restante da plataforma.',
        ],
        [
            'q' => 'Qual a diferença entre um CRM genérico e um CRM jurídico?',
            'a' => 'O CRM genérico organiza o funil e termina na coluna "ganho" — ele não conhece processo, prazo nem intimação. O CRM jurídico conecta o comercial à operação: no Yuris, o card que fecha vira processo com o mesmo contato, a conversa de WhatsApp fica vinculada ao cliente e o cadastro é único, sem duplicidade entre sistemas.',
        ],
        [
            'q' => 'Como o lead vira processo no Yuris?',
            'a' => 'O lead entra no funil como um card no Kanban, vinculado a um contato do cadastro central. A equipe conduz a negociação pelas etapas, com checklist e histórico no próprio card. Quando o contrato fecha, o card é convertido em processo — contato, contexto e vínculos vão junto, sem redigitar dados e sem retrabalho.',
        ],
        [
            'q' => 'O WhatsApp fica integrado ao CRM jurídico?',
            'a' => 'Sim. No Yuris, o atendimento por WhatsApp acontece dentro do sistema, e as conversas ficam vinculadas ao cliente e ao processo. Ao abrir um card do funil, a equipe vê a conversa que sustenta a negociação. O histórico permanece no escritório, mesmo quando o responsável pelo atendimento muda.',
        ],
        [
            'q' => 'O CRM jurídico ajuda na captação de clientes na advocacia?',
            'a' => 'Ajuda na parte que depende de organização: o módulo de prospecção do Yuris registra os leads que já procuram o escritório e alimenta o funil, e o CRM evita que oportunidades se percam por falta de acompanhamento — cada card tem etapa, checklist e responsável. A qualidade do atendimento e a condução da negociação continuam sendo trabalho do escritório.',
        ],
        [
            'q' => 'O CRM do Yuris é vendido separadamente?',
            'a' => 'Não. O CRM faz parte da plataforma Yuris, junto com gestão de processos, intimações, tarefas, financeiro e WhatsApp integrado — os planos têm tudo incluído, sem cobrança por módulo, a partir de R$ 220/mês. Os valores variam conforme o número de usuários e estão públicos na página de planos.',
        ],
    ],

    'relacionados' => [
        ['href' => '/prospeccao-juridica/',   'titulo' => 'Prospecção Jurídica',     'desc' => 'Os leads que já procuram o escritório, organizados no funil comercial.'],
        ['href' => '/controle-de-processos/', 'titulo' => 'Controle de Processos',   'desc' => 'O destino do card que fecha: histórico auditável, prazos e tarefas.'],
        ['href' => '/sistema-juridico/',      'titulo' => 'Sistema Jurídico',        'desc' => 'A plataforma completa que conecta o CRM à operação do escritório.'],
    ],
];

require __DIR__ . '/../includes/seo_page.php';
