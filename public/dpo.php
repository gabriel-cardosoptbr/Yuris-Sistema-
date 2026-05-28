<?php
/**
 * Contato do Encarregado de Dados (DPO) — modelo inicial (LGPD Etapa 5).
 * Lê DPO_NAME, DPO_EMAIL, DPO_PHONE do .env (variáveis vazias → mensagem placeholder).
 */
require_once __DIR__ . '/../app/Helpers/EnvLoader.php';
\App\Helpers\EnvLoader::load();

$dpoNome  = trim(\App\Helpers\EnvLoader::get('DPO_NAME',     ''));
$dpoEmail = trim(\App\Helpers\EnvLoader::get('DPO_EMAIL',    ''));
$dpoFone  = trim(\App\Helpers\EnvLoader::get('DPO_PHONE',    ''));
$dpoEnd   = trim(\App\Helpers\EnvLoader::get('DPO_ADDRESS',  ''));

if ($dpoNome === '' && $dpoEmail === '') {
    $dpoBlock = '<p><em>(DPO ainda não designado nesta instalação. Configure as variáveis '
              . '<code>DPO_NAME</code>, <code>DPO_EMAIL</code>, <code>DPO_PHONE</code> '
              . 'no arquivo <code>.env</code> do servidor para exibir os contatos aqui.)</em></p>';
} else {
    $dpoBlock = '<ul>';
    if ($dpoNome)  $dpoBlock .= '<li><strong>Nome:</strong> ' . htmlspecialchars($dpoNome)  . '</li>';
    if ($dpoEmail) $dpoBlock .= '<li><strong>E-mail:</strong> <a href="mailto:' . htmlspecialchars($dpoEmail) . '">' . htmlspecialchars($dpoEmail) . '</a></li>';
    if ($dpoFone)  $dpoBlock .= '<li><strong>Telefone:</strong> ' . htmlspecialchars($dpoFone)  . '</li>';
    if ($dpoEnd)   $dpoBlock .= '<li><strong>Endereço:</strong> ' . htmlspecialchars($dpoEnd)   . '</li>';
    $dpoBlock .= '</ul>';
}

$LEGAL_PAGE = [
    'titulo'    => 'Encarregado de Dados (DPO)',
    'descricao' => 'Canal oficial para solicitações relacionadas à LGPD.',
    'versao'    => '2026-05-23',
    'corpo_html' => <<<HTML
<h2>1. Quem é o DPO</h2>
<p>O Encarregado de Tratamento de Dados Pessoais (DPO — <em>Data Protection Officer</em>) é o ponto de contato entre a Inovaize, os titulares de dados pessoais e a Autoridade Nacional de Proteção de Dados (ANPD), nos termos do Art. 41 da Lei 13.709/2018 (LGPD).</p>

<h2>2. Contato oficial</h2>
{$dpoBlock}

<h2>3. O que você pode solicitar</h2>
<p>Use este canal para:</p>
<ul>
  <li><strong>Exercer seus direitos</strong> (Art. 18 LGPD): acesso, correção, eliminação, portabilidade, revogação de consentimento, informação sobre compartilhamento;</li>
  <li><strong>Reportar incidentes</strong> de segurança ou suspeita de vazamento envolvendo seus dados;</li>
  <li><strong>Tirar dúvidas</strong> sobre como tratamos seus dados pessoais;</li>
  <li><strong>Apresentar reclamações</strong> antes de recorrer à ANPD.</li>
</ul>

<p style="background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.25);padding:14px;border-radius:8px;margin-top:14px">
  <strong>Forma mais rápida:</strong> abra uma solicitação pelo nosso formulário online em
  <a href="/lgpd/solicitar.php" style="color:#7eb8f7"><strong>/lgpd/solicitar.php</strong></a>.
  Você recebe um link único para acompanhar o status e nós notificamos o DPO automaticamente.
</p>

<h2>4. Como sua solicitação será tratada</h2>
<ol>
  <li>Recebemos o pedido (e-mail, formulário ou outro canal indicado acima);</li>
  <li>Confirmamos sua identidade — para proteger contra solicitações fraudulentas, podemos pedir documento ou informações que só o titular saberia;</li>
  <li>Analisamos a viabilidade e a base legal (alguns dados precisam ser mantidos por obrigação legal);</li>
  <li>Respondemos em até <strong>15 dias</strong> a contar da confirmação da identidade. Para solicitações complexas, podemos estender o prazo, comunicando o titular;</li>
  <li>Documentamos a solicitação e a resposta para fins de auditoria.</li>
</ol>

<h2>5. Recursos</h2>
<p>Se não estiver satisfeito com nossa resposta, você pode recorrer à
<a href="https://www.gov.br/anpd/" target="_blank" rel="noopener">Autoridade Nacional de Proteção de Dados (ANPD)</a>.</p>

<h2>6. Documentos relacionados</h2>
<ul>
  <li><a href="/privacidade.php">Política de Privacidade</a></li>
  <li><a href="/termos.php">Termos de Uso</a></li>
  <li><a href="/lgpd.php">LGPD &amp; Segurança</a></li>
</ul>
HTML
];
require __DIR__ . '/includes/legal_page.php';
