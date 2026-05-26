<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/PipelineColumn.php';
require_once __DIR__ . '/../app/Models/Account.php';
require_once __DIR__ . '/../app/Models/ResourceShare.php';
require_once __DIR__ . '/../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../app/Helpers/UserOptions.php';

use App\Models\Database;
use App\Models\PipelineColumn;
use App\Helpers\AccountContext;
use App\Helpers\UserOptions;

session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /sistema_vendas/public/login.php');
    exit;
}
// HARDENING: bloqueia acesso de contas suspensas/canceladas/inativas
AccountContext::fromSession()->assertAccountActive();
$activePage = 'prospeccao';
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$columns = PipelineColumn::listAll();

// Usuários do tenant (matriz + filiais vinculadas) com info de conta
// → permite agrupamento visual em <optgroup>.
// HARDENING: antes a query era global e vazava usuários cross-tenant.
$users = [];
$usersMap = [];
$origin_accounts = [];   // contas acessíveis (própria + filiais se matriz) — alimenta o filtro de Origem
$origin_self     = ['id' => 0, 'tipo' => 'matriz', 'nome' => ''];
try {
    $ctx_p   = AccountContext::fromSession();
    $users   = $ctx_p->getAccessibleUsers();
    foreach ($users as $u) {
        $usersMap[(string)$u['id']] = $u['nome'];
    }
    // Filtro de Origem: somente matriz precisa do filtro (filial vê só si mesma).
    $origin_self = [
        'id'   => $ctx_p->getAccountId(),
        'tipo' => $ctx_p->getAccountTipo(),
        'nome' => $_SESSION['account_nome'] ?? '',
    ];
    if ($ctx_p->isMatriz()) {
        $pdo_o = Database::getConnection();
        $stmt_o = $pdo_o->prepare(
            "SELECT id, nome, tipo
             FROM accounts
             WHERE deleted_at IS NULL AND status = 'active'
               AND (id = :self OR matriz_id = :self)
             ORDER BY CASE WHEN tipo = 'matriz' THEN 0 ELSE 1 END, nome ASC"
        );
        $stmt_o->execute(['self' => $ctx_p->getAccountId()]);
        $origin_accounts = $stmt_o->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) { /* fallback vazio */ }

function normalize_stage_label(string $text): string
{
    $text = trim($text);
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }
    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'ê' => 'e',
        'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ];
    return strtr($text, $map);
}

function column_display_name(array $col): string
{
    $slug = normalize_stage_label((string)($col['slug'] ?? ''));
    $nome = normalize_stage_label((string)($col['nome'] ?? ''));
    $base = $slug !== '' ? $slug : $nome;

    if (strpos($base, 'prospec') !== false || strpos($base, 'lead') !== false) {
        return 'Leads em atendimento';
    }
    if (strpos($base, 'proposta') !== false) {
        return 'Proposta enviada';
    }
    if (strpos($base, 'negoci') !== false) {
        return 'Negociação jurídica';
    }
    if (strpos($base, 'fechado') !== false || (int)($col['conta_fechado'] ?? 0) === 1) {
        return 'Contrato fechado';
    }
    return (string)($col['nome'] ?? 'Etapa comercial');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Central de Prospecção Jurídica - Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=18">
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
  <style>
    :root {
      --bg-main: #070F1C;
      --panel: #0D1C30;
      --panel-soft: #0E1F36;
      --line: rgba(160,180,210,0.08);
      --line-strong: rgba(160,180,210,0.14);
      --text: #D8E4F0;
      --muted: #7A8898;
      --primary: #244E7A;
      --ok: #1E4A3A;
      --warn: #3D3010;
      --danger: #3A1020;
      --radius: 14px;
    }

    body {
      margin: 0;
      background-color: #070F1C;
      background-image:
        radial-gradient(ellipse 120% 80% at 15% 40%, rgba(20,50,90,0.18) 0%, transparent 55%),
        radial-gradient(ellipse 80% 60% at 85% 20%, rgba(30,60,100,0.12) 0%, transparent 50%);
      background-attachment: fixed;
      color: var(--text);
      font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
    }

    .card-shell {
      background: linear-gradient(165deg, rgba(14, 35, 65, 0.94), rgba(10, 23, 43, 0.96));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: 0 14px 34px rgba(2, 8, 23, 0.45);
      padding: 16px;
    }

    .section-title {
      color: #dceafe;
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: 0.01em;
    }

    .section-subtitle {
      margin-top: 5px;
      color: var(--muted);
      font-size: 0.83rem;
      line-height: 1.45;
    }

    .toolbar-grid {
      margin-top: 0;
      display: grid;
      grid-template-columns: 1.3fr 2.5fr;
      gap: 12px;
      align-items: start;
    }

    .action-buttons {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
    }

    .btn {
      border-radius: 10px;
      border: 1px solid transparent;
      padding: 10px 12px;
      font-size: 0.82rem;
      font-weight: 600;
      color: #f4f8ff;
      cursor: pointer;
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease;
      display: inline-flex;
      gap: 8px;
      align-items: center;
      justify-content: center;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.32);
    }

    .btn.primary {
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      border: 1px solid rgba(160,180,210,0.20);
      color: #C8D4E0;
    }

    .btn.soft {
      background: rgba(14,28,48,0.84);
      border-color: rgba(160,180,210,0.18);
    }

    .btn.ghost {
      background: rgba(8,18,32,0.42);
      border-color: rgba(160,180,210,0.14);
    }

    /* ── Estado "Vinculado à matriz" para botões readonly informativos ────
       Usado no botão "Alterar Colunas" quando o pipeline é herdado.
       Não é desabilitado — é informativo (ainda clicável para mostrar info). */
    .btn.is-inherited {
      background: linear-gradient(135deg, rgba(37,99,235,0.18), rgba(37,99,235,0.10)) !important;
      border: 1px solid rgba(96,165,250,0.50) !important;
      color: #93c5fd !important;
      cursor: help !important;
      opacity: 1 !important;
    }
    .btn.is-inherited:hover {
      background: linear-gradient(135deg, rgba(37,99,235,0.28), rgba(37,99,235,0.18)) !important;
      border-color: rgba(96,165,250,0.70) !important;
    }
    html[data-theme="light"] .btn.is-inherited {
      background: linear-gradient(135deg, rgba(37,99,235,0.12), rgba(37,99,235,0.06)) !important;
      border-color: rgba(37,99,235,0.40) !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .btn.is-inherited:hover {
      background: linear-gradient(135deg, rgba(37,99,235,0.20), rgba(37,99,235,0.10)) !important;
    }

    .filters-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }

    .field-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .field-label {
      color: #cadff9;
      font-size: 0.75rem;
      font-weight: 500;
    }

    .field-control {
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.26);
      background: rgba(10, 24, 46, 0.74);
      color: #eef5ff;
      padding: 9px 10px;
      font-size: 0.83rem;
      transition: border-color .18s ease, box-shadow .18s ease;
      width: 100%;
    }

    .field-control:focus {
      outline: none;
      border-color: rgba(160, 180, 210, 0.32);
      box-shadow: 0 0 0 2px rgba(30, 58, 95, 0.25);
    }

    .kpi-grid {
      margin-top: 14px;
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 10px;
    }

    .kpi-card {
      border-radius: 12px;
      border: 1px solid rgba(96, 165, 250, 0.24);
      background: linear-gradient(145deg, rgba(10, 28, 52, 0.94), rgba(8, 20, 39, 0.94));
      padding: 11px;
      min-height: 88px;
    }

    .kpi-label {
      color: #9eb8d5;
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      line-height: 1.3;
    }

    .kpi-value {
      margin-top: 10px;
      color: #eff7ff;
      font-size: 1.28rem;
      font-weight: 700;
      line-height: 1.1;
    }

    .kpi-foot {
      margin-top: 6px;
      color: #7fa0c3;
      font-size: 0.72rem;
    }

    .kanban-wrap {
      margin-top: 14px;
    }

    .board-hint {
      margin-bottom: 8px;
      color: #9ec0e5;
      font-size: 0.76rem;
      min-height: 17px;
    }

    .board {
      display: flex;
      gap: 12px;
      flex-wrap: nowrap;
      align-items: flex-end;
      overflow-x: auto;
      padding-bottom: 14px;
      -webkit-overflow-scrolling: touch;
      transform: rotateX(180deg);
    }

    .kanban-col {
      transform: rotateX(180deg);
      flex: 0 0 350px;
      width: 350px;
      min-width: 280px;
      max-width: 420px;
      border-radius: 12px;
      border: 1px solid rgba(160, 180, 210, 0.10);
      background: linear-gradient(170deg, rgba(10, 23, 43, 0.84), rgba(8, 19, 35, 0.9));
      padding: 12px;
      transition: border-color .18s ease, box-shadow .18s ease;
    }

    .kanban-col.drop-target {
      border-color: rgba(160, 180, 210, 0.28);
      box-shadow: 0 0 0 2px rgba(26, 58, 92, 0.18);
    }

    .col-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
    }

    .col-title {
      color: #e7f3ff;
      font-size: 0.95rem;
      font-weight: 700;
      line-height: 1.25;
      margin: 0;
    }

    .col-pill {
      border-radius: 999px;
      border: 1px solid rgba(96, 165, 250, 0.5);
      background: rgba(15, 43, 84, 0.72);
      color: #cde4ff;
      padding: 2px 9px;
      font-size: 0.74rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .col-subtitle {
      margin-top: 4px;
      color: #8da8c5;
      font-size: 0.73rem;
      min-height: 16px;
    }

    .cardsList {
      margin-top: 10px;
      min-height: 160px;
      border-radius: 10px;
      background: rgba(255, 255, 255, 0.02);
      padding: 8px;
    }

    .empty-note {
      color: #7e9dbc;
      font-size: 0.78rem;
      padding: 8px;
      text-align: center;
    }

    .card-mini {
      position: relative;
      border-radius: 10px;
      border: 1px solid rgba(96, 165, 250, 0.22);
      background: linear-gradient(150deg, rgba(23, 48, 84, 0.96), rgba(17, 33, 64, 0.96));
      padding: 8px 10px 9px;
      margin-bottom: 7px;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.28);
      cursor: default;
      user-select: none;
      -webkit-user-select: none;
      -ms-user-select: none;
      transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    }

    /* ── Faixa de Origem (MATRIZ / FILIAL — Nome) ─────────────────────────
       Mostra a procedência do card pra evitar confusão quando a matriz
       enxerga leads das filiais no mesmo board. Renderizado pelo JS via
       data-origin-tipo + data-origin-nome no <div class="card-mini">. */
    .card-mini[data-origin-tipo] { padding-top: 26px; }      /* abre espaço pra faixa */
    .card-origin-strip {
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 10px;
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
      border-top-left-radius: 9px;
      border-top-right-radius: 9px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .card-origin-strip.is-matriz {
      background: linear-gradient(90deg, rgba(37,99,235,.32), rgba(37,99,235,.16));
      color: #93c5fd;
    }
    .card-origin-strip.is-filial {
      background: linear-gradient(90deg, rgba(168,85,247,.32), rgba(168,85,247,.14));
      color: #d8b4fe;
    }
    .card-origin-strip .org-name {
      font-weight: 600;
      text-transform: none;
      letter-spacing: 0;
      opacity: .9;
      max-width: 60%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* Tema claro: contraste forte sem perder identidade da cor */
    html[data-theme="light"] .card-origin-strip.is-matriz {
      background: linear-gradient(90deg, rgba(37,99,235,.22), rgba(37,99,235,.10));
      color: #1e40af;
    }
    html[data-theme="light"] .card-origin-strip.is-filial {
      background: linear-gradient(90deg, rgba(126,34,206,.22), rgba(126,34,206,.10));
      color: #6b21a8;
    }

    .card-drag-handle {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 0 0 5px;
      cursor: grab;
      opacity: 0.25;
      transition: opacity .15s ease;
    }

    .card-drag-handle:hover {
      opacity: 0.7;
    }

    .card-drag-handle svg {
      width: 16px;
      height: 8px;
      fill: #93c5fd;
    }

    .card-mini:active .card-drag-handle,
    .card-drag-handle:active {
      cursor: grabbing;
    }

    .sortable-ghost {
      opacity: 0.4;
      background: rgba(59, 130, 246, 0.15) !important;
      border: 2px dashed rgba(59, 130, 246, 0.6) !important;
    }

    .sortable-drag {
      opacity: 1 !important;
      box-shadow: 0 20px 40px rgba(0,0,0,0.5) !important;
      transform: rotate(2deg) scale(1.02) !important;
    }

    .card-mini:hover {
      transform: translateY(-2px);
      border-color: rgba(96, 165, 250, 0.58);
      box-shadow: 0 14px 30px rgba(15, 23, 42, 0.45);
    }

    .card-mini.is-overdue {
      border-color: rgba(176, 96, 112, 0.40);
      box-shadow: none;
    }

    .card-head {
      display: flex;
      justify-content: space-between;
      gap: 6px;
      align-items: center;
      margin-bottom: 2px;
    }

    .card-client {
      color: #f4f9ff;
      font-size: 0.85rem;
      font-weight: 700;
      line-height: 1.2;
    }

    .card-company {
      color: #7a9cbe;
      font-size: 0.7rem;
      line-height: 1.2;
      margin-bottom: 2px;
    }

    .badges {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 4px;
    }

    .badge {
      border-radius: 999px;
      padding: 2px 7px;
      font-size: 0.67rem;
      font-weight: 700;
      letter-spacing: 0.01em;
      border: 1px solid transparent;
      white-space: nowrap;
    }

    .badge-hot {
      background: rgba(58, 16, 32, 0.60);
      color: #B06070;
      border-color: rgba(176, 96, 112, 0.25);
    }

    .badge-warm {
      background: rgba(61, 48, 16, 0.60);
      color: #C4A040;
      border-color: rgba(196, 160, 64, 0.25);
    }

    .badge-cold {
      background: rgba(14, 40, 69, 0.60);
      color: #6898C0;
      border-color: rgba(104, 152, 192, 0.25);
    }

    .badge-urgent {
      background: rgba(58, 16, 32, 0.60);
      color: #B06070;
      border-color: rgba(176, 96, 112, 0.25);
    }

    .badge-waiting {
      background: rgba(30, 40, 55, 0.70);
      color: #6B7887;
      border-color: rgba(107, 120, 135, 0.20);
    }

    .progress-container {
      height: 4px;
      border-radius: 999px;
      background: rgba(148, 163, 184, 0.2);
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #1A3A5C, #3D6A96);
      width: 0;
      transition: width .25s ease;
    }

    .card-meta {
      margin-top: 5px;
      display: flex;
      flex-wrap: wrap;
      gap: 3px 10px;
      font-size: 0.69rem;
      color: #9ab8d5;
      line-height: 1.3;
    }

    .card-meta strong {
      color: #e8f4ff;
      font-weight: 600;
    }

    .meta-strong {
      color: #f2f8ff;
      font-weight: 600;
    }

    .meta-overdue {
      color: #B06070;
      font-weight: 700;
    }

    .meta-fechado {
      color: #7ABDA0;
      font-weight: 600;
    }

    .card-actions {
      margin-top: 7px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 6px;
    }

    .wa-btn {
      border-radius: 8px;
      border: 1px solid rgba(122, 189, 160, 0.25);
      background: rgba(30, 74, 58, 0.40);
      color: #7ABDA0;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 5px 8px;
      cursor: pointer;
      transition: background .18s ease, border-color .18s ease;
    }

    .wa-btn:hover {
      background: rgba(30, 74, 58, 0.60);
      border-color: rgba(122, 189, 160, 0.40);
    }

    .chat-link-btn {
      display: inline-flex; align-items: center; gap: 4px;
      border-radius: 8px;
      border: 1px solid rgba(126,184,247,.28);
      background: rgba(15,40,80,.45);
      color: #7EB8F7;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 5px 8px;
      cursor: pointer;
      text-decoration: none;
      transition: background .18s ease, border-color .18s ease;
    }
    .chat-link-btn:hover {
      background: rgba(15,40,80,.7);
      border-color: rgba(126,184,247,.48);
    }
    .chat-link-btn svg { flex-shrink: 0; }

    .wa-btn.disabled {
      cursor: not-allowed;
      border-color: rgba(148, 163, 184, 0.32);
      background: rgba(71, 85, 105, 0.2);
      color: #a4b7ce;
    }

    .open-hint {
      color: #8fb0d2;
      font-size: 0.68rem;
    }

    /* Make icons inside cards steel blue to match card text */
    .card-mini svg,
    .card-mini .icon svg {
      stroke: #6B8DAA !important;
      fill: #6B8DAA !important;
      color: #6B8DAA !important;
    }

    /* Apply a light-blue calendar button only for the two date fields in Bloco 3 */
    .date-with-icon { position: relative; }
    .date-with-icon .form-input { padding-right: 44px; }

    .calendar-btn {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      width: 34px;
      height: 34px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: transparent;
      border: 0;
      cursor: pointer;
      color: #60a5fa;
      border-radius: 6px;
      padding: 0;
    }

    .calendar-btn svg { width:18px; height:18px; stroke: currentColor; }

    /* Hide native indicator visually but keep clicks working on the input */
    input[name="data_prevista_fechamento"]::-webkit-calendar-picker-indicator,
    input[name="data_fechamento"]::-webkit-calendar-picker-indicator {
      opacity: 0;
      pointer-events: none;
    }

    .modal-shell {
      position: fixed;
      inset: 0;
      background: rgba(2, 6, 23, 0.65);
      backdrop-filter: blur(3px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }

    .modal-shell.hidden {
      display: none;
    }

    .modal-panel {
      position: relative;
      width: min(860px, 96vw);
      max-height: calc(100vh - 48px);
      display: flex;
      flex-direction: column;
      background: linear-gradient(165deg, rgba(15, 34, 61, 0.98), rgba(8, 20, 39, 0.98));
      border: 1px solid rgba(96, 165, 250, 0.32);
      border-radius: 16px;
      box-shadow: 0 22px 48px rgba(2, 6, 23, 0.62);
      overflow: hidden;
    }

    .modal-header {
      border-bottom: 1px solid rgba(148, 163, 184, 0.2);
      padding: 14px 16px;
    }

    .modal-title {
      color: #e7f2ff;
      font-size: 1.02rem;
      font-weight: 700;
    }

    .modal-subtitle {
      margin-top: 4px;
      color: #9bb5d2;
      font-size: 0.78rem;
    }

    .modal-form {
      display: flex;
      flex-direction: column;
      min-height: 0;
      flex: 1;
    }

    .modal-body {
      overflow: auto;
      padding: 14px 16px 104px;
      min-height: 0;
      -webkit-overflow-scrolling: touch;
    }

    .form-section {
      border-radius: 12px;
      border: 1px solid rgba(96, 165, 250, 0.24);
      background: rgba(9, 24, 46, 0.62);
      padding: 12px;
      margin-bottom: 12px;
    }

    .form-section-title {
      color: #dbeafe;
      font-size: 0.86rem;
      font-weight: 700;
      margin-bottom: 9px;
      letter-spacing: 0.01em;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 9px;
    }

    .form-grid.three {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .form-label {
      color: #c8def8;
      font-size: 0.74rem;
      font-weight: 600;
    }

    .form-input,
    .form-textarea,
    .form-select {
      width: 100%;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.3);
      background: rgba(7, 18, 35, 0.76);
      color: #edf6ff;
      padding: 9px 10px;
      font-size: 0.83rem;
    }

    .form-input:focus,
    .form-textarea:focus,
    .form-select:focus {
      outline: none;
      border-color: rgba(96, 165, 250, 0.64);
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
    }

    .form-textarea {
      min-height: 96px;
      resize: vertical;
      line-height: 1.4;
    }

    .checklist-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 8px;
    }

    .checklist-progress {
      color: #9fc1e4;
      font-size: 0.74rem;
      font-weight: 600;
    }

    .checklist-items {
      margin-top: 8px;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: rgba(5, 14, 27, 0.45);
      max-height: 260px;
      overflow: auto;
    }

    .check-item {
      display: grid;
      grid-template-columns: auto 1fr auto auto;
      gap: 8px;
      align-items: center;
      padding: 8px;
      border-bottom: 1px solid rgba(148, 163, 184, 0.16);
      font-size: 0.8rem;
    }

    .check-item:last-child {
      border-bottom: 0;
    }

    .check-handle {
      cursor: grab;
      color: #9ab8d8;
      user-select: none;
      font-size: 0.95rem;
      line-height: 1;
      padding: 0 3px;
    }

    .check-text {
      color: #e2eeff;
      cursor: text;
      line-height: 1.3;
    }

    .priority {
      border-radius: 999px;
      padding: 2px 7px;
      font-size: 0.67rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .priority-high {
      color: #ffd1d1;
      background: rgba(239, 68, 68, 0.2);
      border: 1px solid rgba(239, 68, 68, 0.46);
    }

    .priority-medium {
      color: #ffe9bf;
      background: rgba(245, 158, 11, 0.18);
      border: 1px solid rgba(245, 158, 11, 0.4);
    }

    .priority-low {
      color: #cae7ff;
      background: rgba(59, 130, 246, 0.18);
      border: 1px solid rgba(96, 165, 250, 0.4);
    }

    .check-remove {
      color: #ffb4b4;
      font-size: 0.73rem;
      background: transparent;
      border: 0;
      cursor: pointer;
    }

    .timeline {
      margin-top: 6px;
      border-left: 2px solid rgba(96, 165, 250, 0.36);
      padding-left: 12px;
      max-height: 260px;
      overflow: auto;
    }

    .timeline-item {
      position: relative;
      margin-bottom: 10px;
      padding-bottom: 8px;
      border-bottom: 1px dashed rgba(148, 163, 184, 0.26);
    }

    .timeline-item:last-child {
      border-bottom: 0;
      margin-bottom: 0;
    }

    .timeline-item::before {
      content: '';
      position: absolute;
      left: -17px;
      top: 3px;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #60a5fa;
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }

    .timeline-title {
      color: #e5f0ff;
      font-size: 0.76rem;
      font-weight: 600;
      line-height: 1.35;
    }

    .timeline-meta {
      margin-top: 3px;
      color: #a8c2df;
      font-size: 0.71rem;
    }

    .modal-footer {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      justify-content: center;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
      padding: 12px;
      border-top: 1px solid rgba(148, 163, 184, 0.2);
      background: linear-gradient(180deg, rgba(10, 22, 42, 0.94), rgba(6, 16, 30, 0.94));
      z-index: 3;
    }

    .columns-row {
      user-select: none;
      -webkit-user-select: none;
      border: 1px solid rgba(96, 165, 250, 0.24);
      border-radius: 10px;
      background: rgba(8, 22, 40, 0.72);
      padding: 9px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .drag-handle {
      cursor: grab;
      color: #b8d2ef;
      user-select: none;
      -webkit-user-select: none;
      font-size: 0.95rem;
      line-height: 1;
      padding: 0 3px;
    }

    @media (max-width: 1400px) {
      .kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 1200px) {
      .toolbar-grid {
        grid-template-columns: 1fr;
      }

      .filters-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 980px) {
      .form-grid,
      .form-grid.three {
        grid-template-columns: 1fr;
      }

      .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .action-buttons {
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width: 680px) {
      .filters-grid {
        grid-template-columns: 1fr;
      }

      .kpi-grid {
        grid-template-columns: 1fr;
      }

      .action-buttons {
        grid-template-columns: 1fr;
      }

      .modal-footer {
        justify-content: stretch;
      }

      .modal-footer .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <main class="w-full px-6 py-6">
    <div class="page-layout">

      <!-- ── Sidebar ── -->
      <?php include __DIR__ . '/includes/sidebar.php'; ?>

      <section class="main-content">
        <div class="space-y-4">
          <!-- Header — padrão .page-header (yuris-theme.css) -->
          <div class="card-shell page-header">
            <div class="page-header-inner">
              <div class="page-header-text">
                <h2 class="page-header-title">Central de Prospecção Jurídica</h2>
                <p class="page-header-subtitle">Gestão comercial em formato Kanban para escritórios de advocacia, com foco em previsibilidade e produtividade.</p>
              </div>
            </div>
          </div>

          <div class="card-shell">
            <div class="toolbar-grid">
              <div class="action-buttons">
                <button id="btnNewCard" class="btn primary" type="button">＋ Novo Cliente</button>
                <button id="btnRefresh" class="btn soft" type="button">↻ Atualizar</button>
                <button id="btnColumns" class="btn soft" type="button"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06A2 2 0 1 1 2.27 17.8l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09c.7 0 1.27-.43 1.51-1a1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.3 2.27l.06.06c.5.5 1.2.75 1.82.33A1.65 1.65 0 0 0 9.69 1.5 1.65 1.65 0 0 0 9.7 1H12a2 2 0 1 1 0 4h-.09c-.7 0-1.27.43-1.51 1a1.65 1.65 0 0 0 .33 1.82l.06.06A2 2 0 1 1 17.73 6.2l-.06.06c-.5.5-.75 1.2-.33 1.82.32.56.32 1.28.32 1.82V12a2 2 0 1 1 4 0v.09c0 .7.43 1.27 1 1.51z"/></svg> Alterar Colunas</button>
                <button id="btnToggleFilters" class="btn ghost" type="button">⌕ Filtros</button>
              </div>

              <div id="filtersPanel" class="filters-grid">
                <label class="field-group">
                  <span class="field-label">Busca rápida (cliente/empresa)</span>
                  <input id="filterSearch" class="field-control" type="text" placeholder="Digite um nome ou empresa">
                </label>
                <label class="field-group">
                  <span class="field-label">Responsável</span>
                  <select id="filterResponsible" class="field-control">
                    <option value="">Todos os responsáveis</option>
                    <?= UserOptions::renderGrouped($users, null, null) ?>
                  </select>
                </label>
                <label class="field-group">
                  <span class="field-label">Etapa</span>
                  <select id="filterStage" class="field-control">
                    <option value="">Todas as etapas</option>
                    <?php foreach ($columns as $col): ?>
                      <option value="<?=htmlspecialchars((string)$col['id'])?>"><?=htmlspecialchars(column_display_name($col))?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="field-group">
                  <span class="field-label">Data prevista</span>
                  <input id="filterDate" class="field-control" type="date">
                </label>
                <?php if (count($origin_accounts) > 1): /* só matriz com pelo menos 1 filial */ ?>
                <label class="field-group">
                  <span class="field-label">Origem</span>
                  <select id="filterOrigin" class="field-control">
                    <option value="">Todas as origens</option>
                    <option value="__matriz__">Apenas Matriz</option>
                    <option value="__filiais__">Apenas Filiais</option>
                    <optgroup label="Filial específica">
                      <?php foreach ($origin_accounts as $oa): if ($oa['tipo'] === 'matriz') continue; ?>
                        <option value="<?=htmlspecialchars((string)$oa['id'])?>"><?=htmlspecialchars($oa['nome'])?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  </select>
                </label>
                <?php endif; ?>
              </div>
            </div>

            <!-- KPI cards removed as requested -->
          </div>
          </div>

          <div class="card-shell kanban-wrap">
            <div class="board-hint" id="boardHint"></div>
            <div class="board" id="board">
              <?php foreach ($columns as $col): ?>
                <?php $displayName = column_display_name($col); ?>
                <div class="kanban-col" data-coluna-id="<?=htmlspecialchars((string)$col['id'])?>" data-coluna-nome="<?=htmlspecialchars((string)$col['nome'])?>" data-coluna-slug="<?=htmlspecialchars((string)($col['slug'] ?? ''))?>" data-coluna-fechado="<?=htmlspecialchars((string)($col['conta_fechado'] ?? 0))?>">
                  <div class="col-head">
                    <h3 class="col-title" style="color:<?=htmlspecialchars((string)$col['cor'])?>"><?=htmlspecialchars($displayName)?></h3>
                    <span class="col-pill" id="col-count-<?=htmlspecialchars((string)$col['id'])?>">0</span>
                  </div>
                  <p class="col-subtitle"><?=htmlspecialchars((string)$col['nome'])?></p>
                  <div class="cardsList" id="col-<?=htmlspecialchars((string)$col['id'])?>"></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <div id="modalCreate" class="modal-shell hidden">
    <div class="modal-panel">
      <div class="modal-header">
        <div class="modal-title">Novo Lead Jurídico</div>
        <div class="modal-subtitle">Cadastre os dados principais para iniciar o atendimento comercial.</div>
      </div>
      <form id="createForm" class="modal-form">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
        <div class="modal-body">
          <div class="form-section">
            <div class="form-section-title">Dados principais</div>
            <div class="form-grid">
              <label class="form-group">
                <span class="form-label">Cliente</span>
                <input name="cliente_nome" class="form-input" required>
              </label>
              <label class="form-group">
                <span class="form-label">Empresa / origem</span>
                <input name="empresa_nome" class="form-input">
              </label>
              <label class="form-group">
                <span class="form-label">WhatsApp</span>
                <input name="telefone_whatsapp" class="form-input" placeholder="551199999999">
              </label>
              <label class="form-group">
                <span class="form-label">Responsável</span>
                <select name="responsavel_user_id" class="form-select">
                  <?= UserOptions::renderGrouped($users, $_SESSION['user_id'] ?? null, '— Selecionar —') ?>
                </select>
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Comercial</div>
            <div class="form-grid three">
              <label class="form-group">
                <span class="form-label">Valor estimado</span>
                <input name="valor_estimado" class="form-input" placeholder="R$ 1.000,00">
              </label>
              <label class="form-group">
                <span class="form-label">Etapa atual</span>
                <select name="coluna_id" id="createColunaId" class="form-select">
                  <?php foreach ($columns as $col): ?>
                    <option value="<?=htmlspecialchars((string)$col['id'])?>"><?=htmlspecialchars(column_display_name($col))?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="form-group date-with-icon">
                <span class="form-label">Data de Início</span>
                <div style="position:relative">
                  <input name="data_prevista_fechamento" type="date" class="form-input">
                  <button type="button" class="calendar-btn" aria-label="Abrir calendário" data-target="data_prevista_fechamento">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4"></path></svg>
                  </button>
                </div>
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Observações</div>
            <label class="form-group">
              <span class="form-label">Descrição comercial</span>
              <textarea name="descricao" class="form-textarea" placeholder="Contexto do lead, objetivos, próximos passos..."></textarea>
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" id="cancelCreate" class="btn ghost">Cancelar</button>
          <button type="submit" class="btn primary">Salvar Lead</button>
        </div>
      </form>
    </div>
  </div>

  <div id="modalEdit" class="modal-shell hidden">
    <div class="modal-panel">
      <div class="modal-header">
        <div class="modal-title">Gestão Completa do Lead</div>
        <div class="modal-subtitle">Atualize dados comerciais, operação e histórico com rastreabilidade.</div>
      </div>
      <form id="editForm" class="modal-form">
        <input type="hidden" name="id">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
        <textarea id="descricaoHidden" name="descricao" class="hidden"></textarea>

        <div class="modal-body">
          <div class="form-section">
            <div class="form-section-title">Bloco 1 — Dados principais</div>
            <div class="form-grid">
              <label class="form-group">
                <span class="form-label">Cliente</span>
                <input name="cliente_nome" class="form-input" required>
              </label>
              <label class="form-group">
                <span class="form-label">Empresa / origem</span>
                <input name="empresa_nome" class="form-input">
              </label>
              <label class="form-group">
                <span class="form-label">WhatsApp</span>
                <input name="telefone_whatsapp" class="form-input" placeholder="551199999999">
              </label>
              <label class="form-group">
                <span class="form-label">Responsável</span>
                <select name="responsavel_user_id" class="form-select">
                  <?= UserOptions::renderGrouped($users, null, '— Selecionar —') ?>
                </select>
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Bloco 2 — Comercial</div>
            <div class="form-grid three">
              <label class="form-group">
                <span class="form-label">Valor estimado</span>
                <input name="valor_estimado" class="form-input">
              </label>
              <label class="form-group">
                <span class="form-label">Valor proposta</span>
                <input name="valor_proposta" class="form-input">
              </label>
              <label class="form-group">
                <span class="form-label">Valor fechado</span>
                <input name="valor_fechado_final" class="form-input">
              </label>
              <label class="form-group" style="grid-column: span 3;">
                <span class="form-label">Etapa atual</span>
                <select name="coluna_id" id="editColunaId" class="form-select">
                  <?php foreach ($columns as $col): ?>
                    <option value="<?=htmlspecialchars((string)$col['id'])?>"><?=htmlspecialchars(column_display_name($col))?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Bloco 3 — Datas</div>
            <div class="form-grid">
              <label class="form-group date-with-icon">
                <span class="form-label">Data de Início</span>
                <div style="position:relative">
                  <input name="data_prevista_fechamento" type="date" class="form-input">
                  <button type="button" class="calendar-btn" aria-label="Abrir calendário" data-target="data_prevista_fechamento">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4"></path></svg>
                  </button>
                </div>
              </label>
              <label class="form-group date-with-icon">
                <span class="form-label">Fechamento real</span>
                <div style="position:relative">
                  <input name="data_fechamento" type="date" class="form-input">
                  <button type="button" class="calendar-btn" aria-label="Abrir calendário" data-target="data_fechamento">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4"></path></svg>
                  </button>
                </div>
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Bloco 4 — Observações</div>
            <div class="form-grid">
              <label class="form-group">
                <span class="form-label">Descrição</span>
                <textarea id="editDescricaoMain" class="form-textarea" placeholder="Contexto, objeções, escopo e próximos passos."></textarea>
              </label>
              <label class="form-group">
                <span class="form-label">Observações estratégicas</span>
                <textarea id="editDescricaoNotes" class="form-textarea" placeholder="Estratégia de abordagem, riscos, direcionamento jurídico."></textarea>
              </label>
            </div>
          </div>

          <div class="form-section" id="processosClienteSection">
            <div class="form-section-title">Processos do Cliente</div>

            <div id="processosClienteList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px">
              <div style="color:#9ab0c9;font-size:.82rem">Carregando processos...</div>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
              <button type="button" id="btnVincularProcesso" style="padding:8px 14px;border-radius:8px;background:rgba(37,99,235,.2);border:1px solid rgba(96,165,250,.3);color:#93c5fd;cursor:pointer;font-size:.82rem">Vincular processo existente</button>
              <button type="button" id="btnCriarProcessoCliente" style="padding:8px 14px;border-radius:8px;background:transparent;border:1px solid rgba(96,165,250,.2);color:#9ab0c9;cursor:pointer;font-size:.82rem">+ Criar novo processo para este cliente</button>
            </div>
          </div>

          <div class="form-section" id="chatVinculoSection">
            <div class="form-section-title">Conversa WhatsApp</div>
            <div id="chatVinculoCard"></div>
          </div>
        </div>

        <!-- Modal de busca e vínculo de processo (fora do form para não colidir) -->
        <div id="modalVincularProcesso" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,.8);z-index:3000;align-items:flex-start;justify-content:center;padding:40px 16px">
          <div style="background:linear-gradient(165deg,rgba(10,24,46,.99),rgba(7,18,36,.99));border:1px solid rgba(96,165,250,.25);border-radius:14px;width:580px;max-width:98vw;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.8)">
            <div style="padding:18px 20px;border-bottom:1px solid rgba(96,165,250,.12);display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
              <span style="font-size:1rem;font-weight:700;color:#dbeafe">Vincular Processo ao Cliente</span>
              <button type="button" id="btnFecharVincularProcesso" style="background:transparent;border:1px solid rgba(96,165,250,.3);color:#93c5fd;border-radius:8px;padding:4px 12px;cursor:pointer;font-size:.82rem"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Fechar</button>
            </div>
            <div style="padding:14px 20px;flex-shrink:0">
              <input id="processoSearchInput" type="text" placeholder="Buscar por número, cliente ou tipo de ação..." autocomplete="off"
                     style="width:100%;padding:10px 12px;border-radius:8px;background:rgba(5,18,39,.85);border:1px solid rgba(96,165,250,.2);color:#d6eaff;font-size:.85rem;box-sizing:border-box">
            </div>
            <div id="processoListaModal" style="overflow-y:auto;flex:1;padding:0 12px 12px"></div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" id="cancelEdit" class="btn ghost">Cancelar</button>
          <button type="button" id="openWhatsapp" class="btn soft">WhatsApp</button>
          <button type="submit" id="saveCard" class="btn primary">Salvar</button>
          <button type="button" id="deleteCard" class="btn soft" style="background:rgba(239,68,68,0.16); border-color:rgba(239,68,68,0.45); color:#ffcccc;">Excluir</button>
        </div>
      </form>
    </div>
  </div>

  <div id="modalColumns" class="modal-shell hidden">
    <div class="modal-panel" style="width:min(760px, 96vw);">
      <div class="modal-header">
        <div class="modal-title">Gerenciar Etapas do Funil</div>
        <div class="modal-subtitle">Arraste para ordenar, ajuste nome/cor e salve o pipeline comercial.</div>
      </div>
      <div class="modal-form">
        <div class="modal-body">
          <div class="flex items-center gap-2 flex-wrap">
            <button id="addColumnBtn" type="button" class="btn soft">＋ Nova Coluna</button>
            <span class="text-xs text-blue-200">Reordene pelas alças laterais para refletir o processo comercial.</span>
          </div>
          <div id="columnsList" class="mt-3 space-y-2"></div>
        </div>
        <div class="modal-footer">
          <button type="button" id="cancelColumns" class="btn ghost">Cancelar</button>
          <button type="button" id="saveColumns" class="btn primary">Salvar Colunas</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    const apiCards = '/sistema_vendas/public/api/cards.php';
    const csrf = '<?=htmlspecialchars($csrf)?>';
    const usersMap = <?=json_encode($usersMap, JSON_UNESCAPED_UNICODE)?>;
    let columnsCache = <?=json_encode($columns, JSON_UNESCAPED_UNICODE)?>;

    // Identifica origem do registro (matriz/filial) — controla:
    // 1) faixa visual no topo do card (renderCards) — SEMPRE visível, mesmo
    //    em filial isolada ou matriz sem filiais (decisão produto/UX)
    // 2) filtro "Origem" visível só pra matriz com filiais vinculadas
    window.YURIS_ACCOUNT_SELF       = <?=json_encode($origin_self, JSON_UNESCAPED_UNICODE)?>;
    window.YURIS_ORIGIN_ACCOUNTS    = <?=json_encode($origin_accounts, JSON_UNESCAPED_UNICODE)?>;
    window.YURIS_SHOW_ORIGIN_STRIP  = true;   // faixa sempre visível

    const cardsCacheByColumn = {};
    const sortableByColumn = {};
    let columnsSortable = null;
    let checklistSortable = null;
    let _justDragged = false;

    function byId(id) { return document.getElementById(id); }

    function normalizeText(s) {
      return String(s || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
    }

    function stageDisplayName(column) {
      const slug = normalizeText(column.slug || '');
      const nome = normalizeText(column.nome || '');
      const base = slug || nome;
      if (base.includes('prospec') || base.includes('lead')) return 'Leads em atendimento';
      if (base.includes('proposta')) return 'Proposta enviada';
      if (base.includes('negoci')) return 'Negociação jurídica';
      if (base.includes('fechado') || Number(column.conta_fechado || 0) === 1) return 'Contrato fechado';
      return column.nome || 'Etapa comercial';
    }

    function isClosedColumn(column) {
      if (!column) return false;
      const base = normalizeText((column.slug || '') + ' ' + (column.nome || ''));
      return base.includes('fechado') || Number(column.conta_fechado || 0) === 1;
    }

    function isNegotiationColumn(column) {
      if (!column) return false;
      const base = normalizeText((column.slug || '') + ' ' + (column.nome || ''));
      return base.includes('negoci');
    }

    function isProposalColumn(column) {
      if (!column) return false;
      const base = normalizeText((column.slug || '') + ' ' + (column.nome || ''));
      return base.includes('proposta');
    }

    function toNumber(v) {
      if (typeof v === 'number') return v;
      if (v === null || v === undefined || v === '') return 0;
      const raw = String(v).replace(/\s/g, '').replace('R$', '');
      const normalized = raw.includes(',') ? raw.replace(/\./g, '').replace(',', '.') : raw;
      const n = Number(normalized);
      return Number.isFinite(n) ? n : 0;
    }

    function formatMoney(v) {
      return toNumber(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function formatNumber(v) {
      return Math.round(toNumber(v)).toLocaleString('pt-BR');
    }

    function formatDate(dateValue) {
      if (!dateValue) return '—';
      const d = new Date(dateValue + 'T00:00:00');
      if (Number.isNaN(d.getTime())) return dateValue;
      return d.toLocaleDateString('pt-BR');
    }

    function formatDateTime(dateTimeValue) {
      if (!dateTimeValue) return '—';
      const d = new Date(dateTimeValue.replace(' ', 'T'));
      if (Number.isNaN(d.getTime())) return dateTimeValue;
      return d.toLocaleString('pt-BR');
    }

    function escapeHtml(s) {
      return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function getColumnById(colId) {
      return (columnsCache || []).find(c => String(c.id) === String(colId)) || null;
    }

    function splitDescricao(raw) {
      const marker = '[OBSERVACOES_ESTRATEGICAS]';
      const text = String(raw || '');
      if (!text.includes(marker)) {
        return { main: text.trim(), notes: '' };
      }
      const parts = text.split(marker);
      return {
        main: (parts.shift() || '').trim(),
        notes: parts.join(marker).trim(),
      };
    }

    function composeDescricao(main, notes) {
      const core = String(main || '').trim();
      const obs = String(notes || '').trim();
      if (!obs) return core;
      return core + '\n\n[OBSERVACOES_ESTRATEGICAS]\n' + obs;
    }

    function businessDaysUntil(dateValue) {
      if (!dateValue) return null;
      const due = new Date(dateValue + 'T00:00:00');
      const now = new Date();
      const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      if (Number.isNaN(due.getTime())) return null;
      const diff = due.getTime() - today.getTime();
      return Math.floor(diff / 86400000);
    }

    function getTemperatureBadge(card, checklistPct) {
      let score = 0;
      if (toNumber(card.valor_proposta) >= 15000 || toNumber(card.valor_estimado) >= 20000) score += 2;
      if (checklistPct >= 70) score += 2;
      else if (checklistPct >= 35) score += 1;
      const days = businessDaysUntil(card.data_prevista_fechamento);
      if (days !== null && days <= 5) score += 1;

      if (score >= 3) return { label: 'Quente', cls: 'badge-hot' };
      if (score >= 1) return { label: 'Morno', cls: 'badge-warm' };
      return { label: 'Frio', cls: 'badge-cold' };
    }

    function getFollowUpBadge(card, column, checklistPct) {
      const days = businessDaysUntil(card.data_prevista_fechamento);
      if (days !== null && days <= 2 && !isClosedColumn(column)) {
        return { label: 'Urgente', cls: 'badge-urgent' };
      }
      if ((isProposalColumn(column) || isNegotiationColumn(column)) && checklistPct < 65) {
        return { label: 'Aguardando retorno', cls: 'badge-waiting' };
      }
      return null;
    }

    function updateBoardHint() {
      const hint = byId('boardHint');
      if (!hint) return;
      if (isFilterActive()) {
        hint.textContent = 'Filtros ativos: desative os filtros para movimentar cards entre etapas.';
      } else {
        hint.textContent = 'Arraste os cards entre etapas para refletir o avanço comercial.';
      }
    }

    function setColCount(colId, filtered, total) {
      const el = byId('col-count-' + colId);
      if (!el) return;
      el.textContent = filtered + '/' + total;
    }

    function getChecklistPct(card) {
      const pct = toNumber(card.checklist_percentual);
      return Math.max(0, Math.min(100, pct));
    }

    function renderCards(colId, cards) {
      const listEl = byId('col-' + colId);
      if (!listEl) return;
      listEl.innerHTML = '';

      if (!cards.length) {
        listEl.innerHTML = '<div class="empty-note">Sem leads nesta etapa</div>';
        return;
      }

      const column = getColumnById(colId);

      cards.forEach(card => {
        const div = document.createElement('div');
        const checklistPct = getChecklistPct(card);
        const temp = getTemperatureBadge(card, checklistPct);
        const follow = getFollowUpBadge(card, column, checklistPct);
        const valorEstimado = toNumber(card.valor_estimado);
        const valorFechado = toNumber(card.valor_fechado_final);
        const _df = String(card.data_fechamento || '').trim();
        const hasFechamento = _df.length > 0 && _df !== '0000-00-00' && _df !== '0000-00' && !_df.startsWith('0000');
        const valorExibido = hasFechamento && valorFechado > 0 ? valorFechado : valorEstimado;
        const responsavel = usersMap[String(card.responsavel_user_id || '')] || 'Não definido';
        const hasWhatsapp = String(card.telefone_whatsapp || '').replace(/\D/g, '').length >= 10;
        const dueDays = businessDaysUntil(card.data_prevista_fechamento);
        const isOverdue = dueDays !== null && dueDays < 0 && !isClosedColumn(column);
        const forecastLabel = hasFechamento && card.data_fechamento
          ? '<span class="meta-fechado">Fechado: <strong>' + formatDate(card.data_fechamento) + '</strong></span>'
          : (card.data_prevista_fechamento
            ? (isOverdue ? '<span class="meta-overdue">Atrasado: <strong>' + formatDate(card.data_prevista_fechamento) + '</strong></span>' : '<span>Previsto: <strong>' + formatDate(card.data_prevista_fechamento) + '</strong></span>')
            : '<span>Previsto: <strong>—</strong></span>');

        div.className = 'card-mini' + (isOverdue ? ' is-overdue' : '');
        div.setAttribute('data-id', card.id);
        div.setAttribute('data-coluna-id', colId);

        // Faixa de Origem — só renderiza quando o cliente é matriz com filiais
        // (mostrar pra filial isolada seria ruído). Sessão MATRIZ vê MATRIZ/FILIAL.
        let originStripHtml = '';
        const originTipo = String(card.origin_account_tipo || '').toLowerCase();
        const originNome = String(card.origin_account_nome || '');
        if (window.YURIS_SHOW_ORIGIN_STRIP && originTipo) {
          div.setAttribute('data-origin-tipo', originTipo);
          div.setAttribute('data-origin-id',   String(card.origin_account_id || ''));
          const cls   = originTipo === 'matriz' ? 'is-matriz' : 'is-filial';
          const label = originTipo === 'matriz' ? 'MATRIZ' : 'FILIAL';
          originStripHtml =
            '<div class="card-origin-strip ' + cls + '">' +
              '<span class="org-label">' + label + '</span>' +
              '<span class="org-name" title="' + escapeHtml(originNome) + '">' + escapeHtml(originNome) + '</span>' +
            '</div>';
        }

        const badgeHtml = [
          '<span class="badge ' + temp.cls + '">' + temp.label + '</span>',
          follow ? '<span class="badge ' + follow.cls + '">' + follow.label + '</span>' : ''
        ].join('');

        div.innerHTML =
          originStripHtml +
          '<div class="card-drag-handle">' +
            '<svg viewBox="0 0 18 10" xmlns="http://www.w3.org/2000/svg"><rect y="0" width="18" height="2" rx="1"/><rect y="4" width="18" height="2" rx="1"/><rect y="8" width="18" height="2" rx="1"/></svg>' +
          '</div>' +
          '<div class="card-head">' +
            '<div class="card-client">' + escapeHtml(card.cliente_nome || 'Lead sem nome') + '</div>' +
            '<div class="badges">' + badgeHtml + '</div>' +
          '</div>' +
          '<div class="card-company">' + escapeHtml(card.empresa_nome || 'Origem não informada') + '</div>' +
          '<div class="progress-container" style="margin:6px 0 4px"><div class="progress-fill" style="width:' + checklistPct + '%"></div></div>' +
          '<div class="card-meta">' +
            forecastLabel +
            '<span>Valor: <strong>' + formatMoney(valorExibido) + '</strong></span>' +
            '<span>Resp.: <strong>' + escapeHtml(responsavel) + '</strong></span>' +
          '</div>' +
          '<div class="card-actions">' +
            '<button type="button" class="wa-btn js-whatsapp ' + (hasWhatsapp ? '' : 'disabled') + '" data-phone="' + escapeHtml(card.telefone_whatsapp || '') + '">' + (hasWhatsapp ? 'WhatsApp' : 'Sem WhatsApp') + '</button>' +
            (card.linked_chat_jid
              ? '<a href="/sistema_vendas/public/chat.php?jid=' + encodeURIComponent(card.linked_chat_jid) + '" class="chat-link-btn" title="Abrir conversa no Chat" onclick="event.stopPropagation()">' +
                  '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.38 1.27 4.79L2.05 22l5.38-1.37c1.37.74 2.93 1.16 4.61 1.16 5.45 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.13-2.9-7A9.83 9.83 0 0 0 12.04 2z"/></svg>' +
                  'Chat</a>'
              : '') +
            '<span class="open-hint">Clique para abrir</span>' +
          '</div>';

        listEl.appendChild(div);
      });
    }

    function isFilterActive() {
      const orig = byId('filterOrigin');
      return Boolean(
        byId('filterSearch').value.trim() ||
        byId('filterResponsible').value ||
        byId('filterStage').value ||
        byId('filterDate').value ||
        (orig && orig.value)
      );
    }

    function matchesFilters(card, colId) {
      const search      = normalizeText(byId('filterSearch').value.trim());
      const responsible = byId('filterResponsible').value;
      const stage       = byId('filterStage').value;
      const date        = byId('filterDate').value;
      const origEl      = byId('filterOrigin');
      const origin      = origEl ? origEl.value : '';

      if (search) {
        const haystack = normalizeText((card.cliente_nome || '') + ' ' + (card.empresa_nome || ''));
        if (!haystack.includes(search)) return false;
      }
      if (responsible && String(card.responsavel_user_id || '') !== String(responsible)) return false;
      if (stage && String(colId) !== String(stage)) return false;
      if (date && String(card.data_prevista_fechamento || '') !== String(date)) return false;

      // Filtro de origem: pseudo-valores __matriz__ / __filiais__ ou account_id específico
      if (origin) {
        const tipo = String(card.origin_account_tipo || '').toLowerCase();
        const oid  = String(card.origin_account_id || '');
        if (origin === '__matriz__'  && tipo !== 'matriz') return false;
        if (origin === '__filiais__' && tipo !== 'filial') return false;
        if (origin !== '__matriz__' && origin !== '__filiais__' && oid !== origin) return false;
      }
      return true;
    }

    function collectRenderedCards() {
      const all = [];
      Object.keys(cardsCacheByColumn).forEach(colId => {
        const source = cardsCacheByColumn[colId] || [];
        source.forEach(card => {
          if (matchesFilters(card, colId)) {
            all.push(Object.assign({}, card, { __coluna_id: colId }));
          }
        });
      });
      return all;
    }

    function updateIndicators(cards) {
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      let negotiation = 0;
      let closed = 0;
      let revenueForecast = 0;
      let revenueClosed = 0;
      let late = 0;

      cards.forEach(card => {
        const column = getColumnById(card.__coluna_id || card.coluna_id);
        const closedCol = isClosedColumn(column);
        if (isNegotiationColumn(column)) negotiation += 1;
        if (closedCol) closed += 1;

        if (closedCol) {
          revenueClosed += toNumber(card.valor_fechado_final || card.valor_proposta || card.valor_estimado);
        } else {
          revenueForecast += toNumber(card.valor_proposta || card.valor_estimado || 0);
        }

        if (!closedCol && card.data_prevista_fechamento) {
          const due = new Date(String(card.data_prevista_fechamento) + 'T00:00:00');
          if (!Number.isNaN(due.getTime()) && due < today) {
            late += 1;
          }
        }
      });

      const _set = (id, v) => { const el = byId(id); if (el) el.textContent = v; };
      _set('kpiTotalLeads', formatNumber(cards.length));
      _set('kpiNegotiation', formatNumber(negotiation));
      _set('kpiClosed', formatNumber(closed));
      _set('kpiRevenueForecast', formatMoney(revenueForecast));
      _set('kpiRevenueClosed', formatMoney(revenueClosed));
      _set('kpiLate', formatNumber(late));
    }

    function destroySortables() {
      Object.keys(sortableByColumn).forEach(key => {
        try { sortableByColumn[key].destroy(); } catch (e) {}
        delete sortableByColumn[key];
      });
    }

    function initBoardSortables() {
      destroySortables();
      if (isFilterActive()) return;

      document.querySelectorAll('.kanban-col').forEach(colEl => {
        const colId = colEl.getAttribute('data-coluna-id');
        const listEl = byId('col-' + colId);
        if (!listEl) return;

        sortableByColumn[colId] = Sortable.create(listEl, {
          group: 'cards',
          animation: 140,
          ghostClass: 'sortable-ghost',
          dragClass: 'sortable-drag',
          handle: '.card-drag-handle',
          forceFallback: true,
          fallbackOnBody: true,
          fallbackTolerance: 3,
          swapThreshold: 0.65,
          filter: '.wa-btn, .chat-link-btn',
          preventOnFilter: true,
          onStart() {
            _justDragged = false;
          },
          onOver(evt) {
            const targetCol = evt.to ? evt.to.closest('.kanban-col') : null;
            if (targetCol) targetCol.classList.add('drop-target');
          },
          onOut(evt) {
            const targetCol = evt.to ? evt.to.closest('.kanban-col') : null;
            const fromCol = evt.from ? evt.from.closest('.kanban-col') : null;
            if (targetCol) targetCol.classList.remove('drop-target');
            if (fromCol) fromCol.classList.remove('drop-target');
          },
          async onEnd(evt) {
            _justDragged = true;
            setTimeout(() => { _justDragged = false; }, 400);
            document.querySelectorAll('.kanban-col.drop-target').forEach(el => el.classList.remove('drop-target'));

            const fromColEl = evt.from.closest('.kanban-col');
            const toColEl = evt.to.closest('.kanban-col');
            const fromColId = fromColEl ? fromColEl.getAttribute('data-coluna-id') : null;
            const toColId = toColEl ? toColEl.getAttribute('data-coluna-id') : null;
            const updates = [];

            function collectOrders(colId) {
              const list = document.querySelectorAll('#col-' + colId + ' > [data-id]');
              let idx = 0;
              list.forEach(node => {
                updates.push({
                  id: node.getAttribute('data-id'),
                  coluna_id: parseInt(colId, 10),
                  ordem_na_coluna: idx
                });
                idx += 1;
              });
            }

            if (fromColId) collectOrders(fromColId);
            if (toColId && toColId !== fromColId) collectOrders(toColId);
            if (!updates.length) return;

            await fetch(apiCards, {
              method: 'PATCH',
              headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
              body: JSON.stringify({ reorder: updates, csrf_token: csrf })
            });

            await loadAll();
          }
        });
      });
    }

    function applyFiltersAndRender() {
      Object.keys(cardsCacheByColumn).forEach(colId => {
        const source = cardsCacheByColumn[colId] || [];
        const filtered = source.filter(card => matchesFilters(card, colId));
        renderCards(colId, filtered);
        setColCount(colId, filtered.length, source.length);
      });

      updateIndicators(collectRenderedCards());
      updateBoardHint();
      initBoardSortables();
    }

    async function loadAll() {
      const cols = Array.from(document.querySelectorAll('.kanban-col'));
      await Promise.all(cols.map(async col => {
        const colId = col.getAttribute('data-coluna-id');
        const listEl = byId('col-' + colId);
        if (listEl) listEl.innerHTML = '<div class="empty-note">Carregando...</div>';

        try {
          const res = await fetch(apiCards + '?coluna_id=' + colId, { headers: { 'Accept': 'application/json' } });
          const json = await res.json();
          cardsCacheByColumn[colId] = json.data || [];
        } catch (e) {
          cardsCacheByColumn[colId] = [];
        }
      }));

      applyFiltersAndRender();
    }

    function openModal(id) {
      const el = byId(id);
      if (!el) return;
      el.classList.remove('hidden');
      setTimeout(() => adjustModalSpacing(el), 60);
    }

    function closeModal(id) {
      const el = byId(id);
      if (!el) return;
      el.classList.add('hidden');
    }

    function adjustModalSpacing(modal) {
      const body = modal.querySelector('.modal-body');
      const footer = modal.querySelector('.modal-footer');
      if (!body || !footer) return;
      body.style.paddingBottom = (footer.offsetHeight + 14) + 'px';
    }

    function adjustVisibleModals() {
      ['modalCreate', 'modalEdit', 'modalColumns'].forEach(id => {
        const modal = byId(id);
        if (modal && !modal.classList.contains('hidden')) adjustModalSpacing(modal);
      });
    }

    function openWhatsApp(rawPhone) {
      const phone = String(rawPhone || '').replace(/\D/g, '');
      if (!phone) {
        alert('WhatsApp não preenchido para este lead.');
        return;
      }
      window.open('https://wa.me/' + phone, '_blank');
    }

    function renderStageFilterOptions() {
      const select = byId('filterStage');
      if (!select) return;
      const current = select.value;
      select.innerHTML = '<option value="">Todas as etapas</option>';
      (columnsCache || []).forEach(col => {
        const opt = document.createElement('option');
        opt.value = String(col.id);
        opt.textContent = stageDisplayName(col);
        select.appendChild(opt);
      });
      if (Array.from(select.options).some(opt => opt.value === current)) {
        select.value = current;
      }
    }

    function renderColumnSelectOptions(selectId, selectedId) {
      const select = byId(selectId);
      if (!select) return;
      const current = selectedId || select.value;
      select.innerHTML = '';
      (columnsCache || []).forEach(col => {
        const opt = document.createElement('option');
        opt.value = String(col.id);
        opt.textContent = stageDisplayName(col);
        select.appendChild(opt);
      });
      if (current && Array.from(select.options).some(opt => opt.value === String(current))) {
        select.value = String(current);
      }
    }

    function createColumnElement(col) {
      const el = document.createElement('div');
      el.className = 'kanban-col';
      el.setAttribute('data-coluna-id', col.id);
      el.setAttribute('data-coluna-nome', col.nome || '');
      el.setAttribute('data-coluna-slug', col.slug || '');
      el.setAttribute('data-coluna-fechado', col.conta_fechado || 0);
      el.innerHTML =
        '<div class="col-head">' +
          '<h3 class="col-title" style="color:' + escapeHtml(col.cor || '#cfe4ff') + '">' + escapeHtml(stageDisplayName(col)) + '</h3>' +
          '<span class="col-pill" id="col-count-' + col.id + '">0</span>' +
        '</div>' +
        '<p class="col-subtitle">' + escapeHtml(col.nome || '') + '</p>' +
        '<div class="cardsList" id="col-' + col.id + '"></div>';
      return el;
    }

    // Indica se o pipeline desta sessão é HERDADO da matriz (filial vinculada).
    // Atualizado por refreshColumnHeaders. Usado pra bloquear "Alterar Colunas".
    let _pipelineInherited = false;

    async function refreshColumnHeaders() {
      try {
        const res = await fetch('/sistema_vendas/public/api/columns.php');
        const json = await res.json();
        columnsCache = json.data || [];
        _pipelineInherited = !!json.inherited;

        const board = byId('board');
        board.innerHTML = '';
        columnsCache.forEach(col => {
          board.appendChild(createColumnElement(col));
          if (!cardsCacheByColumn[String(col.id)]) cardsCacheByColumn[String(col.id)] = [];
        });

        Object.keys(cardsCacheByColumn).forEach(colId => {
          if (!columnsCache.find(c => String(c.id) === String(colId))) {
            delete cardsCacheByColumn[colId];
          }
        });

        renderStageFilterOptions();
        renderColumnSelectOptions('createColunaId');
        renderColumnSelectOptions('editColunaId');

        // Atualiza estado do botão "Novo Lead": disabled + tooltip se não tem coluna
        const btnNew = document.getElementById('btnNewCard');
        if (btnNew) {
          if (columnsCache.length === 0) {
            btnNew.classList.add('is-disabled');
            btnNew.setAttribute('disabled', 'disabled');
            btnNew.setAttribute('title', 'Crie pelo menos uma coluna no pipeline antes de cadastrar um lead');
            btnNew.style.opacity   = '0.55';
            btnNew.style.cursor    = 'not-allowed';
          } else {
            btnNew.classList.remove('is-disabled');
            btnNew.removeAttribute('disabled');
            btnNew.removeAttribute('title');
            btnNew.style.opacity   = '';
            btnNew.style.cursor    = '';
          }
        }

        // Botão "Alterar Colunas":
        //  - Pipeline próprio  → "Alterar Colunas" com ícone de engrenagem (CTA)
        //  - Pipeline herdado  → "Colunas vinculadas à matriz" com ícone de link
        //    (não desabilita — fica em estilo informativo; click mostra explicação)
        const btnCols = document.getElementById('btnColumns');
        if (btnCols) {
          const ICON_GEAR = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06A2 2 0 1 1 2.27 17.8l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09c.7 0 1.27-.43 1.51-1a1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.3 2.27l.06.06c.5.5 1.2.75 1.82.33A1.65 1.65 0 0 0 9.69 1.5 1.65 1.65 0 0 0 9.7 1H12a2 2 0 1 1 0 4h-.09c-.7 0-1.27.43-1.51 1a1.65 1.65 0 0 0 .33 1.82l.06.06A2 2 0 1 1 17.73 6.2l-.06.06c-.5.5-.75 1.2-.33 1.82.32.56.32 1.28.32 1.82V12a2 2 0 1 1 4 0v.09c0 .7.43 1.27 1 1.51z"/></svg>';
          const ICON_LINK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
          if (_pipelineInherited) {
            btnCols.classList.add('is-inherited');
            btnCols.removeAttribute('disabled');
            btnCols.setAttribute('title', 'O pipeline desta filial é definido pela matriz. Clique para mais informações.');
            btnCols.innerHTML = ICON_LINK + ' Colunas vinculadas à matriz';
          } else {
            btnCols.classList.remove('is-inherited');
            btnCols.removeAttribute('disabled');
            btnCols.removeAttribute('title');
            btnCols.innerHTML = ICON_GEAR + ' Alterar Colunas';
          }
        }
      } catch (e) {
        console.error('refreshColumnHeaders error', e);
      }
    }

    function getCurrentCardId() {
      return byId('editForm').id.value;
    }

    function checklistPriority(text, order) {
      const base = normalizeText(text);
      if (base.includes('urgente') || base.includes('prazo') || base.includes('hoje') || base.includes('venc')) {
        return { label: 'Alta', cls: 'priority-high' };
      }
      if (base.includes('contrato') || base.includes('proposta') || base.includes('assin')) {
        return { label: 'Média', cls: 'priority-medium' };
      }
      if (order <= 1) {
        return { label: 'Média', cls: 'priority-medium' };
      }
      return { label: 'Baixa', cls: 'priority-low' };
    }

    function updateChecklistProgress(items) {
      const total = items.length;
      const done = items.filter(i => Number(i.marcado) === 1 || i.marcado === true).length;
      const pct = total ? Math.round((done / total) * 100) : 0;
      byId('checklistProgress').textContent = pct + '% concluído (' + done + '/' + total + ')';
      byId('checklistProgressBar').style.width = pct + '%';
    }

    async function persistChecklistOrder(cardId) {
      const rows = Array.from(byId('checklistItems').querySelectorAll('.check-item[data-id]'));
      const reorder = rows.map((row, index) => ({ id: Number(row.getAttribute('data-id')), ordem: index }));
      if (!reorder.length) return;

      const res = await fetch('/sistema_vendas/public/api/card_checklist.php', {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
        body: JSON.stringify({ card_id: Number(cardId), reorder: reorder, csrf_token: csrf })
      });
      const json = await res.json();
      if (!json.success) {
        alert('Não foi possível reordenar o checklist.');
      }
    }

    function initChecklistSortable(cardId) {
      const container = byId('checklistItems');
      if (!container) return;
      if (checklistSortable && typeof checklistSortable.destroy === 'function') {
        try { checklistSortable.destroy(); } catch (e) {}
      }

      checklistSortable = Sortable.create(container, {
        handle: '.check-handle',
        draggable: '.check-item',
        animation: 130,
        ghostClass: 'opacity-50',
        onEnd: function() {
          persistChecklistOrder(cardId);
        }
      });
    }

    async function loadChecklist(cardId) {
      const res = await fetch('/sistema_vendas/public/api/card_checklist.php?card_id=' + cardId);
      const json = await res.json();
      const items = json.data || [];
      const container = byId('checklistItems');
      container.innerHTML = '';

      items.forEach((item, index) => {
        const row = document.createElement('div');
        const priority = checklistPriority(item.descricao, index);
        row.className = 'check-item';
        row.setAttribute('data-id', item.id);
        row.innerHTML =
          '<span class="check-handle">☰</span>' +
          '<label class="flex items-center gap-2">' +
            '<input type="checkbox" class="check-toggle" data-check-id="' + item.id + '" ' + ((Number(item.marcado) === 1 || item.marcado === true) ? 'checked' : '') + '>' +
            '<span class="check-text" data-check-id="' + item.id + '">' + escapeHtml(item.descricao) + '</span>' +
          '</label>' +
          '<span class="priority ' + priority.cls + '">' + priority.label + '</span>' +
          '<button type="button" class="check-remove" data-del-id="' + item.id + '">Remover</button>';
        container.appendChild(row);
      });

      updateChecklistProgress(items);
      initChecklistSortable(cardId);
    }

    function historyActionLabel(history) {
      const map = {
        moved: 'Moveu etapa no funil',
        reorder: 'Reorganizou ordem dos cards',
        checklist_add: 'Adicionou item de checklist',
        checklist_update: 'Atualizou item de checklist',
        checklist_toggle: 'Alterou conclusão do checklist',
        checklist_delete: 'Removeu item de checklist',
        checklist_reorder: 'Reordenou checklist operacional'
      };
      return map[history.acao] || (history.acao ? String(history.acao) : 'Atualização comercial');
    }

    async function loadCardHistory(cardId) {
      const res = await fetch(apiCards + '?id=' + cardId);
      const json = await res.json();
      const card = json.data || null;
      const timeline = byId('cardHistory');
      timeline.innerHTML = '';

      if (!card || !card.history || !card.history.length) {
        timeline.innerHTML = '<div class="timeline-item"><div class="timeline-title">Sem histórico registrado.</div></div>';
        return;
      }

      card.history.forEach(h => {
        const who = h.usuario_login || ('#' + (h.usuario_id || 'sistema'));
        const item = document.createElement('div');
        item.className = 'timeline-item';
        item.innerHTML =
          '<div class="timeline-title">' + escapeHtml(historyActionLabel(h)) + '</div>' +
          '<div class="timeline-meta">' + escapeHtml(who) + ' • ' + escapeHtml(formatDateTime(h.created_at)) + '</div>';
        timeline.appendChild(item);
      });
    }

    // ── Processos do Cliente ─────────────────────────────────────────────────
    let _currentCardId  = null; // card aberto no momento
    let _currentChatJid = null; // jid da conversa vinculada ao card aberto
    let _allProcsCache  = [];   // cache de todos os processos para o modal de busca

    function renderChatVinculo(jid) {
      _currentChatJid = jid || null;
      const el = document.getElementById('chatVinculoCard');
      if (!el) return;
      if (!jid) {
        el.innerHTML = '<div style="color:#9ab0c9;font-size:.82rem">Nenhuma conversa vinculada a este card ainda.<br><span style="font-size:.78rem;opacity:.7">Abra o Chat, selecione a conversa e use o botão Vincular para associá-la.</span></div>';
        return;
      }
      // Extrai número legível do JID (ex: 5511999998888@s.whatsapp.net → 5511999998888)
      const phone = jid.split('@')[0];
      const isGroup = jid.endsWith('@g.us');
      const label = isGroup ? '(grupo)' : phone;
      el.innerHTML = `
        <div style="padding:12px 14px;background:rgba(14,28,50,.7);border:1px solid rgba(37,211,102,.2);border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:12px">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
              <svg viewBox="0 0 24 24" fill="none" stroke="#25d366" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
              <span style="font-size:.85rem;font-weight:600;color:#6ee7b7">Conversa vinculada</span>
            </div>
            <div style="font-size:.78rem;color:#9ab0c9">${escapeHtml(label)}</div>
          </div>
          <a href="/sistema_vendas/public/chat.php?jid=${encodeURIComponent(jid)}"
             style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:rgba(37,211,102,.15);border:1px solid rgba(37,211,102,.35);border-radius:8px;color:#6ee7b7;font-size:.8rem;font-weight:600;text-decoration:none;white-space:nowrap;transition:background .15s"
             onmouseover="this.style.background='rgba(37,211,102,.28)'" onmouseout="this.style.background='rgba(37,211,102,.15)'"
          ><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
          Ir para conversa</a>
        </div>`;
    }

    async function loadProcessosDoCliente(cardId) {
      const el = document.getElementById('processosClienteList');
      if (!el || !cardId) return;
      el.innerHTML = '<div style="color:#9ab0c9;font-size:.82rem">Carregando...</div>';
      try {
        const r = await fetch(`/sistema_vendas/public/api/processes.php?card_id=${encodeURIComponent(cardId)}`, {credentials:'same-origin'});
        const data = await r.json();
        const procs = Array.isArray(data) ? data : (data.data || []);
        el.innerHTML = procs.length ? procs.map(p => {
          const prazo = p.proximo_prazo ? new Date(p.proximo_prazo+'T00:00').toLocaleDateString('pt-BR') : '—';
          return `<div style="padding:10px;background:rgba(14,28,50,.7);border:1px solid rgba(96,165,250,.12);border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:8px">
            <div style="flex:1;min-width:0">
              <div style="font-size:.85rem;font-weight:600;color:#e2f0ff">${p.numero||'Processo'} — ${p.tipo_acao||'—'}</div>
              <div style="font-size:.75rem;color:#9ab0c9">${p.status||''} · Prazo: ${prazo}</div>
            </div>
            <a href="/sistema_vendas/public/processos.php?open=${p.id}" style="font-size:.75rem;color:#60a5fa;white-space:nowrap">Ver →</a>
            <button type="button" onclick="_desvincularProcesso(${p.id})" title="Desvincular processo"
                    style="background:transparent;border:1px solid rgba(239,68,68,.3);color:#fca5a5;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:.72rem;white-space:nowrap"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
          </div>`;
        }).join('') : '<div style="color:#9ab0c9;font-size:.82rem">Nenhum processo vinculado a este cliente ainda.</div>';
      } catch(e) {
        el.innerHTML = '<div style="color:#9ab0c9;font-size:.82rem">Não foi possível carregar processos.</div>';
      }
    }

    // Desvincular processo (remove o card_id do processo)
    window._desvincularProcesso = async (processId) => {
      if (!(await Yuris.confirm('Desvincular este processo do cliente?', { okLabel: 'Desvincular' }))) return;
      await fetch('/sistema_vendas/public/api/processes.php', {
        method: 'PUT', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: processId, card_id: null})
      });
      if (_currentCardId) loadProcessosDoCliente(_currentCardId);
    };

    // ── Modal de vínculo de processo ─────────────────────────────────────────
    function _normProc(s) {
      return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
    }

    function _renderProcessoLista(filtro) {
      const lista = document.getElementById('processoListaModal');
      if (!lista) return;
      const t = _normProc(filtro);
      const filtrados = _allProcsCache.filter(p =>
        !t ||
        _normProc(p.numero).includes(t) ||
        _normProc(p.cliente_nome).includes(t) ||
        _normProc(p.tipo_acao).includes(t)
      );
      if (!filtrados.length) {
        lista.innerHTML = '<div style="color:#9ab0c9;font-size:.83rem;padding:16px 8px;text-align:center">Nenhum processo encontrado</div>';
        return;
      }
      lista.innerHTML = filtrados.map(p => {
        const prazo = p.proximo_prazo ? new Date(p.proximo_prazo+'T00:00').toLocaleDateString('pt-BR') : '—';
        const jaVinculado = String(p.card_id) === String(_currentCardId);
        return `<div onclick="${jaVinculado ? '' : `_vincularProcesso(${p.id})`}"
          style="padding:11px 14px;border-radius:8px;border:1px solid ${jaVinculado ? 'rgba(16,185,129,.3)' : 'rgba(96,165,250,.1)'};background:${jaVinculado ? 'rgba(16,185,129,.08)' : 'rgba(8,20,40,.7)'};margin-bottom:6px;cursor:${jaVinculado ? 'default' : 'pointer'};transition:background .15s"
          ${jaVinculado ? '' : 'onmouseover="this.style.background=\'rgba(37,99,235,.2)\'" onmouseout="this.style.background=\'rgba(8,20,40,.7)\'"'}>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
              <div style="font-size:.87rem;font-weight:600;color:#e2f0ff">${p.numero||'—'} — ${p.tipo_acao||'—'}</div>
              <div style="font-size:.73rem;color:#9ab0c9;margin-top:2px">${p.cliente_nome||'—'} · ${p.status||''} · Prazo: ${prazo}</div>
            </div>
            ${jaVinculado
              ? '<span style="font-size:.72rem;color:#6ee7b7;background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);border-radius:4px;padding:2px 8px"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Vinculado</span>'
              : '<span style="font-size:.72rem;color:#93c5fd">Vincular →</span>'
            }
          </div>
        </div>`;
      }).join('');
    }

    // Vincula o processo ao card atual
    window._vincularProcesso = async (processId) => {
      await fetch('/sistema_vendas/public/api/processes.php', {
        method: 'PUT', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: processId, card_id: _currentCardId})
      });
      // Atualiza a lista e re-renderiza o modal
      if (_currentCardId) {
        await loadProcessosDoCliente(_currentCardId);
        // Recarrega cache e re-renderiza
        const r2 = await fetch('/sistema_vendas/public/api/processes.php', {credentials:'same-origin'});
        const d2 = await r2.json();
        _allProcsCache = Array.isArray(d2) ? d2 : (d2.data || []);
        _renderProcessoLista(document.getElementById('processoSearchInput')?.value || '');
      }
    };

    // Abre o modal de vínculo
    document.getElementById('btnVincularProcesso')?.addEventListener('click', async () => {
      const modal = document.getElementById('modalVincularProcesso');
      if (!modal) return;
      document.getElementById('processoSearchInput').value = '';
      document.getElementById('processoListaModal').innerHTML = '<div style="color:#9ab0c9;font-size:.83rem;padding:16px;text-align:center">Carregando...</div>';
      modal.style.display = 'flex';
      setTimeout(() => document.getElementById('processoSearchInput')?.focus(), 50);
      // Carrega todos os processos
      try {
        const r = await fetch('/sistema_vendas/public/api/processes.php', {credentials:'same-origin'});
        const d = await r.json();
        _allProcsCache = Array.isArray(d) ? d : (d.data || []);
        _renderProcessoLista('');
      } catch(e) {
        document.getElementById('processoListaModal').innerHTML = '<div style="color:#ef4444;font-size:.83rem;padding:16px">Erro ao carregar processos.</div>';
      }
    });

    // Filtro em tempo real no modal
    document.getElementById('processoSearchInput')?.addEventListener('input', function() {
      _renderProcessoLista(this.value);
    });

    // Fechar modal
    document.getElementById('btnFecharVincularProcesso')?.addEventListener('click', () => {
      document.getElementById('modalVincularProcesso').style.display = 'none';
    });
    document.getElementById('modalVincularProcesso')?.addEventListener('click', function(e) {
      if (e.target === this) this.style.display = 'none';
    });

    // Criar novo processo já vinculado a este cliente
    document.getElementById('btnCriarProcessoCliente')?.addEventListener('click', () => {
      if (!_currentCardId) return;
      // Navega para processos.php passando o card_id para pré-selecionar o cliente
      window.location.href = `/sistema_vendas/public/processos.php?new_card_id=${_currentCardId}`;
    });

    async function openEditModal(cardId) {
      const res = await fetch(apiCards + '?id=' + cardId);
      const json = await res.json();
      const card = (json.data && json.data[0]) || json.data || null;
      if (!card) return;

      const form = byId('editForm');
      form.id.value = card.id;
      form.cliente_nome.value = card.cliente_nome || '';
      form.empresa_nome.value = card.empresa_nome || '';
      form.valor_estimado.value = card.valor_estimado || '';
      form.valor_proposta.value = card.valor_proposta || '';
      form.valor_fechado_final.value = card.valor_fechado_final || '';
      form.data_prevista_fechamento.value = card.data_prevista_fechamento || '';
      form.data_fechamento.value = card.data_fechamento || '';
      form.responsavel_user_id.value = card.responsavel_user_id || '';
      form.telefone_whatsapp.value = card.telefone_whatsapp || '';
      renderColumnSelectOptions('editColunaId', card.coluna_id || '');

      const parsedDesc = splitDescricao(card.descricao || '');
      byId('editDescricaoMain').value = parsedDesc.main;
      byId('editDescricaoNotes').value = parsedDesc.notes;
      byId('descricaoHidden').value = composeDescricao(parsedDesc.main, parsedDesc.notes);

      byId('openWhatsapp').setAttribute('data-phone', card.telefone_whatsapp || '');

      // Movido para Gestão Processual
      // await loadChecklist(card.id);
      // await loadCardHistory(card.id);
      _currentCardId = card.id;
      renderChatVinculo(card.linked_chat_jid || null);
      await loadProcessosDoCliente(card.id);
      openModal('modalEdit');
      // Guarda o ID aberto na URL sem recarregar (para navegação cross-page)
      history.replaceState(null,'',`?open=${cardId}`);
    }

    function bindTopActions() {
      byId('btnRefresh').addEventListener('click', loadAll);
      byId('btnNewCard').addEventListener('click', () => {
        // Trava: não permite criar lead sem pelo menos uma coluna no pipeline
        // (antes era possível, e o card ficava órfão com coluna_id = NULL).
        if (!columnsCache || columnsCache.length === 0) {
          if (typeof window.showToast === 'function') {
            window.showToast('Crie pelo menos uma coluna no pipeline antes de cadastrar um lead. Use o botão "Alterar Colunas".', 'error');
          } else {
            alert('Crie pelo menos uma coluna no pipeline antes de cadastrar um lead.\n\nUse o botão "Alterar Colunas" pra criar a primeira etapa.');
          }
          // Abre direto o modal de gestão de colunas pra acelerar
          const btnCols = document.getElementById('btnColumns');
          if (btnCols) btnCols.focus();
          return;
        }
        renderColumnSelectOptions('createColunaId');
        openModal('modalCreate');
      });
      byId('cancelCreate').addEventListener('click', () => closeModal('modalCreate'));
      byId('cancelEdit').addEventListener('click', () => closeModal('modalEdit'));
      byId('cancelColumns').addEventListener('click', () => closeModal('modalColumns'));

      byId('btnToggleFilters').addEventListener('click', () => {
        const panel = byId('filtersPanel');
        panel.classList.toggle('hidden');
      });

      byId('btnColumns').addEventListener('click', async () => {
        // Pipeline herdado da matriz: filial não pode alterar — mostra info amigável.
        if (_pipelineInherited) {
          alert(
            'ℹ Colunas vinculadas à matriz\n\n' +
            'Sua filial faz parte de uma rede e usa o pipeline definido pela matriz, ' +
            'para manter o processo comercial padronizado entre todos os escritórios.\n\n' +
            'Para alterar uma coluna, peça ao administrador da matriz.'
          );
          return;
        }
        openModal('modalColumns');
        await loadColumnsList();
      });

      byId('openWhatsapp').addEventListener('click', function() {
        openWhatsApp(this.getAttribute('data-phone'));
      });
    }

    function bindFilterEvents() {
      const run = () => applyFiltersAndRender();
      const searchEl = byId('filterSearch');
      let debounce = null;
      searchEl.addEventListener('input', function() {
        clearTimeout(debounce);
        debounce = setTimeout(run, 180);
      });

      ['filterResponsible', 'filterStage', 'filterDate', 'filterOrigin'].forEach(id => {
        const el = byId(id);
        if (el) el.addEventListener('change', run);
      });
    }

    function buildCreatePayload(form) {
      const fd = new FormData(form);
      const payload = Object.fromEntries(fd.entries());
      if (!payload.coluna_id) {
        const firstCol = document.querySelector('.kanban-col');
        payload.coluna_id = firstCol ? firstCol.getAttribute('data-coluna-id') : null;
      }
      if (payload.coluna_id) {
        const colCards = cardsCacheByColumn[String(payload.coluna_id)] || [];
        payload.ordem_na_coluna = colCards.length;
      }
      return payload;
    }

    function prepareEditDescription() {
      const composed = composeDescricao(byId('editDescricaoMain').value, byId('editDescricaoNotes').value);
      byId('descricaoHidden').value = composed;
    }

    function bindFormEvents() {
      byId('createForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const payload = buildCreatePayload(this);
        const res = await fetch(apiCards, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify(payload)
        });
        let json = {};
        try { json = await res.json(); } catch(_) {}
        if (!res.ok || !json.success) {
          // Erro específico do backend (ex.: pipeline sem colunas)
          const msg = json && json.error
            ? json.error + (json.hint ? '\n\n' + json.hint : '')
            : 'Erro ao criar lead.';
          alert(msg);
          return;
        }
        closeModal('modalCreate');
        this.reset();
        await loadAll();
      });

      byId('editForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        prepareEditDescription();
        const fd = new FormData(this);
        const payload = Object.fromEntries(fd.entries());

        const res = await fetch(apiCards, {
          method: 'PUT',
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (!json.success) {
          alert('Erro ao salvar card.');
          return;
        }

        closeModal('modalEdit');
        await loadAll();
      });

      byId('deleteCard').addEventListener('click', async function() {
        if (!(await Yuris.confirm('Excluir este card?', { danger: true, okLabel: 'Excluir' }))) return;
        const id = byId('editForm').id.value;
        const res = await fetch(apiCards, {
          method: 'DELETE',
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify({ id: id, csrf_token: csrf })
        });
        const json = await res.json();
        if (!json.success) {
          alert('Erro ao excluir card.');
          return;
        }

        closeModal('modalEdit');
        await loadAll();
      });
    }

    // Ensure clicking the custom calendar area opens the native date picker where supported
    function enableDatePickers() {
      ['input[name="data_prevista_fechamento"]', 'input[name="data_fechamento"]'].forEach(sel => {
        document.querySelectorAll(sel).forEach(el => {
          if (!el) return;
          el.addEventListener('click', function(evt) {
            try {
              if (typeof this.showPicker === 'function') {
                this.showPicker();
              } else {
                // fallback: focus and attempt to click the native indicator
                this.focus();
              }
            } catch (e) {
              this.focus();
            }
          });
        });
      });
    }

    // Wire calendar buttons to open the native picker for their target inputs
    function bindCalendarButtons() {
      document.querySelectorAll('.calendar-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
          // Find the input sibling inside the same wrapper div, not globally,
          // to avoid matching hidden inputs with the same name in other modals.
          const wrapper = this.closest('div[style*="position"]');
          const input = wrapper ? wrapper.querySelector('input[type="date"]') : null;
          if (!input) return;
          try {
            if (typeof input.showPicker === 'function') {
              input.showPicker();
            } else {
              input.focus();
              try { input.click(); } catch (err) {}
            }
          } catch (err) {
            input.focus();
          }
        });
      });
    }

    function bindBoardClicks() {
      document.addEventListener('click', async function(e) {
        const waBtn = e.target.closest('.js-whatsapp');
        if (waBtn) {
          e.preventDefault();
          e.stopPropagation();
          if (waBtn.classList.contains('disabled')) {
            alert('Este lead não possui WhatsApp cadastrado.');
            return;
          }
          openWhatsApp(waBtn.getAttribute('data-phone'));
          return;
        }

        const cardEl = e.target.closest('.card-mini');
        if (!cardEl) return;
        if (_justDragged) return;
        await openEditModal(cardEl.getAttribute('data-id'));
      });
    }

    function bindChecklistEvents() {
      byId('addChecklist').addEventListener('click', async function() {
        const text = byId('newChecklistItem').value.trim();
        const cardId = getCurrentCardId();
        if (!text || !cardId) return;

        const res = await fetch('/sistema_vendas/public/api/card_checklist.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify({ card_id: cardId, descricao: text, csrf_token: csrf })
        });
        const json = await res.json();
        if (!json.success) {
          alert('Erro ao adicionar item no checklist.');
          return;
        }

        byId('newChecklistItem').value = '';
        await loadChecklist(cardId);
        await loadCardHistory(cardId);
        await loadAll();
      });

      byId('checklistItems').addEventListener('click', async function(e) {
        const removeBtn = e.target.closest('button[data-del-id]');
        if (removeBtn) {
          const itemId = removeBtn.getAttribute('data-del-id');
          if (!(await Yuris.confirm('Remover item do checklist?', { danger: true, okLabel: 'Remover' }))) return;

          const res = await fetch('/sistema_vendas/public/api/card_checklist.php', {
            method: 'DELETE',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({ id: itemId, csrf_token: csrf })
          });
          const json = await res.json();
          if (!json.success) {
            alert('Erro ao remover item.');
            return;
          }

          const cardId = getCurrentCardId();
          await loadChecklist(cardId);
          await loadCardHistory(cardId);
          await loadAll();
          return;
        }

        const textEl = e.target.closest('.check-text');
        if (textEl) {
          e.preventDefault();
          e.stopPropagation();
          const current = textEl.textContent || '';
          const itemId = textEl.getAttribute('data-check-id');
          const input = document.createElement('input');
          input.type = 'text';
          input.className = 'form-input';
          input.value = current.trim();
          textEl.parentNode.replaceChild(input, textEl);
          input.focus();

          const finish = async (save) => {
            if (save) {
              const next = input.value.trim();
              if (next && next !== current) {
                const res = await fetch('/sistema_vendas/public/api/card_checklist.php', {
                  method: 'PATCH',
                  headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                  body: JSON.stringify({ id: itemId, descricao: next, csrf_token: csrf })
                });
                const json = await res.json();
                if (!json.success) {
                  alert('Erro ao atualizar item.');
                }
              }
            }

            const span = document.createElement('span');
            span.className = 'check-text';
            span.setAttribute('data-check-id', itemId);
            span.textContent = input.value;
            input.parentNode.replaceChild(span, input);

            const cardId = getCurrentCardId();
            await loadChecklist(cardId);
            await loadCardHistory(cardId);
            await loadAll();
          };

          input.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') finish(true);
            if (ev.key === 'Escape') finish(false);
          });
          input.addEventListener('blur', () => finish(true));
          return;
        }
      });

      byId('checklistItems').addEventListener('change', async function(e) {
        const chk = e.target.closest('.check-toggle');
        if (!chk) return;
        const itemId = chk.getAttribute('data-check-id');

        const res = await fetch('/sistema_vendas/public/api/card_checklist.php', {
          method: 'PATCH',
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify({ id: itemId, marcado: chk.checked, csrf_token: csrf })
        });
        const json = await res.json();
        if (!json.success) {
          alert('Erro ao atualizar checklist.');
          return;
        }

        const cardId = getCurrentCardId();
        await loadChecklist(cardId);
        await loadCardHistory(cardId);
        await loadAll();
      });
    }

    function initColumnsHandlers() {
      byId('addColumnBtn').addEventListener('click', async function() {
        const payload = { nome: 'Nova Coluna', cor: '#084779', csrf_token: csrf };
        const res = await fetch('/sistema_vendas/public/api/columns.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (!json.success) {
          alert('Erro ao criar coluna.');
          return;
        }

        await loadColumnsList();
        await refreshColumnHeaders();
        await loadAll();
      });

      byId('saveColumns').addEventListener('click', async function() {
        const rows = Array.from(document.querySelectorAll('#columnsList > [data-id]'));
        for (let i = 0; i < rows.length; i += 1) {
          const id = rows[i].getAttribute('data-id');
          const nome = rows[i].querySelector('.col-name').value.trim();
          const cor = rows[i].querySelector('.col-color').value || '#EEEEEE';

          await fetch('/sistema_vendas/public/api/columns.php', {
            method: 'PUT',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({ id: id, nome: nome, cor: cor, ordem: i, csrf_token: csrf })
          });
        }

        closeModal('modalColumns');
        await refreshColumnHeaders();
        await loadAll();
      });

      byId('columnsList').addEventListener('click', async function(e) {
        const btn = e.target.closest('.delete-col-btn');
        if (!btn) return;

        const id = btn.getAttribute('data-del-col-id');
        if (!id) return;
        if (!(await Yuris.confirm('Excluir coluna?\n\nOs cards nessa etapa serão afetados.', { danger: true, okLabel: 'Excluir' }))) return;

        const res = await fetch('/sistema_vendas/public/api/columns.php', {
          method: 'DELETE',
          headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
          body: JSON.stringify({ id: id, csrf_token: csrf })
        });
        const json = await res.json();
        if (!json.success) {
          alert('Erro ao excluir coluna.');
          return;
        }

        await loadColumnsList();
        await refreshColumnHeaders();
        await loadAll();
      });
    }

    async function loadColumnsList() {
      const res = await fetch('/sistema_vendas/public/api/columns.php');
      const json = await res.json();
      const cols = json.data || [];
      const list = byId('columnsList');
      list.innerHTML = '';

      cols.forEach(col => {
        const row = document.createElement('div');
        const color = col.cor || '#EEEEEE';
        row.className = 'columns-row';
        row.setAttribute('data-id', col.id);
        row.innerHTML =
          '<span class="drag-handle">☰</span>' +
          '<input class="col-name form-input" value="' + escapeHtml(col.nome || '') + '" style="flex:1;">' +
          '<input type="color" class="col-color" value="' + escapeHtml(color) + '" style="display:none;">' +
          '<span class="col-swatch" title="Clique para editar cor" style="width:28px;height:20px;border-radius:4px;border:1px solid rgba(96,165,250,0.22);display:inline-block;background:' + escapeHtml(color) + ';"></span>' +
          '<button type="button" class="delete-col-btn btn ghost" data-del-col-id="' + col.id + '" style="padding:6px 8px;font-size:.72rem;">Excluir</button>';

        list.appendChild(row);

        const colorInput = row.querySelector('.col-color');
        const swatch = row.querySelector('.col-swatch');
        swatch.addEventListener('click', function(e) {
          e.stopPropagation();
          if (row.querySelector('.col-color-text')) {
            row.querySelector('.col-color-text').focus();
            return;
          }

          const txt = document.createElement('input');
          txt.type = 'text';
          txt.className = 'col-color-text form-input';
          txt.style.width = '100px';
          txt.value = colorInput.value;
          swatch.parentNode.insertBefore(txt, swatch.nextSibling);
          txt.focus();

          const apply = () => {
            let value = txt.value.trim();
            if (!value) { txt.remove(); return; }
            if (!value.startsWith('#')) value = '#' + value;
            if (/^#([0-9A-Fa-f]{6})$/.test(value)) {
              colorInput.value = value;
              swatch.style.background = value;
            }
            txt.remove();
          };

          txt.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') apply();
            if (ev.key === 'Escape') txt.remove();
          });
          txt.addEventListener('blur', apply);
        });
      });

      if (columnsSortable && typeof columnsSortable.destroy === 'function') {
        try { columnsSortable.destroy(); } catch (e) {}
      }

      columnsSortable = Sortable.create(list, {
        handle: '.drag-handle',
        draggable: '.columns-row',
        animation: 120,
        ghostClass: 'opacity-50',
        fallbackOnBody: true
      });
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeModal('modalCreate');
        closeModal('modalEdit');
        closeModal('modalColumns');
      }
    });

    window.addEventListener('resize', () => setTimeout(adjustVisibleModals, 60));

    function bindAll() {
      bindTopActions();
      bindFilterEvents();
      bindFormEvents();
      bindBoardClicks();
      // bindChecklistEvents(); // Movido para Gestão Processual
      initColumnsHandlers();
    }

    bindAll();

    // Detecta F5/reload — se for reload, NÃO auto-abre o modal mesmo que a URL
    // ainda tenha ?open=X. Só auto-abre quando o usuário CHEGA na página via link.
    function _isPageReload() {
      try {
        const nav = (performance.getEntriesByType('navigation') || [])[0];
        if (nav && nav.type) return nav.type === 'reload';
        // fallback legado
        return performance.navigation && performance.navigation.type === 1;
      } catch (e) { return false; }
    }

    // Limpa os params da URL (chamada também quando ignoramos por reload)
    function _cleanUrlParams() {
      try {
        const u = new URL(location.href);
        u.searchParams.delete('open');
        u.searchParams.delete('contato');
        const qs = u.searchParams.toString();
        history.replaceState(null, '', u.pathname + (qs ? '?' + qs : '') + u.hash);
      } catch (e) {}
    }

    refreshColumnHeaders().then(loadAll).then(() => {
      const params    = new URLSearchParams(location.search);
      const openId    = params.get('open');
      const contatoId = params.get('contato');
      const isReload  = _isPageReload();

      // Sempre limpa a URL — independente de reload ou navegação fresca
      if (openId || contatoId) _cleanUrlParams();

      // Se foi reload (F5/Ctrl+R), aborta auto-open mesmo com params presentes
      if (isReload) return;

      // Navegação fresca: auto-abre o card solicitado
      if (openId) { openEditModal(openId); return; }
      if (contatoId) {
        for (const colCards of Object.values(cardsCacheByColumn)) {
          const found = colCards.find(c => String(c.contato_id) === String(contatoId));
          if (found) { openEditModal(found.id); break; }
        }
      }
    });
    enableDatePickers();
    bindCalendarButtons();
  </script>
  <script src="assets/dashboard.js?v=13"></script>
  <script src="/sistema_vendas/public/assets/fog.js"></script>
</body>
</html>

