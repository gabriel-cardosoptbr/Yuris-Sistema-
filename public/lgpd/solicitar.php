<?php
/**
 * /lgpd/solicitar.php — Formulário público de solicitação LGPD (Art. 18).
 *
 * Qualquer titular (logado ou não) abre solicitação aqui. Sistema gera token,
 * notifica DPO via Mailer (driver atual = log), e devolve link de acompanhamento.
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

<form id="lgpdForm" style="display:flex;flex-direction:column;gap:14px;margin-top:24px" autocomplete="off">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.85rem;color:#cbd5e1">
      Nome completo *
      <input name="titular_nome" required style="padding:9px 12px;border:1px solid rgba(96,165,250,.25);border-radius:7px;background:rgba(8,12,24,.7);color:#fff;font:inherit">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.85rem;color:#cbd5e1">
      E-mail *
      <input name="titular_email" type="email" required style="padding:9px 12px;border:1px solid rgba(96,165,250,.25);border-radius:7px;background:rgba(8,12,24,.7);color:#fff;font:inherit">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.85rem;color:#cbd5e1">
      CPF (opcional)
      <input name="titular_cpf" placeholder="000.000.000-00" style="padding:9px 12px;border:1px solid rgba(96,165,250,.25);border-radius:7px;background:rgba(8,12,24,.7);color:#fff;font:inherit">
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:.85rem;color:#cbd5e1">
      Telefone (opcional)
      <input name="titular_telefone" placeholder="(11) 99999-9999" style="padding:9px 12px;border:1px solid rgba(96,165,250,.25);border-radius:7px;background:rgba(8,12,24,.7);color:#fff;font:inherit">
    </label>
  </div>

  <label style="display:flex;flex-direction:column;gap:4px;font-size:.85rem;color:#cbd5e1">
    Tipo da solicitação *
    <select name="tipo" required style="padding:9px 12px;border:1px solid rgba(96,165,250,.25);border-radius:7px;background:rgba(8,12,24,.7);color:#fff;font:inherit">
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

  <label style="display:flex;flex-direction:column;gap:4px;font-size:.85rem;color:#cbd5e1">
    Descrição (detalhes da solicitação)
    <textarea name="descricao" rows="4" placeholder="Ex: Quero saber quais dos meus dados estão registrados nos sistemas do escritório XYZ." style="padding:9px 12px;border:1px solid rgba(96,165,250,.25);border-radius:7px;background:rgba(8,12,24,.7);color:#fff;font:inherit;resize:vertical"></textarea>
  </label>

  <label style="display:flex;align-items:flex-start;gap:8px;font-size:.84rem;color:#cbd5e1;cursor:pointer">
    <input type="checkbox" name="aceito_tratamento" required style="margin-top:3px">
    <span>
      Autorizo o tratamento dos dados pessoais informados nesta solicitação <strong>exclusivamente para responder a este pedido</strong>,
      conforme nossa <a href="/sistema_vendas/public/privacidade.php" target="_blank" style="color:#7eb8f7">Política de Privacidade</a>.
      Estes dados serão usados para identificar você e processar o pedido — não serão utilizados para outras finalidades.
    </span>
  </label>

  <button type="submit" id="submitBtn" style="padding:12px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:8px;font-weight:700;font-size:.92rem;cursor:pointer">Enviar solicitação</button>
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
    const r = await fetch('/sistema_vendas/public/api/lgpd/request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const j = await r.json();
    if (!j.ok) {
      out.innerHTML = '<div style="padding:12px;background:rgba(239,68,68,.13);border:1px solid rgba(239,68,68,.30);color:#fca5a5;border-radius:8px;margin-top:8px">Erro: ' + (j.error || 'desconhecido') + '</div>';
      btn.disabled = false; btn.textContent = 'Enviar solicitação';
      return;
    }
    const link = j.data.acompanhamento;
    out.innerHTML = '<div style="padding:14px;background:rgba(16,185,129,.13);border:1px solid rgba(16,185,129,.30);color:#a7f3d0;border-radius:8px;margin-top:12px">'
      + '<strong>Solicitação registrada!</strong> Prazo de resposta: ' + j.data.prazo_dias + ' dias corridos.<br><br>'
      + '<strong>Link de acompanhamento (guarde este link):</strong><br>'
      + '<a href="' + link + '" style="color:#7eb8f7;word-break:break-all">' + window.location.origin + link + '</a><br><br>'
      + 'Você também pode reabrir esta página a qualquer momento e usar o link para verificar o status.'
      + '</div>';
    form.style.display = 'none';
  } catch (e) {
    out.innerHTML = '<div style="padding:12px;background:rgba(239,68,68,.13);border:1px solid rgba(239,68,68,.30);color:#fca5a5;border-radius:8px;margin-top:8px">Erro de rede: ' + e.message + '</div>';
    btn.disabled = false; btn.textContent = 'Enviar solicitação';
  }
});
</script>
HTML
];
require __DIR__ . '/../includes/legal_page.php';
