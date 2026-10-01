<?php
require_once __DIR__ . '/../app/bootstrap.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
// HARDENING: bloqueia acesso de contas suspensas/canceladas/inativas
\App\Core\AccountContext::fromSession()->assertAccountActive();
$activePage = 'tarefas';
$csrf       = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$userId     = (int)$_SESSION['user_id'];
$userName   = htmlspecialchars($_SESSION['user_nome'] ?? '');
// Edição CRM (conta sem módulo jurídico): a ficha da tarefa tem o desenho do
// Fleetiflow (assets/ff-ficha.css e .js). A edição jurídica não muda.
$edicaoCrm  = false;
try { $edicaoCrm = !\App\Core\AccountContext::fromSession()->moduloJuridicoDisponivel(); } catch (\Throwable $e) { /* Yuris */ }

// Contas acessíveis (própria + filiais ativas, se matriz) — alimenta filtro de Origem
$origin_accounts = [];
$origin_self     = ['id' => 0, 'tipo' => 'matriz', 'nome' => ''];
try {
    $ctx_t = \App\Core\AccountContext::fromSession();
    $origin_self = [
        'id'   => $ctx_t->getAccountId(),
        'tipo' => $ctx_t->getAccountTipo(),
        'nome' => $_SESSION['account_nome'] ?? '',
    ];
    if ($ctx_t->isMatriz()) {
        // FIX (auditoria 2026-06-01): matriz_id e sempre NULL — usa account_vinculos
        // via getAccessibleAccountIds (canonico), respeitando sync_tarefas das filiais.
        $accessibleIds = $ctx_t->getAccessibleAccountIds('tarefas');
        if (count($accessibleIds) > 1) {
            $pdo_t = \App\Core\Database::getConnection();
            $ph_t  = implode(',', array_fill(0, count($accessibleIds), '?'));
            $stmt_t = $pdo_t->prepare(
                "SELECT id, nome, tipo
                 FROM accounts
                 WHERE id IN ($ph_t) AND deleted_at IS NULL
                   AND status IN ('active','trial','overdue')
                 ORDER BY CASE WHEN tipo = 'matriz' THEN 0 ELSE 1 END, nome ASC"
            );
            $stmt_t->execute(array_map('intval', $accessibleIds));
            $origin_accounts = $stmt_t->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tarefas — Yuris</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <link rel="stylesheet" href="/assets/tarefas.css?v=13">
<?php if ($edicaoCrm): ?>
  <link rel="stylesheet" href="/assets/ff-ficha.css?v=<?= @filemtime(__DIR__ . '/assets/ff-ficha.css') ?: 1 ?>">
  <script src="/assets/ff-ficha.js?v=<?= @filemtime(__DIR__ . '/assets/ff-ficha.js') ?: 1 ?>"></script>
<?php endif; ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background: #070F1C;
      background-image:
        radial-gradient(ellipse 120% 80% at 15% 40%, rgba(20,50,90,0.18) 0%, transparent 55%),
        radial-gradient(ellipse 80% 60% at 85% 20%, rgba(30,60,100,0.12) 0%, transparent 50%);
      background-attachment: fixed;
      color: #D8E4F0;
      font-family: 'Poppins', system-ui, sans-serif;
      min-height: 100vh;
    }
    main.w-full { padding-top: 24px !important; }
    section.main-content { display: flex; flex-direction: column; overflow: hidden; }
    @media (max-width: 768px) { section.main-content { padding-top: 8px; } }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content">
      <div class="card-shell page-header" style="margin-bottom:16px;flex-shrink:0;">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Tarefas</h2>
            <p class="page-header-subtitle">Organize sua equipe com quadros Kanban, listas e calendário.</p>
          </div>
          <div class="page-header-actions">
            <button id="tkBtnNewTask" class="tk-btn-new-task">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Nova tarefa
            </button>
          </div>
        </div>
      </div>

      <!-- ── Topbar ── -->
      <div class="tk-topbar">
      <select id="tkBoardSelect" class="tk-board-select">
        <option value="">Carregando...</option>
      </select>
      <button id="tkBtnEditBoard" title="Editar quadro" style="flex-shrink:0;padding:0 8px;height:32px;border-radius:7px;border:1px solid rgba(96,165,250,.2);background:transparent;color:#7eb8f6;cursor:pointer;display:flex;align-items:center;transition:background .15s" onmouseover="this.style.background='rgba(37,99,235,.18)'" onmouseout="this.style.background='transparent'">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      </button>
      <button id="tkBtnDelBoard" title="Excluir quadro" style="flex-shrink:0;padding:0 8px;height:32px;border-radius:7px;border:1px solid rgba(239,68,68,.2);background:transparent;color:#fca5a5;cursor:pointer;display:flex;align-items:center;transition:background .15s" onmouseover="this.style.background='rgba(220,38,38,.18)'" onmouseout="this.style.background='transparent'">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
      </button>
      <button id="tkBtnManageCols" title="Gerenciar colunas" style="flex-shrink:0;padding:0 10px;height:32px;border-radius:7px;border:1px solid rgba(96,165,250,.2);background:transparent;color:#93c5fd;cursor:pointer;display:flex;align-items:center;gap:5px;font-size:.78rem;font-weight:600;transition:background .15s" onmouseover="this.style.background='rgba(37,99,235,.18)'" onmouseout="this.style.background='transparent'">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="5" height="15" rx="1"/></svg>
        Colunas
      </button>

      <button id="tkBtnNewBoard" class="tk-btn-new-board">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo quadro
      </button>

      <div class="tk-view-btns">
        <button class="tk-view-btn active" data-view="kanban">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="5" height="15" rx="1"/></svg>
          Kanban
        </button>
        <button class="tk-view-btn" data-view="lista">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          Lista
        </button>
        <button class="tk-view-btn" data-view="calendario">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Calendário
        </button>
      </div>

      <div class="tk-filters">
        <select id="fltResponsavel" class="tk-filter-select">
          <option value="">Responsável</option>
        </select>
        <select id="fltPrioridade" class="tk-filter-select">
          <option value="">Prioridade</option>
          <option value="urgente">Urgente</option>
          <option value="alta">Alta</option>
          <option value="media">Média</option>
          <option value="baixa">Baixa</option>
        </select>
        <select id="fltPrazo" class="tk-filter-select">
          <option value="">Prazo</option>
          <option value="hoje">Hoje</option>
          <option value="atrasadas">Atrasadas</option>
          <option value="7dias">Próximos 7 dias</option>
        </select>
        <?php
        // MEDIA #9: classifica as contas vinculadas por TIPO real. Conta solo é
        // 'advogado' (não 'filial') — precisa de optgroup/label próprios, nunca
        // pode aparecer sob "Filial específica" nem ser contada como filial.
        $filiais_accounts   = array_values(array_filter($origin_accounts, fn($a) => ($a['tipo'] ?? '') === 'filial'));
        $advogado_accounts  = array_values(array_filter($origin_accounts, fn($a) => ($a['tipo'] ?? '') === 'advogado'));
        if (count($origin_accounts) > 1): /* matriz com filiais e/ou advogados vinculados */ ?>
        <select id="fltOrigin" class="tk-filter-select" title="Filtrar por origem da tarefa">
          <option value="">Origem</option>
          <option value="__matriz__">Apenas Matriz</option>
          <?php if ($filiais_accounts): ?>
          <option value="__filiais__">Apenas Filiais</option>
          <optgroup label="Filial específica">
            <?php foreach ($filiais_accounts as $oa): ?>
              <option value="<?=htmlspecialchars((string)$oa['id'])?>"><?=htmlspecialchars($oa['nome'])?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if ($advogado_accounts): ?>
          <optgroup label="Advogado específico">
            <?php foreach ($advogado_accounts as $oa): ?>
              <option value="<?=htmlspecialchars((string)$oa['id'])?>"><?=htmlspecialchars($oa['nome'])?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
        <?php endif; ?>
        <input id="fltBusca" class="tk-search" type="text" placeholder="Buscar tarefas...">
      </div>
      </div>

      <!-- ── Área principal ── -->
      <div id="tkMainArea" style="flex:1;overflow:hidden;display:flex;flex-direction:column;">
      <!-- Kanban -->
      <div id="tkKanban" class="tk-kanban"></div>
      <!-- Lista -->
      <div id="tkLista" class="tk-list-view" style="display:none;overflow-y:auto;flex:1;"></div>
      <!-- Calendário -->
      <div id="tkCalendario" style="display:none;flex-direction:column;flex:1;">
        <div class="tk-cal-header">
          <button class="tk-cal-nav" id="calPrev">&#8249;</button>
          <span class="tk-cal-month" id="calMonthLabel"></span>
          <button class="tk-cal-nav" id="calNext">&#8250;</button>
        </div>
        <div class="tk-cal-grid" id="calGrid"></div>
      </div>
      </div>
    </section>
  </div>
</main>

<!-- ── Drawer ── -->
<div class="tk-drawer-overlay" id="tkDrawerOverlay">
<div class="tk-drawer" id="tkDrawer">
  <div class="tk-drawer-header">
    <div class="tk-drawer-title-row">
      <textarea class="tk-drawer-title" id="dTitle" rows="1" placeholder="Título da tarefa"></textarea>
      <div class="tk-drawer-actions">
        <button class="tk-drawer-btn concluir" id="dBtnConcluir">✓ Concluir</button>
        <button class="tk-drawer-btn arquivar" id="dBtnArquivar">Arquivar</button>
        <button class="tk-drawer-btn close-btn" id="dBtnClose">✕</button>
      </div>
    </div>
    <div class="tk-tabs" id="tkTabs">
      <button class="tk-tab active" data-tab="geral">Geral</button>
      <button class="tk-tab" data-tab="checklist">Checklist</button>
      <button class="tk-tab" data-tab="proc-tarefas">Tarefas Processuais</button>
      <button class="tk-tab" data-tab="vinculos">Vínculos</button>
      <button class="tk-tab" data-tab="anexos">Anexos</button>
      <button class="tk-tab" data-tab="lembretes">Lembretes</button>
      <button class="tk-tab" data-tab="comentarios">Comentários</button>
      <button class="tk-tab" data-tab="historico">Histórico</button>
    </div>
  </div>
  <div class="tk-drawer-body" id="dBody">
    <!-- Geral -->
    <div class="tk-tab-pane active" id="pane-geral">
      <div class="tk-field">
        <label>Descrição</label>
        <textarea id="dDescricao" placeholder="Descreva a tarefa..."></textarea>
      </div>
      <div class="tk-row-2">
        <div class="tk-field">
          <label>Prioridade</label>
          <select id="dPrioridade">
            <option value="baixa">Baixa</option>
            <option value="media" selected>Média</option>
            <option value="alta">Alta</option>
            <option value="urgente">Urgente</option>
          </select>
        </div>
        <div class="tk-field">
          <label>Tipo de prazo</label>
          <div style="position:relative;">
            <select id="dPrazoTipo" style="padding-right:32px;width:100%;"></select>
            <button onclick="openPrazoTipoConfig()" title="Personalizar tipos" style="position:absolute;right:28px;top:50%;transform:translateY(-50%);background:none;border:none;color:#4A5568;cursor:pointer;padding:2px;display:flex;align-items:center;transition:color .15s;" onmouseover="this.style.color='#A8CFEE'" onmouseout="this.style.color='#4A5568'">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="tk-row-2">
        <div class="tk-field">
          <label>Prazo</label>
          <input type="datetime-local" id="dPrazo">
        </div>
        <div class="tk-field">
          <label>Responsável</label>
          <select id="dResponsavel">
            <option value="">Sem responsável</option>
          </select>
        </div>
      </div>
      <div class="tk-field">
        <label>Coluna</label>
        <select id="dColuna"></select>
      </div>
      <!-- Recorrência -->
      <div class="tk-recurrence-section" id="dRecSection">
        <label class="tk-toggle">
          <input type="checkbox" id="dRecToggle">
          <span class="tk-toggle-sw"></span>
          <span class="tk-toggle-label">Tarefa recorrente</span>
        </label>
        <div id="dRecFields" style="display:none;display:flex;flex-direction:column;gap:10px;">
          <div class="tk-field">
            <label>Tipo</label>
            <select id="dRecTipo" onchange="toggleCustomRec('d')">
              <option value="diaria">Diária</option>
              <option value="semanal">Semanal</option>
              <option value="quinzenal">Quinzenal</option>
              <option value="mensal">Mensal</option>
              <option value="anual">Anual</option>
              <option value="custom">Personalizada</option>
            </select>
          </div>
          <div class="tk-row-2 tk-rec-custom" id="dRecCustom" style="display:none;">
            <div class="tk-field">
              <label>A cada</label>
              <input type="number" id="dRecIntervalo" value="1" min="1" placeholder="Ex: 2">
            </div>
            <div class="tk-field">
              <label>Unidade</label>
              <select id="dRecUnidade">
                <option value="day">Dias</option>
                <option value="week">Semanas</option>
                <option value="month">Meses</option>
                <option value="year">Anos</option>
              </select>
            </div>
          </div>
          <div class="tk-row-2">
            <div class="tk-field">
              <label>Data início</label>
              <input type="date" id="dRecInicio">
            </div>
            <div class="tk-field">
              <label>Data fim (opcional)</label>
              <input type="date" id="dRecFim">
            </div>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:6px;">
        <button class="tk-btn-sm tk-btn-ok" id="dBtnSave">Salvar</button>
      </div>
    </div>
    <!-- Checklist -->
    <div class="tk-tab-pane" id="pane-checklist">
      <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
        <input id="dCheckNew" type="text" placeholder="Nova tarefa..."
          style="flex:1;min-width:140px;padding:9px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.82rem;font-family:inherit;color-scheme:dark;">
        <input id="dCheckPrazo" type="date"
          style="padding:9px 10px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.82rem;font-family:inherit;color-scheme:dark;width:140px;">
        <button id="dCheckAdd"
          style="padding:9px 16px;border-radius:8px;background:rgba(37,99,235,.25);border:1px solid rgba(96,165,250,.3);color:#93c5fd;cursor:pointer;font-size:.82rem;font-family:inherit;white-space:nowrap;">Adicionar</button>
      </div>
      <div id="dCheckMsg" style="display:none;font-size:.78rem;color:#fca5a5;margin-bottom:6px;padding:6px 10px;background:rgba(239,68,68,.1);border-radius:6px;border:1px solid rgba(239,68,68,.2);"></div>
      <div id="dCheckProgress" style="font-size:.78rem;color:#9ab0c9;margin-bottom:4px;">0% concluído (0/0)</div>
      <div style="height:4px;background:rgba(255,255,255,.08);border-radius:4px;margin-bottom:10px;">
        <div id="dCheckProgressBar" style="height:100%;background:#3b82f6;border-radius:4px;width:0%;transition:width .3s;"></div>
      </div>
      <div class="tk-check-list" id="dCheckList" style="display:flex;flex-direction:column;gap:6px;"></div>
    </div>
    <!-- Tarefas Processuais -->
    <div class="tk-tab-pane" id="pane-proc-tarefas">
      <div id="dProcTarefasBody" style="display:flex;flex-direction:column;gap:14px;"></div>
    </div>
    <!-- Vínculos -->
    <div class="tk-tab-pane" id="pane-vinculos">
      <!-- vínculos existentes -->
      <div id="dLinksList" class="tk-links-container"></div>

      <!-- seletor de tipo -->
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button class="tk-link-type-btn active" data-type="processo">Processo</button>
        <button class="tk-link-type-btn" data-type="cliente">Cliente</button>
        <button class="tk-link-type-btn" data-type="card">Card CRM</button>
        <button class="tk-link-type-btn" data-type="dre_account">Conta DRE</button>
      </div>

      <!-- busca inline -->
      <input class="tk-inline-input" id="dVinculoBusca" type="text" placeholder="Buscar para vincular...">

      <!-- resultados -->
      <div id="dVinculoResults" style="display:flex;flex-direction:column;gap:4px;"></div>
    </div>
    <!-- Anexos (ALTA #30) -->
    <div class="tk-tab-pane" id="pane-anexos">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
        <input id="dAnexoFile" type="file"
          style="flex:1;min-width:160px;font-size:.8rem;color:#9ab0c9;font-family:inherit;color-scheme:dark;">
        <button id="dAnexoUpload"
          style="padding:9px 16px;border-radius:8px;background:rgba(37,99,235,.25);border:1px solid rgba(96,165,250,.3);color:#93c5fd;cursor:pointer;font-size:.82rem;font-family:inherit;white-space:nowrap;">Enviar</button>
      </div>
      <div id="dAnexoMsg" style="display:none;font-size:.78rem;margin-bottom:8px;padding:6px 10px;border-radius:6px;"></div>
      <div id="dAnexoList" style="display:flex;flex-direction:column;gap:6px;"></div>
    </div>
    <!-- Lembretes (ALTA #31) -->
    <div class="tk-tab-pane" id="pane-lembretes">
      <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:10px;flex-wrap:wrap;">
        <div class="tk-field" style="flex:1;min-width:160px;">
          <label>Lembrar em</label>
          <input id="dLembreteEm" type="datetime-local">
        </div>
        <div class="tk-field" style="width:130px;">
          <label>Canal</label>
          <select id="dLembreteCanal">
            <option value="sistema">Sistema</option>
            <option value="email">E-mail</option>
            <option value="whatsapp">WhatsApp</option>
          </select>
        </div>
        <button id="dLembreteAdd"
          style="padding:9px 16px;height:38px;border-radius:8px;background:rgba(37,99,235,.25);border:1px solid rgba(96,165,250,.3);color:#93c5fd;cursor:pointer;font-size:.82rem;font-family:inherit;white-space:nowrap;">Adicionar</button>
      </div>
      <div id="dLembreteMsg" style="display:none;font-size:.78rem;margin-bottom:8px;padding:6px 10px;border-radius:6px;"></div>
      <div id="dLembreteList" style="display:flex;flex-direction:column;gap:6px;"></div>
    </div>
    <!-- Comentários -->
    <div class="tk-tab-pane" id="pane-comentarios">
      <div class="tk-comment-list" id="dCommentList"></div>
      <div class="tk-comment-input-row">
        <textarea class="tk-comment-input" id="dCommentInput" placeholder="Escreva um comentário..." rows="1"></textarea>
        <button class="tk-comment-send" id="dCommentSend">Enviar</button>
      </div>
    </div>
    <!-- Histórico -->
    <div class="tk-tab-pane" id="pane-historico">
      <div class="tk-history-list" id="dHistoryList"></div>
    </div>
  </div>
</div><!-- /#tkDrawer -->
</div><!-- /#tkDrawerOverlay -->

<!-- ── Modal: Gerenciar Colunas ── -->
<div class="tk-modal-overlay" id="modalManageCols">
  <div class="tk-modal" style="width:480px;">
    <h3>Colunas do Quadro</h3>
    <div id="colsList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;max-height:340px;overflow-y:auto;"></div>
    <div style="border-top:1px solid rgba(96,165,250,.1);padding-top:12px;">
      <p style="font-size:.75rem;color:#7a8898;margin:0 0 8px;">Nova coluna</p>
      <div style="display:flex;gap:8px;align-items:center;">
        <input type="text" id="colNewNome" placeholder="Nome da coluna" style="flex:1;padding:8px 10px;border-radius:7px;border:1px solid rgba(96,165,250,.2);background:rgba(5,18,39,.85);color:#d6eaff;font-size:.83rem;font-family:inherit;">
        <input type="color" id="colNewCor" value="#94a3b8" style="width:36px;height:34px;padding:2px;border-radius:6px;border:1px solid rgba(96,165,250,.2);background:transparent;cursor:pointer;">
        <button id="colNewSave" style="padding:0 14px;height:34px;border-radius:7px;border:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-size:.8rem;font-weight:600;cursor:pointer;">Adicionar</button>
      </div>
    </div>
    <div class="tk-modal-actions" style="margin-top:14px;">
      <button class="tk-modal-btn tk-modal-btn-cancel" id="colsClose">Fechar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Editar Quadro ── -->
<div class="tk-modal-overlay" id="modalEditBoard">
  <div class="tk-modal">
    <h3>Editar Quadro</h3>
    <div class="tk-field" style="margin-bottom:12px;">
      <label>Nome</label>
      <input type="text" id="ebNome" placeholder="Nome do quadro">
    </div>
    <div class="tk-row-2" style="margin-bottom:12px;">
      <div class="tk-field">
        <label>Tipo</label>
        <select id="ebTipo">
          <option value="pessoal">Pessoal</option>
          <option value="compartilhado">Compartilhado</option>
        </select>
      </div>
      <div class="tk-field">
        <label>Cor</label>
        <input type="color" id="ebCor" value="#6366f1" style="height:38px;padding:2px;">
      </div>
    </div>
    <div class="tk-modal-actions">
      <button class="tk-modal-btn tk-modal-btn-cancel" id="ebCancel">Cancelar</button>
      <button class="tk-modal-btn tk-modal-btn-primary" id="ebSave">Salvar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Novo Quadro ── -->
<div class="tk-modal-overlay" id="modalNewBoard">
  <div class="tk-modal">
    <h3>Novo Quadro</h3>
    <div class="tk-field" style="margin-bottom:12px;">
      <label>Nome</label>
      <input type="text" id="nbNome" placeholder="Ex: Meu Dia">
    </div>
    <div class="tk-row-2" style="margin-bottom:12px;">
      <div class="tk-field">
        <label>Tipo</label>
        <select id="nbTipo">
          <option value="pessoal">Pessoal</option>
          <option value="compartilhado">Compartilhado</option>
        </select>
      </div>
      <div class="tk-field">
        <label>Cor</label>
        <input type="color" id="nbCor" value="#6366f1" style="height:38px;padding:2px;">
      </div>
    </div>
    <div class="tk-modal-actions">
      <button class="tk-modal-btn tk-modal-btn-cancel" id="nbCancel">Cancelar</button>
      <button class="tk-modal-btn tk-modal-btn-primary" id="nbSave">Criar quadro</button>
    </div>
  </div>
</div>

<!-- ── Modal: Nova Tarefa completa ── -->
<div class="tk-modal-overlay" id="modalNewTask">
  <div class="tk-modal">
    <h3>Nova Tarefa</h3>
    <div class="tk-field" style="margin-bottom:12px;">
      <label>Título *</label>
      <input type="text" id="ntTitulo" placeholder="Título da tarefa">
    </div>
    <div class="tk-row-2" style="margin-bottom:12px;">
      <div class="tk-field">
        <label>Prioridade</label>
        <select id="ntPrioridade">
          <option value="baixa">Baixa</option>
          <option value="media" selected>Média</option>
          <option value="alta">Alta</option>
          <option value="urgente">Urgente</option>
        </select>
      </div>
      <div class="tk-field">
        <label>Tipo de prazo</label>
        <div style="position:relative;">
          <select id="ntPrazoTipo" style="padding-right:32px;width:100%;"></select>
          <button onclick="openPrazoTipoConfig()" title="Personalizar tipos" style="position:absolute;right:28px;top:50%;transform:translateY(-50%);background:none;border:none;color:#4A5568;cursor:pointer;padding:2px;display:flex;align-items:center;transition:color .15s;" onmouseover="this.style.color='#A8CFEE'" onmouseout="this.style.color='#4A5568'">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
        </div>
      </div>
    </div>
    <div class="tk-row-2" style="margin-bottom:12px;">
      <div class="tk-field">
        <label>Prazo</label>
        <input type="datetime-local" id="ntPrazo">
      </div>
      <div class="tk-field">
        <label>Responsável</label>
        <select id="ntResponsavel">
          <option value="">Sem responsável</option>
        </select>
      </div>
    </div>
    <div class="tk-field" style="margin-bottom:12px;">
      <label>Coluna</label>
      <select id="ntColuna"></select>
    </div>
    <div class="tk-field" style="margin-bottom:12px;">
      <label>Descrição</label>
      <textarea id="ntDescricao" placeholder="Opcional..." rows="2"></textarea>
    </div>
    <!-- Recorrência -->
    <div class="tk-recurrence-section" style="margin-bottom:12px;">
      <label class="tk-toggle">
        <input type="checkbox" id="ntRecToggle">
        <span class="tk-toggle-sw"></span>
        <span class="tk-toggle-label">Tarefa recorrente</span>
      </label>
      <div id="ntRecFields" style="display:none;flex-direction:column;gap:10px;margin-top:10px;">
        <div class="tk-field">
          <label>Tipo</label>
          <select id="ntRecTipo" onchange="toggleCustomRec('nt')">
            <option value="diaria">Diária</option>
            <option value="semanal">Semanal</option>
            <option value="quinzenal">Quinzenal</option>
            <option value="mensal">Mensal</option>
            <option value="anual">Anual</option>
            <option value="custom">Personalizada</option>
          </select>
        </div>
        <div class="tk-row-2 tk-rec-custom" id="ntRecCustom" style="display:none;">
          <div class="tk-field">
            <label>A cada</label>
            <input type="number" id="ntRecIntervalo" value="1" min="1" placeholder="Ex: 2">
          </div>
          <div class="tk-field">
            <label>Unidade</label>
            <select id="ntRecUnidade">
              <option value="day">Dias</option>
              <option value="week">Semanas</option>
              <option value="month">Meses</option>
              <option value="year">Anos</option>
            </select>
          </div>
        </div>
        <div class="tk-row-2">
          <div class="tk-field">
            <label>Data início</label>
            <input type="date" id="ntRecInicio">
          </div>
          <div class="tk-field">
            <label>Data fim (opcional)</label>
            <input type="date" id="ntRecFim">
          </div>
        </div>
      </div>
    </div>
    <div class="tk-modal-actions">
      <button class="tk-modal-btn tk-modal-btn-cancel" id="ntCancel">Cancelar</button>
      <button class="tk-modal-btn tk-modal-btn-primary" id="ntSave">Criar tarefa</button>
    </div>
  </div>
</div>


<!-- ── Modal: configurar tipos de prazo ── -->
<div class="tk-modal-overlay" id="modalPrazoTipoConfig">
  <div class="tk-modal" style="width:420px;">
    <h3>Personalizar Tipos de Prazo</h3>
    <p style="font-size:.78rem;color:#7A8898;margin:-10px 0 16px;">Adicione, renomeie ou exclua tipos. A cor define o alerta visual no card (vermelho = urgente).</p>

    <div id="ptcList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;max-height:300px;overflow-y:auto;"></div>

    <button id="ptcAddBtn" style="width:100%;padding:8px;border:1px dashed rgba(160,180,210,0.2);background:transparent;color:#4A5568;border-radius:8px;font-family:inherit;font-size:.8rem;cursor:pointer;transition:color .15s,border-color .15s;">
      + Adicionar tipo
    </button>

    <div class="tk-modal-actions" style="margin-top:16px;">
      <button class="tk-modal-btn tk-modal-btn-cancel" id="ptcCancel">Cancelar</button>
      <button class="tk-modal-btn tk-modal-btn-primary" id="ptcSave">Salvar</button>
    </div>
  </div>
</div>

<script>
window.YURIS_CSRF = <?= json_encode($csrf) ?>;
window.YURIS_USER_ID = <?= $userId ?>;
window.YURIS_USER_NAME = <?= json_encode($_SESSION['user_nome'] ?? '') ?>;

// Identifica origem do registro (matriz/filial) — faixa SEMPRE visível
// (mesmo em filial isolada ou matriz sem filiais).
window.YURIS_ACCOUNT_SELF      = <?= json_encode($origin_self, JSON_UNESCAPED_UNICODE) ?>;
window.YURIS_ORIGIN_ACCOUNTS   = <?= json_encode($origin_accounts, JSON_UNESCAPED_UNICODE) ?>;
window.YURIS_SHOW_ORIGIN_STRIP = true;
</script>
<!-- ── Modal de confirmação customizado ── -->
<div id="tkConfirmOverlay" style="
  display:none;position:fixed;inset:0;z-index:9999;
  background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
  align-items:center;justify-content:center;">
  <div style="
    background:linear-gradient(160deg,#0d1f3c 0%,#0a1628 100%);
    border:1px solid rgba(96,165,250,.2);border-radius:16px;
    padding:32px 28px;max-width:380px;width:90%;text-align:center;
    box-shadow:0 24px 60px rgba(0,0,0,.5);">
    <div id="tkConfirmIcon" style="margin-bottom:14px;display:flex;justify-content:center;"></div>
    <p id="tkConfirmMsg" style="color:#d6eaff;font-size:.92rem;line-height:1.5;margin-bottom:22px;"></p>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button id="tkConfirmNo"
        style="flex:1;padding:9px 0;border-radius:8px;border:1px solid rgba(96,165,250,.2);
               background:rgba(255,255,255,.05);color:#9ab0c9;font-size:.85rem;
               font-family:inherit;cursor:pointer;transition:background .15s;"
        onmouseover="this.style.background='rgba(255,255,255,.1)'"
        onmouseout="this.style.background='rgba(255,255,255,.05)'">
        Cancelar
      </button>
      <button id="tkConfirmYes"
        style="flex:1;padding:9px 0;border-radius:8px;border:none;
               background:#dc2626;color:#fff;font-size:.85rem;font-weight:600;
               font-family:inherit;cursor:pointer;transition:opacity .15s;"
        onmouseover="this.style.opacity='.85'"
        onmouseout="this.style.opacity='1'">
        Excluir
      </button>
    </div>
  </div>
</div>

<!-- ── Modal editar tarefa processual ── -->
<div id="ptEditOverlay" style="display:none;position:fixed;inset:0;z-index:10000;
  background:rgba(0,0,0,.6);backdrop-filter:blur(5px);
  align-items:center;justify-content:center;">
  <div style="background:linear-gradient(160deg,#0d1f3c 0%,#0a1628 100%);
    border:1px solid rgba(96,165,250,.22);border-radius:16px;
    padding:28px 26px;width:92%;max-width:420px;
    box-shadow:0 24px 60px rgba(0,0,0,.55);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;">
      <span style="color:#d6eaff;font-size:.95rem;font-weight:600;">Editar Tarefa Processual</span>
      <button id="ptEditClose" style="background:none;border:none;color:#6b8aaa;font-size:1.2rem;cursor:pointer;line-height:1;">✕</button>
    </div>
    <div style="display:flex;flex-direction:column;gap:14px;">
      <div>
        <label style="display:block;font-size:.72rem;color:#7a95b4;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Título</label>
        <input id="ptEditTitulo" type="text" style="width:100%;padding:9px 11px;background:rgba(255,255,255,.06);
          border:1px solid rgba(96,165,250,.18);border-radius:8px;color:#d6eaff;
          font-size:.85rem;font-family:inherit;box-sizing:border-box;outline:none;"
          placeholder="Título da tarefa">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
          <label style="display:block;font-size:.72rem;color:#7a95b4;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Data</label>
          <input id="ptEditData" type="date" style="width:100%;padding:9px 11px;background:rgba(255,255,255,.06);
            border:1px solid rgba(96,165,250,.18);border-radius:8px;color:#d6eaff;
            font-size:.85rem;font-family:inherit;box-sizing:border-box;outline:none;color-scheme:dark;">
        </div>
        <div>
          <label style="display:block;font-size:.72rem;color:#7a95b4;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Prioridade</label>
          <select id="ptEditPrioridade" style="width:100%;padding:9px 11px;background:#0d1f3c;
            border:1px solid rgba(96,165,250,.18);border-radius:8px;color:#d6eaff;
            font-size:.85rem;font-family:inherit;box-sizing:border-box;outline:none;">
            <option value="baixa">Baixa</option>
            <option value="media">Média</option>
            <option value="alta">Alta</option>
          </select>
        </div>
      </div>
      <div>
        <label style="display:block;font-size:.72rem;color:#7a95b4;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Responsável</label>
        <select id="ptEditResponsavel" style="width:100%;padding:9px 11px;background:#0d1f3c;
          border:1px solid rgba(96,165,250,.18);border-radius:8px;color:#d6eaff;
          font-size:.85rem;font-family:inherit;box-sizing:border-box;outline:none;">
          <option value="">— Nenhum —</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:22px;justify-content:flex-end;">
      <button id="ptEditCancel" style="padding:9px 20px;border-radius:8px;border:1px solid rgba(96,165,250,.2);
        background:rgba(255,255,255,.05);color:#9ab0c9;font-size:.85rem;font-family:inherit;cursor:pointer;">
        Cancelar
      </button>
      <button id="ptEditSave" style="padding:9px 22px;border-radius:8px;border:none;
        background:#2563eb;color:#fff;font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;">
        Salvar
      </button>
    </div>
  </div>
</div>

<!-- SortableJS: drag-and-drop entre colunas + reordenação intra-coluna (igual Pipeline) -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
<!-- Helper de selects de usuário agrupados por Matriz/Filial (carrega ANTES do tarefas.js) -->
<script src="/assets/user_select.js?v=2"></script>
<script src="/assets/tarefas.js?v=<?= filemtime(__DIR__ . '/assets/tarefas.js') ?>"></script>
<?php if ($edicaoCrm): ?>
<script>
// ── Ficha da tarefa no desenho do Fleetiflow (ff-ficha.js + ff-ficha.css) ──
// Só a edição CRM chega aqui. O painel da tarefa (#tkDrawer) mantém todos os
// ids, abas e painéis: aqui ele ganha cabeçalho, faixa de resumo (título
// editável, prioridade, prazo, responsável, coluna, quadro), seções numeradas
// em duas colunas na aba Geral, e um rodapé com Arquivar, Concluir e Salvar.
// A aba "Tarefas Processuais" e o vínculo "Processo" somem: não existem nesta
// edição. Os selects da faixa não têm name e espelham os do painel.
(function () {
  const F = window.FfFicha;
  const drawer = document.getElementById('tkDrawer');
  if (!F || !drawer) return;
  const $ = (s, r) => (r || document).querySelector(s);
  document.body.classList.add('ff-quadro');
  drawer.classList.add('ff-modal', 'ff-tarefa');

  // Cabeçalho próprio no alto do painel.
  const header = $('.tk-drawer-header', drawer);
  const cabEl = document.createElement('div');
  cabEl.className = 'ff-cab';
  cabEl.innerHTML = '<div class="ff-titulo">Gestão da Tarefa</div><div class="ff-subtitulo">Atualize dados, prazo, checklist e histórico com rastreabilidade.</div>';
  drawer.insertBefore(cabEl, header);
  const cab = F.cabecalho(cabEl, () => closeDrawer());

  // Faixa de resumo entre o cabeçalho e as abas.
  const faixa = F.resumo(drawer, header, [
    { chave: 'tarefa', rotulo: 'Tarefa' }, { chave: 'prio', rotulo: 'Prioridade' }, { chave: 'prazo', rotulo: 'Prazo' },
    { chave: 'resp', rotulo: 'Responsável' }, { chave: 'coluna', rotulo: 'Coluna' }
  ]);
  // O título editável vai para a primeira célula, com a "logo" da tarefa.
  const celTarefa = faixa.celula('tarefa');
  const logo = document.createElement('span'); logo.className = 'ff-logo'; logo.innerHTML = F.ico('tarefa');
  celTarefa.insertBefore(logo, celTarefa.firstChild);
  const wrap = document.createElement('div');
  while (logo.nextSibling) wrap.appendChild(logo.nextSibling);
  celTarefa.appendChild(wrap);
  faixa.valor('tarefa').appendChild($('#dTitle'));

  // Abas: ícones, e a aba processual some.
  const ICONES_ABA = { geral: 'grade', checklist: 'tarefa', vinculos: 'clipe', anexos: 'clipe', lembretes: 'relogio', comentarios: 'chat', historico: 'relogio' };
  drawer.querySelectorAll('.tk-tab').forEach(b => {
    const k = b.dataset.tab;
    if (k === 'proc-tarefas') { b.classList.add('ff-ocultar'); return; }
    b.insertAdjacentHTML('afterbegin', F.ico(ICONES_ABA[k] || 'grade'));
  });
  const btnProc = drawer.querySelector('.tk-link-type-btn[data-type="processo"]');
  if (btnProc) {
    btnProc.classList.add('ff-ocultar');
    const btnCard = drawer.querySelector('.tk-link-type-btn[data-type="card"]');
    if (btnCard) { btnCard.textContent = 'Lead'; if (btnProc.classList.contains('active')) { btnProc.classList.remove('active'); btnCard.classList.add('active'); } }
    // O tipo em uso (let de topo do tarefas.js) nascia 'processo'; sem isto a busca de vínculo procurava processos até o primeiro clique.
    if (typeof currentLinkType !== 'undefined' && currentLinkType === 'processo') currentLinkType = 'card';
  }

  // Aba Geral em duas colunas: descrição e recorrência à esquerda, planejamento à direita.
  const geral = $('#pane-geral');
  const fDesc = $('#dDescricao').closest('.tk-field');
  const campos = [$('#dPrioridade').closest('.tk-row-2'), $('#dPrazo').closest('.tk-row-2'), $('#dColuna').closest('.tk-field')];
  const rec = $('#dRecSection');
  // O Salvar sai da linha ANTES de ela ser removida: ele volta no rodapé.
  const bSave = $('#dBtnSave');
  const linhaSalvar = bSave.parentElement;
  bSave.remove();
  const s1 = F.envolver([fDesc]), s2 = F.envolver([rec]), s3 = F.envolver(campos);
  F.secao(s1, 1, 'Descrição', 'nota', 'ambar');
  F.secao(s2, 2, 'Recorrência', 'relogio', 'roxo');
  F.secao(s3, 3, 'Planejamento', 'calendario');
  F.colunas(geral, [s1, s2], [s3]);
  linhaSalvar.remove();

  // Demais abas: o conteúdo entra numa seção com título.
  const SECOES = { checklist: ['Checklist', 'tarefa', 'verde'], vinculos: ['Vínculos', 'clipe', ''], anexos: ['Anexos', 'clipe', ''], lembretes: ['Lembretes', 'relogio', 'ambar'], comentarios: ['Comentários', 'chat', ''], historico: ['Histórico', 'relogio', 'cinza'] };
  Object.keys(SECOES).forEach(k => {
    const pane = document.getElementById('pane-' + k);
    if (!pane) return;
    const s = F.envolver(Array.from(pane.children));
    F.secao(s, 0, SECOES[k][0], SECOES[k][1], SECOES[k][2]);
    pane.appendChild(s);
  });

  // Rodapé: Arquivar, Concluir e Salvar, que já existem, só mudam de lugar.
  const rodape = document.createElement('div');
  rodape.className = 'modal-footer';
  const bArq = $('#dBtnArquivar'), bOk = $('#dBtnConcluir');
  bArq.classList.add('btn'); bOk.classList.add('btn'); bSave.classList.add('btn');
  F.botao(bArq, 'arquivar', 'ff-btn-perigo'); F.botao(bOk, 'tarefa', 'ff-btn-destaque'); F.botao(bSave, 'salvar', 'ff-btn-principal');
  bOk.childNodes.forEach(n => { if (n.nodeType === 3) n.textContent = n.textContent.replace('✓', '').trim() ? ' Concluir' : ''; });
  rodape.appendChild(bArq); rodape.appendChild(bOk); rodape.appendChild(bSave);
  drawer.appendChild(rodape);
  $('#dBtnClose').classList.add('ff-ocultar');

  let tarefa = null;
  function prazoTexto(v) {
    if (!v) return '<span class="ff-txt ff-vazio-txt">Sem prazo</span>';
    const d = new Date(v);
    if (isNaN(d.getTime())) return F.esc(v);
    const dias = Math.round((d.setHours(0,0,0,0) - new Date().setHours(0,0,0,0)) / 86400000);
    const concluida = tarefa && tarefa.status === 'concluida';
    let rel = dias === 0 ? 'hoje' : dias === 1 ? 'amanhã' : dias > 1 ? 'em ' + dias + ' dias' : dias === -1 ? 'ontem' : 'há ' + (-dias) + ' dias';
    const atrasada = dias < 0 && !concluida;
    return '<span class="ff-txt' + (atrasada ? ' ff-cel-prazo-atraso' : '') + '">' + F.esc(F.fmtDataHora(v).replace(' · ', ' ')) + '</span>' +
           '<span class="ff-pill' + (atrasada ? ' ff-pill-off ff-cel-prazo-atraso' : dias <= 1 ? ' ff-pill-pausa' : '') + '">' + (atrasada ? 'atrasada, ' : '') + rel + '</span>';
  }
  let atualizar = function () {
    cab.meta(F.codigo('TR', openTaskId), F.fmtDataHora(tarefa && tarefa.created_at));
    const pr = F.celulaSelect(faixa.valor('prio'), $('#dPrioridade'));
    pr.className = 'ff-sel ' + ({ urgente: 'ff-sel-vermelho', alta: 'ff-sel-vermelho', media: 'ff-sel-ambar', baixa: 'ff-sel-neutro' }[pr.value] || '');
    faixa.set('prazo', prazoTexto($('#dPrazo').value));
    F.celulaResponsavel(faixa.valor('resp'), $('#dResponsavel'), 'Responsável');
    const celCol = faixa.valor('coluna');
    F.celulaSelect(celCol, $('#dColuna'), 'ff-sel-neutro');
    let ok = celCol.querySelector('.ff-pill-ok');
    if (!ok) { ok = document.createElement('span'); ok.className = 'ff-pill ff-pill-ok'; ok.textContent = 'Concluída'; celCol.appendChild(ok); }
    ok.style.display = tarefa && tarefa.status === 'concluida' ? '' : 'none';
  };
  // O título cresce com o texto, em vez de cortar numa linha.
  const tit = $('#dTitle');
  const crescer = () => { tit.style.height = 'auto'; tit.style.height = Math.max(28, tit.scrollHeight + 4) + 'px'; };
  tit.addEventListener('input', crescer);
  const _atualizar = atualizar;
  atualizar = function () { _atualizar(); crescer(); };
  geral.addEventListener('input', atualizar);
  geral.addEventListener('change', atualizar);

  // Quando o painel carrega a tarefa (refreshDrawer é global do tarefas.js),
  // a faixa lê a tarefa de novo para o número, a data e o quadro.
  const _refresh = refreshDrawer;
  refreshDrawer = async function () {
    await _refresh.apply(this, arguments);
    try { const r = await GET('/tasks.php?id=' + openTaskId); tarefa = (r && r.data) || null; } catch (e) { tarefa = null; }
    atualizar();
  };
  F.aoAbrir(document.getElementById('tkDrawerOverlay'), () => {
    drawer.querySelectorAll('.form-section.ff-fechada').forEach(s => s.classList.remove('ff-fechada'));
    $('.tk-drawer-body', drawer).scrollTop = 0;
    atualizar();
  });
})();
</script>
<?php endif; ?>
</body>
</html>
