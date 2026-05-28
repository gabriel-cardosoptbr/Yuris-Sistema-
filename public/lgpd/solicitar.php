<?php
/**
 * /lgpd/solicitar.php — Formulário público de solicitação LGPD (Art. 18).
 *
 * Qualquer titular (logado ou não) abre solicitação aqui. Sistema gera token,
 * notifica DPO via Mailer (driver atual = log), e devolve link de acompanhamento.
 *
 * Visual: usa classes utilitárias do legal_page.php que respondem ao tema
 * (claro/escuro via localStorage).
 */
$LEGAL_PAGE = [
    'titulo'    => 'Solicitação de Direitos LGPD',
    'descricao' => 'Exerça seus direitos como titular de dados pessoais (Lei 13.709/2018).',
    'versao'    => '',
    'corpo_html' => <<<'HTML'
<p>Como titular de dados pessoais tratados pelo Yuris ou por escritórios de advocacia que usam a plataforma, você pode exercer os direitos previstos no <strong>Art. 18 da LGPD</strong>:</p>
<ul>
  <li><strong>Confirmação</strong> da existência de tratamento;</li>
  <li><strong>Acesso</strong> aos seus dados;</li>
  <li><strong>Correção</strong> de dados incompletos, inexatos ou desatualizados;</li>
  <li><strong>Anonimização</strong>, bloqueio ou eliminação de dados;</li>
  <li><strong>Portabilidade</strong> dos dados;</li>
  <li><strong>Eliminação</strong> dos dados tratados com base em consentimento;</li>
  <li><strong>Informação</strong> sobre compartilhamento;</li>
  <li><strong>Revogação</strong> do consentimento.</li>
</ul>
<p>Após o envio, você receberá um <strong>link único de acompanhamento</strong>. Guarde-o — é a forma de verificar o status sem precisar criar conta.</p>
<p>Prazo de resposta: até <strong>15 dias corridos</strong> (LGPD Art. 19), prorrogável em casos complexos.</p>

<form id="lgpdForm" class="legal-form" autocomplete="off">
  <div class="legal-form-grid">
    <label class="legal-field">
      Nome completo *
      <input name="titular_nome" required class="legal-input">
    </label>
    <label class="legal-field">
      E-mail *
      <input name="titular_email" type="email" required class="legal-input">
    </label>
    <label class="legal-field">
      CPF (opcional)
      <input name="titular_cpf" placeholder="000.000.000-00" class="legal-input">
    </label>
    <label class="legal-field">
      Telefone (opcional)
      <input name="titular_telefone" placeholder="(11) 99999-9999" class="legal-input">
    </label>
  </div>

  <label class="legal-field">
    Tipo da solicitação *
    <select name="tipo" required class="legal-input">
      <option value="">— escolha —</option>
      <option value="confirmacao_existencia">Confirmar existência de tratamento</option>
      <option value="acesso">Acessar meus dados</option>
      <option value="correcao">Corrigir dados desatualizados</option>
      <option value="anonimizacao">Anonimizar meus dados</option>
      <option value="bloqueio">Bloquear o tratamento</option>
      <option value="eliminacao">Eliminar meus dados</option>
      <option value="portabilidade">Portabilidade (exportar meus dados)</option>
      <option value="info_compartilhamento">Saber com quem meus dados são compartilhados</option>
      <option value="revogacao_consentimento">Revogar consentimento</option>
      <option value="revisao_decisao_automatizada">Revisão de decisão automatizada</option>
    </select>
  </label>

  <label class="legal-field">
    Descrição (detalhes da solicitação)
    <textarea name="descricao" rows="4" class="legal-input"
              placeholder="Ex: Quero saber quais dos meus dados estão registrados nos sistemas do escritório XYZ."></textarea>
  </label>

  <label class="legal-field-row">
    <input type="checkbox" name="aceito_tratamento" required style="margin-top:3px">
    <span>
      Autorizo o tratamento dos dados pessoais informados nesta solicitação <strong>exclusivamente para responder a este pedido</strong>,
      conforme nossa <a href="/privacidade.php">Política de Privacidade</a>.
      Estes dados serão usados para identificar você e processar o pedido — não serão utilizados para outras finalidades.
    </span>
  </label>

  <button type="submit" id="submitBtn" class="legal-btn-primary">Enviar solicitação</button>
  <div id="formAlert"></div>
</form>

<script>
document.getElementById('lgpdForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const form = ev.target;
  const btn  = document.getElementById('submitBtn');
  const out  = document.getElementById('formAlert');
  btn.disabled = true; btn.textContent = 'Enviando...';
  out.innerHTML = '';

  const data = Object.fromEntries(new FormData(form).entries());
  data.aceito_tratamento = !!data.aceito_tratamento;

  try {
    const r = await fetch('/api/lgpd/request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const j = await r.json();
    if (!j.ok) {
      out.innerHTML = '<div class="legal-alert legal-alert-error">Erro: ' + (j.error || 'desconhecido') + '</div>';
      btn.disabled = false; btn.textContent = 'Enviar solicitação';
      return;
    }
    const link = j.data.acompanhamento;
    out.innerHTML = '<div class="legal-alert legal-alert-success">'
      + '<strong>Solicitação registrada!</strong> Prazo de resposta: ' + j.data.prazo_dias + ' dias corridos.<br><br>'
      + '<strong>Link de acompanhamento (guarde este link):</strong><br>'
      + '<a href="' + link + '" style="word-break:break-all">' + window.location.origin + link + '</a><br><br>'
      + 'Você também pode reabrir esta página a qualquer momento e usar o link para verificar o status.'
      + '</div>';
    form.style.display = 'none';
  } catch (e) {
    out.innerHTML = '<div class="legal-alert legal-alert-error">Erro de rede: ' + e.message + '</div>';
    btn.disabled = false; btn.textContent = 'Enviar solicitação';
  }
});
</script>
HTML
];
require __DIR__ . '/../includes/legal_page.php';
