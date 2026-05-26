<?php
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/Account.php';
require_once __DIR__ . '/../app/Models/ResourceShare.php';
require_once __DIR__ . '/../app/Models/PushTodayCache.php';
require_once __DIR__ . '/../app/Models/PushEvent.php';
require_once __DIR__ . '/../app/Models/PushEventUserStatus.php';
require_once __DIR__ . '/../app/Helpers/AccountContext.php';

session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /sistema_vendas/public/login.php');
    exit;
}
// HARDENING: bloqueia acesso de contas suspensas/canceladas/inativas
\App\Helpers\AccountContext::fromSession()->assertAccountActive();

$activePage = 'intimacoes';
$csrf       = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

use App\Helpers\AccountContext;
use App\Models\PushTodayCache;
use App\Models\PushEventUserStatus;

$ctx        = AccountContext::fromSession();
$accountId  = $ctx->getAccountId();
$userId     = (int)$_SESSION['user_id'];

$kpiCacheHoje = PushTodayCache::countActive($accountId);
$kpiNaoLidas  = PushEventUserStatus::countNaoLidas($userId, $accountId);

// Usuários acessíveis (matriz + filiais ativas) — alimenta o select de Responsável
// no modal "Criar prazo processual", espelhando o padrão da aba Processos.
$system_users = [];
try { $system_users = $ctx->getAccessibleUsers(); } catch (\Throwable $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Intimações — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=44">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=18">
  <!-- Flatpickr (calendário visual range) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/pt.js" defer></script>
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
    section.main-content { display: flex; flex-direction: column; }

    /* ── Layout interno: lista (1fr) + filtro lateral (320px) ── */
    .int-grid {
      display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px;
      align-items: start;
    }
    .int-grid > * { min-width: 0; }  /* impede explosão horizontal de filhos */
    @media (max-width: 1024px) {
      .int-grid { grid-template-columns: minmax(0, 1fr); }
    }

    /* ── Tabs estilo AASP ── */
    .int-tabs { display: flex; gap: 0; border-bottom: 1px solid rgba(96,165,250,.18); margin-bottom: 18px; flex-wrap: wrap; }
    .int-tab {
      padding: 12px 18px; font-size: .82rem; font-weight: 600; color: #7A8898;
      letter-spacing: .04em; text-transform: uppercase; border-bottom: 2px solid transparent;
      cursor: pointer; transition: color .15s, border-color .15s;
    }
    .int-tab.active { color: #dbeafe; border-bottom-color: #8b1c2d; }
    .int-tab:not(.active):hover { color: #93c5fd; }
    .int-tab-count {
      display: inline-block; margin-left: 6px; padding: 2px 8px;
      background: rgba(139,28,45,.18); color: #f87171; border-radius: 10px; font-size: .68rem;
    }

    /* ── Toolbar / botões ── */
    .int-toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
    .int-toolbar-info { color: #7A8898; font-size: .82rem; }
    .int-toolbar-info strong { color: #93c5fd; }
    .int-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 13px; border-radius: 9px; font-size: .82rem; font-weight: 600;
      border: 1px solid rgba(96,165,250,.22); background: rgba(8,20,40,.55);
      color: #dbeafe; cursor: pointer; transition: all .15s; line-height: 1;
    }
    .int-btn:hover { background: rgba(36,78,122,.5); border-color: rgba(96,165,250,.45); }
    .int-btn.primary {
      background: linear-gradient(135deg, #244E7A, #36608E); color: #fff;
      border-color: rgba(96,165,250,.5);
    }
    .int-btn.danger {
      background: rgba(176,96,112,.15); border-color: rgba(176,96,112,.3); color: #f87171;
    }

    /* ── Lista publicações ── */
    .int-day-header {
      margin: 22px 0 10px; padding: 8px 12px;
      background: rgba(8,20,40,.45); border-left: 3px solid #6898C0;
      border-radius: 4px; color: #93c5fd; font-size: .85rem; font-weight: 600;
    }
    .int-pub-card {
      display: flex; gap: 12px; padding: 14px 16px;
      background: rgba(13,28,48,.75); border: 1px solid rgba(96,165,250,.12);
      border-radius: 10px; margin-bottom: 10px;
      transition: border-color .15s, box-shadow .15s;
      color: #D8E4F0;
      /* Contenção horizontal — não expande além do container */
      max-width: 100%; overflow: hidden;
      word-break: break-word; overflow-wrap: anywhere;
    }
    .int-pub-card:hover { border-color: rgba(96,165,250,.32); box-shadow: 0 4px 14px rgba(0,0,0,.35); }
    .int-pub-card.unread { border-left: 3px solid #f87171; }
    /* Card de intimação já vinculada a um processo do CRM — destaque verde */
    .int-pub-card.linked {
      border-left: 3px solid #10b981;
      background: linear-gradient(90deg, rgba(16,185,129,.06) 0%, transparent 35%);
    }
    .int-pub-card.linked.unread {
      /* Vinculado venceu sobre não-lida visualmente — verde fala "ok, processo conhecido" */
      border-left-color: #10b981;
    }
    html[data-theme="light"] .int-pub-card.linked {
      border-left-color: #059669 !important;
      background: linear-gradient(90deg, rgba(16,185,129,.10) 0%, #FFFFFF 35%) !important;
    }
    /* Ícone do botão "Ações" — destaque AZUL quando intimação já vinculada */
    .int-icon-btn.is-linked {
      color: #60a5fa;
      background: rgba(96,165,250,.20);
      border-color: rgba(96,165,250,.55);
    }
    .int-icon-btn.is-linked:hover { background: rgba(96,165,250,.30); }
    html[data-theme="light"] .int-icon-btn.is-linked {
      color: #1E4A8A !important;
      background: rgba(37,99,235,.15) !important;
      border-color: rgba(37,99,235,.55) !important;
    }
    .int-pub-body { flex: 1; min-width: 0; max-width: 100%; overflow: hidden; }
    .int-pub-meta { display: flex; gap: 12px; align-items: center; margin-bottom: 6px; font-size: .72rem; color: #7A8898; flex-wrap: wrap; min-width: 0; }
    .int-pub-meta strong { color: #93c5fd; font-weight: 600; }
    .int-pub-meta > * { min-width: 0; word-break: break-word; }
    .int-pub-trib {
      display: inline-block; padding: 1px 7px; font-size: .68rem; font-weight: 700;
      background: rgba(36,78,122,.3); color: #93c5fd; border-radius: 4px; letter-spacing: .03em;
      flex-shrink: 0;
    }
    .int-pub-orgao { font-size: .85rem; font-weight: 600; color: #dbeafe; margin-bottom: 4px; word-break: break-word; }
    .int-pub-text {
      font-size: .82rem; color: #b8c7d6; line-height: 1.55;
      word-break: break-word; overflow-wrap: anywhere; white-space: pre-wrap;
      /* Mostra preview de até 8 linhas; estende ao hover/clicar via classe .expanded */
      max-height: 12.5em; overflow: hidden; position: relative;
      transition: max-height .25s ease;
    }
    .int-pub-text.expanded { max-height: none; }
    .int-pub-text-toggle {
      display: inline-block; margin-top: 6px; padding: 3px 10px;
      background: transparent; border: 1px solid rgba(96,165,250,.25);
      border-radius: 6px; color: #93c5fd; cursor: pointer;
      font-size: .72rem; font-weight: 600; letter-spacing: .02em;
    }
    .int-pub-text-toggle:hover { background: rgba(96,165,250,.10); }
    .int-pub-text-fade {
      position: absolute; left: 0; right: 0; bottom: 0; height: 48px;
      /* Dark: fade pro azul do card (mesmo tom do fundo) */
      background: linear-gradient(to bottom, transparent 0%, rgba(13,28,48,.85) 70%, rgba(13,28,48,1) 100%);
      pointer-events: none;
    }
    .int-pub-text.expanded .int-pub-text-fade { display: none; }

    /* Light theme: fade suave em azul claro/branco, sem aquela faixa preta horrível */
    html[data-theme="light"] .int-pub-text-fade {
      background: linear-gradient(to bottom,
        rgba(255,255,255,0) 0%,
        rgba(240,247,255,0.85) 60%,
        rgba(219,234,254,0.95) 100%) !important;
    }
    .int-pub-actions { flex-shrink: 0; }
    .int-pub-actions { display: flex; flex-direction: column; gap: 6px; padding-left: 8px; flex-shrink: 0; }
    .int-icon-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 7px;
      background: rgba(15,33,60,.65); border: 1px solid rgba(96,165,250,.18);
      color: #93c5fd; cursor: pointer; transition: all .15s;
    }
    .int-icon-btn:hover { background: rgba(36,78,122,.5); color: #fff; }
    .int-icon-btn svg { width: 15px; height: 15px; }

    /* ── Empty state ── */
    .int-empty { text-align: center; padding: 60px 20px; color: #7A8898; }
    .int-empty-icon {
      display: inline-flex; padding: 16px; border-radius: 50%;
      background: rgba(36,78,122,.18); color: #6898C0; margin-bottom: 14px;
    }
    .int-empty-icon svg { width: 32px; height: 32px; }
    .int-empty h3 { color: #dbeafe; font-size: 1rem; font-weight: 600; margin-bottom: 6px; }
    .int-empty p { font-size: .85rem; line-height: 1.5; max-width: 380px; margin: 0 auto; }

    /* ── Filtro lateral (vira card-shell) ── */
    .int-filter-section { margin-bottom: 16px; }
    .int-filter-label {
      display: block; font-size: .74rem; color: #7A8898;
      text-transform: uppercase; letter-spacing: .05em; font-weight: 600; margin-bottom: 6px;
    }
    .int-filter-input {
      width: 100%; padding: 8px 10px; background: rgba(8,20,40,.6);
      border: 1px solid rgba(96,165,250,.2); border-radius: 7px;
      color: #dbeafe; font-size: .82rem;
    }
    .int-filter-checkboxes { display: flex; flex-direction: column; gap: 6px; }
    .int-filter-cb { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .int-filter-cb input { accent-color: #6898C0; }
    .int-filter-cb span { font-size: .82rem; color: #dbeafe; }

    /* ── Banner aviso busca manual ── */
    .int-banner-info {
      background: rgba(36,78,122,.2); border: 1px solid rgba(96,165,250,.32);
      border-radius: 9px; padding: 11px 14px; margin-bottom: 14px;
      font-size: .8rem; color: #b8d0e8; line-height: 1.5; display: none;
    }
    .int-banner-info.show { display: block; }

    /* ── Modal Monitores ── */
    .int-modal-bg {
      display: none; position: fixed; inset: 0; z-index: 9999;
      background: rgba(7,15,28,.78); backdrop-filter: blur(4px);
      align-items: center; justify-content: center;
    }
    .int-modal-bg.show { display: flex; }
    .int-modal {
      width: min(720px, 92vw); max-height: 86vh; overflow-y: auto;
      background: linear-gradient(165deg, rgba(14,35,65,.98), rgba(10,23,43,.99));
      border: 1px solid rgba(96,165,250,.25); border-radius: 14px;
      box-shadow: 0 20px 60px rgba(0,0,0,.55);
      padding: 22px;
    }
    .int-modal h2 { font-size: 1.15rem; font-weight: 700; color: #dbeafe; margin-bottom: 4px; }
    .int-modal .modal-sub { color: #7A8898; font-size: .82rem; margin-bottom: 18px; }
    .int-modal-section { margin-bottom: 20px; }
    .int-modal-section h3 {
      font-size: .78rem; font-weight: 600; color: #93c5fd;
      text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px;
    }
    .int-mon-form { display: grid; grid-template-columns: 1fr 140px 110px; gap: 8px; align-items: end; }
    @media (max-width: 900px) { .int-mon-form { grid-template-columns: 1fr 1fr; } }
    /* Variante usada no bloco "Meu perfil" — 3 colunas: UF / OAB / Nome */
    .int-mon-form.profile { grid-template-columns: 70px 1fr 1.6fr; }
    @media (max-width: 900px) { .int-mon-form.profile { grid-template-columns: 1fr 1fr; } }
    .int-mon-form-hint { font-size: .72rem; color: #7A8898; margin-top: 8px; line-height: 1.45; }
    html[data-theme="light"] .int-mon-form-hint { color: #5A6B7E !important; }
    .int-mon-form input, .int-mon-form select {
      width: 100%; padding: 8px 10px; background: rgba(8,20,40,.6);
      border: 1px solid rgba(96,165,250,.2); border-radius: 7px;
      color: #dbeafe; font-size: .82rem;
    }
    .int-mon-form label {
      font-size: .68rem; color: #7A8898; text-transform: uppercase;
      letter-spacing: .04em; font-weight: 600; display: block; margin-bottom: 4px;
    }
    .int-mon-list { display: flex; flex-direction: column; gap: 6px; }
    .int-mon-row {
      display: flex; align-items: center; gap: 10px; padding: 9px 12px;
      background: rgba(13,28,48,.7); border: 1px solid rgba(96,165,250,.12);
      border-radius: 8px; font-size: .82rem; color: #D8E4F0;
    }
    .int-mon-row .pill {
      padding: 1px 7px; font-size: .64rem; font-weight: 700; border-radius: 4px;
      background: rgba(36,78,122,.3); color: #93c5fd; text-transform: uppercase;
      letter-spacing: .03em;
    }
    .int-mon-row .pill.paused  { background: rgba(196,160,64,.18); color: #fbbf24; }
    .int-mon-row .pill.erro    { background: rgba(176,96,112,.18); color: #f87171; }
    .int-mon-row .grow { flex: 1; min-width: 0; }
    .int-mon-row .meta { font-size: .72rem; color: #7A8898; }
    .int-mon-empty { color: #7A8898; text-align: center; padding: 24px; font-size: .85rem; }
    .int-modal-close {
      position: absolute; top: 14px; right: 18px; cursor: pointer;
      width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
      background: rgba(15,33,60,.6); border: 1px solid rgba(96,165,250,.2); border-radius: 7px;
      color: #93c5fd;
    }
    .int-modal-close:hover { background: rgba(176,96,112,.18); color: #f87171; }

    /* ── Popup inline de ação ── */
    .int-action-popup {
      position: absolute; z-index: 200; min-width: 320px; max-width: 380px;
      background: linear-gradient(165deg, rgba(14,35,65,.98), rgba(10,23,43,.99));
      border: 1px solid rgba(96,165,250,.32); border-radius: 11px;
      box-shadow: 0 12px 36px rgba(0,0,0,.55); padding: 14px;
    }
    .int-action-popup h4 {
      font-size: .82rem; font-weight: 600; color: #93c5fd; margin-bottom: 10px;
      display: flex; justify-content: space-between; align-items: center;
    }
    .int-action-popup .close-x { cursor: pointer; color: #7A8898; font-size: 1rem; }
    .int-action-popup textarea,
    .int-action-popup input[type=text],
    .int-action-popup input[type=date] {
      width: 100%; padding: 8px 10px; background: rgba(8,20,40,.6);
      border: 1px solid rgba(96,165,250,.2); border-radius: 7px;
      color: #dbeafe; font-size: .82rem; resize: vertical; min-height: 32px;
    }
    .int-action-popup textarea { min-height: 90px; }
    .int-action-popup label { font-size: .72rem; color: #7A8898; display: block; margin-bottom: 4px; }
    .int-action-popup .actions { display: flex; gap: 8px; margin-top: 10px; justify-content: flex-end; }
    .int-popup-suggest {
      max-height: 220px; overflow-y: auto; margin-top: 4px;
      background: rgba(8,20,40,.6); border: 1px solid rgba(96,165,250,.2); border-radius: 7px;
    }
    .int-popup-suggest-item { padding: 8px 10px; cursor: pointer; border-bottom: 1px solid rgba(96,165,250,.08); }
    .int-popup-suggest-item:last-child { border-bottom: none; }
    .int-popup-suggest-item:hover { background: rgba(36,78,122,.4); }
    .int-popup-suggest-item .label { font-weight: 600; color: #dbeafe; font-size: .82rem; }
    .int-popup-suggest-item .sub   { font-size: .72rem; color: #7A8898; margin-top: 2px; }
    .int-popup-checkbox {
      display: flex; align-items: center; gap: 6px;
      font-size: .78rem; color: #dbeafe; margin-top: 8px;
    }
    .int-popup-checkbox input { accent-color: #6898C0; }

    /* ── Modal Ações da Intimação (seções) ── */
    .int-actions-summary {
      font-size: .82rem; color: #dbeafe;
      margin-bottom: 14px; padding: 8px 11px;
      background: rgba(15,33,60,.5); border-radius: 7px;
      border-left: 3px solid rgba(96,165,250,.55);
      line-height: 1.45;
    }
    .int-actions-summary strong { color: #ffffff; }
    .int-actions-summary .int-summary-sub { color: #9CA3AF; }
    html[data-theme="light"] .int-actions-summary {
      background: #EEF3FB !important;
      border-left-color: #2563EB !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-actions-summary strong { color: #0A1729 !important; }
    html[data-theme="light"] .int-actions-summary .int-summary-sub { color: #4B5B70 !important; }

    .int-actions-section {
      padding: 12px 14px; margin-bottom: 12px;
      background: rgba(15,33,60,.4); border: 1px solid rgba(96,165,250,.18);
      border-radius: 8px;
    }
    .int-actions-section h4 {
      margin: 0 0 8px; font-size: .88rem; color: #dbeafe;
      font-weight: 600; letter-spacing: .01em;
    }
    .int-actions-section label {
      display: block; font-size: .7rem; font-weight: 600; color: #7A8898;
      text-transform: uppercase; margin-bottom: 4px; letter-spacing: .04em;
    }
    .int-actions-section input[type="text"],
    .int-actions-section input[type="date"],
    .int-actions-section select,
    .int-actions-section textarea {
      width: 100%; padding: 8px 10px;
      background: rgba(8,20,40,.6); border: 1px solid rgba(96,165,250,.2);
      border-radius: 7px; color: #dbeafe; font-size: .82rem;
    }
    .int-actions-section .int-actions-input { margin-bottom: 8px; }
    .int-actions-section .int-actions-textarea { resize: vertical; min-height: 70px; }
    .int-actions-list {
      max-height: 240px; overflow-y: auto;
      border: 1px solid rgba(96,165,250,.18); border-radius: 7px;
      background: rgba(8,20,40,.4);
    }
    .act-proc-row {
      padding: 9px 11px; cursor: pointer;
      border-bottom: 1px solid rgba(96,165,250,.10);
      border-left: 3px solid transparent;
      transition: background .15s, border-left-color .15s;
    }
    .act-proc-row:last-child { border-bottom: none; }
    .act-proc-row:hover { background: rgba(96,165,250,.12); }
    .act-proc-row-num { font-size: .82rem; color: #dbeafe; font-weight: 600; }
    .act-proc-row-sub { font-size: .72rem; color: #7A8898; margin-top: 2px; }
    /* Marca a linha do processo já vinculado a essa intimação */
    .act-proc-row.linked {
      border-left-color: #10b981;
      background: rgba(16,185,129,.08);
    }
    .act-proc-row.linked:hover { background: rgba(16,185,129,.14); }
    .act-proc-tag {
      display: inline-block; margin-left: 6px; padding: 1px 7px;
      background: rgba(16,185,129,.20); color: #10b981;
      border: 1px solid rgba(16,185,129,.45); border-radius: 10px;
      font-size: .65rem; text-transform: uppercase; letter-spacing: .04em;
    }
    /* Link "Ver processo" dentro do modal */
    .int-link {
      color: #60a5fa; text-decoration: none;
      border-bottom: 1px dashed rgba(96,165,250,.5);
    }
    .int-link:hover { color: #93c5fd; border-bottom-color: #93c5fd; }
    html[data-theme="light"] .int-link {
      color: #2563EB !important;
      border-bottom-color: rgba(37,99,235,.45) !important;
    }
    html[data-theme="light"] .int-link:hover { color: #1E4A8A !important; }
    html[data-theme="light"] .act-proc-row.linked {
      background: rgba(16,185,129,.10) !important;
      border-left-color: #059669 !important;
    }
    html[data-theme="light"] .act-proc-row.linked:hover { background: rgba(16,185,129,.18) !important; }
    html[data-theme="light"] .act-proc-tag {
      background: rgba(16,185,129,.14) !important;
      color: #047857 !important;
      border-color: rgba(5,150,105,.40) !important;
    }

    html[data-theme="light"] .int-actions-section {
      background: rgba(248,250,253,.95) !important;
      border-color: rgba(15,31,54,.12) !important;
    }
    html[data-theme="light"] .int-actions-section h4 { color: #0F1F36 !important; }
    html[data-theme="light"] .int-actions-section label { color: #5A6B7E !important; }
    html[data-theme="light"] .int-actions-section input,
    html[data-theme="light"] .int-actions-section select,
    html[data-theme="light"] .int-actions-section textarea {
      background: #FFFFFF !important; color: #0F1F36 !important;
      border-color: rgba(15,31,54,.18) !important;
    }
    html[data-theme="light"] .int-actions-list {
      background: #FFFFFF !important;
      border-color: rgba(15,31,54,.14) !important;
    }
    html[data-theme="light"] .act-proc-row {
      border-bottom-color: rgba(15,31,54,.08) !important;
    }
    html[data-theme="light"] .act-proc-row:hover { background: rgba(37,99,235,.07) !important; }
    html[data-theme="light"] .act-proc-row-num { color: #0F1F36 !important; }
    html[data-theme="light"] .act-proc-row-sub { color: #5A6B7E !important; }
    /* Botão único "Ações" no card */
    .int-pub-actions-single {
      display: flex; flex-direction: column; gap: 4px; align-items: flex-end;
    }

    /* ── Texto da publicação — respeita quebras de linha semânticas ── */
    .int-pub-text {
      white-space: pre-wrap;
      word-break: break-word;
    }

    /* ── Linhas "Parte(s):" e "Adv:" no card de publicação ── */
    .int-pub-partes, .int-pub-advs {
      font-size: .74rem; color: #7A8898; margin-top: 4px; line-height: 1.45;
      padding: 4px 8px; background: rgba(15,33,60,.35); border-radius: 5px;
      border-left: 2px solid rgba(96,165,250,.3);
    }
    .int-pub-partes strong, .int-pub-advs strong { color: #9CA3AF; }
    /* Borda esquerda diferenciada: partes (vermelho/laranja sutil) vs advogados (azul) */
    .int-pub-partes { border-left-color: rgba(251,191,36,.4); }
    html[data-theme="light"] .int-pub-partes,
    html[data-theme="light"] .int-pub-advs {
      background: rgba(37,99,235,.05) !important;
      border-left-color: rgba(37,99,235,.35) !important;
      color: #5A6B7E !important;
    }
    html[data-theme="light"] .int-pub-partes { border-left-color: rgba(217,119,6,.5) !important; }
    html[data-theme="light"] .int-pub-partes strong,
    html[data-theme="light"] .int-pub-advs strong { color: #1E2E45 !important; }

    /* ── Aba AASP ── */
    .int-aasp-disclaimer {
      background: rgba(36,78,122,.18);
      border: 1px solid rgba(96,165,250,.28);
      border-left: 3px solid #6898C0;
      border-radius: 8px;
      padding: 12px 14px;
      font-size: .82rem;
      color: #b8d0e8;
      line-height: 1.55;
      margin-bottom: 14px;
    }
    .int-aasp-disclaimer strong { color: #dbeafe; }
    .int-aasp-disclaimer a { color: #93c5fd; text-decoration: underline; }
    html[data-theme="light"] .int-aasp-disclaimer {
      background: rgba(37,99,235,.06) !important;
      border-color: rgba(37,99,235,.22) !important;
      border-left-color: #2563EB !important;
      color: #1E2E45 !important;
    }
    html[data-theme="light"] .int-aasp-disclaimer strong { color: #0F1F36 !important; }
    html[data-theme="light"] .int-aasp-disclaimer a { color: #1E4A8A !important; }
    .int-aasp-toolbar {
      display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap;
    }
    .int-aasp-int-card {
      display: flex; gap: 12px; padding: 14px 16px;
      background: rgba(13,28,48,.75); border: 1px solid rgba(96,165,250,.12);
      border-radius: 10px; margin-bottom: 10px; color: #D8E4F0;
      align-items: center;
    }
    html[data-theme="light"] .int-aasp-int-card {
      background: rgba(255,255,255,.92) !important;
      border-color: rgba(15,31,54,.10) !important;
      color: #1E2E45 !important;
    }
    .int-aasp-int-card.status-active   { border-left: 3px solid #34D399; }
    .int-aasp-int-card.status-pending  { border-left: 3px solid #FBBF24; }
    .int-aasp-int-card.status-error    { border-left: 3px solid #F87171; }
    .int-aasp-int-card.status-inactive { border-left: 3px solid #6B7280; opacity: .7; }
    .int-aasp-int-info { flex: 1; min-width: 0; }
    .int-aasp-int-info h4 { font-size: .95rem; font-weight: 600; color: #dbeafe; margin: 0 0 4px; }
    html[data-theme="light"] .int-aasp-int-info h4 { color: #0F1F36 !important; }
    .int-aasp-int-info .meta { font-size: .74rem; color: #7A8898; }
    html[data-theme="light"] .int-aasp-int-info .meta { color: #5A6B7E !important; }
    .int-aasp-int-info .meta .chip {
      display: inline-block; padding: 1px 7px; background: rgba(36,78,122,.3);
      color: #93c5fd; border-radius: 4px; font-size: .68rem; font-weight: 700;
      letter-spacing: .03em; margin-right: 4px;
    }
    .int-aasp-int-info .meta .chip.active   { background: rgba(52,211,153,.18); color: #34D399; }
    .int-aasp-int-info .meta .chip.pending  { background: rgba(251,191,36,.18); color: #FBBF24; }
    .int-aasp-int-info .meta .chip.error    { background: rgba(248,113,113,.18); color: #F87171; }
    .int-aasp-int-info .meta .chip.inactive { background: rgba(107,114,128,.18); color: #9CA3AF; }
    /* Light theme: chips precisam de contraste sobre fundo branco */
    html[data-theme="light"] .int-aasp-int-info .meta .chip {
      background: rgba(37,99,235,.14) !important; color: #1E4A8A !important;
    }
    html[data-theme="light"] .int-aasp-int-info .meta .chip.active {
      background: rgba(5,150,105,.16) !important; color: #047857 !important;
    }
    html[data-theme="light"] .int-aasp-int-info .meta .chip.pending {
      background: rgba(217,119,6,.16) !important; color: #B45309 !important;
    }
    html[data-theme="light"] .int-aasp-int-info .meta .chip.error {
      background: rgba(220,38,38,.14) !important; color: #B91C1C !important;
    }
    html[data-theme="light"] .int-aasp-int-info .meta .chip.inactive {
      background: rgba(107,114,128,.14) !important; color: #4B5563 !important;
    }
    .int-aasp-int-actions { display: flex; gap: 6px; flex-shrink: 0; }
    /* Ícones de ação dentro do card AASP — override forçado pro tema claro */
    html[data-theme="light"] .int-aasp-int-card .int-icon-btn {
      background: rgba(37,99,235,.08) !important;
      border-color: rgba(37,99,235,.28) !important;
      color: #1E4A8A !important;
    }
    html[data-theme="light"] .int-aasp-int-card .int-icon-btn:hover {
      background: rgba(37,99,235,.20) !important;
      color: #0F2C5A !important;
    }
    html[data-theme="light"] .int-aasp-int-card .int-icon-btn svg {
      stroke: currentColor !important;
    }
    .int-aasp-mask {
      font-family: 'Courier New', monospace; font-size: .82rem;
      color: #93c5fd; background: rgba(36,78,122,.2);
      padding: 2px 6px; border-radius: 4px; letter-spacing: .04em;
    }
    html[data-theme="light"] .int-aasp-mask {
      background: rgba(37,99,235,.10) !important; color: #1E4A8A !important;
    }

    /* Resumo da última busca AASP — dark + light themes */
    .int-aasp-lastsearch {
      margin-top: 12px; padding: 10px 12px;
      background: rgba(96,165,250,.10);
      border: 1px solid rgba(96,165,250,.30);
      border-radius: 8px; font-size: .80rem; color: #dbeafe; line-height: 1.55;
    }
    .int-aasp-lastsearch strong { color: #ffffff; }
    .int-aasp-lastsearch .muted { color: #93c5fd; font-size: .72rem; }
    html[data-theme="light"] .int-aasp-lastsearch {
      background: rgba(37,99,235,.08) !important;
      border-color: rgba(37,99,235,.32) !important;
      color: #1E2E45 !important;
    }
    html[data-theme="light"] .int-aasp-lastsearch strong { color: #0F1F36 !important; }
    html[data-theme="light"] .int-aasp-lastsearch .muted { color: #5A6B7E !important; }
    /* Diagnóstico raw — pre/code com contraste no tema claro */
    html[data-theme="light"] .int-aasp-lastsearch pre,
    html[data-theme="light"] .int-aasp-lastsearch code {
      background: rgba(240,247,255,.95) !important;
      color: #1E2E45 !important;
      border: 1px solid rgba(15,31,54,.12);
    }
    html[data-theme="light"] .int-aasp-lastsearch summary { color: #1E4A8A !important; }

    /* Dica "2 formas de puxar" — dark + light themes */
    .int-aasp-tip {
      margin-top: 14px; font-size: .78rem; color: #b8c7d6; line-height: 1.6;
      padding: 10px 12px; background: rgba(15,33,60,.5); border-radius: 7px;
      border-left: 3px solid rgba(96,165,250,.4);
    }
    .int-aasp-tip strong { color: #dbeafe; }
    html[data-theme="light"] .int-aasp-tip {
      background: rgba(248,250,253,.95) !important;
      border-left-color: #2563EB !important;
      color: #2E3A4F !important;
    }
    html[data-theme="light"] .int-aasp-tip strong { color: #0F1F36 !important; }

    /* Microcopy do botão "Buscar publicações" no painel lateral */
    #searchHint { color: #b8c7d6; }
    #searchHint strong { color: #dbeafe; }
    html[data-theme="light"] #searchHint {
      background: rgba(37,99,235,.06) !important;
      border-left-color: #2563EB !important;
      color: #2E3A4F !important;
    }
    html[data-theme="light"] #searchHint strong { color: #0F1F36 !important; }
    #searchHint.aasp-mode { border-left-color: rgba(139,28,45,.5); }
    #searchHint.realtime-mode { border-left-color: rgba(251,191,36,.6); }
    /* Test result box */
    #aaspTestResult {
      margin-top: 12px; padding: 11px 13px; border-radius: 8px;
      font-size: .82rem; line-height: 1.5;
    }
    #aaspTestResult.ok    { background: rgba(52,211,153,.10); border: 1px solid rgba(52,211,153,.4); color: #34D399; }
    #aaspTestResult.error { background: rgba(248,113,113,.10); border: 1px solid rgba(248,113,113,.4); color: #F87171; }
    #aaspTestResult.info  { background: rgba(96,165,250,.10); border: 1px solid rgba(96,165,250,.32); color: #93c5fd; }
    #aaspTestResult pre {
      margin-top: 6px; padding: 8px; max-height: 200px; overflow:auto;
      background: rgba(8,20,40,.6); border-radius: 5px; font-size: .72rem;
      white-space: pre-wrap; word-break: break-word;
    }
    html[data-theme="light"] #aaspTestResult pre {
      background: rgba(240,247,255,.9) !important; color: #1E2E45 !important;
    }
    /* Input type=date no tema claro precisa de contraste */
    html[data-theme="light"] #aaspTestData {
      background: #FFFFFF !important; color: #0F1F36 !important;
      border-color: rgba(15,31,54,.18) !important;
    }
    /* No tema escuro, browsers renderizam o ícone do calendário em preto sobre fundo escuro — invisível. Inverte. */
    #aaspTestData::-webkit-calendar-picker-indicator { filter: invert(0.85); }
    html[data-theme="light"] #aaspTestData::-webkit-calendar-picker-indicator { filter: invert(0.25); }

    /* ── Helper do calendário Flatpickr range (passo-a-passo) ── */
    .fp-range-helper {
      padding: 10px 12px; text-align: center;
      font-size: .82rem; font-weight: 600;
      border-bottom: 1px solid rgba(96,165,250,.2);
      letter-spacing: .01em;
    }
    .fp-range-helper-info { background: rgba(96,165,250,.12); color: #93c5fd; }
    .fp-range-helper-step { background: rgba(251,191,36,.14); color: #FBBF24; }
    .fp-range-helper-done { background: rgba(52,211,153,.14); color: #34D399; }
    html[data-theme="light"] .fp-range-helper-info { background: rgba(37,99,235,.10) !important; color: #1E4A8A !important; }
    html[data-theme="light"] .fp-range-helper-step { background: rgba(217,119,6,.12) !important; color: #B45309 !important; }
    html[data-theme="light"] .fp-range-helper-done { background: rgba(5,150,105,.12) !important; color: #047857 !important; }

    /* ── Flatpickr customization (calendário range) ── */
    .flatpickr-calendar {
      background: linear-gradient(165deg, #0E2341 0%, #0A172B 100%) !important;
      border: 1px solid rgba(96,165,250,.32) !important;
      box-shadow: 0 12px 36px rgba(0,0,0,.55) !important;
      color: #dbeafe !important;
    }
    .flatpickr-calendar .flatpickr-months .flatpickr-month,
    .flatpickr-calendar .flatpickr-current-month,
    .flatpickr-calendar .flatpickr-monthDropdown-months,
    .flatpickr-calendar .flatpickr-weekday {
      color: #dbeafe !important; background: transparent !important; fill: #dbeafe !important;
    }
    .flatpickr-calendar .flatpickr-monthDropdown-months option { background: #0E2341 !important; color: #dbeafe !important; }
    .flatpickr-calendar .flatpickr-prev-month svg,
    .flatpickr-calendar .flatpickr-next-month svg { fill: #93c5fd !important; }
    .flatpickr-calendar .flatpickr-prev-month:hover svg,
    .flatpickr-calendar .flatpickr-next-month:hover svg { fill: #fff !important; }
    .flatpickr-calendar .flatpickr-day {
      color: #b8c7d6 !important; background: transparent !important; border: 1px solid transparent !important;
    }
    .flatpickr-calendar .flatpickr-day:hover { background: rgba(36,78,122,.45) !important; color: #fff !important; }
    .flatpickr-calendar .flatpickr-day.today {
      border-color: #6898C0 !important; color: #fff !important; font-weight: 700 !important;
    }
    .flatpickr-calendar .flatpickr-day.selected,
    .flatpickr-calendar .flatpickr-day.startRange,
    .flatpickr-calendar .flatpickr-day.endRange {
      background: linear-gradient(135deg, #244E7A, #2563EB) !important; color: #fff !important;
      border-color: rgba(96,165,250,.6) !important;
    }
    .flatpickr-calendar .flatpickr-day.inRange {
      background: rgba(36,78,122,.4) !important; color: #dbeafe !important; box-shadow: none !important;
    }
    .flatpickr-calendar .flatpickr-day.disabled,
    .flatpickr-calendar .flatpickr-day.flatpickr-disabled,
    .flatpickr-calendar .flatpickr-day.prevMonthDay,
    .flatpickr-calendar .flatpickr-day.nextMonthDay { color: #4a5868 !important; }
    .flatpickr-calendar .numInputWrapper:hover { background: rgba(36,78,122,.3) !important; }
    .flatpickr-calendar input.numInput { color: #dbeafe !important; }
    .flatpickr-calendar .arrowUp::after { border-bottom-color: #93c5fd !important; }
    .flatpickr-calendar .arrowDown::after { border-top-color: #93c5fd !important; }

    /* Light theme overrides */
    html[data-theme="light"] .flatpickr-calendar {
      background: linear-gradient(165deg, #FFFFFF 0%, #F4F7FB 100%) !important;
      border: 1px solid rgba(15,31,54,.18) !important;
      box-shadow: 0 12px 36px rgba(15,31,54,.18) !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-month,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-current-month,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-monthDropdown-months,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-weekday { color: #0F1F36 !important; fill: #0F1F36 !important; }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-monthDropdown-months option { background: #FFFFFF !important; color: #0F1F36 !important; }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-prev-month svg,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-next-month svg { fill: #1E4A8A !important; }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day { color: #1E2E45 !important; }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day:hover { background: rgba(37,99,235,.10) !important; color: #1E4A8A !important; }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.today { border-color: #2563EB !important; color: #1E4A8A !important; }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.selected,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.startRange,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.endRange {
      background: linear-gradient(135deg, #1E4A8A, #2563EB) !important; color: #FFFFFF !important;
    }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.inRange {
      background: rgba(37,99,235,.12) !important; color: #1E4A8A !important;
    }
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.disabled,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.prevMonthDay,
    html[data-theme="light"] .flatpickr-calendar .flatpickr-day.nextMonthDay { color: #B8C2D0 !important; }
    html[data-theme="light"] .flatpickr-calendar input.numInput { color: #0F1F36 !important; }

    /* ══════════════════════════════════════════════════════════════════════
       TEMA CLARO — Patches dos blocos que ficavam dark sobre fundo branco
       Mapeados via auditoria: aba AASP ativa, botao Conectar, card AASP,
       banner info, day header, card de publicacao base.
       ══════════════════════════════════════════════════════════════════════ */

    /* 1) Aba "Integração AASP" ativa */
    html[data-theme="light"] .int-tab.active {
      background: #EFF6FF !important;
      border-color: #BFDBFE !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .int-tab {
      color: #5A6B7E !important;
    }
    html[data-theme="light"] .int-tab:hover {
      color: #1E4A8A !important;
      background: #F1F5F9 !important;
    }

    /* 2) Botão "+ Conectar chave AASP" (.int-btn.primary) */
    html[data-theme="light"] .int-btn.primary {
      background: linear-gradient(135deg, #2563EB, #1D4ED8) !important;
      border-color: #1D4ED8 !important;
      color: #FFFFFF !important;
    }
    html[data-theme="light"] .int-btn.primary:hover {
      filter: brightness(1.08);
    }
    /* .int-btn neutro (Sincronizar etc.) — outline azul claro */
    html[data-theme="light"] .int-btn:not(.primary):not(.danger) {
      background: #FFFFFF !important;
      border-color: #BFDBFE !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .int-btn:not(.primary):not(.danger):hover {
      background: #EFF6FF !important;
    }

    /* 3) Card AASP (.int-aasp-int-card + status-*) */
    html[data-theme="light"] .int-aasp-int-card {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-aasp-int-card .pill.active,
    html[data-theme="light"] .int-aasp-int-card .pill-active {
      background: #D1FAE5 !important; color: #047857 !important; border-color: #6EE7B7 !important;
    }
    html[data-theme="light"] .int-aasp-int-card .pill.pending,
    html[data-theme="light"] .int-aasp-int-card .pill-pending {
      background: #FEF3C7 !important; color: #B45309 !important; border-color: #FCD34D !important;
    }
    html[data-theme="light"] .int-aasp-int-card .pill.error,
    html[data-theme="light"] .int-aasp-int-card .pill-error {
      background: #FEE2E2 !important; color: #B91C1C !important; border-color: #FCA5A5 !important;
    }

    /* 4) Banner azul "Esta busca consulta fontes oficiais..." (.int-banner-info) */
    html[data-theme="light"] .int-banner-info {
      background: #EFF6FF !important;
      border-color: #BFDBFE !important;
      color: #1E4A8A !important;
    }

    /* 5) Header de dia "Dia 25/05/2026 — N publicações" (.int-day-header) */
    html[data-theme="light"] .int-day-header {
      background: #F1F5F9 !important;
      color: #1D4ED8 !important;
      border-color: #CBD5E1 !important;
    }

    /* 6) Card de publicação base (.int-pub-card) — tribunal + intimação + nome */
    html[data-theme="light"] .int-pub-card {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-pub-card h4,
    html[data-theme="light"] .int-pub-card .int-pub-title { color: #0F1F36 !important; }
    html[data-theme="light"] .int-pub-card .int-pub-meta,
    html[data-theme="light"] .int-pub-card .int-pub-text { color: #4B5B70 !important; }
    /* Badges dentro do card (TJSP, Intimação, etc.) */
    html[data-theme="light"] .int-pub-card .int-badge,
    html[data-theme="light"] .int-pub-card .pill {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
      border-color: #BFDBFE !important;
    }
    /* "resultado não persistido" — badge amarelo */
    html[data-theme="light"] .int-pub-card [class*="nao-persist"],
    html[data-theme="light"] .int-pub-card .int-pill-warn {
      background: #FEF3C7 !important;
      color: #B45309 !important;
      border-color: #FCD34D !important;
    }
    /* Texto longo do conteúdo da intimação */
    html[data-theme="light"] .int-pub-card .int-pub-body,
    html[data-theme="light"] .int-pub-card p {
      color: #0F1F36 !important;
    }
    /* Botões de ação na coluna direita (favoritar, comentário, etc.) */
    html[data-theme="light"] .int-pub-card .int-icon-btn {
      background: #FFFFFF !important;
      border: 1px solid #BFDBFE !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .int-pub-card .int-icon-btn:hover {
      background: #EFF6FF !important;
    }

    /* 7) Textos azul-claros que ficavam ilegíveis no tema claro
       (#dbeafe e #93c5fd sobre fundo branco = fantasma).
       Cobre: nome do órgão, labels dos filtros, "Ver tudo (...)", etc. */
    html[data-theme="light"] .int-pub-orgao {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-pub-meta strong {
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .int-pub-text-toggle {
      background: #FFFFFF !important;
      border: 1px solid #BFDBFE !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .int-pub-text-toggle:hover {
      background: #EFF6FF !important;
      border-color: #2563EB !important;
    }
    /* Sidebar de filtros — "Buscar por" */
    html[data-theme="light"] .int-filter-label {
      color: #475569 !important;
    }
    html[data-theme="light"] .int-filter-input {
      background: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-filter-input::placeholder {
      color: #94A3B8 !important;
    }
    /* Labels dos checkboxes: Lidas / Não lidas / Favoritas / Com prazo */
    html[data-theme="light"] .int-filter-cb span {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-filter-cb input { accent-color: #2563EB !important; }
    /* Empty state ("nenhuma intimação encontrada") */
    html[data-theme="light"] .int-empty {
      background: #F1F5F9 !important;
      color: #475569 !important;
    }
    html[data-theme="light"] .int-empty h3 { color: #0F1F36 !important; }
    /* Toolbar info: "AASP — 25/05/2026: 0 publicações" */
    html[data-theme="light"] .int-toolbar-info strong { color: #1D4ED8 !important; }
    /* Tab inativa em hover */
    html[data-theme="light"] .int-tab:not(.active) { color: #475569 !important; }
    html[data-theme="light"] .int-tab:not(.active):hover { color: #1E4A8A !important; }
    /* Botão "Sincronizar / Buscar" (.int-toolbar-info link inline) */
    html[data-theme="light"] .int-toolbar-info { color: #1E4A8A !important; }

    /* 8) Badges TJSP / Intimação / Processo no header do card de publicação
       (substituem o pasteizinho rgba+93c5fd que sumia no claro). */
    html[data-theme="light"] .int-pub-trib {
      background: #DBEAFE !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .int-pub-meta {
      color: #475569 !important;
    }
    html[data-theme="light"] .int-pub-meta strong {
      color: #1D4ED8 !important;
    }
    /* Texto preview da intimação (.int-pub-text) — cinza médio era claro demais */
    html[data-theme="light"] .int-pub-text {
      color: #334155 !important;
    }
    /* Fade-out na parte inferior do preview quando truncado */
    html[data-theme="light"] .int-pub-text-fade {
      background: linear-gradient(to bottom, transparent 0%, rgba(255,255,255,.85) 70%, #FFFFFF 100%) !important;
    }

    /* 9) Modal "Vincular processo + criar prazo" (.int-modal-bg / .int-modal) */
    html[data-theme="light"] .int-modal-bg {
      background: rgba(15,31,54,0.45) !important;
    }
    html[data-theme="light"] .int-modal {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 24px 60px rgba(15,31,54,0.18) !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-modal h2 {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-modal h4 {
      color: #1E4A8A !important;
    }
    html[data-theme="light"] .int-modal p {
      color: #475569 !important;
    }
    html[data-theme="light"] .int-modal label {
      color: #475569 !important;
    }
    html[data-theme="light"] .int-modal-close {
      background: #F1F5F9 !important;
      border: 1px solid #CBD5E1 !important;
      color: #475569 !important;
    }
    html[data-theme="light"] .int-modal-close:hover {
      background: #FEE2E2 !important;
      color: #B91C1C !important;
      border-color: #FCA5A5 !important;
    }
    /* Header summary (caixa azul com TJSP · Intimação · Processo) */
    html[data-theme="light"] .int-actions-summary {
      background: #EFF6FF !important;
      border: 1px solid #BFDBFE !important;
      color: #1E4A8A !important;
    }
    html[data-theme="light"] .int-actions-summary strong { color: #0A1729 !important; }
    /* Seções (cards) dentro do modal */
    html[data-theme="light"] .int-actions-section {
      background: #F8FAFC !important;
      border: 1px solid #E2E8F0 !important;
      color: #0F1F36 !important;
    }
    /* Input de busca dentro do modal */
    html[data-theme="light"] .int-actions-input,
    html[data-theme="light"] .int-actions-textarea {
      background: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-actions-input::placeholder,
    html[data-theme="light"] .int-actions-textarea::placeholder {
      color: #94A3B8 !important;
    }
    /* Lista de processos no modal (items PROC-MATRIZ...) */
    html[data-theme="light"] .int-actions-list {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .int-actions-list > div,
    html[data-theme="light"] .int-actions-list .int-actions-list-item {
      background: #FFFFFF !important;
      border-bottom: 1px solid #F1F5F9 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-actions-list > div:hover,
    html[data-theme="light"] .int-actions-list .int-actions-list-item:hover {
      background: #EFF6FF !important;
    }
    /* Form do prazo (labels + inputs/selects dentro do .int-mon-form) */
    html[data-theme="light"] .int-mon-form label {
      color: #475569 !important;
    }
    html[data-theme="light"] .int-mon-form input,
    html[data-theme="light"] .int-mon-form select,
    html[data-theme="light"] .int-mon-form textarea {
      background-color: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-mon-form input::placeholder,
    html[data-theme="light"] .int-mon-form textarea::placeholder {
      color: #94A3B8 !important;
    }

    /* 10) Lista de monitores ATIVOS / PAUSADOS no modal Monitoramento.
       Cada linha (.int-mon-row) era cinza-escura sobre fundo branco. */
    html[data-theme="light"] .int-mon-row {
      background: #F8FAFC !important;
      border: 1px solid #E2E8F0 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-mon-row .grow strong,
    html[data-theme="light"] .int-mon-row strong {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .int-mon-row .meta {
      color: #5A6B7E !important;
    }
    /* Pill ATIVO (default) — verde claro pra reforcar status positivo */
    html[data-theme="light"] .int-mon-row .pill {
      background: #D1FAE5 !important;
      color: #047857 !important;
      border: 1px solid #6EE7B7 !important;
    }
    html[data-theme="light"] .int-mon-row .pill.paused {
      background: #FEF3C7 !important;
      color: #B45309 !important;
      border-color: #FCD34D !important;
    }
    html[data-theme="light"] .int-mon-row .pill.erro {
      background: #FEE2E2 !important;
      color: #B91C1C !important;
      border-color: #FCA5A5 !important;
    }
    /* Empty state "Nenhum monitor cadastrado" */
    html[data-theme="light"] .int-mon-empty {
      color: #5A6B7E !important;
    }
  </style>
</head>
<body>
<main class="w-full px-6 py-6">
  <div class="page-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <section class="main-content">
      <!-- Header padrão Yuris -->
      <div class="card-shell page-header" style="margin-bottom:16px;flex-shrink:0;">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">Intimações</h2>
            <p class="page-header-subtitle">Publicações do Diário de Justiça Eletrônico Nacional, busca ativa e leitura de termos.</p>
          </div>
          <div class="page-header-actions">
            <button class="int-btn primary" id="btnBuscarHoje">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Buscar publicações de hoje
            </button>
            <button class="int-btn" id="btnMonitores">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              Monitoramento
            </button>
          </div>
        </div>
      </div>

      <!-- Conteúdo principal: grid lista + filtro lateral -->
      <div class="int-grid">

        <!-- Lista principal -->
        <div class="card-shell" style="padding:18px;">
          <!-- Tabs no topo -->
          <div class="int-tabs">
            <div class="int-tab active" data-tab="djen">Intimações publicadas no Diário<span class="int-tab-count" id="kpiNaoLidas"><?= (int)$kpiNaoLidas ?> não lidas</span></div>
            <div class="int-tab" data-tab="aasp">Integração AASP<span class="int-tab-count" id="kpiAaspIntegracoes" style="display:none;">0</span></div>
          </div>

          <!-- Painel da aba AASP (escondido por padrão) — fica EM CIMA da lista -->
          <div id="intAaspPanel" style="display:none;margin-bottom:14px;">
            <div class="int-aasp-disclaimer">
              <strong>Integração com API de Intimações AASP para associados habilitados.</strong>
              Use sua chave individual de associado ou chave de empresa (escritório/sociedade)
              obtida em <a href="https://intimacaoapi-cadastro.aasp.org.br/" target="_blank" rel="noopener">intimacaoapi-cadastro.aasp.org.br</a>.
              A chave fica <strong>criptografada</strong> (AES-256-GCM) e nunca é exposta na UI ou em logs.
            </div>

            <div class="int-aasp-toolbar">
              <button class="int-btn primary" id="btnAaspConectar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Conectar chave AASP
              </button>
              <span class="int-toolbar-info" id="aaspSummary">Carregando integrações…</span>
            </div>

            <div id="aaspIntegrationsList">
              <div class="int-empty" style="padding:30px 20px;">
                <span class="int-empty-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </span>
                <h3>Carregando…</h3>
                <p></p>
              </div>
            </div>

            <!-- Resumo da última busca por período (preenchido via JS após search) -->
            <div id="aaspLastSearch" class="int-aasp-lastsearch" style="display:none;"></div>

            <div class="int-aasp-tip">
              <strong>2 formas de puxar intimações:</strong>
              <strong>1)</strong> Botão <strong>Sincronizar</strong> no card acima — puxa o dia de hoje (rápido);
              <strong>2)</strong> Filtro lateral &rarr; <strong>Período</strong> (Hoje / 7 dias / 30 dias) + <strong>Filtrar</strong> — busca intervalo de datas (máx 30 dias). A lista de publicações aparece logo abaixo &darr;
            </div>
          </div>

          <div class="int-toolbar">
            <span class="int-toolbar-info" id="intCacheToolbar">Cache atual: <strong><?= (int)$kpiCacheHoje ?></strong> <?= $kpiCacheHoje === 1 ? 'publicação' : 'publicações' ?></span>
          </div>

          <div class="int-banner-info" id="bannerBuscaManual">
            Esta busca consulta fontes oficiais em tempo real. Os resultados serão exibidos para conferência e
            <strong>só serão salvos</strong> se você marcar como lida, favoritar, comentar ou vincular ao processo.
          </div>

          <div id="intList">
            <div class="int-empty">
              <span class="int-empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              </span>
              <h3>Nenhuma publicação carregada ainda</h3>
              <p>Clique em <strong>Buscar publicações de hoje</strong> para consultar o DJEN agora, ou configure monitoramento automático.</p>
            </div>
          </div>
        </div>

        <!-- Filtro lateral -->
        <aside class="card-shell" style="padding:18px;position:sticky;top:18px;">
          <h3 class="page-header-title" style="font-size:1rem;margin-bottom:4px;">Buscar por</h3>
          <p style="font-size:.74rem;color:#7A8898;margin-bottom:14px;line-height:1.45;">
            Defina os critérios abaixo e clique em <strong>Buscar publicações</strong> no final.
          </p>

          <div class="int-filter-section">
            <label class="int-filter-label">Período</label>
            <input type="text" class="int-filter-input" id="filterPeriodo" placeholder="Clique para escolher datas" readonly>
            <!-- Inputs hidden mantidos pra retro-compat com collectFilters -->
            <input type="hidden" id="filterDataInicio">
            <input type="hidden" id="filterDataFim">
            <div style="display:flex;gap:6px;margin-top:6px;">
              <button type="button" class="int-btn" id="periodHoje" style="flex:1;padding:5px 8px;font-size:.7rem;">Hoje</button>
              <button type="button" class="int-btn" id="period7dias" style="flex:1;padding:5px 8px;font-size:.7rem;">7 dias</button>
              <button type="button" class="int-btn" id="period30dias" style="flex:1;padding:5px 8px;font-size:.7rem;">30 dias</button>
              <button type="button" class="int-btn" id="periodLimpar" style="flex:0 0 auto;padding:5px 8px;font-size:.7rem;" title="Limpar período">×</button>
            </div>
          </div>

          <div class="int-filter-section">
            <label class="int-filter-label">Status</label>
            <div class="int-filter-checkboxes">
              <label class="int-filter-cb"><input type="checkbox" id="fLidas"><span>Lidas</span></label>
              <label class="int-filter-cb"><input type="checkbox" id="fNaoLidas" checked><span>Não lidas</span></label>
              <label class="int-filter-cb"><input type="checkbox" id="fFavoritas"><span>Favoritas</span></label>
              <label class="int-filter-cb"><input type="checkbox" id="fComPrazo"><span>Com prazo</span></label>
            </div>
          </div>

          <!-- Tribunal/Processo/OAB/Nome removidos — info já está no Monitoramento -->

          <!-- Hint quando perfil não configurado: leva pro modal Monitoramento -->
          <div id="profileNudge" style="display:none;background:rgba(37,99,235,0.08);border:1px solid rgba(37,99,235,0.32);border-radius:7px;padding:9px 11px;font-size:.75rem;color:#1E4A8A;line-height:1.45;margin-bottom:10px;">
            <strong>Dica:</strong> configure sua <strong>OAB</strong> e <strong>nome</strong> em
            <a href="#" id="lnkOpenMon" style="color:#1E4A8A;font-weight:600;text-decoration:underline;">Monitoramento</a>
            pra ver só <em>suas</em> intimações automaticamente.
          </div>

          <!-- Microcopy contextual — atualizada via JS conforme aba + período preenchido -->
          <div id="searchHint" style="font-size:.72rem;color:#7A8898;line-height:1.45;margin-top:12px;padding:8px 10px;background:rgba(96,165,250,.06);border-radius:6px;border-left:2px solid rgba(96,165,250,.4);">
            Vai buscar no <strong>Diário (DJEN)</strong> com os critérios definidos.
          </div>

          <div style="display: flex; gap: 8px; margin-top: 10px;">
            <button class="int-btn" style="flex: 0 0 auto;padding:9px 14px;" id="btnLimparFiltro">Limpar</button>
            <button class="int-btn primary" style="flex: 1;padding:10px 14px;font-weight:700;" id="btnAplicarFiltro">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="margin-right:4px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Buscar publicações
            </button>
          </div>
        </aside>

      </div>
    </section>
  </div>
</main>

<!-- Modal Setup Inicial (Perfil DJEN) -->
<div class="int-modal-bg" id="profileModal">
  <div class="int-modal" style="position:relative;max-width:520px;">
    <span class="int-modal-close" id="profileModalClose" title="Fechar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </span>
    <h2>Configure seu perfil de busca</h2>
    <p class="modal-sub">
      Cadastre sua <strong>OAB</strong> e/ou <strong>nome do advogado</strong> uma única vez.
      Daí em diante o Yuris busca <strong>só suas intimações</strong> automaticamente,
      igual a AASP. Sem isso, a busca traz todas as publicações do tribunal e fica difícil filtrar.
    </p>

    <div class="int-modal-section">
      <div class="int-mon-form" style="grid-template-columns: 80px 1fr;">
        <div>
          <label>UF</label>
          <input type="text" id="profUf" placeholder="SP" maxlength="2">
        </div>
        <div>
          <label>OAB</label>
          <input type="text" id="profOab" placeholder="ex: 357838">
        </div>
      </div>
      <div class="int-mon-form" style="grid-template-columns: 1fr; margin-top:8px;">
        <div>
          <label>Nome do advogado (como aparece no Diário)</label>
          <input type="text" id="profNome" placeholder="ex: Bruno Carreira Ferreira">
        </div>
      </div>
      <div class="int-mon-form-hint">
        Recomendado preencher os 2 campos — algumas intimações vêm só pelo nome, outras só pela OAB.
        Vai criar <strong>1 monitor automático</strong> para cada campo preenchido.
      </div>
    </div>

    <div style="display:flex;gap:8px;margin-top:14px;">
      <button class="int-btn" id="profSkip" style="flex:0 0 auto;">Continuar sem filtro</button>
      <button class="int-btn primary" id="profSubmit" style="flex:1;">Salvar e buscar</button>
    </div>
  </div>
</div>

<!-- Modal Ações da Intimação (unificado: lida/favorita/prazo/comentário/vincular/criar tarefa) -->
<div class="int-modal-bg" id="intActionsModal">
  <div class="int-modal" style="position:relative;max-width:680px;max-height:88vh;overflow-y:auto;">
    <span class="int-modal-close" id="intActionsClose" title="Fechar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </span>
    <h2>Vincular processo + criar prazo</h2>
    <div id="intActionsSummary" class="int-actions-summary"></div>

    <!-- ETAPA 1: Vincular ao processo -->
    <div class="int-actions-section" id="actStep1Vincular">
      <h4>1. Vincular ao processo do CRM</h4>
      <div id="actVinculoStatus" style="font-size:.78rem;margin-bottom:8px;"></div>

      <!-- Search inline (filtra a lista abaixo) -->
      <input type="text" id="actBuscaProcesso" class="int-actions-input"
             placeholder="Buscar por número CNJ, cliente, parte contrária ou tipo da ação..."
             autocomplete="off">

      <!-- Lista de processos (sempre visível, scrollable) -->
      <div id="actListaProcessos" class="int-actions-list"></div>

      <div style="display:flex;justify-content:space-between;margin-top:8px;align-items:center;">
        <p style="font-size:.7rem;color:#7A8898;line-height:1.4;margin:0;flex:1;">
          <strong>💡 Auto-link:</strong> próximas intimações com mesmo CNJ vinculadas automaticamente.
        </p>
        <button class="int-btn" id="actBtnDesvincular" style="background:rgba(176,96,112,.15);border-color:rgba(176,96,112,.3);color:#f87171;display:none;margin-left:8px;">Desvincular</button>
      </div>
    </div>

    <!-- ETAPA 2: Criar prazo processual (só aparece após vincular) -->
    <div class="int-actions-section" id="actStep2Prazo" style="display:none;">
      <h4>2. Criar prazo processual</h4>
      <p style="font-size:.72rem;color:#7A8898;margin:0 0 10px;">
        O prazo aparecerá em <strong>Processos &rarr; aba Prazos</strong> do processo vinculado.
      </p>
      <div class="int-mon-form" style="grid-template-columns:1fr;">
        <div>
          <label>Descrição do prazo</label>
          <input type="text" id="actPrazoDescricao" style="font-size:.85rem;" placeholder="ex: Apresentar contrarrazões em 15 dias">
        </div>
      </div>
      <div class="int-mon-form" style="grid-template-columns:1fr 1fr 1fr;margin-top:6px;">
        <div>
          <label>Data limite</label>
          <input type="date" id="actPrazoDataLimite" style="font-size:.85rem;">
        </div>
        <div>
          <label>Prioridade</label>
          <select id="actPrazoPrioridade" style="font-size:.85rem;">
            <option value="alta">Alta</option>
            <option value="media">Média</option>
            <option value="baixa">Baixa</option>
          </select>
        </div>
        <div>
          <label>Responsável</label>
          <select id="actPrazoResponsavel" style="font-size:.85rem;">
            <option value="">— Selecionar —</option>
          </select>
        </div>
      </div>
      <div class="int-mon-form" style="grid-template-columns:1fr;margin-top:6px;">
        <div>
          <label>Observação (opcional)</label>
          <textarea id="actPrazoObservacao" rows="3" class="int-actions-textarea"
                    placeholder="Detalhes adicionais (origem, link ao documento, etc.)"></textarea>
        </div>
      </div>
      <button class="int-btn primary" id="actBtnCriarPrazo" style="margin-top:10px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Criar prazo processual
      </button>
    </div>

  </div>
</div>

<!-- Modal AASP (criar/editar integração) -->
<div class="int-modal-bg" id="aaspModal">
  <div class="int-modal" style="position:relative;max-width:600px;">
    <span class="int-modal-close" id="aaspModalClose" title="Fechar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </span>
    <h2 id="aaspModalTitle">Conectar chave AASP</h2>
    <p class="modal-sub">
      Cole sua chave AASP. Ela é cifrada com AES-256-GCM no servidor — nem nós, nem você
      voltamos a ver ela depois de salvar (só os 4 últimos dígitos pra identificar).
      <br>Cadastro de chave: <a href="https://intimacaoapi-cadastro.aasp.org.br/" target="_blank" rel="noopener" style="color:#93c5fd;text-decoration:underline;">intimacaoapi-cadastro.aasp.org.br</a>
    </p>

    <div class="int-modal-section">
      <div class="int-mon-form" style="grid-template-columns: 1fr;">
        <div>
          <label>Nome da integração</label>
          <input type="text" id="aaspNome" placeholder="ex: Bruno Carreira — chave individual" autocomplete="off">
        </div>
      </div>
      <div class="int-mon-form" style="grid-template-columns: 1fr 130px; margin-top:8px;">
        <div>
          <label>Tipo da chave</label>
          <select id="aaspTipo">
            <option value="associado">Associado (individual)</option>
            <option value="empresa">Empresa (sociedade/escritório)</option>
          </select>
        </div>
        <div>
          <label>Sync (min)</label>
          <input type="number" id="aaspIntervalo" value="120" min="30" max="1440" step="30">
        </div>
      </div>
      <div class="int-mon-form" style="grid-template-columns: 1fr; margin-top:8px;">
        <div>
          <label>Chave AASP</label>
          <input type="password" id="aaspChave" placeholder="Cole sua chave AASP" autocomplete="new-password">
          <div class="int-mon-form-hint">
            Cole <strong>só a chave</strong>, sem espaços/aspas/texto extra.
            A AASP retorna erro 500 se a chave estiver mal-formada (ex: copiou junto algum prefixo).
            Não fica em texto puro no banco — só a versão cifrada.
          </div>
        </div>
      </div>

      <!-- Códigos de associado (só modo empresa) -->
      <div class="int-mon-form" id="aaspEmpresaRow" style="grid-template-columns: 1fr; margin-top:8px; display:none;">
        <div>
          <label>Códigos de associado (1 por linha)</label>
          <textarea id="aaspCodigos" rows="3" placeholder="12345&#10;67890" style="width:100%;padding:8px 10px;background:rgba(8,20,40,.6);border:1px solid rgba(96,165,250,.2);border-radius:7px;color:#dbeafe;font-size:.82rem;resize:vertical;"></textarea>
          <div class="int-mon-form-hint">
            A API AASP exige <strong>pelo menos 1 código</strong> em chamadas modo empresa.
            Cada código gera 1 chamada por sync (vou pausar 1s entre elas pra não floodar).
          </div>
        </div>
      </div>
    </div>

    <!-- Data de teste (opcional) -->
    <div class="int-mon-form" style="grid-template-columns: 1fr auto auto auto; margin-top:8px; align-items: end;">
      <div>
        <label>Data para testar (default: hoje)</label>
        <input type="date" id="aaspTestData" max="<?= date('Y-m-d') ?>" style="width:100%;padding:8px 10px;background:rgba(8,20,40,.6);border:1px solid rgba(96,165,250,.2);border-radius:7px;color:#dbeafe;font-size:.82rem;">
      </div>
      <button type="button" class="int-btn" id="aaspTestDateHoje" style="padding:6px 10px;font-size:.72rem;">Hoje</button>
      <button type="button" class="int-btn" id="aaspTestDateOntem" style="padding:6px 10px;font-size:.72rem;">Ontem</button>
      <button type="button" class="int-btn" id="aaspTestDateUltSexta" style="padding:6px 10px;font-size:.72rem;">Últ. sexta</button>
    </div>

    <!-- Resultado do teste -->
    <div id="aaspTestResult"></div>

    <div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end;flex-wrap:wrap;">
      <button class="int-btn" id="aaspCancel">Cancelar</button>
      <button class="int-btn" id="aaspTest">Testar conexão</button>
      <button class="int-btn primary" id="aaspSave">Salvar integração</button>
    </div>
  </div>
</div>

<!-- Modal Monitores -->
<div class="int-modal-bg" id="monitorsModal">
  <div class="int-modal" style="position:relative;">
    <span class="int-modal-close" id="monitorsClose" title="Fechar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </span>
    <h2>Monitoramento de intimações</h2>
    <p class="modal-sub">A cada ciclo do cron (a cada 10 min), o sistema busca novas publicações para cada monitor ativo. Quando algo novo aparece, você recebe notificação.</p>

    <!-- Meu perfil de busca (OAB + nome do user logado) -->
    <div class="int-modal-section" style="background:rgba(37,99,235,0.06);border:1px solid rgba(37,99,235,0.20);border-radius:9px;padding:14px;">
      <h3 style="margin-bottom:8px;">Meu perfil de busca</h3>
      <p style="font-size:.78rem;color:#7A8898;margin-bottom:12px;line-height:1.45;">
        Cadastre <strong>uma única vez</strong> sua OAB e/ou nome. O Yuris passa a buscar
        <strong>só suas</strong> intimações automaticamente, igual a AASP.
      </p>
      <div class="int-mon-form profile">
        <div>
          <label>UF</label>
          <input type="text" id="myProfUf" placeholder="SP" maxlength="2">
        </div>
        <div>
          <label>OAB</label>
          <input type="text" id="myProfOab" placeholder="ex: 357838">
        </div>
        <div>
          <label>Nome do advogado</label>
          <input type="text" id="myProfNome" placeholder="ex: Bruno Carreira Ferreira">
        </div>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
        <button class="int-btn primary" id="myProfSave">Salvar meu perfil + criar monitores</button>
        <span id="myProfStatus" style="font-size:.75rem;color:#7A8898;"></span>
      </div>
    </div>

    <div class="int-modal-section">
      <h3>Monitorar processo específico</h3>
      <div class="int-mon-form">
        <div>
          <label>Processo (CNJ)</label>
          <input type="text" id="monNewProc" placeholder="ex: 0000000-00.0000.0.00.0000">
        </div>
        <div>
          <label>Tribunal (opc.)</label>
          <input type="text" id="monNewTrib" placeholder="ex: TJSP">
        </div>
        <div>
          <label>Intervalo (min)</label>
          <input type="number" id="monNewIntervalo" value="120" min="30" max="1440" step="30">
        </div>
      </div>
      <div class="int-mon-form-hint">
        Use para acompanhar processos específicos (de clientes, casos pontuais). Sua OAB e nome já estão no
        <strong>Meu perfil</strong> acima — não precisa repetir aqui.
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;">
        <button class="int-btn primary" id="monNewSubmit">Adicionar processo</button>
      </div>
    </div>

    <div class="int-modal-section">
      <h3>Ativos / Pausados</h3>
      <div class="int-mon-list" id="monitorsList">
        <div class="int-mon-empty">Carregando…</div>
      </div>
    </div>
  </div>
</div>

<?php
  $intJsPath = __DIR__ . '/assets/intimacoes.js';
  $intJsVer  = file_exists($intJsPath) ? @filemtime($intJsPath) : '1';
  $usSelPath = __DIR__ . '/assets/user_select.js';
  $usSelVer  = file_exists($usSelPath) ? @filemtime($usSelPath) : '1';
?>
<script>
  // Usuários acessíveis pelo tenant (matriz + filiais ativas) — usado no select
  // de Responsável do modal Vincular intimação, igual aba Processos.
  window._SYSTEM_USERS = <?= json_encode($system_users, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/sistema_vendas/public/assets/user_select.js?v=<?= $usSelVer ?>"></script>
<script src="/sistema_vendas/public/assets/intimacoes.js?v=<?= $intJsVer ?>"></script>
<script>
  (function () {
    if (!window.YurisIntimacoesApp) return;
    window.YurisIntimacoesApp.init({
      csrf:      <?= json_encode($csrf) ?>,
      accountId: <?= (int)$accountId ?>,
      userId:    <?= (int)$userId ?>,
      apiBase:   '/sistema_vendas/public/api/push',
    });
  })();
</script>

</body>
</html>
