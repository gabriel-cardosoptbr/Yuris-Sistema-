<?php
/**
 * /configuracoes/monitoramentos.php — Painel da conta para gerenciar
 * cotas de monitoramento (add-on).
 *
 * 3 abas:
 *   1) Geral             — KPIs da própria conta + flag advogado
 *   2) Distribuir cota   — só matriz: aloca cota pra filiais (Etapa 8)
 *   3) Histórico         — log de allocations (ativas + revogadas)
 *
 * Etapa 9 adiciona aba "Solicitações" (separada).
 *
 * Padrão visual: igual /configuracoes/privacidade.php (cfg-panel +
 * sidebar include).
 *
 * @since 2026-05-26 (Etapa 8 add-on Monitoramentos)
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/MonitorQuota.php';
require_once __DIR__ . '/../../app/Helpers/MonitorPermission.php';
require_once __DIR__ . '/../../app/Helpers/BillingGuard.php';

use App\Helpers\AccountContext;
use App\Helpers\MonitorQuota;
use App\Helpers\MonitorPermission;
use App\Helpers\BillingGuard;
use App\Models\Database;

session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /sistema_vendas/public/login.php');
    exit;
}

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$accountId   = $ctx->getAccountId();
$userId      = $ctx->getUserId();
$csrf        = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$activePage  = 'monitoramentos';

// Dados da conta + tipo
$pdo  = Database::getConnection();
$stmt = $pdo->prepare('SELECT id, nome, tipo FROM accounts WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $accountId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $accountId, 'nome' => '?', 'tipo' => 'matriz'];

$isMatriz   = ($account['tipo'] === 'matriz');
$canAllocate = MonitorPermission::canManageQuotaAllocations($ctx);
$status      = MonitorQuota::getQuotaStatus($accountId);
$planBase    = BillingGuard::getBaseLimit($accountId, 'monitors.limit');
$overrideSum = BillingGuard::getOverrideSum($accountId, 'monitors.limit');
$advFlag     = MonitorPermission::isAdvogadoAllowedToCreate($accountId);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <base href="/sistema_vendas/public/">
  <title>Monitoramentos — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=19">
  <style>
    :root {
      --bg-main: #070F1C;
      --line: rgba(160,180,210,0.08);
      --line-strong: rgba(160,180,210,0.14);
      --text: #D8E4F0;
      --muted: #7A8898;
      --primary: #244E7A;
      --radius: 14px;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      background-color: #070F1C;
      background-image:
        radial-gradient(ellipse 120% 80% at 15% 40%, rgba(20,50,90,0.18) 0%, transparent 55%),
        radial-gradient(ellipse 80% 60% at 85% 20%, rgba(30,60,100,0.12) 0%, transparent 50%);
      background-attachment: fixed;
      color: var(--text);
      font-family: 'Poppins', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
    }
    .cfg-panel {
      background: linear-gradient(165deg, rgba(14,35,65,.94), rgba(10,23,43,.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 14px 40px rgba(2,6,23,.45);
    }
    /* ── KPI cards ── */
    .kpi-grid {
      display: grid; gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      margin-top: 14px;
    }
    .kpi-card {
      background: rgba(14,35,65,.55);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 14px 16px;
    }
    .kpi-label { font-size:.74rem; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
    .kpi-value { font-size:1.6rem; font-weight:700; margin-top:4px; color:#E8EEF6; }
    .kpi-card.good .kpi-value  { color:#6EE7B7; }
    .kpi-card.warn .kpi-value  { color:#FBBF24; }
    .kpi-card.bad  .kpi-value  { color:#FCA5A5; }
    .kpi-meta { font-size:.72rem; color:var(--muted); margin-top:6px; }

    /* ── Tabs ── */
    .tabs {
      display: flex; gap: 4px; margin-bottom: 18px;
      border-bottom: 1px solid var(--line);
      flex-wrap: wrap;
    }
    .tab {
      padding: 10px 16px; cursor: pointer; font-weight:600;
      font-size:.85rem; color: var(--muted);
      border-bottom: 2px solid transparent;
      transition: color .12s, border-color .12s;
    }
    .tab:hover { color: #B6C5D8; }
    .tab.active {
      color: #93C5FD;
      border-bottom-color: #2563EB;
    }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* ── Tabela filiais ── */
    .filiais-table {
      width: 100%; border-collapse: collapse; font-size:.85rem;
    }
    .filiais-table th, .filiais-table td {
      padding: 10px 12px; text-align: left;
      border-bottom: 1px solid var(--line);
    }
    .filiais-table th {
      font-size:.7rem; text-transform:uppercase; letter-spacing:.05em;
      color: var(--muted); font-weight:600;
    }
    .filiais-table tr:last-child td { border-bottom: none; }
    .alloc-input {
      width: 80px; padding: 6px 8px;
      background: rgba(14,35,65,.7);
      border: 1px solid var(--line-strong);
      border-radius: 6px;
      color: var(--text);
      font: inherit; font-size:.85rem;
      text-align: center;
    }
    .alloc-input:focus { outline:none; border-color:#2563EB; }

    /* ── Botões padrão Yuris ── */
    .btn {
      padding: 7px 14px; border-radius: 8px; border: 1px solid transparent;
      font: inherit; cursor: pointer; font-weight: 600; font-size: .82rem;
      transition: background .15s, border-color .15s, transform .12s;
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-primary {
      background: linear-gradient(135deg, #2563EB, #1D4ED8);
      color: #FFFFFF; border-color: #1D4ED8;
    }
    .btn-primary:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .btn-primary:disabled, .btn:disabled {
      opacity:.45; cursor:not-allowed; filter:grayscale(.5);
      transform:none;
    }
    .btn-danger {
      background: rgba(220,38,38,.12); color: #FCA5A5;
      border-color: rgba(220,38,38,.30);
    }
    .btn-danger:hover { background: rgba(220,38,38,.20); border-color: rgba(220,38,38,.50); }
    .btn-ghost {
      background: rgba(36,78,122,.18); color: #93C5FD;
      border-color: rgba(96,165,250,.20);
    }
    .btn-ghost:hover { background: rgba(36,78,122,.32); }

    .pill {
      display: inline-block; padding: 2px 10px;
      border-radius: 999px; font-size: .68rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .04em;
    }
    .pill-active   { background: rgba(74,144,120,.16); color: #6EE7B7; border:1px solid rgba(74,144,120,.30); }
    .pill-revoked  { background: rgba(160,180,210,.12); color: #94A3B8; border:1px solid rgba(160,180,210,.22); }

    /* ── Toggle ── */
    .switch { position: relative; display: inline-block; width: 42px; height: 22px; vertical-align: middle; }
    .switch input { opacity:0; width:0; height:0; }
    .slider {
      position:absolute; cursor:pointer; inset:0; background:#3B4A5E;
      border-radius:22px; transition: background .15s;
    }
    .slider:before {
      content: ""; position:absolute; height:16px; width:16px; left:3px; top:3px;
      background:#fff; border-radius:50%; transition:.15s;
    }
    input:checked + .slider { background: #2563EB; }
    input:checked + .slider:before { transform: translateX(20px); }

    .empty {
      padding: 26px 14px; color: var(--muted);
      text-align: center; font-size: .9rem; font-style: italic;
    }
    .hint {
      font-size:.8rem; color:var(--muted); margin-top:6px;
    }
    .alert {
      padding: 12px 14px; border-radius: 10px;
      font-size:.85rem; margin-bottom: 14px;
    }
    .alert-info {
      background: rgba(36,78,122,.15);
      border:1px solid rgba(96,165,250,.22);
      color:#BBD7FF;
    }
    .alert-warn {
      background: rgba(245,158,11,.10);
      border:1px solid rgba(245,158,11,.30);
      color:#FED7AA;
    }

    /* Tema claro overrides */
    html[data-theme="light"] body { background-color: #EFF3F8; color: #0F1F36; }
    html[data-theme="light"] .cfg-panel {
      background: linear-gradient(165deg, #FFFFFF, #F7F9FC);
      border-color: rgba(15,31,54,.10);
      color:#0F1F36;
    }
    html[data-theme="light"] .kpi-card {
      background: #F2F5F9; border-color: rgba(15,31,54,.10);
    }
    html[data-theme="light"] .kpi-value { color: #0F1F36; }
    html[data-theme="light"] .kpi-card.good .kpi-value { color: #047857; }
    html[data-theme="light"] .kpi-card.warn .kpi-value { color: #B45309; }
    html[data-theme="light"] .kpi-card.bad  .kpi-value { color: #B91C1C; }
    html[data-theme="light"] .filiais-table th { color: #475569; }
    html[data-theme="light"] .filiais-table td { color: #0F1F36; }
    html[data-theme="light"] .tab { color: #475569; }
    html[data-theme="light"] .tab.active { color: #1D4ED8; }
    html[data-theme="light"] .alloc-input {
      background: #FFFFFF; color: #0F1F36;
      border-color: rgba(15,31,54,.15);
    }
    html[data-theme="light"] .alert-info {
      background: rgba(37,99,235,.07); border-color: rgba(37,99,235,.20); color:#1D4ED8;
    }
    html[data-theme="light"] .alert-warn {
      background: rgba(245,158,11,.07); border-color: rgba(245,158,11,.30); color:#92400E;
    }
    html[data-theme="light"] .pill-active  { background:rgba(22,163,74,.12); color:#15803D; border-color:rgba(22,163,74,.30); }
    html[data-theme="light"] .pill-revoked { background:rgba(100,116,139,.12); color:#475569; border-color:rgba(100,116,139,.30); }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <section class="main-content space-y-4">

      <!-- Header -->
      <div class="cfg-panel page-header">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Monitoramentos</h2>
            <p class="page-header-subtitle">
              Gerencie a cota contratada de monitoramentos
              (<strong><?= htmlspecialchars($account['nome']) ?></strong> &middot;
              <?= htmlspecialchars($account['tipo']) ?>) e
              <?php if ($isMatriz): ?>distribua entre filiais vinculadas<?php else: ?>visualize a alocação recebida<?php endif; ?>.
            </p>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="cfg-panel">
        <div class="tabs" id="monitTabs">
          <div class="tab active" data-tab="geral">Geral</div>
          <?php if ($isMatriz && $canAllocate): ?>
            <div class="tab" data-tab="distribuir">Distribuir cota</div>
          <?php endif; ?>
          <div class="tab" data-tab="solicitacoes">
            Solicitações
            <span id="reqPendingBadge" style="display:none; margin-left:6px; padding:1px 7px; border-radius:999px; background:#DC2626; color:#FFF; font-size:.7rem;">0</span>
          </div>
          <div class="tab" data-tab="historico">Histórico</div>
        </div>

        <!-- ── Aba: Geral ─────────────────────────────────────── -->
        <div class="tab-panel active" data-panel="geral">
          <?php if ($status['effective_limit'] <= 0): ?>
            <div class="alert alert-warn">
              <strong>Você ainda não tem monitoramentos contratados.</strong><br>
              Cada OAB/advogado monitorado é cobrado à parte do plano do sistema.
              Fale com o time comercial para contratar.
            </div>
          <?php endif; ?>

          <div class="kpi-grid">
            <div class="kpi-card">
              <div class="kpi-label">Contratado</div>
              <div class="kpi-value"><?= (int) $status['effective_limit'] ?></div>
              <div class="kpi-meta">
                <?php if ($status['has_allocations'] && !$isMatriz): ?>
                  alocação recebida da matriz
                <?php elseif (!$isMatriz): ?>
                  pool aberto da matriz
                <?php else: ?>
                  plano: <?= (int) $planBase ?> &middot; add-ons: <?= (int) $overrideSum ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="kpi-card <?= $status['percent_used'] >= 100 ? 'bad' : ($status['percent_used'] >= 80 ? 'warn' : '') ?>">
              <div class="kpi-label">Em uso</div>
              <div class="kpi-value"><?= (int) $status['current_usage'] ?></div>
              <div class="kpi-meta"><?= (int) $status['percent_used'] ?>% utilizado</div>
            </div>
            <div class="kpi-card <?= $status['available'] > 0 ? 'good' : 'bad' ?>">
              <div class="kpi-label">Disponível</div>
              <div class="kpi-value"><?= (int) $status['available'] ?></div>
              <div class="kpi-meta">vagas livres pra novo monitor</div>
            </div>
          </div>

          <?php if ($ctx->isOwnerOrAdmin()): ?>
            <hr style="margin: 22px 0; border:none; border-top: 1px solid var(--line);">

            <h3 style="font-size:1.02rem;font-weight:600;margin:0 0 14px">Permissões</h3>
            <div style="display:flex; align-items:center; gap:14px; padding:14px; background:rgba(14,35,65,.4); border:1px solid var(--line); border-radius:10px;">
              <label class="switch">
                <input type="checkbox" id="flagAdvogadoCreate" <?= $advFlag ? 'checked' : '' ?>>
                <span class="slider"></span>
              </label>
              <div>
                <div style="font-weight:600;">Advogados podem criar próprio monitoramento</div>
                <div class="hint">
                  Quando desligado, advogados precisam <strong>solicitar</strong> ao admin.
                  Owner/admin/super_admin sempre podem criar.
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- ── Aba: Distribuir cota (só matriz) ────────────────── -->
        <?php if ($isMatriz && $canAllocate): ?>
          <div class="tab-panel" data-panel="distribuir">
            <div class="alert alert-info" id="distribInfo">
              <strong>Modelo híbrido:</strong> sem alocações fixas, todas as filiais
              vinculadas usam o pool aberto da matriz. Aloque para
              <em>reservar</em> uma quantidade fixa pra uma filial específica.
            </div>

            <div class="kpi-grid" id="distribKpis">
              <div class="kpi-card">
                <div class="kpi-label">Pool total</div>
                <div class="kpi-value" id="kpiPoolTotal">—</div>
                <div class="kpi-meta">limite contratado</div>
              </div>
              <div class="kpi-card warn">
                <div class="kpi-label">Alocado</div>
                <div class="kpi-value" id="kpiAllocado">—</div>
                <div class="kpi-meta">reservado pra filiais</div>
              </div>
              <div class="kpi-card good">
                <div class="kpi-label">Livre no pool</div>
                <div class="kpi-value" id="kpiPoolLivre">—</div>
                <div class="kpi-meta">disponível pra novas alocações</div>
              </div>
            </div>

            <h3 style="font-size:1.02rem;font-weight:600;margin:24px 0 12px">Filiais vinculadas</h3>
            <div id="filiaisWrap">
              <div class="empty">Carregando…</div>
            </div>
          </div>
        <?php endif; ?>

        <!-- ── Aba: Solicitações ──────────────────────────────── -->
        <div class="tab-panel" data-panel="solicitacoes">
          <?php if ($ctx->isOwnerOrAdmin() || $ctx->isSuperAdmin()): ?>
            <div class="alert alert-info">
              <strong>Caixa de entrada do admin:</strong> aprove ou recuse
              solicitações de monitoramento feitas por usuários da conta.
              Aprovar cria o monitor automaticamente.
            </div>
          <?php else: ?>
            <div class="alert alert-info">
              Aqui você vê o status das suas solicitações de monitoramento.
              Quando o admin aprovar, o monitor passa a rodar automaticamente.
            </div>
          <?php endif; ?>

          <div style="display:flex; gap:10px; margin-bottom:12px; flex-wrap:wrap; align-items:center;">
            <label class="hint" style="margin:0">Filtro:</label>
            <select id="reqScope" class="alloc-input" style="width:auto;">
              <option value="pending">Pendentes</option>
              <option value="all">Todas</option>
              <option value="mine">Só as minhas</option>
            </select>
            <button class="btn btn-ghost" onclick="loadRequests()">Atualizar</button>
          </div>

          <div id="requestsWrap">
            <div class="empty">Carregando…</div>
          </div>
        </div>

        <!-- ── Aba: Histórico ─────────────────────────────────── -->
        <div class="tab-panel" data-panel="historico">
          <h3 style="font-size:1.02rem;font-weight:600;margin:0 0 12px">Histórico de alocações</h3>
          <?php if ($isMatriz && $canAllocate): ?>
            <div id="historicoWrap">
              <div class="empty">Carregando…</div>
            </div>
          <?php else: ?>
            <div class="empty">
              Você verá aqui o histórico de cotas alocadas/revogadas para sua conta.
            </div>
            <div id="historicoFilialWrap" style="margin-top:14px;"></div>
          <?php endif; ?>
        </div>

      </div>

    </section>
  </div>
</main>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const IS_MATRIZ = <?= $isMatriz ? 'true' : 'false' ?>;
const CAN_ALLOCATE = <?= $canAllocate ? 'true' : 'false' ?>;
const CAN_FLIP_FLAG = <?= $ctx->isOwnerOrAdmin() ? 'true' : 'false' ?>;

// ── Tabs simples ──
document.querySelectorAll('#monitTabs .tab').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('#monitTabs .tab').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    t.classList.add('active');
    const target = t.dataset.tab;
    document.querySelector(`.tab-panel[data-panel="${target}"]`)?.classList.add('active');
    if (target === 'distribuir' || target === 'historico') loadAllocations();
    if (target === 'solicitacoes') loadRequests();
  });
});

// Carrega solicitações em background pra mostrar badge na aba Solicitações
(function preloadRequests() {
  loadRequests(true /*quiet*/);
})();

// ── Flag advogado pode criar ──
if (CAN_FLIP_FLAG) {
  const cb = document.getElementById('flagAdvogadoCreate');
  cb?.addEventListener('change', async () => {
    try {
      const r = await fetch('/sistema_vendas/public/api/push/permissions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ _csrf: CSRF, advogado_pode_criar_monitor: !!cb.checked })
      });
      const data = await r.json();
      if (!data.ok) {
        alert(data.error || 'Erro ao salvar');
        cb.checked = !cb.checked;
      }
    } catch (e) {
      console.error(e);
      cb.checked = !cb.checked;
      alert('Falha de rede ao salvar');
    }
  });
}

// ── Aba Distribuir / Histórico — carrega ──
let _allocData = null;
let _loading = false;

async function loadAllocations() {
  if (_loading) return;
  if (!IS_MATRIZ || !CAN_ALLOCATE) { renderHistoricoFilial(); return; }
  _loading = true;
  try {
    const r = await fetch('/sistema_vendas/public/api/push/allocations.php', {
      headers: {'Accept':'application/json'}
    });
    const data = await r.json();
    if (!data.ok) {
      document.getElementById('filiaisWrap').innerHTML =
        `<div class="empty">Erro: ${escHtml(data.error||'?')}</div>`;
      document.getElementById('historicoWrap').innerHTML =
        `<div class="empty">Erro: ${escHtml(data.error||'?')}</div>`;
      return;
    }
    _allocData = data;
    renderKpis();
    renderFiliais();
    renderHistorico();
  } catch (e) {
    console.error(e);
    document.getElementById('filiaisWrap').innerHTML =
      `<div class="empty">Falha de rede</div>`;
  } finally {
    _loading = false;
  }
}

function renderKpis() {
  if (!_allocData) return;
  const p = _allocData.parent;
  document.getElementById('kpiPoolTotal').textContent  = p.own_limit;
  document.getElementById('kpiAllocado').textContent   = p.allocated_total;
  document.getElementById('kpiPoolLivre').textContent  = p.pool_disponivel;
}

function renderFiliais() {
  const wrap = document.getElementById('filiaisWrap');
  if (!_allocData || !_allocData.filiais.length) {
    wrap.innerHTML = `<div class="empty">
      Nenhuma filial vinculada com <code>sync_monitoramentos=1</code>.<br>
      Crie ou ative o vínculo em <a href="/sistema_vendas/public/escritorios.php" class="btn-link" style="text-decoration:underline">Escritórios → Filiais</a>.
    </div>`;
    return;
  }

  const livre = _allocData.parent.pool_disponivel;

  let html = `<table class="filiais-table">
    <thead>
      <tr>
        <th>Filial</th>
        <th>Em uso</th>
        <th>Alocação atual</th>
        <th>Nova alocação</th>
        <th style="text-align:right">Ações</th>
      </tr>
    </thead>
    <tbody>`;
  for (const f of _allocData.filiais) {
    const inputId = `alloc-${f.account_id}`;
    const valAtual = f.allocated || 0;
    html += `<tr data-acc="${f.account_id}" data-id="${f.allocation_id||''}">
      <td><strong>${escHtml(f.nome)}</strong>
          <div class="hint">#${f.account_id}</div></td>
      <td>${f.current_usage||0}</td>
      <td>${valAtual > 0
            ? `<span class="pill pill-active">${valAtual} reservados</span>`
            : `<span class="pill pill-revoked">pool aberto</span>`}</td>
      <td>
        <input type="number" min="0" step="1" class="alloc-input"
               id="${inputId}" value="${valAtual}"
               data-orig="${valAtual}">
      </td>
      <td style="text-align:right">
        <button class="btn btn-primary" onclick="saveAlloc(${f.account_id})">Salvar</button>
        ${f.allocation_id
          ? `<button class="btn btn-danger" style="margin-left:6px" onclick="revokeAlloc(${f.allocation_id})">Revogar</button>`
          : ''}
      </td>
    </tr>`;
  }
  html += `</tbody></table>
    <div class="hint" style="margin-top:10px">
      Definir <strong>0</strong> e clicar Salvar = volta a filial pra pool aberto.
      Pool livre disponível: <strong>${livre}</strong>.
    </div>`;
  wrap.innerHTML = html;
}

function renderHistorico() {
  const wrap = document.getElementById('historicoWrap');
  if (!_allocData || !_allocData.allocations.length) {
    wrap.innerHTML = `<div class="empty">Nenhuma alocação registrada ainda.</div>`;
    return;
  }
  let html = `<table class="filiais-table">
    <thead><tr>
      <th>Quando</th><th>Filial</th><th>Qtd</th><th>Status</th><th>Por</th><th>Obs.</th>
    </tr></thead><tbody>`;
  for (const a of _allocData.allocations) {
    const isAtiva = a.status === 'active';
    html += `<tr>
      <td>${formatDate(a.created_at)}${a.revoked_at ? `<div class="hint">revogada em ${formatDate(a.revoked_at)}</div>`:''}</td>
      <td>${escHtml(a.target_nome||'?')} <span class="hint">#${a.target_account_id}</span></td>
      <td>${a.allocated}</td>
      <td>${isAtiva ? `<span class="pill pill-active">ativa</span>` : `<span class="pill pill-revoked">revogada</span>`}</td>
      <td>${escHtml(a.created_by_nome||'?')}${a.revoked_by_nome ? `<div class="hint">por ${escHtml(a.revoked_by_nome)}</div>`:''}</td>
      <td>${escHtml(a.observacoes||'')}</td>
    </tr>`;
  }
  html += `</tbody></table>`;
  wrap.innerHTML = html;
}

function renderHistoricoFilial() {
  // Filial: futura — endpoint próprio. Por ora, hint.
  const wrap = document.getElementById('historicoFilialWrap');
  if (!wrap) return;
  wrap.innerHTML = `<div class="hint">
    O painel completo de histórico está disponível para a conta matriz.
  </div>`;
}

async function saveAlloc(accountId) {
  const row = document.querySelector(`[data-acc="${accountId}"]`);
  if (!row) return;
  const input = row.querySelector('.alloc-input');
  const allocId = row.dataset.id ? parseInt(row.dataset.id, 10) : 0;
  const novo = parseInt(input.value, 10);
  if (Number.isNaN(novo) || novo < 0) { alert('Valor inválido'); return; }

  // 0 com alocação existente → revogar
  if (novo === 0 && allocId) {
    if (!confirm('Definir 0 vai revogar a alocação. Continuar?')) return;
    return revokeAlloc(allocId);
  }
  // 0 sem alocação → no-op
  if (novo === 0 && !allocId) return;

  try {
    let r;
    if (allocId) {
      r = await fetch(`/sistema_vendas/public/api/push/allocations.php?id=${allocId}`, {
        method: 'PATCH',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ _csrf: CSRF, allocated: novo })
      });
    } else {
      r = await fetch('/sistema_vendas/public/api/push/allocations.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
          _csrf: CSRF,
          target_account_id: accountId,
          allocated: novo
        })
      });
    }
    const data = await r.json();
    if (!data.ok) {
      alert(data.error || 'Erro ao salvar');
      return;
    }
    await loadAllocations();
  } catch (e) {
    console.error(e);
    alert('Falha de rede');
  }
}

async function revokeAlloc(id) {
  if (!confirm('Revogar alocação? A filial volta pro pool aberto da matriz.')) return;
  try {
    const r = await fetch(`/sistema_vendas/public/api/push/allocations.php?id=${id}`, {
      method: 'DELETE',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ _csrf: CSRF })
    });
    const data = await r.json();
    if (!data.ok) { alert(data.error || 'Erro ao revogar'); return; }
    await loadAllocations();
  } catch (e) {
    console.error(e);
    alert('Falha de rede');
  }
}

// ── Aba Solicitações ──
let _reqData = null;
let _loadingReq = false;
async function loadRequests(quiet) {
  if (_loadingReq) return;
  _loadingReq = true;
  const wrap = document.getElementById('requestsWrap');
  const scopeSel = document.getElementById('reqScope');
  const scope = scopeSel ? scopeSel.value : 'pending';
  try {
    const r = await fetch(`/sistema_vendas/public/api/push/requests.php?scope=${encodeURIComponent(scope)}`, {
      headers: {'Accept':'application/json'}
    });
    const data = await r.json();
    if (!data.ok) {
      if (!quiet && wrap) wrap.innerHTML = `<div class="empty">Erro: ${escHtml(data.error||'?')}</div>`;
      return;
    }
    _reqData = data;
    updatePendingBadge();
    if (!quiet) renderRequests();
  } catch (e) {
    if (!quiet && wrap) wrap.innerHTML = `<div class="empty">Falha de rede</div>`;
  } finally {
    _loadingReq = false;
  }
}

function updatePendingBadge() {
  const badge = document.getElementById('reqPendingBadge');
  if (!badge || !_reqData) return;
  const n = _reqData.counts?.pending || 0;
  if (n > 0) {
    badge.style.display = 'inline-block';
    badge.textContent = String(n);
  } else {
    badge.style.display = 'none';
  }
}

function renderRequests() {
  const wrap = document.getElementById('requestsWrap');
  if (!wrap || !_reqData) return;
  const list = _reqData.requests || [];
  const canApprove = _reqData.can?.approve;
  if (!list.length) {
    wrap.innerHTML = `<div class="empty">Nenhuma solicitação encontrada nesse filtro.</div>`;
    return;
  }
  let html = `<table class="filiais-table">
    <thead><tr>
      <th>Quando</th><th>Quem</th><th>Pediu</th><th>Justificativa</th><th>Status</th><th style="text-align:right">Ações</th>
    </tr></thead><tbody>`;
  for (const r of list) {
    const pillStatus = {
      pending:  '<span class="pill" style="background:rgba(245,158,11,.16);color:#FBBF24;border:1px solid rgba(245,158,11,.35);">pendente</span>',
      approved: '<span class="pill pill-active">aprovada</span>',
      denied:   '<span class="pill" style="background:rgba(220,38,38,.16);color:#FCA5A5;border:1px solid rgba(220,38,38,.35);">recusada</span>',
      canceled: '<span class="pill pill-revoked">cancelada</span>',
    }[r.status] || r.status;

    const acoes = r.status === 'pending'
      ? (canApprove
        ? `<button class="btn btn-primary" onclick="resolveRequest(${r.id}, 'approve')">Aprovar</button>
           <button class="btn btn-danger" onclick="resolveRequest(${r.id}, 'deny')" style="margin-left:6px">Recusar</button>`
        : `<button class="btn btn-ghost" onclick="resolveRequest(${r.id}, 'cancel')">Cancelar</button>`)
      : (r.resulting_monitor_id ? `<span class="hint">monitor #${r.resulting_monitor_id}</span>` : '');

    const pediu = `${escHtml(r.tipo_monitoramento)} <strong>${escHtml(r.valor_monitorado)}</strong>`
      + (r.uf ? ` <span class="hint">/${escHtml(r.uf)}</span>` : '')
      + (r.nome_complementar ? `<div class="hint">${escHtml(r.nome_complementar)}</div>` : '');

    html += `<tr>
      <td>${formatDate(r.created_at)}${r.approved_at ? `<div class="hint">resolvida ${formatDate(r.approved_at||r.denied_at)}</div>`:''}</td>
      <td>${escHtml(r.requesting_user_nome||'?')}</td>
      <td>${pediu}</td>
      <td><div class="hint" style="max-width:240px; white-space:pre-wrap;">${escHtml(r.justificativa||'—')}</div>${r.motivo_recusa ? `<div class="hint" style="color:#FCA5A5; margin-top:4px;">${escHtml(r.motivo_recusa)}</div>`:''}</td>
      <td>${pillStatus}</td>
      <td style="text-align:right; white-space:nowrap;">${acoes}</td>
    </tr>`;
  }
  html += `</tbody></table>`;
  wrap.innerHTML = html;
}

async function resolveRequest(id, action) {
  let motivo = null;
  if (action === 'deny') {
    motivo = prompt('Motivo da recusa (opcional, mas recomendado):', '');
    if (motivo === null) return; // cancelou o prompt
  }
  if (action === 'cancel') {
    if (!confirm('Cancelar essa solicitação?')) return;
  }
  if (action === 'approve') {
    if (!confirm('Aprovar e criar o monitor agora?')) return;
  }
  try {
    const r = await fetch(`/sistema_vendas/public/api/push/requests.php?id=${id}`, {
      method: 'PATCH',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ _csrf: CSRF, action, motivo })
    });
    const data = await r.json();
    if (!data.ok) { alert(data.error || 'Erro'); return; }
    await loadRequests();
  } catch (e) {
    console.error(e);
    alert('Falha de rede');
  }
}

document.getElementById('reqScope')?.addEventListener('change', () => loadRequests());

function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}
function formatDate(s) {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleDateString('pt-BR', {day:'2-digit', month:'2-digit', year:'2-digit'})
       + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
}
</script>
</body>
</html>
