<?php
/**
 * projetos.php: a pasta Projetos da edição CRM (pedido da Inovaize, 14/10/2026).
 *
 *   /projetos.php            Visão geral: a carteira inteira, gráficos e um
 *                            cartão por projeto-mãe.
 *   /projetos.php?p=<slug>   Página do projeto: números (do projeto + os
 *                            subprojetos), gráficos e as abas Visão geral,
 *                            Subprojetos, Contas, Custos e receitas, Acessos e
 *                            Ficha e pendências.
 *
 * A regra de dinheiro mora em App\Projetos (Projeto, Conta, Acesso); a tela fala
 * com /api/projetos.php (public/assets/projetos.js). Só para conta com o módulo
 * ligado (configuracoes.modulos.projetos) e só para administrador: vendedor da
 * edição CRM volta para o painel dele.
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\Projetos\Projeto;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->vendedorCrm()) { header('Location: /dashboard'); exit; }
$_projConta = \App\Master\Account::findById((int) $ctx->getAccountId()) ?? [];
if (!Projeto::habilitado($_projConta)) { header('Location: /dashboard'); exit; }

// Página de um projeto: o menu acende o projeto-mãe (o subprojeto não tem item próprio).
$_slug = trim((string) ($_GET['p'] ?? ''));
$activePage = 'projetos';
if ($_slug !== '') {
    $_st = \App\Core\Database::getConnection()->prepare(
        "SELECT p.slug, m.slug AS mae FROM projetos p LEFT JOIN projetos m ON m.id = p.parent_id AND m.account_id = p.account_id AND m.deleted_at IS NULL
          WHERE p.account_id = ? AND p.slug = ? AND p.deleted_at IS NULL LIMIT 1");
    $_st->execute([(int) $ctx->getAccountId(), $_slug]);
    $_achado = $_st->fetch(\PDO::FETCH_ASSOC);
    if (!$_achado) { header('Location: /projetos'); exit; }
    $activePage = 'projeto:' . ($_achado['mae'] ?: $_achado['slug']);
}
$_projVer = @filemtime(__DIR__ . '/assets/projetos.js') ?: 1;
$_projCssVer = @filemtime(__DIR__ . '/assets/projetos.css') ?: 1;
$_ico = fn(string $d) => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
$_mais = $_ico('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>');
$_fechar = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Projetos</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <link rel="stylesheet" href="/assets/projetos.css?v=<?= $_projCssVer ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
<main class="w-full px-6 py-6">
  <div class="page-layout">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content space-y-4" id="projPagina" data-slug="<?= htmlspecialchars($_slug) ?>">
      <!-- Desenhado por assets/projetos.js: visão geral (sem slug) ou a página do projeto. -->
      <div class="dre-panel proj-carregando" id="projConteudo">Carregando projetos…</div>
    </section>
  </div>
</main>

<!-- ══ MODAL: Projeto (novo / editar) ══ -->
<div id="projModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:700px">
    <div class="modal-header">
      <div class="modal-title" id="pmTitulo">Novo projeto</div>
      <button type="button" class="modal-close" data-fechar="projModal" aria-label="Fechar"><?= $_fechar ?></button>
    </div>
    <input type="hidden" id="pmId">
    <div class="modal-form-grid">
      <div class="form-group span2"><label for="pmNome">Nome do projeto</label><input id="pmNome" type="text" maxlength="150" autocomplete="off" placeholder="Ex: Arte Piedosa"></div>
      <div class="form-group span2"><label for="pmPai">Dentro de qual projeto</label><select id="pmPai"></select></div>
      <div class="form-group"><label for="pmTipo">Tipo</label><select id="pmTipo"></select></div>
      <div class="form-group"><label for="pmStatus">Situação</label><select id="pmStatus"></select></div>
      <?php if (\App\Ambientes\Ambiente::habilitado(\App\Master\Account::findById((int) \App\Core\AccountContext::fromSession()->getAccountId()) ?? [])): /* ambientes: área responsável */ ?>
      <div class="form-group"><label for="pmArea">Área responsável</label><select id="pmArea"><option value="software">Software</option><option value="digital">Digital / Marketing</option><option value="empresa">Empresa</option></select></div>
      <?php endif; ?>
      <div class="form-group"><label for="pmCliente">Cliente</label><input id="pmCliente" type="text" maxlength="150" autocomplete="off" placeholder="Quem contratou (vazio se for nosso)"></div>
      <div class="form-group"><label for="pmResponsavel">Responsável</label><input id="pmResponsavel" type="text" maxlength="150" autocomplete="off"></div>
      <div class="form-group"><label for="pmUrl">Endereço de produção</label><input id="pmUrl" type="text" maxlength="255" autocomplete="off" placeholder="https://"></div>
      <div class="form-group"><label for="pmRepo">Repositório</label><input id="pmRepo" type="text" maxlength="255" autocomplete="off" placeholder="GitHub, pasta local..."></div>
      <div class="form-group"><label for="pmServidor">Onde roda (servidor)</label><select id="pmServidor"></select></div>
      <div class="form-group"><label for="pmPeso">Peso no servidor (rateio)</label><input id="pmPeso" type="number" min="1" max="100" step="1" value="1"></div>
      <div class="form-group"><label for="pmStack">Stack</label><input id="pmStack" type="text" maxlength="255" autocomplete="off" placeholder="PHP + MySQL, Next.js..."></div>
      <div class="form-group"><label for="pmUsuarios">Usuários ativos</label><input id="pmUsuarios" type="number" min="0" step="1" placeholder="vazio = soma das contas"></div>
      <div class="form-group"><label for="pmInicio">Início do projeto</label><input id="pmInicio" type="date"></div>
      <div class="form-group span2"><label for="pmDescricao">Descrição</label><textarea id="pmDescricao" class="proj-textarea" rows="2" maxlength="4000"></textarea></div>
      <div class="form-group span2"><label for="pmObs">Observações</label><textarea id="pmObs" class="proj-textarea" rows="2" maxlength="4000" placeholder="Contrato, combinados (senhas vão em Acessos)"></textarea></div>
    </div>
    <div id="pmMsg" class="error-msg"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-fechar="projModal">Cancelar</button>
      <button type="button" class="btn btn-primary" id="pmSalvar">Salvar projeto</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Receita ou custo ══ -->
<div id="itemModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:620px">
    <div class="modal-header">
      <div><div class="modal-title" id="imTitulo">Novo custo</div><p class="proj-sub" id="imSub"></p></div>
      <button type="button" class="modal-close" data-fechar="itemModal" aria-label="Fechar"><?= $_fechar ?></button>
    </div>
    <input type="hidden" id="imId"><input type="hidden" id="imNatureza"><input type="hidden" id="imProjeto">
    <div class="modal-form-grid">
      <div class="form-group"><label for="imCategoria">Categoria</label><select id="imCategoria"></select></div>
      <div class="form-group"><label for="imFornecedor" id="imFornecedorRot">Fornecedor</label><input id="imFornecedor" type="text" maxlength="150" autocomplete="off" list="projFornecedores"></div>
      <div class="form-group span2"><label for="imDescricao">Descrição</label><input id="imDescricao" type="text" maxlength="255" autocomplete="off" placeholder="Ex: API da OpenAI do agente de atendimento"></div>
      <div class="form-group"><label for="imValor">Valor unitário</label><input id="imValor" type="text" inputmode="decimal" placeholder="vazio = a preencher" style="text-align:right" autocomplete="off"></div>
      <div class="form-group"><label for="imMoeda">Moeda</label><select id="imMoeda"><option value="BRL">Real (R$)</option><option value="USD">Dólar (US$)</option></select></div>
      <div class="form-group"><label for="imQtd">Quantidade</label><input id="imQtd" type="text" inputmode="decimal" value="1" style="text-align:right" autocomplete="off"></div>
      <div class="form-group"><label for="imRecorrencia">Recorrência</label><select id="imRecorrencia"></select></div>
      <div class="form-group"><label for="imInicio">Início</label><input id="imInicio" type="date"></div>
      <div class="form-group"><label for="imFim">Fim (se tiver)</label><input id="imFim" type="date"></div>
      <div class="form-group span2"><label>Total</label><div class="proj-previa" id="imPrevia">Informe o valor</div></div>
      <div class="form-group span2"><label for="imObs">Observação</label><textarea id="imObs" class="proj-textarea" rows="2" maxlength="2000"></textarea></div>
    </div>
    <div id="imMsg" class="error-msg"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-fechar="itemModal">Cancelar</button>
      <button type="button" class="btn btn-primary" id="imSalvar">Salvar</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Conta do projeto ══ -->
<div id="contaModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:640px">
    <div class="modal-header">
      <div><div class="modal-title" id="cmTitulo">Nova conta</div><p class="proj-sub" id="cmSub"></p></div>
      <button type="button" class="modal-close" data-fechar="contaModal" aria-label="Fechar"><?= $_fechar ?></button>
    </div>
    <input type="hidden" id="cmId"><input type="hidden" id="cmProjeto">
    <div class="modal-form-grid">
      <div class="form-group"><label for="cmNome">Conta / cliente</label><input id="cmNome" type="text" maxlength="150" autocomplete="off" placeholder="Ex: Escritório Silva Advogados"></div>
      <div class="form-group"><label for="cmIdent">Identificador</label><input id="cmIdent" type="text" maxlength="80" autocomplete="off" placeholder="Ex: conta #83, CNPJ"></div>
      <div class="form-group"><label for="cmPlano">Plano</label><input id="cmPlano" type="text" maxlength="80" autocomplete="off" placeholder="Equipe, Pro..."></div>
      <div class="form-group"><label for="cmStatus">Situação</label><select id="cmStatus"></select></div>
      <div class="form-group"><label for="cmReceita">Quanto paga por mês</label><div class="proj-valor-moeda"><input id="cmReceita" type="text" inputmode="decimal" placeholder="vazio = a preencher" style="text-align:right" autocomplete="off"><select id="cmMoeda"><option value="BRL">R$</option><option value="USD">US$</option></select></div></div>
      <div class="form-group"><label for="cmUsuarios">Usuários</label><input id="cmUsuarios" type="number" min="0" step="1"></div>
      <div class="form-group"><label for="cmPeso">Peso no consumo</label><input id="cmPeso" type="text" inputmode="decimal" value="1" style="text-align:right" autocomplete="off" title="1 = divide o custo do projeto igual entre as contas; 2 = consome o dobro"></div>
      <div class="form-group"><label for="cmDireto">Custo só desta conta (R$/mês)</label><input id="cmDireto" type="text" inputmode="decimal" placeholder="ex: IA que só ela usa" style="text-align:right" autocomplete="off"></div>
      <div class="form-group"><label for="cmInicio">Cliente desde</label><input id="cmInicio" type="date"></div>
      <div class="form-group span2"><label for="cmObs">Observação</label><textarea id="cmObs" class="proj-textarea" rows="2" maxlength="2000"></textarea></div>
    </div>
    <p class="proj-nota" id="cmSync" style="display:none"></p>
    <div id="cmMsg" class="error-msg"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-fechar="contaModal">Cancelar</button>
      <button type="button" class="btn btn-primary" id="cmSalvar">Salvar conta</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Acesso (link, login, master) ══ -->
<div id="acessoModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:560px">
    <div class="modal-header">
      <div><div class="modal-title" id="amTitulo">Novo acesso</div><p class="proj-sub" id="amSub"></p></div>
      <button type="button" class="modal-close" data-fechar="acessoModal" aria-label="Fechar"><?= $_fechar ?></button>
    </div>
    <input type="hidden" id="amId"><input type="hidden" id="amProjeto">
    <div class="modal-form-grid">
      <div class="form-group span2"><label for="amRotulo">Nome do acesso</label><input id="amRotulo" type="text" maxlength="120" autocomplete="off" placeholder="Ex: Painel Master, Login do cliente, Servidor (SSH)"></div>
      <div class="form-group span2"><label for="amUrl">Endereço</label><input id="amUrl" type="text" maxlength="255" autocomplete="off" placeholder="https://"></div>
      <div class="form-group"><label for="amUsuario">Usuário / e-mail</label><input id="amUsuario" type="text" maxlength="150" autocomplete="off"></div>
      <div class="form-group"><label for="amSenha">Senha</label><input id="amSenha" type="password" maxlength="500" autocomplete="new-password" placeholder="guardada cifrada"></div>
      <div class="form-group span2"><label class="proj-check"><input type="checkbox" id="amLimpar"> Apagar a senha guardada</label></div>
      <div class="form-group span2"><label for="amObs">Observação</label><textarea id="amObs" class="proj-textarea" rows="2" maxlength="2000" placeholder="2FA, quem tem acesso, onde está a chave"></textarea></div>
    </div>
    <p class="proj-nota">A senha é guardada cifrada e só aparece quando alguém clica em Mostrar; cada vez fica registrada no histórico da conta.</p>
    <div id="amMsg" class="error-msg"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-fechar="acessoModal">Cancelar</button>
      <button type="button" class="btn btn-primary" id="amSalvar">Salvar acesso</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Servidores ══ -->
<div id="srvModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:780px">
    <div class="modal-header">
      <div><div class="modal-title">Servidores</div><p class="proj-sub">O custo de cada servidor é dividido entre os projetos que rodam nele, pelo peso de cada um</p></div>
      <button type="button" class="modal-close" data-fechar="srvModal" aria-label="Fechar"><?= $_fechar ?></button>
    </div>
    <div class="dre-table-wrap"><table class="dre-table"><thead><tr><th>Servidor</th><th>Projetos</th><th style="text-align:right">Custo/mês</th><th style="text-align:right">Ações</th></tr></thead><tbody id="srvLinhas"></tbody></table></div>
    <div class="proj-srv-form">
      <div class="proj-srv-tit" id="srvFormTitulo">Novo servidor</div>
      <input type="hidden" id="srvId">
      <div class="modal-form-grid">
        <div class="form-group"><label for="srvNome">Nome</label><input id="srvNome" type="text" maxlength="150" autocomplete="off" placeholder="Ex: AWS EC2 principal"></div>
        <div class="form-group"><label for="srvProvedor">Provedor</label><input id="srvProvedor" type="text" maxlength="100" autocomplete="off" placeholder="AWS, Hostinger, Vercel..."></div>
        <div class="form-group"><label for="srvEndereco">Endereço / IP</label><input id="srvEndereco" type="text" maxlength="255" autocomplete="off"></div>
        <div class="form-group"><label for="srvValor">Custo mensal</label><div class="proj-valor-moeda"><input id="srvValor" type="text" inputmode="decimal" placeholder="vazio = a preencher" style="text-align:right" autocomplete="off"><select id="srvMoeda"><option value="BRL">R$</option><option value="USD">US$</option></select></div></div>
        <div class="form-group span2"><label for="srvObs">Observação</label><input id="srvObs" type="text" maxlength="2000" autocomplete="off" placeholder="Tipo de máquina, plano, o que mais roda nele"></div>
      </div>
      <div id="srvMsg" class="error-msg"></div>
      <div class="proj-srv-botoes">
        <button type="button" class="btn btn-ghost btn-sm" id="srvLimpar">Limpar</button>
        <button type="button" class="btn btn-primary btn-sm" id="srvSalvar">Salvar servidor</button>
      </div>
    </div>
  </div>
</div>
<datalist id="projFornecedores"></datalist>

<script src="/assets/projetos.js?v=<?= $_projVer ?>"></script>
<script src="/assets/fog.js"></script>
</body>
</html>
