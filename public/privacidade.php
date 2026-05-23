<?php
/**
 * Política de Privacidade — modelo inicial (LGPD Etapa 5).
 *
 * ESTE TEXTO É UM MODELO. Deve ser revisado por advogado especialista em
 * proteção de dados antes da publicação definitiva. Não afirma conformidade
 * total; adota linguagem responsável ("buscamos cumprir", "processo contínuo").
 */
$LEGAL_PAGE = [
    'titulo'    => 'Política de Privacidade',
    'descricao' => 'Como o Yuris coleta, usa e protege seus dados pessoais.',
    'versao'    => '2026-05-23 (modelo inicial — pendente revisão jurídica)',
    'corpo_html' => <<<HTML
<h2>1. Quem somos</h2>
<p>O Yuris é um sistema jurídico em modelo SaaS (Software as a Service) operado
pela Inovaize. Esta Política descreve como tratamos dados pessoais coletados
ao longo do uso da plataforma.</p>

<h2>2. Definições</h2>
<ul>
  <li><strong>Titular:</strong> pessoa natural a quem se referem os dados pessoais (clientes do escritório, advogados, partes processuais etc).</li>
  <li><strong>Controlador:</strong> quem decide a finalidade do tratamento. O escritório/usuário do Yuris é controlador dos dados que cadastra; a Inovaize é controladora dos dados próprios da relação SaaS (cadastro do cliente PJ, cobrança, suporte).</li>
  <li><strong>Operador:</strong> quem realiza o tratamento em nome do controlador. O Yuris atua como <strong>operador</strong> dos dados que o escritório cadastra (clientes finais, processos, mensagens).</li>
</ul>

<h2>3. Dados que tratamos</h2>
<h3>3.1 Dados do cliente PJ (controladoria nossa)</h3>
<ul>
  <li>Nome, e-mail, telefone, CNPJ, endereço da pessoa jurídica contratante.</li>
  <li>Nome, e-mail, telefone dos sócios e usuários cadastrados.</li>
  <li>Dados de pagamento (apenas tokens do gateway — não armazenamos PAN/CVV).</li>
  <li>Dados de uso da plataforma (logs de acesso, IP, user-agent, ações realizadas).</li>
</ul>
<h3>3.2 Dados que o escritório cadastra (operadoria nossa)</h3>
<ul>
  <li>Clientes finais: nome, telefone, e-mail, CPF/CNPJ, endereço, observações.</li>
  <li>Processos: número CNJ, partes envolvidas, dados processuais, anexos.</li>
  <li>Comunicação: mensagens WhatsApp, chat interno, e-mails.</li>
  <li>Tarefas, agendamentos, financeiro (lançamentos, contas a receber/pagar).</li>
</ul>

<h2>4. Finalidades e bases legais</h2>
<table style="width:100%;border-collapse:collapse;margin:12px 0">
<thead><tr style="text-align:left;border-bottom:1px solid rgba(96,165,250,.18)"><th style="padding:6px">Tratamento</th><th>Base legal (LGPD Art. 7º)</th></tr></thead>
<tbody>
<tr><td style="padding:6px">Cadastro de cliente PJ + cobrança</td><td>V — execução de contrato</td></tr>
<tr><td style="padding:6px">Operação do escritório (clientes finais, processos)</td><td>V — execução de contrato com o escritório</td></tr>
<tr><td style="padding:6px">Defesa em processos judiciais</td><td>VI — exercício regular de direitos</td></tr>
<tr><td style="padding:6px">Logs de auditoria e segurança</td><td>IX — legítimo interesse (segurança da plataforma)</td></tr>
<tr><td style="padding:6px">Cookies analíticos / marketing</td><td>I — consentimento (via banner)</td></tr>
</tbody>
</table>

<h2>5. Com quem compartilhamos</h2>
<p>Compartilhamos dados pessoais apenas com operadores e suboperadores essenciais à prestação do serviço, sob contrato:</p>
<ul>
  <li><strong>Evolution API / Meta WhatsApp</strong> — para envio e recepção de mensagens. Mensagens transitam pelo provedor do WhatsApp (Meta), que atua como suboperador.</li>
  <li><strong>Gateway de pagamento</strong> — quando ativado, para processar cobranças.</li>
  <li><strong>Provedor de e-mail (SMTP)</strong> — quando ativado, para envio de notificações.</li>
  <li><strong>Provedor de infraestrutura</strong> — hospedagem dos servidores.</li>
  <li><strong>Webhooks configurados pelo escritório</strong> — quando o escritório configura integrações próprias (n8n, Make, Zapier, sistema interno), o Yuris envia dados para essas URLs sob responsabilidade do controlador.</li>
</ul>
<p>Carregamos também recursos estáticos (fontes, CSS, JS) de CDN externa (Google Fonts, jsDelivr/Cloudflare), o que envia seu IP ao operador desses serviços. Você pode controlar isso pelo banner de cookies.</p>

<h2>6. Transferência internacional</h2>
<p>Alguns operadores podem processar dados fora do Brasil (ex: Meta — EUA, Google Fonts — EUA, jsDelivr/Cloudflare — global). Buscamos cláusulas contratuais padrão e/ou garantias equivalentes nos termos do Art. 33 da LGPD. Avalie a aceitação na seção de cookies/consentimento.</p>

<h2>7. Tempo de retenção</h2>
<ul>
  <li><strong>Dados ativos do escritório:</strong> mantidos enquanto durar o contrato.</li>
  <li><strong>Dados processuais:</strong> preservados conforme prazos legais e prescricionais (em geral, mínimo de 5 anos após o trânsito em julgado).</li>
  <li><strong>Logs de acesso:</strong> 90 dias (conforme Marco Civil da Internet — Art. 15).</li>
  <li><strong>Mensagens WhatsApp:</strong> 365 dias por padrão (configurável pelo escritório).</li>
  <li><strong>Webhook logs, login attempts:</strong> 90 dias.</li>
  <li><strong>Dados de cobrança e fiscais:</strong> 5 anos (conforme legislação tributária).</li>
</ul>
<p>Após o término do contrato, os dados são bloqueados por 30 dias para eventual recuperação e então anonimizados ou excluídos, salvo obrigação legal de retenção.</p>

<h2>8. Direitos do titular (Art. 18 LGPD)</h2>
<p>Você pode exercer os seguintes direitos, gratuitamente:</p>
<ol>
  <li>Confirmação da existência de tratamento;</li>
  <li>Acesso aos seus dados;</li>
  <li>Correção de dados incompletos, inexatos ou desatualizados;</li>
  <li>Anonimização, bloqueio ou eliminação de dados desnecessários ou tratados em desconformidade;</li>
  <li>Portabilidade dos dados;</li>
  <li>Eliminação de dados tratados com base em consentimento, ressalvadas as hipóteses legais de retenção;</li>
  <li>Informação sobre compartilhamento;</li>
  <li>Revogação do consentimento.</li>
</ol>
<p>Solicite através do <a href="/sistema_vendas/public/dpo.php">canal do Encarregado de Dados (DPO)</a>. Prazo de resposta: 15 dias.</p>

<h2>9. Segurança da informação</h2>
<p>Adotamos medidas técnicas e administrativas em processo contínuo de aprimoramento, incluindo:</p>
<ul>
  <li>Senhas armazenadas com hash bcrypt;</li>
  <li>Comunicação cifrada (HTTPS) e cookies seguros;</li>
  <li>Autenticação em dois fatores (TOTP) disponível para super administradores;</li>
  <li>Isolamento multi-tenant entre contas (cada escritório só vê os próprios dados);</li>
  <li>Logs de auditoria das ações administrativas;</li>
  <li>Backups regulares;</li>
  <li>Validação de CSRF, rate-limit de login, allowlist de tipos de upload.</li>
</ul>
<p>Nenhum sistema é absolutamente seguro. Em caso de incidente que represente risco aos direitos dos titulares, comunicaremos a Autoridade Nacional (ANPD) e os titulares afetados nos termos do Art. 48 da LGPD.</p>

<h2>10. Crianças e adolescentes</h2>
<p>O Yuris não se destina a uso por menores de 18 anos como usuários da plataforma. Dados de menores podem aparecer em processos jurídicos cadastrados pelos escritórios — esses dados são tratados sob a responsabilidade do escritório e respeitando o melhor interesse da criança (Art. 14 LGPD).</p>

<h2>11. Alterações desta Política</h2>
<p>Esta Política pode ser atualizada. Mudanças relevantes serão comunicadas com antecedência razoável e podem exigir novo aceite. O histórico de versões é mantido para consulta.</p>

<h2>12. Contato</h2>
<p>Encarregado de Dados (DPO): consulte os contatos atualizados em <a href="/sistema_vendas/public/dpo.php">/dpo</a>.</p>
HTML
];
require __DIR__ . '/includes/legal_page.php';
