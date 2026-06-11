<?php
/**
 * /lgpd-escritorios-advocacia/ — página estratégica de SEO.
 * Palavra-chave principal: "LGPD para escritórios de advocacia" · secundárias:
 * "LGPD advocacia", "proteção de dados escritório de advocacia", "software jurídico LGPD".
 * Intenção: informacional/comercial — advogado pesquisando como adequar o escritório à LGPD.
 * Conteúdo baseado no fact sheet do produto (sem claims não confirmados).
 */

$SEO_PAGE = [
    'title'       => 'LGPD para Escritórios de Advocacia na Prática | Yuris',
    'description' => 'Entenda o que a LGPD espera de escritórios de advocacia na prática e como o Yuris apoia a adequação com auditoria, permissões, anonimização e retenção.',
    'path'        => '/lgpd-escritorios-advocacia/',
    'breadcrumb'  => 'LGPD para Escritórios de Advocacia',
    'eyebrow'     => 'LGPD na Advocacia',
    'h1'          => 'LGPD para escritórios de advocacia: o que a lei espera e como se estruturar',
    'lede'        => 'A LGPD se aplica integralmente a escritórios de advocacia — que tratam dados pessoais e sensíveis de clientes e de terceiros em processos, laudos e documentos. Em linhas gerais, a lei espera controle de acesso, registro de operações, atendimento aos direitos do titular e regras de retenção. O Yuris apoia essa estrutura com isolamento de dados, permissões por escopo, auditoria imutável, anonimização e Privacy Center.',
    'cta_msg'     => 'Olá Bruno, quero conhecer a estrutura de proteção de dados do Yuris para meu escritório!',
    'cta_label'   => 'Solicitar demonstração',
    'cta_titulo'  => 'Veja a estrutura de proteção de dados do Yuris por dentro',
    'cta_texto'   => 'Em uma conversa rápida no WhatsApp, a equipe mostra permissões, trilha de auditoria, anonimização e o Privacy Center aplicados à rotina do seu escritório.',

    'corpo_html' => <<<'HTML'
<h2>O que a LGPD exige de um escritório de advocacia?</h2>
<div class="sp-definicao">
  <p>Em linhas gerais, a <strong>LGPD (Lei Geral de Proteção de Dados — Lei nº 13.709/2018)</strong> espera que um escritório de advocacia trate dados pessoais com finalidade definida, acesso restrito a quem realmente precisa, registro das operações realizadas, regras claras de guarda e eliminação e capacidade de responder aos direitos dos titulares, como acesso e correção. O desenho exato das obrigações varia conforme a operação de cada escritório e deve ser avaliado com apoio especializado em proteção de dados.</p>
</div>
<p>O ponto de partida é direto: a LGPD se aplica a qualquer organização que trate dados pessoais no Brasil — e poucos negócios tratam tantos dados, com tanta profundidade, quanto a advocacia. A pergunta deixou de ser "a lei se aplica ao meu escritório?" e passou a ser "como organizo a operação para tratar esses dados de forma demonstrável?".</p>

<h2>Por que a advocacia é um caso crítico de proteção de dados</h2>
<p>O escritório de advocacia tem duas características que o tornam diferente da empresa comum diante da LGPD.</p>
<h3>O dado tratado é, com frequência, sensível</h3>
<p>Laudos médicos e periciais, processos trabalhistas e previdenciários, disputas de família e sucessões, defesas criminais: boa parte do acervo de um escritório se enquadra na categoria de dados sensíveis da lei. São informações cujo vazamento causa dano direto ao titular — e difícil de reparar depois.</p>
<h3>O dado não é só do cliente</h3>
<p>Cada processo carrega dados de terceiros que nunca assinaram nada com o escritório: partes contrárias, testemunhas, peritos, pessoas citadas em documentos juntados aos autos. Esses titulares também têm direitos, e o escritório responde pelo tratamento que dá a essas informações — mesmo que tenham chegado "por arrasto", dentro de um PDF anexado ao caso.</p>
<p>Somam-se a isso o sigilo profissional e a confiança que sustentam a relação entre advogado e cliente: um incidente de dados em um escritório não é só um problema regulatório — é um problema de reputação.</p>

<h2>O que a lei espera na prática</h2>
<p>Sem prescrever o programa de conformidade de ninguém — isso é trabalho para avaliação especializada, caso a caso —, em linhas gerais a LGPD aponta para um conjunto de capacidades operacionais:</p>
<ul>
  <li><strong>Controle de acesso</strong> — cada pessoa enxerga apenas os dados de que precisa para trabalhar, e não todo o acervo do escritório.</li>
  <li><strong>Registro de operações</strong> — ser capaz de demonstrar quem acessou, alterou ou excluiu uma informação, e quando.</li>
  <li><strong>Atendimento ao titular</strong> — ter um caminho organizado para receber e responder pedidos de acesso, correção e informação sobre o tratamento.</li>
  <li><strong>Retenção e eliminação</strong> — guardar o dado pelo tempo necessário e ter critério para anonimizar ou eliminar quando o ciclo termina.</li>
  <li><strong>Transparência</strong> — comunicar de forma clara, em linguagem acessível, como os dados são tratados.</li>
</ul>
<p>Planilhas compartilhadas, pastas em rede e WhatsApp pessoal falham exatamente nesses pontos: não há controle de quem vê o quê, não há registro de operações e não há ciclo de vida do dado — tudo fica guardado para sempre, acessível a todos.</p>

<h2>Como o Yuris apoia a adequação — estrutura, não promessa</h2>
<p>O Yuris foi desenhado com proteção de dados como parte da arquitetura do <a href="/sistema-juridico/">sistema jurídico</a>, não como módulo vendido à parte. Na prática, cada frente de adequação encontra um apoio estrutural correspondente:</p>
<div class="sp-tabela-wrap">
<table class="sp-tabela">
  <thead>
    <tr><th>Frente de adequação</th><th>Apoio estrutural no Yuris</th></tr>
  </thead>
  <tbody>
    <tr><td><strong>Isolamento de dados</strong></td><td>Arquitetura multi-tenant: cada escritório opera em conta isolada; estruturas com matriz e filial têm permissões separadas por escopo de unidade</td></tr>
    <tr><td><strong>Controle de acesso</strong></td><td>Permissões granulares por usuário, com segundo fator de autenticação (2FA) opcional</td></tr>
    <tr><td><strong>Registro de operações</strong></td><td>Trilha de auditoria imutável: quem fez o quê, quando e em qual contexto</td></tr>
    <tr><td><strong>Ciclo de vida do dado</strong></td><td>Anonimização e retenção como recursos estruturais da plataforma</td></tr>
    <tr><td><strong>Direitos do titular</strong></td><td>Privacy Center público, com páginas voltadas ao exercício de direitos pelo titular</td></tr>
    <tr><td><strong>Comunicação registrada</strong></td><td>Atendimento por WhatsApp dentro do sistema, com conversas vinculadas a cliente e processo — em vez do celular pessoal</td></tr>
  </tbody>
</table>
</div>
<p>Esse desenho aparece no dia a dia da operação: o <a href="/controle-de-processos/">controle de processos</a> mantém histórico auditado de cada movimentação, o que serve tanto à gestão do caso quanto à demonstração de como os dados foram tratados.</p>
<p>O enquadramento correto é este: o Yuris adota medidas técnicas e organizacionais voltadas à proteção de dados pessoais e segue em processo contínuo de adequação à LGPD. Nenhum software sério promete "conformidade 100%" — adequação é processo, não selo.</p>

<h2>O que o software não resolve sozinho</h2>
<p>Vale dizer com clareza, porque o mercado às vezes sugere o contrário: <strong>ferramenta não substitui programa de conformidade</strong>. Continuam sendo trabalho do escritório — com apoio especializado quando necessário:</p>
<ul>
  <li>Mapear os tratamentos de dados realizados e suas bases legais;</li>
  <li>Definir políticas internas, contratos e cláusulas com clientes e fornecedores;</li>
  <li>Treinar a equipe e definir responsabilidades pela proteção de dados;</li>
  <li>Avaliar riscos específicos da operação e preparar a resposta a incidentes.</li>
</ul>
<p>O que um bom sistema faz é tornar essas decisões executáveis: a política de acesso vira permissão configurada, a regra de guarda vira retenção aplicada, o dever de demonstração vira trilha de auditoria. Software é apoio estrutural — não aconselhamento jurídico nem atalho para a conformidade.</p>

<h2>Por onde começar</h2>
<p>Se o escritório ainda trabalha com planilhas e pastas compartilhadas, o primeiro passo prático é centralizar a operação em um ambiente com controle de acesso e registro — e, a partir daí, construir o programa de conformidade sobre uma base sólida. Para conhecer essa estrutura por dentro, agende uma <a href="/demonstracao/">demonstração</a>; para entender quem constrói o produto e como ele é mantido, veja a página <a href="/sobre/">sobre o Yuris</a>. Os planos são públicos, com tudo incluído, a partir de R$ 220/mês — detalhes na <a href="/planos.php">página de planos</a>.</p>
HTML,

    'faq' => [
        [
            'q' => 'A LGPD se aplica a escritórios de advocacia?',
            'a' => 'Sim. A LGPD se aplica a qualquer organização que trate dados pessoais no Brasil, e escritórios de advocacia tratam dados em volume e profundidade: clientes, partes contrárias, testemunhas e terceiros citados em documentos. Boa parte desse material envolve dados sensíveis, como informações de saúde presentes em laudos e processos, o que torna a advocacia um caso que pede atenção especial à proteção de dados.',
        ],
        [
            'q' => 'Quais dados pessoais um escritório de advocacia costuma tratar?',
            'a' => 'Além dos dados cadastrais de clientes e contatos, o escritório recebe dados de terceiros por meio dos próprios casos: documentos juntados aos autos, laudos periciais, contratos e históricos médicos e trabalhistas. Muitos desses dados são sensíveis na definição da LGPD e chegam sem relação contratual com o titular — o que reforça a necessidade de acesso controlado, registro de operações e regras de guarda.',
        ],
        [
            'q' => 'Usar o Yuris deixa o escritório 100% conforme a LGPD?',
            'a' => 'Não — e nenhum software deveria prometer isso. A adequação à LGPD envolve processos, contratos, treinamento e governança que vão além da ferramenta. O Yuris adota medidas técnicas e organizacionais voltadas à proteção de dados pessoais e segue em processo contínuo de adequação à LGPD, oferecendo a base estrutural — controle de acesso, auditoria, anonimização e retenção — sobre a qual o programa de conformidade do escritório se apoia.',
        ],
        [
            'q' => 'Como o Yuris ajuda a atender os direitos do titular?',
            'a' => 'O Yuris possui estrutura dedicada aos direitos do titular, incluindo um Privacy Center público com páginas voltadas a quem deseja exercer seus direitos, além de recursos de anonimização e de retenção de dados. Isso dá ao escritório um caminho organizado para receber e tratar solicitações de titulares, em vez de improvisar respostas caso a caso, sem registro do que foi feito.',
        ],
        [
            'q' => 'O que é a trilha de auditoria e por que ela importa para a LGPD?',
            'a' => 'É o registro imutável de quem fez o quê, quando e em qual contexto dentro do sistema. Para a proteção de dados, isso significa conseguir demonstrar como as informações foram acessadas e alteradas — útil em verificações internas, em respostas a titulares e na apuração de qualquer incidente. No Yuris, a trilha de auditoria faz parte da arquitetura, não é um módulo opcional.',
        ],
        [
            'q' => 'Escritório pequeno também precisa se preocupar com a LGPD?',
            'a' => 'Sim. A lei não diferencia o porte para fins de aplicação, e o advogado autônomo trata os mesmos tipos de dado sensível que uma banca grande — a diferença está na proporcionalidade das medidas. Começar com o básico bem-feito, como acesso controlado, registro de operações e regras de guarda, já coloca o escritório pequeno em posição muito melhor do que planilhas e pastas compartilhadas sem controle.',
        ],
    ],

    'relacionados' => [
        ['href' => '/sistema-juridico/',       'titulo' => 'Sistema Jurídico',       'desc' => 'A plataforma completa: processos, prazos, intimações, CRM e financeiro.'],
        ['href' => '/controle-de-processos/',  'titulo' => 'Controle de Processos',  'desc' => 'Histórico auditado, prazos e vínculos — a base do registro de operações.'],
        ['href' => '/demonstracao/',           'titulo' => 'Demonstração',           'desc' => 'Veja a estrutura de proteção de dados do Yuris aplicada ao seu escritório.'],
    ],
];

require __DIR__ . '/../includes/seo_page.php';
