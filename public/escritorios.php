<?php
require_once __DIR__ . '/../app/Models/Database.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /sistema_vendas/public/login.php'); exit; }
$activePage = 'escritorios';
$csrf       = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$userRole   = $_SESSION['user_role']  ?? 'user';
$accountId  = (int)($_SESSION['account_id'] ?? 0);
$accountTipo= $_SESSION['account_tipo'] ?? 'matriz';
$isAdmin    = in_array($userRole, ['owner', 'admin']) || ($_SESSION['user_perfil'] ?? '') === 'admin';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Escritórios — Yuris</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=8">
  <style>
    /* ── Tabs ── */
    .es-tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .es-tab  { padding:8px 18px; border-radius:8px; border:1px solid rgba(96,165,250,.18); background:transparent; color:#7a96b4; font-size:.84rem; font-weight:600; cursor:pointer; transition:all .18s; }
    .es-tab.active { background:rgba(37,99,235,.22); border-color:rgba(96,165,250,.45); color:#93c5fd; }
    .es-tab:hover:not(.active) { background:rgba(37,99,235,.1); color:#a8c5e8; }

    /* ── Cards de conteúdo ── */
    .es-card { background:linear-gradient(145deg,#0d1c30,#09131f); border:1px solid rgba(160,180,210,.14); border-radius:12px; padding:20px 22px; margin-bottom:16px; }
    .es-card-title { font-size:.88rem; font-weight:700; color:#dbeafe; margin-bottom:14px; letter-spacing:.02em; text-transform:uppercase; }

    /* ── Info grid ── */
    .es-info-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; }
    .es-info-item label { display:block; font-size:.72rem; color:#7a96b4; margin-bottom:3px; }
    .es-info-item span  { font-size:.9rem; color:#d6eaff; font-weight:600; }

    /* ── Badge ── */
    .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.72rem; font-weight:700; }
    .badge-matriz   { background:rgba(59,130,246,.2); color:#93c5fd; border:1px solid rgba(59,130,246,.35); }
    .badge-filial   { background:rgba(139,92,246,.2); color:#c4b5fd; border:1px solid rgba(139,92,246,.35); }
    .badge-active   { background:rgba(34,197,94,.15); color:#86efac; border:1px solid rgba(34,197,94,.3); }
    .badge-pending  { background:rgba(245,158,11,.15); color:#fcd34d; border:1px solid rgba(245,158,11,.3); }
    .badge-rejected { background:rgba(239,68,68,.15); color:#fca5a5; border:1px solid rgba(239,68,68,.3); }
    .badge-revoked, .badge-suspended { background:rgba(100,116,139,.15); color:#94a3b8; border:1px solid rgba(100,116,139,.3); }

    /* ── Código de vínculo ── */
    .codigo-box { display:flex; align-items:center; gap:10px; background:rgba(5,18,39,.8); border:1px solid rgba(96,165,250,.2); border-radius:8px; padding:10px 14px; }
    .codigo-box code { flex:1; font-family:monospace; color:#7eb8f6; font-size:.92rem; letter-spacing:.06em; }
    .copy-btn { padding:5px 12px; border-radius:6px; border:1px solid rgba(96,165,250,.3); background:transparent; color:#93c5fd; font-size:.78rem; cursor:pointer; transition:background .15s; }
    .copy-btn:hover { background:rgba(37,99,235,.2); }

    /* ── Tabela ── */
    .es-table { width:100%; border-collapse:collapse; font-size:.83rem; }
    .es-table th { color:#7a96b4; font-weight:600; text-align:left; padding:8px 12px; border-bottom:1px solid rgba(160,180,210,.1); font-size:.72rem; text-transform:uppercase; }
    .es-table td { padding:10px 12px; border-bottom:1px solid rgba(160,180,210,.06); color:#c8ddf0; vertical-align:middle; }
    .es-table tr:last-child td { border-bottom:none; }
    .es-table tr:hover td { background:rgba(37,99,235,.05); }

    /* ── Botões ── */
    .btn-sm  { padding:5px 12px; border-radius:6px; font-size:.78rem; font-weight:600; cursor:pointer; border:none; transition:all .15s; }
    .btn-primary { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; }
    .btn-primary:hover { background:linear-gradient(135deg,#3b82f6,#2563eb); }
    .btn-danger  { background:rgba(220,38,38,.2); color:#fca5a5; border:1px solid rgba(220,38,38,.3); }
    .btn-danger:hover  { background:rgba(220,38,38,.35); }
    .btn-success { background:rgba(34,197,94,.2); color:#86efac; border:1px solid rgba(34,197,94,.3); }
    .btn-success:hover { background:rgba(34,197,94,.35); }
    .btn-outline { background:transparent; color:#7eb8f6; border:1px solid rgba(96,165,250,.25); }
    .btn-outline:hover { background:rgba(37,99,235,.15); }

    /* ── Form ── */
    .es-form-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .es-field { display:flex; flex-direction:column; gap:5px; flex:1; min-width:180px; }
    .es-field label { font-size:.75rem; color:#7a96b4; font-weight:600; }
    .es-input { padding:8px 12px; border-radius:8px; background:rgba(5,18,39,.85); border:1px solid rgba(96,165,250,.2); color:#d6eaff; font-size:.83rem; font-family:inherit; }
    .es-input:focus { outline:none; border-color:rgba(96,165,250,.5); }
    .es-textarea { resize:vertical; min-height:70px; }

    /* ── Empty state ── */
    .es-empty { text-align:center; padding:32px 16px; color:#4a5568; font-size:.84rem; }
    .es-empty svg { width:36px; height:36px; margin:0 auto 10px; opacity:.4; display:block; }

    /* ── Pane ── */
    .es-pane { display:none; }
    .es-pane.active { display:block; }

    /* ── Modal ── */
    .es-overlay { display:none; position:fixed; inset:0; z-index:9000; background:rgba(2,6,23,.75); backdrop-filter:blur(4px); align-items:center; justify-content:center; }
    .es-overlay.open { display:flex; }
    .es-modal { background:linear-gradient(165deg,rgba(13,28,48,.98),rgba(8,18,35,.99)); border:1px solid rgba(96,165,250,.2); border-radius:14px; padding:26px 28px 22px; width:480px; max-width:95vw; box-shadow:0 24px 60px rgba(2,6,23,.7); }
    .es-modal h3 { font-size:1rem; font-weight:700; color:#dbeafe; margin-bottom:18px; }
    .es-modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content space-y-4">

      <!-- Header -->
      <div class="card-shell page-header">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Escritórios</h2>
            <p class="page-header-subtitle">Gerencie vínculos Matriz–Filial, advogados associados e compartilhamento de recursos.</p>
          </div>
        </div>
      </div>

      <!-- Painel da conta atual -->
      <div class="es-card" id="painelConta">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="es-card-title" style="margin:0">Minha Conta</div>
          <?php if ($isAdmin): ?>
          <button class="btn-sm btn-outline" onclick="toggleEditConta()">Editar</button>
          <?php endif; ?>
        </div>

        <!-- Modo visualização -->
        <div id="contaView">
          <div class="es-info-grid">
            <div class="es-info-item"><label>Nome</label><span id="cNome">—</span></div>
            <div class="es-info-item"><label>Tipo</label><span id="cTipo">—</span></div>
            <div class="es-info-item"><label>Plano</label><span id="cPlano">—</span></div>
            <div class="es-info-item"><label>Status</label><span id="cStatus">—</span></div>
          </div>
          <?php if ($isAdmin): ?>
          <div style="margin-top:16px;">
            <label style="font-size:.75rem;color:#7a96b4;font-weight:600;display:block;margin-bottom:6px;">Código de vínculo <span style="color:#4a5568;font-weight:400">(compartilhe com filiais)</span></label>
            <div class="codigo-box">
              <code id="codigoVinculo">carregando...</code>
              <button class="copy-btn" onclick="copiarCodigo()">Copiar</button>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Modo edição -->
        <?php if ($isAdmin): ?>
        <div id="contaEdit" style="display:none;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div class="es-field">
              <label>Nome do escritório</label>
              <input type="text" id="editNome" class="es-input">
            </div>
            <div class="es-field">
              <label>Tipo de conta</label>
              <select id="editTipo" class="es-input">
                <option value="matriz">Matriz (escritório principal)</option>
                <option value="filial">Filial (vinculada a uma Matriz)</option>
              </select>
            </div>
            <div class="es-field">
              <label>Plano</label>
              <select id="editPlano" class="es-input">
                <option value="basico">Básico</option>
                <option value="profissional">Profissional</option>
                <option value="enterprise">Enterprise</option>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn-sm btn-primary" onclick="salvarConta()">Salvar</button>
            <button class="btn-sm btn-outline" onclick="toggleEditConta()">Cancelar</button>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Tabs -->
      <div class="es-tabs">
        <button class="es-tab active" onclick="switchTab('vinculos')">Vínculos</button>
        <button class="es-tab" onclick="switchTab('advogados')">Advogados Associados</button>
        <button class="es-tab" onclick="switchTab('compartilhamentos')">Compartilhamentos</button>
        <?php if ($isAdmin && $accountTipo === 'filial'): ?>
        <button class="es-tab" onclick="switchTab('solicitar')">Solicitar Vínculo</button>
        <?php endif; ?>
      </div>

      <!-- ── PANE: Vínculos ── -->
      <div class="es-pane active" id="pane-vinculos">
        <div class="es-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <div class="es-card-title" style="margin:0">Vínculos Matriz–Filial</div>
            <?php if ($isAdmin && $accountTipo === 'filial'): ?>
            <button class="btn-sm btn-primary" onclick="abrirSolicitarVinculo()">+ Solicitar Vínculo</button>
            <?php endif; ?>
          </div>
          <div id="vinculosList"><div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Carregando...</div></div>
        </div>
      </div>

      <!-- ── PANE: Advogados ── -->
      <div class="es-pane" id="pane-advogados">
        <div class="es-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <div class="es-card-title" style="margin:0">Advogados Associados</div>
            <?php if ($isAdmin): ?>
            <button class="btn-sm btn-primary" onclick="abrirModalConvite()">+ Novo Convite</button>
            <?php endif; ?>
          </div>
          <div id="advogadosList"><div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Carregando...</div></div>
        </div>
      </div>

      <!-- ── PANE: Compartilhamentos ── -->
      <div class="es-pane" id="pane-compartilhamentos">
        <div class="es-card">
          <div class="es-card-title">Compartilhamentos Ativos</div>
          <div id="sharesList"><div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Carregando...</div></div>
        </div>
      </div>

      <!-- ── PANE: Solicitar vínculo ── -->
      <div class="es-pane" id="pane-solicitar">
        <div class="es-card">
          <div class="es-card-title">Solicitar Vínculo com Matriz</div>
          <p style="font-size:.83rem;color:#7a96b4;margin-bottom:16px;">Informe o código de vínculo fornecido pelo escritório Matriz.</p>
          <div class="es-form-row">
            <div class="es-field">
              <label>Código de vínculo da Matriz</label>
              <input type="text" id="inputCodigoMatriz" class="es-input" placeholder="Ex: a1b2c3d4...">
            </div>
            <button class="btn-sm btn-primary" style="height:36px;white-space:nowrap;" onclick="solicitarVinculo()">Solicitar</button>
          </div>
          <div id="solicitarMsg" style="margin-top:10px;font-size:.82rem;"></div>
        </div>
      </div>

    </section>
  </div>
</main>

<!-- ── Modal: Compartilhar com Advogado Associado ── -->
<div class="es-overlay" id="modalConvite">
  <div class="es-modal" style="width:520px;">
    <h3>Compartilhar com Advogado Associado</h3>

    <!-- Passo 1: identificar conta -->
    <div id="adv-passo1">
      <p style="font-size:.82rem;color:#7a96b4;margin-bottom:14px;">Informe o código de vínculo do escritório do advogado. Ele encontra o código em <strong>Escritórios → Minha Conta</strong>.</p>
      <div class="es-form-row" style="align-items:flex-end;">
        <div class="es-field">
          <label>Código de vínculo do advogado *</label>
          <input type="text" id="advCodigo" class="es-input" placeholder="Ex: 4799-7d7c-9ca1-96b4" oninput="limparBusca()">
        </div>
        <button class="btn-sm btn-outline" style="height:36px;white-space:nowrap;" onclick="buscarContaAdvogado()">Buscar conta</button>
      </div>
      <div id="advContaResultado" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:.84rem;"></div>
    </div>

    <!-- Passo 2: selecionar processo (aparece após conta validada) -->
    <div id="adv-passo2" style="display:none;margin-top:18px;border-top:1px solid rgba(160,180,210,.1);padding-top:16px;">
      <div class="es-field" style="margin-bottom:10px;">
        <label>Processo *</label>
        <input type="text" id="advProcessoBusca" class="es-input" placeholder="Digite número ou nome do cliente..." oninput="buscarProcessos(this.value)">
        <div id="advProcessoLista" style="display:none;margin-top:4px;background:rgba(5,18,39,.9);border:1px solid rgba(96,165,250,.2);border-radius:8px;overflow:hidden;max-height:180px;overflow-y:auto;"></div>
        <input type="hidden" id="advProcessoId">
        <div id="advProcessoSelecionado" style="display:none;margin-top:6px;font-size:.82rem;color:#86efac;"></div>
      </div>
      <div class="es-field" style="max-width:200px;">
        <label>Permissão</label>
        <select id="advPermissao" class="es-input">
          <option value="view">Visualizar</option>
          <option value="edit">Editar</option>
        </select>
      </div>
    </div>

    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="fecharModalConvite()">Cancelar</button>
      <button class="btn-sm btn-primary" id="btnConfirmarAdv" style="display:none;" onclick="confirmarCompartilhamento()">Compartilhar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Solicitar Vínculo ── -->
<div class="es-overlay" id="modalSolicitar">
  <div class="es-modal">
    <h3>Solicitar Vínculo com Matriz</h3>
    <p style="font-size:.83rem;color:#7a96b4;margin-bottom:16px;">Informe o código fornecido pelo escritório Matriz.</p>
    <div class="es-field">
      <label>Código de vínculo</label>
      <input type="text" id="inputCodigoModal" class="es-input" placeholder="Ex: a1b2c3d4e5f6...">
    </div>
    <div id="solicitarModalMsg" style="margin-top:8px;font-size:.82rem;"></div>
    <div class="es-modal-footer">
      <button class="btn-sm btn-outline" onclick="document.getElementById('modalSolicitar').classList.remove('open')">Cancelar</button>
      <button class="btn-sm btn-primary" onclick="solicitarVinculoModal()">Solicitar</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const ACCOUNT_TIPO = <?= json_encode($accountTipo) ?>;

const api = (url, opts = {}) => fetch(url, {
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
  ...opts
}).then(r => r.json());

// ── Carregar conta ────────────────────────────────────────────────────────────
let _contaData = null;
async function carregarConta() {
  const r = await api('/sistema_vendas/public/api/accounts.php');
  const c = r.data || r;
  if (!c || !c.nome) return;
  _contaData = c;
  document.getElementById('cNome').textContent   = c.nome || '—';
  document.getElementById('cTipo').innerHTML     = `<span class="badge badge-${c.tipo}">${c.tipo}</span>`;
  document.getElementById('cPlano').textContent  = c.plano || 'basico';
  document.getElementById('cStatus').innerHTML   = `<span class="badge badge-${c.status}">${c.status}</span>`;

  if (IS_ADMIN) {
    const rc = await api('/sistema_vendas/public/api/accounts.php?action=codigo');
    const codigo = rc.codigo_vinculo || rc.data?.codigo_vinculo || '—';
    document.getElementById('codigoVinculo').textContent = codigo;
  }
}

function toggleEditConta() {
  const view = document.getElementById('contaView');
  const edit = document.getElementById('contaEdit');
  const isEditing = edit.style.display !== 'none';
  if (!isEditing && _contaData) {
    document.getElementById('editNome').value  = _contaData.nome  || '';
    document.getElementById('editTipo').value  = _contaData.tipo  || 'matriz';
    document.getElementById('editPlano').value = _contaData.plano || 'basico';
  }
  view.style.display = isEditing ? '' : 'none';
  edit.style.display = isEditing ? 'none' : '';
}

async function salvarConta() {
  const payload = {
    nome:        document.getElementById('editNome').value.trim(),
    tipo:        document.getElementById('editTipo').value,
    plano:       document.getElementById('editPlano').value,
    csrf_token:  CSRF,
  };
  if (!payload.nome) { toast('Nome obrigatório.', 'err'); return; }
  const r = await api('/sistema_vendas/public/api/accounts.php', {
    method: 'PUT', body: JSON.stringify(payload)
  });
  if (r.success || r.ok) {
    toast('Conta atualizada!', 'ok');
    toggleEditConta();
    carregarConta();
  } else {
    toast(r.error || 'Erro ao salvar.', 'err');
  }
}

function copiarCodigo() {
  const txt = document.getElementById('codigoVinculo').textContent;
  navigator.clipboard.writeText(txt).then(() => {
    const btn = document.querySelector('.copy-btn');
    btn.textContent = 'Copiado!'; setTimeout(() => btn.textContent = 'Copiar', 2000);
  });
}

// ── Tabs ─────────────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.es-tab').forEach((t,i) => {
    const tabs = ['vinculos','advogados','compartilhamentos','solicitar'];
    t.classList.toggle('active', tabs[i] === name);
  });
  document.querySelectorAll('.es-pane').forEach(p => p.classList.remove('active'));
  document.getElementById('pane-' + name)?.classList.add('active');
  if (name === 'vinculos') carregarVinculos();
  if (name === 'advogados') carregarAdvogados();
  if (name === 'compartilhamentos') carregarShares();
}

// ── Vínculos ─────────────────────────────────────────────────────────────────
async function carregarVinculos() {
  const el = document.getElementById('vinculosList');
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  const r = await api('/sistema_vendas/public/api/account_vinculos.php');
  const lista = r.ok ? (r.data || []) : [];
  if (!lista.length) {
    el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>Nenhum vínculo cadastrado.</div>`;
    return;
  }
  el.innerHTML = `<table class="es-table">
    <thead><tr><th>Tipo</th><th>Escritório</th><th>Status</th><th>Desde</th>${IS_ADMIN ? '<th>Ações</th>' : ''}</tr></thead>
    <tbody>${lista.map(v => {
      const iAmMatriz = v.meu_papel === 'matriz';
      const outro = iAmMatriz ? (v.filial_nome || v.filial_account_id) : (v.matriz_nome || v.matriz_account_id);
      const tipo  = iAmMatriz ? 'Filial' : 'Matriz';
      const acoes = IS_ADMIN ? `
        ${v.status === 'pending' && iAmMatriz ? `<button class="btn-sm btn-success" onclick="aprovarVinculo(${v.id})">Aprovar</button> <button class="btn-sm btn-danger" onclick="rejeitarVinculo(${v.id})">Rejeitar</button>` : ''}
        ${v.status === 'active' && iAmMatriz ? `<button class="btn-sm btn-danger" onclick="suspenderVinculo(${v.id})">Suspender</button>` : ''}
      ` : '';
      return `<tr>
        <td><span class="badge badge-${tipo.toLowerCase()}">${tipo}</span></td>
        <td>${outro}</td>
        <td><span class="badge badge-${v.status}">${v.status}</span></td>
        <td style="color:#4a5568">${(v.created_at||'').slice(0,10)}</td>
        ${IS_ADMIN ? `<td style="display:flex;gap:6px;flex-wrap:wrap">${acoes}</td>` : ''}
      </tr>`;
    }).join('')}</tbody>
  </table>`;
}

async function aprovarVinculo(id) {
  const r = await api('/sistema_vendas/public/api/account_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'aprovar', csrf_token: CSRF })
  });
  r.ok ? (toast('Vínculo aprovado!', 'ok'), carregarVinculos()) : toast(r.error || 'Erro', 'err');
}
async function rejeitarVinculo(id) {
  const r = await api('/sistema_vendas/public/api/account_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'rejeitar', csrf_token: CSRF })
  });
  r.ok ? (toast('Vínculo rejeitado.', 'ok'), carregarVinculos()) : toast(r.error || 'Erro', 'err');
}
async function suspenderVinculo(id) {
  const motivo = prompt('Motivo da suspensão:') ?? '';
  if (motivo === null) return;
  const r = await api('/sistema_vendas/public/api/account_vinculos.php', {
    method: 'PATCH', body: JSON.stringify({ id, action: 'suspender', motivo, csrf_token: CSRF })
  });
  r.ok ? (toast('Vínculo suspenso.', 'ok'), carregarVinculos()) : toast(r.error || 'Erro', 'err');
}

function abrirSolicitarVinculo() {
  document.getElementById('modalSolicitar').classList.add('open');
}
async function solicitarVinculoModal() {
  const codigo = document.getElementById('inputCodigoModal').value.trim();
  const msg    = document.getElementById('solicitarModalMsg');
  if (!codigo) { msg.innerHTML = '<span style="color:#fca5a5">Informe o código.</span>'; return; }
  const r = await api('/sistema_vendas/public/api/account_vinculos.php', {
    method: 'POST', body: JSON.stringify({ codigo_vinculo: codigo, csrf_token: CSRF })
  });
  if (r.ok) {
    msg.innerHTML = '<span style="color:#86efac">Solicitação enviada! Aguarde aprovação da Matriz.</span>';
    setTimeout(() => { document.getElementById('modalSolicitar').classList.remove('open'); carregarVinculos(); }, 1800);
  } else {
    msg.innerHTML = `<span style="color:#fca5a5">${r.error || 'Erro ao solicitar.'}</span>`;
  }
}

// ── Advogados Associados (via resource_shares) ────────────────────────────────
async function carregarAdvogados() {
  const el = document.getElementById('advogadosList');
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  const r = await api('/sistema_vendas/public/api/resource_shares.php');
  const lista = (r.data || r || []).filter(s => s.status === 'active');
  if (!lista.length) {
    el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Nenhum compartilhamento ativo.</div>`;
    return;
  }
  el.innerHTML = `<table class="es-table">
    <thead><tr><th>Escritório</th><th>Recurso</th><th>Permissão</th><th>Desde</th>${IS_ADMIN ? '<th>Ações</th>' : ''}</tr></thead>
    <tbody>${lista.map(s => `<tr>
      <td>${s.to_account_nome || `Conta #${s.to_account_id}`}</td>
      <td><span class="badge badge-filial">${s.resource_type} #${s.resource_id}</span></td>
      <td>${s.permission_level}</td>
      <td style="color:#4a5568">${(s.created_at||'').slice(0,10)}</td>
      ${IS_ADMIN ? `<td><button class="btn-sm btn-danger" onclick="revogarShare(${s.id})">Revogar</button></td>` : ''}
    </tr>`).join('')}</tbody>
  </table>`;
}

function abrirModalConvite() {
  _advContaDestino = null;
  document.getElementById('advCodigo').value = '';
  document.getElementById('advContaResultado').style.display = 'none';
  document.getElementById('adv-passo2').style.display = 'none';
  document.getElementById('btnConfirmarAdv').style.display = 'none';
  document.getElementById('advProcessoBusca').value = '';
  document.getElementById('advProcessoId').value = '';
  document.getElementById('advProcessoSelecionado').style.display = 'none';
  document.getElementById('modalConvite').classList.add('open');
}
function fecharModalConvite() {
  document.getElementById('modalConvite').classList.remove('open');
}

let _advContaDestino = null;
async function buscarContaAdvogado() {
  const codigo    = document.getElementById('advCodigo').value.trim();
  const resultado = document.getElementById('advContaResultado');
  if (!codigo) { toast('Informe o código de vínculo.', 'err'); return; }

  resultado.style.display = 'block';
  resultado.innerHTML = 'Buscando...';
  resultado.style.background = 'rgba(30,50,80,.5)';
  resultado.style.color = '#7a96b4';
  resultado.style.border = '1px solid rgba(96,165,250,.1)';

  const r = await fetch(`/sistema_vendas/public/api/accounts_lookup.php?codigo_vinculo=${encodeURIComponent(codigo)}`).then(x=>x.json()).catch(()=>({}));

  if (r.data) {
    _advContaDestino = r.data;
    const isUser = r.data.tipo_lookup === 'usuario';
    const label  = isUser
      ? `<strong style="color:#86efac">✓ Advogado encontrado:</strong> ${r.data.user_nome} <span style="color:#7a96b4;font-size:.78rem">(${r.data.user_email})</span> — escritório: <em>${r.data.account_nome}</em>`
      : `<strong style="color:#86efac">✓ Escritório encontrado:</strong> ${r.data.nome} <span class="badge badge-${r.data.tipo}" style="margin-left:6px">${r.data.tipo}</span>`;
    resultado.innerHTML = label;
    resultado.style.background = 'rgba(34,197,94,.08)';
    resultado.style.border = '1px solid rgba(34,197,94,.25)';
    document.getElementById('adv-passo2').style.display = 'block';
    document.getElementById('btnConfirmarAdv').style.display = 'inline-block';
  } else {
    _advContaDestino = null;
    resultado.innerHTML = `<span style="color:#fca5a5">✗ ${r.error || 'Código não encontrado.'}</span>`;
    resultado.style.background = 'rgba(239,68,68,.08)';
    resultado.style.border = '1px solid rgba(239,68,68,.2)';
    document.getElementById('adv-passo2').style.display = 'none';
    document.getElementById('btnConfirmarAdv').style.display = 'none';
  }
}

function limparBusca() {
  _advContaDestino = null;
  document.getElementById('advContaResultado').style.display = 'none';
  document.getElementById('adv-passo2').style.display = 'none';
  document.getElementById('btnConfirmarAdv').style.display = 'none';
}

let _buscaTimer = null;
async function buscarProcessos(q) {
  clearTimeout(_buscaTimer);
  _buscaTimer = setTimeout(async () => {
    const r = await fetch(`/sistema_vendas/public/api/processes_search.php?q=${encodeURIComponent(q)}`).then(x=>x.json()).catch(()=>({data:[]}));
    const lista = document.getElementById('advProcessoLista');
    if (!r.data?.length) { lista.style.display = 'none'; return; }
    lista.style.display = 'block';
    lista.innerHTML = r.data.map(p =>
      `<div onclick="selecionarProcesso(${p.id},'${(p.numero||'').replace(/'/g,'')}',' ${(p.cliente_nome||'').replace(/'/g,'')}')"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid rgba(96,165,250,.08);font-size:.83rem;color:#c8ddf0;"
            onmouseover="this.style.background='rgba(37,99,235,.15)'" onmouseout="this.style.background=''">
        <strong>${p.numero || '—'}</strong> — ${p.cliente_nome || '—'}
      </div>`
    ).join('');
  }, 280);
}

function selecionarProcesso(id, numero, cliente) {
  document.getElementById('advProcessoId').value = id;
  document.getElementById('advProcessoBusca').value = `${numero} — ${cliente}`;
  document.getElementById('advProcessoLista').style.display = 'none';
  const sel = document.getElementById('advProcessoSelecionado');
  sel.style.display = 'block';
  sel.textContent = `✓ Processo #${id} selecionado`;
}

async function confirmarCompartilhamento() {
  const processoId = document.getElementById('advProcessoId').value;
  const permissao  = document.getElementById('advPermissao').value;
  if (!_advContaDestino) { toast('Busque a conta do advogado primeiro.', 'err'); return; }
  if (!processoId)       { toast('Selecione um processo.', 'err'); return; }

  const payload = {
    resource_type:    'processo',
    resource_id:      parseInt(processoId),
    to_account_id:    _advContaDestino.account_id || _advContaDestino.id,
    permission_level: permissao,
    csrf_token:       CSRF,
  };
  // Se encontrado por código de usuário, inclui to_user_id para compartilhamento específico
  if (_advContaDestino.tipo_lookup === 'usuario' && _advContaDestino.user_id) {
    payload.to_user_id = _advContaDestino.user_id;
  }
  const r = await api('/sistema_vendas/public/api/resource_shares.php', {
    method: 'POST',
    body: JSON.stringify(payload)
  });
  if (r.success || r.ok) {
    toast(`Processo compartilhado com ${_advContaDestino.nome}!`, 'ok');
    fecharModalConvite();
    carregarAdvogados();
    carregarShares();
  } else {
    toast(r.error || 'Erro ao compartilhar.', 'err');
  }
}

// ── Compartilhamentos ─────────────────────────────────────────────────────────
async function carregarShares() {
  const el = document.getElementById('sharesList');
  el.innerHTML = '<div class="es-empty">Carregando...</div>';
  const r = await api('/sistema_vendas/public/api/resource_shares.php');
  const lista = r.ok ? (r.data || []) : [];
  if (!lista.length) {
    el.innerHTML = `<div class="es-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>Nenhum compartilhamento ativo.</div>`;
    return;
  }
  el.innerHTML = `<table class="es-table">
    <thead><tr><th>Recurso</th><th>Para</th><th>Permissão</th><th>Status</th>${IS_ADMIN ? '<th>Ações</th>' : ''}</tr></thead>
    <tbody>${lista.map(s => `<tr>
      <td><span class="badge badge-filial">${s.resource_type} #${s.resource_id}</span></td>
      <td>${s.to_account_nome || s.to_account_id || '—'}</td>
      <td>${s.permission_level}</td>
      <td><span class="badge badge-${s.status}">${s.status}</span></td>
      ${IS_ADMIN ? `<td>${s.status === 'active' ? `<button class="btn-sm btn-danger" onclick="revogarShare(${s.id})">Revogar</button>` : ''}</td>` : ''}
    </tr>`).join('')}</tbody>
  </table>`;
}

async function revogarShare(id) {
  if (!confirm('Revogar este compartilhamento?')) return;
  const r = await api('/sistema_vendas/public/api/resource_shares.php', {
    method: 'DELETE', body: JSON.stringify({ id, csrf_token: CSRF })
  });
  r.ok ? (toast('Compartilhamento revogado.', 'ok'), carregarShares()) : toast(r.error || 'Erro', 'err');
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 'ok') {
  const t = document.createElement('div');
  t.textContent = msg;
  Object.assign(t.style, {
    position:'fixed', bottom:'24px', right:'24px', zIndex:9999,
    padding:'10px 18px', borderRadius:'8px', fontSize:'.84rem', fontWeight:'600',
    background: type === 'ok' ? 'rgba(34,197,94,.2)' : 'rgba(239,68,68,.2)',
    border: `1px solid ${type === 'ok' ? 'rgba(34,197,94,.4)' : 'rgba(239,68,68,.4)'}`,
    color: type === 'ok' ? '#86efac' : '#fca5a5',
    boxShadow: '0 8px 24px rgba(0,0,0,.3)',
    transition: 'opacity .3s',
  });
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

// ── Init ──────────────────────────────────────────────────────────────────────
carregarConta();
carregarVinculos();
</script>
</body>
</html>
