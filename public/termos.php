<?php
/**
 * Termos de Uso — modelo inicial (LGPD Etapa 5).
 * Revisão jurídica obrigatória antes de publicar.
 */
$LEGAL_PAGE = [
    'titulo'    => 'Termos de Uso',
    'descricao' => 'Condições de utilização do sistema Yuris.',
    'versao'    => '2026-05-23',
    'corpo_html' => <<<HTML
<h2>1. Aceitação</h2>
<p>Ao criar conta, acessar ou utilizar o Yuris, você declara ter lido, entendido
e concordado com estes Termos de Uso e com a
<a href="/privacidade.php">Política de Privacidade</a>.
Caso não concorde, não utilize o serviço.</p>

<h2>2. Definições</h2>
<ul>
  <li><strong>Yuris:</strong> plataforma SaaS jurídica operada pela Inovaize.</li>
  <li><strong>Usuário:</strong> pessoa física que acessa a plataforma.</li>
  <li><strong>Cliente PJ:</strong> pessoa jurídica que contrata o Yuris (escritório de advocacia, advogado autônomo etc).</li>
  <li><strong>Plano:</strong> nível de serviço contratado, com limites e funcionalidades específicas.</li>
</ul>

<h2>3. Cadastro</h2>
<p>Para usar o Yuris, é necessário cadastro com informações verdadeiras e atualizadas. O Cliente PJ é responsável pelas credenciais de acesso e pelas ações realizadas em sua conta. Notifique-nos imediatamente em caso de uso não autorizado.</p>

<h2>4. Planos, pagamento e renovação</h2>
<ul>
  <li>Os planos vigentes, valores e funcionalidades estão disponíveis na <a href="/planos.php">página de planos</a>.</li>
  <li>O pagamento é realizado conforme periodicidade contratada (mensal ou anual).</li>
  <li>Inadimplência por mais de 7 dias pode resultar em suspensão de acesso; após 30 dias, cancelamento.</li>
  <li>Cancelamento pode ser solicitado a qualquer momento; faturas em aberto continuam devidas.</li>
  <li>Estornos seguem as regras do gateway de pagamento utilizado.</li>
</ul>

<h2>5. Uso aceitável</h2>
<p>É vedado utilizar o Yuris para:</p>
<ul>
  <li>Violar leis brasileiras ou direitos de terceiros;</li>
  <li>Enviar spam, conteúdo ilegal ou difamatório;</li>
  <li>Tentar acessar dados de outros tenants (multi-tenancy);</li>
  <li>Realizar engenharia reversa, scraping abusivo ou ataques à plataforma;</li>
  <li>Compartilhar credenciais de acesso entre múltiplas pessoas;</li>
  <li>Armazenar dados de menores sem autorização legal apropriada.</li>
</ul>

<h2>6. Conteúdo do usuário</h2>
<p>Você mantém a titularidade dos dados que cadastra. Concede à Inovaize licença limitada para processar esses dados estritamente para fornecer o serviço, manter backups, e cumprir obrigações legais.</p>
<p>Você é responsável pela legalidade dos dados que cadastra (incluindo, mas não limitado a, dados de clientes, partes processuais, mensagens). Em particular, declara ter base legal adequada para tratar dados pessoais de terceiros que cadastrar no sistema.</p>

<h2>7. Propriedade intelectual</h2>
<p>O Yuris, sua marca, código, interfaces e documentação são de propriedade da Inovaize. Estes Termos não transferem direitos de propriedade intelectual sobre a plataforma.</p>

<h2>8. Limitação de responsabilidade</h2>
<p>O Yuris é fornecido "como está". Buscamos garantir alta disponibilidade e segurança, mas não garantimos operação ininterrupta nem ausência absoluta de falhas. A Inovaize não se responsabiliza por:</p>
<ul>
  <li>Perda de dados por falha do usuário (ex: exclusão acidental, senha perdida sem 2FA configurado);</li>
  <li>Indisponibilidade causada por terceiros (provedor de hospedagem, Evolution API, gateway de pagamento);</li>
  <li>Decisões jurídicas tomadas com base em informações da plataforma;</li>
  <li>Uso indevido por terceiros que obtiveram credenciais válidas do usuário.</li>
</ul>
<p>A responsabilidade da Inovaize, quando aplicável, limita-se ao valor pago pelo Cliente PJ nos últimos 12 meses.</p>

<h2>9. Suspensão e encerramento</h2>
<p>Podemos suspender ou encerrar o acesso em caso de:</p>
<ul>
  <li>Violação destes Termos;</li>
  <li>Inadimplência;</li>
  <li>Determinação judicial ou regulatória;</li>
  <li>Risco à segurança da plataforma ou de outros usuários.</li>
</ul>
<p>Após encerramento, os dados são mantidos por 30 dias para eventual recuperação e então anonimizados/excluídos, salvo obrigação legal de retenção.</p>

<h2>10. Alterações dos Termos</h2>
<p>Estes Termos podem ser atualizados. Mudanças materiais serão comunicadas com antecedência razoável e podem exigir novo aceite. O uso continuado após a vigência implica concordância.</p>

<h2>11. Foro</h2>
<p>Fica eleito o foro da Comarca da sede da Inovaize para dirimir controvérsias decorrentes destes Termos, com renúncia a qualquer outro, por mais privilegiado que seja.</p>

<h2>12. Contato</h2>
<p>Suporte: através do canal indicado na plataforma. Encarregado de Dados (DPO): <a href="/dpo.php">consulte aqui</a>.</p>
HTML
];
require __DIR__ . '/includes/legal_page.php';
