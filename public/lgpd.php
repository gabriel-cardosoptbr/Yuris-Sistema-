<?php
/**
 * LGPD & Segurança — página pública resumo (LGPD Etapa 5).
 * Conteúdo gerencial/comercial responsável, sem afirmações de conformidade total.
 */
$LEGAL_PAGE = [
    'titulo'    => 'LGPD, Privacidade e Segurança no Yuris',
    'descricao' => 'Nosso compromisso contínuo com a proteção de dados pessoais.',
    'versao'    => '2026-05-23 (modelo inicial — pendente revisão jurídica)',
    'corpo_html' => <<<HTML
<h2>1. Nosso compromisso</h2>
<p>A privacidade e a segurança dos dados pessoais são tratadas pela Inovaize como processo contínuo de melhoria. Buscamos atender aos princípios da Lei Geral de Proteção de Dados (Lei 13.709/2018) e adotamos medidas técnicas e organizacionais para proteger os dados confiados ao Yuris.</p>

<div class="legal-disclaimer" style="margin: 18px 0">
Esta página é informativa e <strong>não constitui</strong> garantia absoluta de segurança ou
conformidade total. A LGPD é uma jornada contínua. Detalhes completos estão na
<a href="/sistema_vendas/public/privacidade.php">Política de Privacidade</a>
e nos <a href="/sistema_vendas/public/termos.php">Termos de Uso</a>.
</div>

<h2>2. Como protegemos seus dados</h2>
<ul>
  <li><strong>Isolamento multi-tenant.</strong> Cada conta (escritório) tem seus dados isolados; queries são filtradas server-side por <code>account_id</code> e validadas em múltiplas camadas.</li>
  <li><strong>Senhas em bcrypt.</strong> Nunca armazenamos senhas em texto plano.</li>
  <li><strong>Autenticação em dois fatores (TOTP).</strong> Disponível para super administradores.</li>
  <li><strong>Comunicação cifrada.</strong> HTTPS em todas as conexões; cookies HttpOnly, Secure e SameSite.</li>
  <li><strong>Validação CSRF</strong> em endpoints state-changing.</li>
  <li><strong>Rate limit</strong> contra ataques de força bruta no login.</li>
  <li><strong>Allowlist de uploads</strong> com validação por magic bytes (não confia em MIME do cliente).</li>
  <li><strong>Logs de auditoria</strong> imutáveis para ações administrativas críticas.</li>
  <li><strong>Cifragem at-rest</strong> para segredos sensíveis (tokens TOTP, chaves de API de integração).</li>
  <li><strong>Webhooks isolados por tenant</strong> — eventos de um escritório nunca vazam para webhooks de outro.</li>
</ul>

<h2>3. Como funciona o tratamento</h2>
<h3>3.1 Quando somos controlador</h3>
<p>Para os dados próprios da relação SaaS: cadastro do escritório PJ, dados de cobrança, suporte, segurança e marketing direto. Decidimos a finalidade e respondemos pelos direitos dos titulares.</p>
<h3>3.2 Quando somos operador</h3>
<p>Para os dados que o escritório cadastra na plataforma (clientes finais, processos, mensagens). O escritório é o controlador desses dados e decide finalidade, base legal e retenção. Atuamos sob suas instruções e contrato.</p>

<h2>4. Direitos dos titulares</h2>
<p>Você (titular dos dados) pode exercer os direitos previstos no Art. 18 da LGPD:</p>
<ul>
  <li>Confirmação da existência de tratamento;</li>
  <li>Acesso aos seus dados;</li>
  <li>Correção;</li>
  <li>Anonimização, bloqueio ou eliminação;</li>
  <li>Portabilidade;</li>
  <li>Revogação de consentimento;</li>
  <li>Informação sobre compartilhamento.</li>
</ul>
<p>Solicite pelo <a href="/sistema_vendas/public/dpo.php">canal do DPO</a>. Prazo de resposta: até 15 dias.</p>

<h2>5. Subprocessadores e terceiros</h2>
<p>O Yuris pode utilizar operadores essenciais à prestação do serviço:</p>
<ul>
  <li>Evolution API + Meta WhatsApp — para mensageria;</li>
  <li>Gateway de pagamento — para cobrança;</li>
  <li>Provedores de e-mail e infraestrutura;</li>
  <li>CDNs (Google Fonts, jsDelivr).</li>
</ul>
<p>A lista completa e atualizada está na <a href="/sistema_vendas/public/privacidade.php#operadores">Política de Privacidade</a>.</p>

<h2>6. Incidentes</h2>
<p>Em caso de incidente que represente risco aos direitos dos titulares, comunicaremos a ANPD e os titulares afetados conforme o Art. 48 da LGPD, em prazo razoável (geralmente 2 dias úteis a partir da ciência).</p>

<h2>7. Responsabilidade compartilhada</h2>
<ul>
  <li><strong>Yuris fornece:</strong> infraestrutura, código seguro, isolamento de dados, logs, ferramentas de exercício de direitos.</li>
  <li><strong>O escritório (cliente Yuris) é responsável por:</strong> base legal para tratar dados de seus clientes finais, manter dados atualizados, atender solicitações de seus próprios titulares, configurar 2FA e gestão de acessos da sua equipe.</li>
  <li><strong>O titular é responsável por:</strong> proteger sua senha, ativar 2FA quando disponível, não compartilhar credenciais.</li>
</ul>

<h2>8. Documentos relacionados</h2>
<ul>
  <li><a href="/sistema_vendas/public/privacidade.php">Política de Privacidade</a> — completa</li>
  <li><a href="/sistema_vendas/public/termos.php">Termos de Uso</a></li>
  <li><a href="/sistema_vendas/public/cookies.php">Política de Cookies</a></li>
  <li><a href="/sistema_vendas/public/dpo.php">Contato do DPO</a></li>
</ul>
HTML
];
require __DIR__ . '/includes/legal_page.php';
