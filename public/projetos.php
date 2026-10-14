<?php
/**
 * projetos.php: a aba Projetos da edição CRM (pedido da Inovaize, 14/10/2026).
 *
 * Carteira de projetos da conta: o que cada um consome (servidor rateado, IA,
 * WhatsApp, domínio, licença por usuário...), quanto arrecada, a margem, e o
 * dossiê de cada um com o que ainda falta preencher. A regra de dinheiro mora
 * em App\Projetos\Projeto; a tela fala com /api/projetos.php
 * (public/assets/projetos.js).
 *
 * Só para conta com o módulo ligado (configuracoes.modulos.projetos) e só para
 * administrador: vendedor da edição CRM volta para o painel dele.
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\Projetos\Projeto;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage = 'projetos';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->vendedorCrm()) { header('Location: /dashboard.php'); exit; }
$_projConta = \App\Master\Account::findById((int) $ctx->getAccountId()) ?? [];
if (!Projeto::habilitado($_projConta)) { header('Location: /dashboard.php'); exit; }
$_projVer = @filemtime(__DIR__ . '/assets/projetos.js') ?: 1;
$_projCssVer = @filemtime(__DIR__ . '/assets/projetos.css') ?: 1;
$_ico = fn(string $d) => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
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
</head>
<body>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
<main class="w-full px-6 py-6">
  <div class="page-layout">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content space-y-4" id="projPagina">

      <div class="dre-panel page-header">
        <div class="page-header-inner proj-cab">
          <div class="page-header-text">
            <h2 class="page-header-title">Projetos</h2>
            <p class="page-header-subtitle">Tudo o que a empresa roda: o que cada projeto consome, quanto arrecada e o que ainda falta preencher</p>
          </div>
          <div class="proj-cab-botoes">
            <button type="button" class="btn btn-ghost btn-sm" id="projServidoresBtn"><?= $_ico('<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>') ?> Servidores</button>
            <button type="button" class="btn btn-primary btn-sm" id="projNovoBtn"><?= $_ico('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>') ?> Novo projeto</button>
          </div>
        </div>
      </div>

      <div class="kpi-grid proj-kpis">
        <div class="kpi-card lucro"><div class="kpi-label">Projetos em produção</div><div class="kpi-value" id="kProjetos">–</div><div class="kpi-desc" id="kProjetosDesc">&nbsp;</div></div>
        <div class="kpi-card receita"><div class="kpi-label">Receita por mês</div><div class="kpi-value" id="kReceita">–</div><div class="kpi-desc" id="kReceitaDesc">recorrente, projetos não encerrados</div></div>
        <div class="kpi-card despesa"><div class="kpi-label">Custo por mês</div><div class="kpi-value" id="kCusto">–</div><div class="kpi-desc">servidores, IA, APIs, licenças e ferramentas</div></div>
        <div class="kpi-card margem"><div class="kpi-label">Margem por mês</div><div class="kpi-value" id="kMargem">–</div><div class="kpi-desc" id="kMargemDesc">&nbsp;</div></div>
        <div class="kpi-card resultado"><div class="kpi-label">Arrecadado até hoje</div><div class="kpi-value" id="kArrecadado">–</div><div class="kpi-desc">previsto pelos contratos com data de início</div></div>
        <div class="kpi-card imposto"><div class="kpi-label">Dossiê a completar</div><div class="kpi-value" id="kPendencias">–</div><div class="kpi-desc" id="kPendenciasDesc">informações faltando</div></div>
      </div>

      <div class="dre-panel">
        <div class="proj-barra">
          <div class="proj-filtros" id="projFiltros">
            <button type="button" class="btn btn-primary btn-sm" data-filtro="ativos">Ativos</button>
            <button type="button" class="btn btn-ghost btn-sm" data-filtro="producao">Em produção</button>
            <button type="button" class="btn btn-ghost btn-sm" data-filtro="desenvolvimento">Em desenvolvimento</button>
            <button type="button" class="btn btn-ghost btn-sm" data-filtro="pausado">Pausados</button>
            <button type="button" class="btn btn-ghost btn-sm" data-filtro="encerrado">Encerrados</button>
            <button type="button" class="btn btn-ghost btn-sm" data-filtro="pendentes">Com pendência</button>
          </div>
          <div class="proj-barra-dir">
            <input type="search" id="projBusca" class="proj-busca" placeholder="Buscar projeto, cliente, fornecedor" autocomplete="off">
            <button type="button" class="btn btn-ghost btn-sm" id="projCotacaoBtn" title="Cotação usada para converter custos em dólar">US$ 1 = <span id="projCotacao">R$ –</span></button>
          </div>
        </div>
        <div class="dre-table-wrap">
          <table class="dre-table proj-tabela" id="projTabela">
            <thead>
              <tr>
                <th>Projeto</th>
                <th>Situação</th>
                <th>Onde roda</th>
                <th style="text-align:right">Usuários</th>
                <th style="text-align:right">Receita/mês</th>
                <th style="text-align:right">Custo/mês</th>
                <th style="text-align:right">Margem/mês</th>
                <th style="text-align:right">Arrecadado</th>
                <th>Dossiê</th>
              </tr>
            </thead>
            <tbody><tr class="empty-row"><td colspan="9">Carregando projetos…</td></tr></tbody>
          </table>
        </div>
      </div>

      <div class="proj-graficos">
        <div class="dre-panel">
          <div class="panel-title">Para onde vai o custo</div>
          <p class="panel-sub">Custo mensal da carteira por tipo de consumo</p>
          <div id="projCategorias" class="proj-barras"></div>
        </div>
        <div class="dre-panel">
          <div class="panel-title">Quem paga a conta</div>
          <p class="panel-sub">Receita e custo mensal de cada projeto ativo</p>
          <div id="projRanking" class="proj-barras"></div>
        </div>
      </div>

    </section>
  </div>
</main>

<!-- ══ MODAL: Dossiê do projeto ══ -->
<div id="projDossie" class="modal-shell" style="display:none">
  <div class="modal-panel proj-dossie">
    <div class="modal-header">
      <div style="min-width:0">
        <div class="modal-title" id="dosNome">Projeto</div>
        <p class="proj-dossie-sub" id="dosSub"></p>
      </div>
      <div class="proj-dossie-acoes">
        <button type="button" class="btn btn-ghost btn-sm" id="dosEditar">Editar dados</button>
        <button type="button" class="btn btn-ghost btn-sm proj-perigo" id="dosExcluir">Excluir</button>
        <button type="button" class="modal-close" data-fechar="projDossie" aria-label="Fechar"><?= $_fechar ?></button>
      </div>
    </div>
    <div class="proj-numeros" id="dosNumeros"></div>
    <div class="proj-pend" id="dosPendencias"></div>
    <div class="proj-secao">
      <div class="proj-secao-cab"><h3>Ficha</h3></div>
      <dl class="proj-ficha" id="dosFicha"></dl>
    </div>
    <div class="proj-secao">
      <div class="proj-secao-cab">
        <h3>O que consome</h3>
        <button type="button" class="btn btn-ghost btn-sm" data-novo-item="custo"><?= $_ico('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>') ?> Custo</button>
      </div>
      <div class="dre-table-wrap"><table class="dre-table proj-itens"><thead><tr><th>Item</th><th>Fornecedor</th><th style="text-align:right">Valor</th><th>Recorrência</th><th style="text-align:right">Por mês</th><th style="text-align:right">Ações</th></tr></thead><tbody id="dosCustos"></tbody></table></div>
    </div>
    <div class="proj-secao">
      <div class="proj-secao-cab">
        <h3>O que arrecada</h3>
        <button type="button" class="btn btn-ghost btn-sm" data-novo-item="receita"><?= $_ico('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>') ?> Receita</button>
      </div>
      <div class="dre-table-wrap"><table class="dre-table proj-itens"><thead><tr><th>Item</th><th>Quem paga</th><th style="text-align:right">Valor</th><th>Recorrência</th><th style="text-align:right">Por mês</th><th style="text-align:right">Ações</th></tr></thead><tbody id="dosReceitas"></tbody></table></div>
    </div>
  </div>
</div>

<!-- ══ MODAL: Projeto (novo / editar) ══ -->
<div id="projModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:680px">
    <div class="modal-header">
      <div class="modal-title" id="pmTitulo">Novo projeto</div>
      <button type="button" class="modal-close" data-fechar="projModal" aria-label="Fechar"><?= $_fechar ?></button>
    </div>
    <input type="hidden" id="pmId">
    <div class="modal-form-grid">
      <div class="form-group span2"><label for="pmNome">Nome do projeto</label><input id="pmNome" type="text" maxlength="150" autocomplete="off" placeholder="Ex: CRM Via Autodoc"></div>
      <div class="form-group"><label for="pmTipo">Tipo</label><select id="pmTipo"></select></div>
      <div class="form-group"><label for="pmStatus">Situação</label><select id="pmStatus"></select></div>
      <div class="form-group"><label for="pmCliente">Cliente</label><input id="pmCliente" type="text" maxlength="150" autocomplete="off" placeholder="Quem contratou (vazio se for nosso)"></div>
      <div class="form-group"><label for="pmResponsavel">Responsável</label><input id="pmResponsavel" type="text" maxlength="150" autocomplete="off"></div>
      <div class="form-group"><label for="pmUrl">Endereço de produção</label><input id="pmUrl" type="text" maxlength="255" autocomplete="off" placeholder="https://"></div>
      <div class="form-group"><label for="pmRepo">Repositório</label><input id="pmRepo" type="text" maxlength="255" autocomplete="off" placeholder="GitHub, pasta local..."></div>
      <div class="form-group"><label for="pmServidor">Onde roda (servidor)</label><select id="pmServidor"></select></div>
      <div class="form-group"><label for="pmPeso">Peso no servidor (rateio)</label><input id="pmPeso" type="number" min="1" max="100" step="1" value="1"></div>
      <div class="form-group"><label for="pmStack">Stack</label><input id="pmStack" type="text" maxlength="255" autocomplete="off" placeholder="PHP + MySQL, Next.js..."></div>
      <div class="form-group"><label for="pmUsuarios">Usuários ativos</label><input id="pmUsuarios" type="number" min="0" step="1" placeholder="a preencher"></div>
      <div class="form-group"><label for="pmInicio">Início do projeto</label><input id="pmInicio" type="date"></div>
      <div class="form-group span2"><label for="pmDescricao">Descrição</label><textarea id="pmDescricao" class="proj-textarea" rows="2" maxlength="4000"></textarea></div>
      <div class="form-group span2"><label for="pmObs">Observações</label><textarea id="pmObs" class="proj-textarea" rows="2" maxlength="4000" placeholder="Contrato, combinados, acessos (sem senha)"></textarea></div>
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
      <div><div class="modal-title" id="imTitulo">Novo custo</div><p class="proj-dossie-sub" id="imSub"></p></div>
      <button type="button" class="modal-close" data-fechar="itemModal" aria-label="Fechar"><?= $_fechar ?></button>
    </div>
    <input type="hidden" id="imId"><input type="hidden" id="imNatureza">
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

<!-- ══ MODAL: Servidores ══ -->
<div id="srvModal" class="modal-shell" style="display:none">
  <div class="modal-panel" style="width:760px">
    <div class="modal-header">
      <div><div class="modal-title">Servidores</div><p class="proj-dossie-sub">O custo de cada servidor é dividido entre os projetos que rodam nele, pelo peso de cada um</p></div>
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
