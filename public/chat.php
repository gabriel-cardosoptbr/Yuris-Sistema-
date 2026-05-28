<?php
require_once __DIR__ . '/../app/Models/Database.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$activePage    = 'chat';
$csrf          = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$auto_open_jid = isset($_GET['jid']) ? trim($_GET['jid']) : '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#070F1C">
  <title>Chat WhatsApp — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=44">
  <link rel="stylesheet" href="/assets/fog.css">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <style>
    /* ── Layout base ── */
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; overflow: hidden; }

    /* ── Conteúdo principal do chat ── */
    .chat-main {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
      overflow: hidden;
      gap: 12px;
    }

    /* ── Painel do chat (split view) ── */
    .chat-panel {
      flex: 1;
      display: flex;
      min-height: 0;
      border: 1px solid rgba(160,180,210,0.12);
      border-radius: 14px;
      overflow: hidden;
      background: linear-gradient(145deg, #0D1C30 0%, #09131F 100%);
    }

    /* ════════════════════════════════════════
       COLUNA ESQUERDA — Lista de conversas
       ════════════════════════════════════════ */
    .chat-sidebar {
      width: 320px;
      min-width: 280px;
      max-width: 340px;
      display: flex;
      flex-direction: column;
      border-right: 1px solid rgba(160,180,210,0.08);
      background: linear-gradient(180deg, #0B1827 0%, #080E1A 100%);
    }

    /* Header da coluna esquerda */
    .chat-sidebar-header {
      padding: 14px 16px 10px;
      border-bottom: 1px solid rgba(160,180,210,0.08);
      background: rgba(8,15,26,0.60);
      flex-shrink: 0;
    }

    .chat-sidebar-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 10px;
    }

    .chat-sidebar-title h2 {
      font-size: .95rem;
      font-weight: 700;
      color: #D8E4F0;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* Status dot */
    .wa-status-dot {
      width: 9px; height: 9px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }
    .wa-status-dot.connected    { background: #4A9078; box-shadow: 0 0 6px rgba(74,144,120,.5); }
    .wa-status-dot.connecting   { background: #9A7B28; animation: pulse-dot 1.2s ease-in-out infinite; }
    .wa-status-dot.disconnected { background: #8A4050; }
    .wa-status-dot.qr           { background: #9A7B28; animation: pulse-dot 1.2s ease-in-out infinite; }

    @keyframes pulse-dot {
      0%,100% { opacity: 1; }
      50%      { opacity: .4; }
    }

    /* Busca */
    .chat-search {
      position: relative;
      margin-bottom: 8px;
    }
    .chat-search input {
      width: 100%;
      padding: 8px 12px 8px 34px;
      background: #081220;
      border: 1px solid rgba(160,180,210,0.12);
      border-radius: 8px;
      color: #D8E4F0;
      font-size: .83rem;
      outline: none;
      transition: border-color .15s;
    }
    .chat-search input:focus { border-color: rgba(160,180,210,0.28); }
    .chat-search-icon {
      position: absolute;
      left: 10px; top: 50%; transform: translateY(-50%);
      color: #4A5568; width: 15px; height: 15px; pointer-events: none;
    }

    /* Filtros — wrap em 2 linhas quando ha 6 botoes (Todas/Nao lidas/Grupos
       /Individuais/Fixadas/Arquivadas). Cada um ocupa ~33% pra ficar 3+3.
       Cada botao tem sua propria borda vazada pra deixar claro que sao
       opcoes individuais (nao um grupo unico). */
    .chat-filters {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
    }
    .chat-filter-btn {
      flex: 1 1 calc(33.333% - 5px);
      min-width: 0;
      padding: 5px 6px;
      font-size: .7rem;
      font-weight: 600;
      border-radius: 14px;
      border: 1px solid rgba(160,180,210,.18);
      background: transparent;
      color: #6B7887;
      cursor: pointer;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      transition: all .15s;
    }
    .chat-filter-btn:hover  { background: rgba(26,58,92,.2); color: #A8BDD4; border-color: rgba(160,180,210,.30); }
    .chat-filter-btn.active { background: rgba(37,99,235,.20); color: #D8E4F0; border-color: rgba(96,165,250,.45); }

    /* Filtro de setor (sidebar) */
    .chat-sector-filter {
      position: relative;
      margin-top: 6px;
    }
    .chat-sector-btn {
      display: flex;
      align-items: center;
      gap: 6px;
      width: 100%;
      padding: 5px 10px;
      font-size: .72rem;
      font-weight: 600;
      border-radius: 6px;
      border: 1px solid rgba(160,180,210,.1);
      background: rgba(255,255,255,.03);
      color: #6B7887;
      cursor: pointer;
      transition: all .15s;
      text-align: left;
    }
    .chat-sector-btn:hover { background: rgba(26,58,92,.2); color: #A8BDD4; }
    .chat-sector-btn.active {
      background: rgba(26,58,92,.35);
      color: #D8E4F0;
      border-color: rgba(160,180,210,.15);
    }
    .chat-sector-btn .csf-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
      background: #4A5568;
    }
    .chat-sector-btn .csf-label { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .chat-sector-btn .csf-chevron { margin-left: auto; opacity: .5; flex-shrink: 0; }
    .chat-sector-dd {
      position: absolute;
      top: calc(100% + 4px);
      left: 0; right: 0;
      background: #0D1C30;
      border: 1px solid rgba(160,180,210,.12);
      border-radius: 8px;
      box-shadow: 0 8px 24px rgba(0,0,0,.5);
      z-index: 200;
      overflow: hidden;
      display: none;
    }
    .chat-sector-dd-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      font-size: .75rem;
      font-weight: 500;
      color: #A8BDD4;
      cursor: pointer;
      transition: background .12s;
    }
    .chat-sector-dd-item:hover { background: rgba(26,58,92,.3); }
    .chat-sector-dd-item.active { color: #D8E4F0; background: rgba(26,58,92,.4); }
    .chat-sector-dd-item .csf-dd-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .chat-sector-dd-divider { border: none; border-top: 1px solid rgba(160,180,210,.08); margin: 2px 0; }

    /* Lista */
    .chat-list {
      flex: 1;
      overflow-y: auto;
      overscroll-behavior: contain;
    }

    .chat-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      cursor: pointer;
      border-bottom: 1px solid rgba(160,180,210,0.04);
      transition: background .12s;
      position: relative;
    }
    .chat-item:hover    { background: rgba(26,58,92,.18); }
    .chat-item.active   { background: rgba(26,58,92,.32); }

    .chat-avatar {
      width: 42px; height: 42px;
      border-radius: 50%;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .9rem;
      font-weight: 700;
      color: #C8D4E0;
      flex-shrink: 0;
      overflow: hidden;
    }
    .chat-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

    .chat-item-info { flex: 1; min-width: 0; }
    .chat-item-row1 {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .chat-item-name {
      font-size: .84rem;
      font-weight: 600;
      color: #D8E4F0;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      max-width: 160px;
    }
    .chat-item-time {
      font-size: .68rem;
      color: #4A5568;
      flex-shrink: 0;
    }
    /* Etiqueta de setor no item da lista */
    .chat-item-sector {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      margin-top: 2px;
      margin-bottom: 1px;
    }
    .chat-item-sector-dot {
      width: 6px; height: 6px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .chat-item-sector-name {
      font-size: .65rem;
      font-weight: 600;
      letter-spacing: .02em;
      color: #7A8898;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 120px;
    }

    .chat-item-row2 {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 2px;
    }
    .chat-item-preview {
      font-size: .77rem;
      color: #7A8898;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      max-width: 170px;
    }
    .chat-item-preview.outbound::before { content: '\2713\00a0'; color: #6898C0; }

    .chat-unread-badge {
      min-width: 18px; height: 18px;
      border-radius: 9px;
      background: #244E7A;
      color: #C8D4E0;
      font-size: .65rem;
      font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      padding: 0 4px;
      flex-shrink: 0;
    }
    .chat-pinned-icon {
      color: #4A5568;
      font-size: .65rem;
      flex-shrink: 0;
    }

    /* ── Estado vazio na lista ── */
    .chat-list-empty {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100%;
      gap: 10px;
      color: #4A5568;
      font-size: .83rem;
      padding: 20px;
      text-align: center;
    }
    .chat-list-empty svg { width: 40px; height: 40px; opacity: .3; }

    /* ════════════════════════════════════════
       COLUNA DIREITA — Área de mensagens
       ════════════════════════════════════════ */
    .chat-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      background: linear-gradient(145deg, #0A1520 0%, #070F1A 100%);
    }

    /* Header do chat aberto */
    .chat-content-header {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 16px;
      border-bottom: 1px solid rgba(160,180,210,0.08);
      background: rgba(8,15,26,0.70);
      flex-shrink: 0;
    }
    .chat-content-header .chat-avatar { width: 36px; height: 36px; font-size: .78rem; }

    .chat-contact-info { flex: 1; min-width: 0; }
    .chat-contact-name {
      font-size: .9rem;
      font-weight: 600;
      color: #D8E4F0;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .chat-contact-sub {
      font-size: .72rem;
      color: #6B7887;
    }

    .chat-header-actions {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .chat-icon-btn {
      width: 32px; height: 32px;
      border-radius: 8px;
      border: 1px solid rgba(160,180,210,.1);
      background: rgba(160,180,210,.05);
      color: #6B7887;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
      transition: all .15s;
    }
    .chat-icon-btn:hover { background: rgba(26,58,92,.25); color: #A8BDD4; }
    .chat-icon-btn svg { width: 15px; height: 15px; }

    /* Menu de mais opções (⋮) */
    .chat-more-wrap { position: relative; }
    .chat-more-dd {
      position: absolute;
      top: calc(100% + 6px);
      right: 0;
      min-width: 180px;
      background: #0D1C30;
      border: 1px solid rgba(160,180,210,.12);
      border-radius: 10px;
      box-shadow: 0 8px 28px rgba(0,0,0,.55);
      z-index: 300;
      overflow: hidden;
      display: none;
    }
    .chat-more-item {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 10px 14px;
      font-size: .78rem;
      font-weight: 500;
      color: #A8BDD4;
      cursor: pointer;
      transition: background .12s;
    }
    .chat-more-item:hover { background: rgba(26,58,92,.35); }
    .chat-more-item.danger { color: #E07080; }
    .chat-more-item.danger:hover { background: rgba(140,50,60,.25); }
    .chat-more-item svg { width: 14px; height: 14px; flex-shrink: 0; opacity: .85; }

    /* Área de mensagens */
    .chat-messages {
      flex: 1;
      overflow-y: auto;
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      overscroll-behavior: contain;
    }

    /* Bolha de mensagem */
    .msg-row {
      display: flex;
      flex-direction: column;
      max-width: 70%;
    }
    .msg-row.inbound  { align-self: flex-start; align-items: flex-start; }
    .msg-row.outbound { align-self: flex-end;   align-items: flex-end; }

    .msg-bubble {
      padding: 8px 12px;
      border-radius: 12px;
      font-size: .83rem;
      line-height: 1.45;
      word-break: break-word;
      position: relative;
    }
    .msg-row.inbound  .msg-bubble {
      background: linear-gradient(135deg, #0F1E30, #0D1A28);
      border: 1px solid rgba(160,180,210,.1);
      color: #D8E4F0;
      border-bottom-left-radius: 4px;
    }
    .msg-sender {
      font-size: .7rem;
      font-weight: 600;
      color: #7EB8F7;
      margin-bottom: 3px;
      opacity: .85;
    }

    /* ── Badge de Setor no Header ── */
    .sector-badge-btn {
      width: auto !important; padding: 0 9px !important;
      gap: 5px !important; font-size: .72rem !important;
      border-color: rgba(160,180,210,.18) !important;
      color: #A0B4C8 !important;
      display: flex; align-items: center;
    }
    .sector-badge-btn.has-sector {
      border-color: var(--sector-color, rgba(99,179,237,.35)) !important;
      color: var(--sector-color, #63B3ED) !important;
      background: rgba(99,179,237,.07) !important;
    }
    .sector-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: #4A5568; flex-shrink: 0;
      transition: background .2s;
    }
    /* Dropdown de seleção de setor */
    .sector-dropdown {
      position: absolute; top: calc(100% + 6px); right: 0;
      min-width: 180px; z-index: 900;
      background: #0C1A2C;
      border: 1px solid rgba(160,180,210,.14);
      border-radius: 10px;
      padding: 6px 0;
      box-shadow: 0 8px 24px rgba(0,0,0,.45);
    }
    .sector-dd-item {
      display: flex; align-items: center; gap: 9px;
      padding: 8px 14px; cursor: pointer;
      font-size: .8rem; color: #B0C4D8;
      transition: background .15s;
      white-space: nowrap;
    }
    .sector-dd-item:hover { background: rgba(126,184,247,.08); color: #D8E8F8; }
    .sector-dd-item.active { color: #7EB8F7; background: rgba(126,184,247,.06); }
    .sector-dd-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .sector-dd-divider { border: none; border-top: 1px solid rgba(160,180,210,.08); margin: 4px 0; }
    .sector-dd-remove {
      color: #B06070 !important;
    }
    .sector-dd-remove:hover { background: rgba(176,96,112,.08) !important; }

    /* ── Link picker (Vincular Conversa) ── */
    .link-select {
      width: 100%; padding: 8px 12px; border-radius: 8px;
      background: rgba(5,18,39,.7);
      border: 1px solid rgba(96,165,250,.18);
      color: #C8D8E8; font-size: .83rem; min-height: 38px;
      outline: none; cursor: pointer; appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237A8898' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 10px center;
      padding-right: 30px; transition: border-color .2s;
    }
    .link-select:hover, .link-select:focus { border-color: rgba(126,184,247,.38); }
    .link-select option { background: #0E1F36; color: #C8D8E8; }

    .link-picker { position: relative; }
    .link-picker-trigger {
      display: flex; align-items: center; justify-content: space-between;
      padding: 8px 12px; border-radius: 8px; cursor: pointer;
      background: rgba(5,18,39,.7);
      border: 1px solid rgba(96,165,250,.18);
      min-height: 38px; font-size: .83rem; transition: border-color .2s;
      user-select: none;
    }
    .link-picker-trigger:hover { border-color: rgba(126,184,247,.38); }
    .link-picker-trigger.has-value { border-color: rgba(126,184,247,.32); }
    .link-picker-label {
      flex: 1; color: #5A7A9A; font-style: italic;
    }
    .link-picker-label.selected { color: #D8E4F0; font-style: normal; }
    .link-picker-sub {
      font-size: .7rem; color: #4A6A8A; margin-left: 6px;
    }
    .link-picker-clear {
      background: none; border: none; color: #4A6A8A; cursor: pointer;
      padding: 2px 6px; font-size: 1rem; line-height: 1; flex-shrink: 0;
      border-radius: 4px; transition: color .15s;
    }
    .link-picker-clear:hover { color: #E08080; }
    .link-picker-chevron {
      color: #4A6A8A; font-size: .75rem; margin-left: 4px; flex-shrink: 0;
    }
    .link-picker-dropdown {
      display: none; margin-top: 4px;
      border-radius: 8px; border: 1px solid rgba(96,165,250,.2);
      background: rgba(4,14,30,.97);
      box-shadow: 0 8px 24px rgba(0,0,0,.5);
    }
    .link-picker-dropdown.open { display: block; }
    .link-picker-search {
      width: 100%; padding: 9px 12px; box-sizing: border-box;
      background: transparent; border: none;
      border-bottom: 1px solid rgba(96,165,250,.14);
      color: #D8E4F0; font-size: .82rem; outline: none;
    }
    .link-picker-search::placeholder { color: #4A6A8A; }
    .link-picker-list {
      max-height: 168px; overflow-y: auto; padding: 4px 6px 6px;
    }
    .link-picker-item {
      padding: 8px 10px; border-radius: 6px; cursor: pointer;
      font-size: .82rem; color: #B8D0E8; transition: background .12s;
    }
    .link-picker-item:hover { background: rgba(37,99,235,.22); color: #D8E4F0; }
    .link-picker-empty {
      padding: 12px 10px; font-size: .8rem; color: #3A5A7A;
      text-align: center; font-style: italic;
    }
    .msg-row.outbound .msg-bubble {
      background: linear-gradient(135deg, #1A3A5C, #1E4570);
      border: 1px solid rgba(100,160,220,.15);
      color: #E0EEF8;
      border-bottom-right-radius: 4px;
    }

    /* Imagem na bolha */
    .msg-image-wrap { display: inline-block; cursor: pointer; max-width: 260px; }
    .msg-bubble img.msg-image {
      width: 260px;
      max-width: 260px;
      max-height: 320px;
      border-radius: 10px;
      object-fit: cover;
      display: block;
      cursor: pointer;
      margin-bottom: 4px;
    }

    /* Documento na bolha */
    .msg-doc {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 8px;
      background: rgba(160,180,210,.06);
      border-radius: 8px;
      color: #A8BDD4;
      font-size: .78rem;
      text-decoration: none;
      margin-bottom: 4px;
    }
    .msg-doc svg { width: 18px; height: 18px; flex-shrink: 0; }

    /* Áudio na bolha */
    .msg-audio {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .msg-audio-play {
      width: 32px; height: 32px;
      border-radius: 50%;
      background: rgba(36,78,122,.4);
      border: none;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
      color: #A8BDD4;
      flex-shrink: 0;
    }
    .msg-audio audio { max-width: 240px; height: 36px; }
    .msg-caption { display: block; font-size: .78rem; color: #A8BDD4; margin-top: 4px; }

    /* Meta da mensagem */
    .msg-meta {
      display: flex;
      align-items: center;
      gap: 4px;
      margin-top: 3px;
      font-size: .65rem;
      color: #4A5568;
    }
    .msg-row.outbound .msg-meta { justify-content: flex-end; }
    .msg-status-icon { width: 13px; height: 13px; }
    .msg-status-icon.read { color: #6898C0; }
    .msg-status-icon.delivered { color: #6B7887; }
    .msg-status-icon.pending { color: #4A5568; }

    /* Separador de data */
    /* Separador de data — texto sutil centralizado, SEM chip/fundo
       (estilo minimalista; antes tinha pill cinza que poluia visualmente). */
    .msg-date-sep {
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 14px 0 8px;
    }
    .msg-date-sep > span {
      display: inline-block;
      padding: 0;
      background: transparent;
      color: #6B7A8C;
      font-size: .72rem;
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
    }
    html[data-theme="light"] .msg-date-sep > span {
      color: #94A3B8 !important;
      background: transparent !important;
      box-shadow: none !important;
    }

    /* Estado vazio / sem chat selecionado */
    .chat-empty-state {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      color: #4A5568;
      text-align: center;
      padding: 40px;
    }
    .chat-empty-state svg { width: 64px; height: 64px; opacity: .2; }
    .chat-empty-state h3 { font-size: 1rem; font-weight: 600; color: #6B7887; margin: 0; }
    .chat-empty-state p  { font-size: .82rem; margin: 0; color: #4A5568; }

    /* ── Input area ── */
    .chat-input-area {
      padding: 10px 14px;
      border-top: 1px solid rgba(160,180,210,.08);
      background: rgba(8,15,26,.70);
      display: flex;
      align-items: flex-end;
      gap: 8px;
      flex-shrink: 0;
    }

    .chat-attach-btn, .chat-audio-btn {
      width: 36px; height: 36px;
      border-radius: 8px;
      border: 1px solid rgba(160,180,210,.12);
      background: rgba(160,180,210,.05);
      color: #6B7887;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
      transition: all .15s;
      flex-shrink: 0;
    }
    .chat-attach-btn:hover, .chat-audio-btn:hover { background: rgba(26,58,92,.3); color: #A8BDD4; }
    .chat-attach-btn svg, .chat-audio-btn svg { width: 16px; height: 16px; }

    .chat-audio-btn.recording {
      background: rgba(58,16,32,.5);
      border-color: rgba(176,96,112,.3);
      color: #B06070;
      animation: pulse-dot 1s ease-in-out infinite;
    }

    .chat-text-wrap {
      flex: 1;
      position: relative;
    }
    #chatInput {
      width: 100%;
      padding: 9px 12px;
      background: #081220;
      border: 1px solid rgba(160,180,210,.14);
      border-radius: 10px;
      color: #D8E4F0;
      font-size: .84rem;
      resize: none;
      max-height: 120px;
      min-height: 36px;
      outline: none;
      line-height: 1.45;
      font-family: inherit;
      overflow-y: auto;
      transition: border-color .15s;
    }
    #chatInput:focus { border-color: rgba(160,180,210,.28); }

    .chat-send-btn {
      width: 36px; height: 36px;
      border-radius: 8px;
      border: none;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      color: #C8D4E0;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
      transition: all .15s;
      flex-shrink: 0;
    }
    .chat-send-btn:hover  { background: linear-gradient(135deg, #244E7A, #2E6090); }
    .chat-send-btn:active { transform: scale(.95); }
    .chat-send-btn svg { width: 16px; height: 16px; }

    /* Arquivo selecionado preview */
    .chat-file-preview {
      display: none;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      background: rgba(26,58,92,.2);
      border: 1px solid rgba(160,180,210,.12);
      border-radius: 8px;
      margin-bottom: 6px;
      font-size: .78rem;
      color: #A8BDD4;
    }
    .chat-file-preview.visible { display: flex; }
    .chat-file-preview-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .chat-file-remove {
      cursor: pointer; color: #6B7887;
      background: none; border: none; padding: 0;
      display: flex; align-items: center;
    }
    .chat-file-remove:hover { color: #B06070; }

    /* ════════════════════════════════════════
       TELA DE CONEXÃO — aparece quando desconectado
       ════════════════════════════════════════ */
    #connectionScreen {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 20px;
      padding: 30px;
      text-align: center;
    }

    .qr-container {
      display: none;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    .qr-container.visible { display: flex; }

    #qrCodeImg {
      width: 220px; height: 220px;
      border-radius: 12px;
      border: 3px solid rgba(36,78,122,.5);
      background: #fff;
      object-fit: contain;
    }

    .conn-status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 14px;
      border-radius: 20px;
      font-size: .78rem;
      font-weight: 600;
    }
    .conn-status-badge.connected  { background: rgba(30,74,58,.4); color: #7ABDA0; border: 1px solid rgba(122,189,160,.2); }
    .conn-status-badge.connecting { background: rgba(61,48,16,.4); color: #C4A040; border: 1px solid rgba(196,160,64,.2); }
    .conn-status-badge.disconnected { background: rgba(58,16,32,.4); color: #B06070; border: 1px solid rgba(176,96,112,.2); }
    .conn-status-badge.qr { background: rgba(61,48,16,.4); color: #C4A040; border: 1px solid rgba(196,160,64,.2); }

    .conn-title { font-size: 1.1rem; font-weight: 700; color: #D8E4F0; margin: 0; }
    .conn-sub   { font-size: .82rem; color: #7A8898; margin: 0; max-width: 320px; }

    .conn-btn-primary {
      padding: 10px 26px;
      border-radius: 10px;
      border: 1px solid rgba(160,180,210,.18);
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      color: #C8D4E0;
      font-size: .88rem;
      font-weight: 600;
      cursor: pointer;
      transition: all .15s;
      font-family: inherit;
    }
    .conn-btn-primary:hover { background: linear-gradient(135deg, #244E7A, #2E6090); transform: translateY(-1px); }

    .conn-btn-secondary {
      padding: 8px 20px;
      border-radius: 10px;
      border: 1px solid rgba(160,180,210,.14);
      background: rgba(160,180,210,.06);
      color: #7A8898;
      font-size: .82rem;
      font-weight: 600;
      cursor: pointer;
      transition: all .15s;
      font-family: inherit;
    }
    .conn-btn-secondary:hover { background: rgba(160,180,210,.12); color: #A8BDD4; }

    /* ════════════════════════════════════════
       MODAL DE CONFIGURAÇÕES
       ════════════════════════════════════════ */
    .modal-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,.65);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }
    .modal-overlay.open { display: flex; }

    .modal-box {
      background: linear-gradient(160deg, #0C1A2E 0%, #080F1C 100%);
      border: 1px solid rgba(160,180,210,.14);
      border-radius: 14px;
      width: 480px;
      max-width: 95vw;
      max-height: 85vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .modal-header {
      padding: 16px 20px;
      border-bottom: 1px solid rgba(160,180,210,.08);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .modal-header h3 { font-size: .95rem; font-weight: 700; color: #D8E4F0; margin: 0; }
    .modal-close {
      cursor: pointer; color: #6B7887; background: none; border: none;
      display: flex; align-items: center;
    }
    .modal-close:hover { color: #B06070; }
    .modal-close svg { width: 18px; height: 18px; }

    .modal-body { padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }

    .form-field { display: flex; flex-direction: column; gap: 5px; }
    .form-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6B7887; }
    .form-input {
      padding: 9px 12px;
      background: #081220;
      border: 1px solid rgba(160,180,210,.14);
      border-radius: 8px;
      color: #D8E4F0;
      font-size: .84rem;
      outline: none;
      font-family: inherit;
      transition: border-color .15s;
    }
    .form-input:focus { border-color: rgba(160,180,210,.28); }
    .form-input::placeholder { color: #4A5568; }

    .modal-footer {
      padding: 14px 20px;
      border-top: 1px solid rgba(160,180,210,.08);
      display: flex;
      gap: 8px;
      justify-content: flex-end;
    }

    /* ════════════════════════════════════════
       MODAL VINCULAR CONVERSA
       ════════════════════════════════════════ */
    /* Reutiliza .modal-overlay / .modal-box */

    /* ── Painel topo com KPIs ── */
    .chat-kpis {
      display: flex;
      gap: 10px;
      flex-shrink: 0;
    }
    .chat-kpi {
      flex: 1;
      padding: 10px 14px;
      background: linear-gradient(145deg, #0C1A2C, #081020);
      border: 1px solid rgba(160,180,210,.1);
      border-radius: 10px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chat-kpi-icon {
      width: 32px; height: 32px;
      border-radius: 8px;
      background: rgba(26,58,92,.3);
      display: flex; align-items: center; justify-content: center;
      color: #6898C0;
      flex-shrink: 0;
    }
    .chat-kpi-icon svg { width: 15px; height: 15px; }
    .chat-kpi-label { font-size: .68rem; color: #7A8898; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
    .chat-kpi-val   { font-size: 1.1rem; font-weight: 700; color: #D8E4F0; }

    /* ── Page header ── */
    .chat-page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }
    .chat-page-title {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chat-page-title h1 {
      font-size: 1.3rem;
      font-weight: 700;
      color: #D8E4F0;
      margin: 0;
    }
    .chat-page-title p {
      font-size: .8rem;
      color: #7A8898;
      margin: 0;
    }
    .chat-page-actions { display: flex; gap: 8px; }

    /* Toast */
    #chatToast {
      position: fixed;
      bottom: 24px; right: 24px;
      padding: 10px 18px;
      border-radius: 10px;
      background: linear-gradient(135deg, #0D1C30, #09131F);
      border: 1px solid rgba(160,180,210,.18);
      color: #D8E4F0;
      font-size: .82rem;
      font-weight: 500;
      z-index: 2000;
      transform: translateY(60px);
      opacity: 0;
      transition: all .3s ease;
      pointer-events: none;
    }
    #chatToast.show { transform: translateY(0); opacity: 1; }
    #chatToast.error { border-color: rgba(176,96,112,.3); color: #B06070; }
    #chatToast.success { border-color: rgba(122,189,160,.3); color: #7ABDA0; }

    /* Alerta de desconexão */
    #disconnectAlert {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 3100;
      background: linear-gradient(90deg, #1A0A0A, #2A0E0E);
      border-bottom: none;
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0 20px;
      height: 0;
      overflow: hidden;
      transition: height .35s ease;
    }
    #disconnectAlert.visible {
      height: 56px;
      border-bottom: 2px solid rgba(220,80,80,.35);
    }
    .disc-icon { flex-shrink: 0; color: #E05050; }
    .disc-text { flex: 1; min-width: 0; }
    .disc-text strong { display: block; font-size: .82rem; color: #E08080; }
    .disc-text span   { font-size: .75rem; color: #7A4040; }
    .disc-actions { display: flex; gap: 8px; flex-shrink: 0; }
    .disc-btn-qr {
      padding: 6px 14px; border-radius: 7px; font-size: .78rem; font-weight: 600;
      background: rgba(220,80,80,.18); border: 1px solid rgba(220,80,80,.35);
      color: #E08080; cursor: pointer; transition: background .2s;
    }
    .disc-btn-qr:hover { background: rgba(220,80,80,.3); }
    .disc-btn-dismiss {
      padding: 5px 9px; border-radius: 7px; font-size: .78rem;
      background: transparent; border: 1px solid rgba(220,80,80,.2);
      color: #7A4040; cursor: pointer; transition: background .2s;
    }
    .disc-btn-dismiss:hover { background: rgba(220,80,80,.12); }
    #reconnectStatus {
      font-size: .72rem; color: #5A5A7A; margin-left: 4px; font-style: italic;
    }

    /* Banner de sincronização */
    #syncBanner {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 3000;
      background: linear-gradient(90deg, #0A1828, #0D2040);
      border-bottom: 2px solid rgba(126,184,247,.25);
      padding: 0 24px;
      height: 0;
      overflow: hidden;
      transition: height .35s ease;
      display: flex;
      align-items: center;
      gap: 16px;
    }
    #syncBanner.visible { height: 56px; }
    .sync-spinner {
      width: 20px; height: 20px; flex-shrink: 0;
      border: 2px solid rgba(126,184,247,.2);
      border-top-color: #7EB8F7;
      border-radius: 50%;
      animation: spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .sync-progress-wrap {
      flex: 1; min-width: 0;
    }
    .sync-progress-text {
      font-size: .8rem; color: #A0C4E8; margin-bottom: 5px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sync-progress-bar-track {
      height: 3px; background: rgba(126,184,247,.12);
      border-radius: 2px; overflow: hidden;
    }
    .sync-progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #7EB8F7, #4A90D9);
      border-radius: 2px;
      width: 0%;
      transition: width .5s ease;
      animation: indeterminate 1.6s ease-in-out infinite;
    }
    @keyframes indeterminate {
      0%   { transform: translateX(-100%) scaleX(.4); }
      50%  { transform: translateX(60%)   scaleX(.6); }
      100% { transform: translateX(200%)  scaleX(.4); }
    }
    .sync-timer {
      font-size: .75rem; color: #5A7A9A;
      flex-shrink: 0; font-variant-numeric: tabular-nums;
      min-width: 48px; text-align: right;
    }

    /* Scrollbar */
    .chat-messages::-webkit-scrollbar,
    .chat-list::-webkit-scrollbar { width: 4px; }
    .chat-messages::-webkit-scrollbar-track,
    .chat-list::-webkit-scrollbar-track { background: transparent; }
    .chat-messages::-webkit-scrollbar-thumb,
    .chat-list::-webkit-scrollbar-thumb { background: rgba(160,180,210,.12); border-radius: 2px; }

    /* Responsivo mobile */
    @media (max-width: 768px) {
      .chat-sidebar { width: 100%; max-width: 100%; }
      .chat-content { display: none; }
      .chat-content.mobile-open { display: flex; }
      .chat-panel { flex-direction: column; }
    }

    /* ═════════════════════════════════════════════════════════════════════
       AUDITORIA 2026-05-24 — novos componentes:
       reply bar, file preview rico, msg actions menu, reactions, group members,
       separador de dia, quote em bubble, msg apagada, infinite scroll loader
       ═════════════════════════════════════════════════════════════════════ */

    /* — Reply bar (P0-J) — */
    .chat-reply-bar {
      display:none; align-items:center; gap:10px;
      padding:8px 12px; margin-bottom:8px;
      background: rgba(37,99,235,.10);
      border: 1px solid rgba(37,99,235,.25);
      border-left: 3px solid #2563eb;
      border-radius:8px;
    }
    .chat-reply-bar-icon { color:#2563eb; display:flex; flex-shrink:0; }
    .chat-reply-bar-icon svg { width:16px; height:16px; }
    .chat-reply-bar-content { flex:1; min-width:0; }
    .chat-reply-bar-label { font-size:.68rem; font-weight:700; color:#2563eb; text-transform:uppercase; letter-spacing:.04em; }
    .chat-reply-bar-text { font-size:.82rem; color:#D8E4F0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .chat-reply-bar-cancel {
      background:transparent; border:none; cursor:pointer; color:#6B7887;
      display:flex; flex-shrink:0; padding:2px;
    }
    .chat-reply-bar-cancel:hover { color:#B06070; }
    .chat-reply-bar-cancel svg { width:14px; height:14px; }

    /* — File preview rico (P0-E) — */
    .chat-file-preview {
      display:none; align-items:center; gap:10px;
      padding:8px 10px; margin-bottom:8px;
      background: rgba(37,99,235,.12);
      border:1px solid rgba(96,165,250,.25);
      border-radius:10px;
    }
    .chat-file-preview.visible { display:flex; }
    .chat-file-thumb {
      width:42px; height:42px; flex-shrink:0; border-radius:6px;
      background: rgba(160,180,210,.10);
      display:flex; align-items:center; justify-content:center;
      color:#7EB8F6; overflow:hidden;
    }
    .chat-file-thumb.image { background:#000; }
    .chat-file-thumb svg { width:20px; height:20px; }
    .chat-file-thumb img { width:100%; height:100%; object-fit:cover; }
    .chat-file-info { flex:1; min-width:0; display:flex; flex-direction:column; gap:2px; }
    .chat-file-preview-name {
      font-size:.83rem; font-weight:600; color:#D8E4F0;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .chat-file-preview-size { font-size:.71rem; color:#7A8898; }
    .chat-file-remove {
      background: rgba(220,38,38,.18); border:1px solid rgba(220,38,38,.30);
      color:#fca5a5; border-radius:6px; padding:4px 6px;
      cursor:pointer; display:flex; align-items:center;
    }
    .chat-file-remove:hover { background: rgba(220,38,38,.32); }

    /* — Msg actions menu (P1-K) — */
    .msg-menu-btn {
      position:absolute; top:6px; right:6px;
      background: rgba(0,0,0,.18); border:none;
      width:22px; height:22px; border-radius:50%; cursor:pointer;
      display:none; align-items:center; justify-content:center;
      color:#A8BDD4;
    }
    .msg-row:hover .msg-menu-btn { display:flex; }
    .msg-menu-btn:hover { background: rgba(0,0,0,.32); color:#fff; }
    .msg-menu-btn svg { width:14px; height:14px; }
    .msg-bubble { position:relative; padding-right:28px; } /* reserva espaço pro botão */

    .msg-actions-menu {
      background: linear-gradient(165deg, #0F2540, #0A1828);
      border:1px solid rgba(160,180,210,.18);
      border-radius:10px; padding:6px;
      box-shadow:0 12px 32px rgba(0,0,0,.5);
      min-width:200px; max-width:240px;
      display:flex; flex-direction:column; gap:2px;
    }
    .msg-actions-menu button {
      display:flex; align-items:center; gap:8px; padding:8px 10px;
      background:transparent; border:none; cursor:pointer;
      color:#D8E4F0; font-size:.83rem; font-family:inherit;
      border-radius:6px; text-align:left; transition:background .12s;
    }
    .msg-actions-menu button:hover { background: rgba(37,99,235,.18); }
    .msg-actions-menu button.danger { color:#fca5a5; }
    .msg-actions-menu button.danger:hover { background: rgba(220,38,38,.18); }
    .msg-actions-menu button svg { width:14px; height:14px; flex-shrink:0; }
    .msg-actions-reactions {
      display:flex; gap:4px; padding:4px 6px;
      border-top:1px solid rgba(160,180,210,.10);
      border-bottom:1px solid rgba(160,180,210,.10);
      margin:4px 0;
    }
    .msg-act-react {
      background:transparent !important; border:none !important;
      padding:6px !important; cursor:pointer; font-size:1.1rem !important;
      border-radius:6px !important; transition:transform .12s, background .12s !important;
    }
    .msg-act-react:hover { background: rgba(255,255,255,.10) !important; transform:scale(1.25); }

    /* — Reactions pílulas (P2-M) — */
    .msg-reactions {
      display:flex; flex-wrap:wrap; gap:4px;
      margin-top:4px; margin-left:8px;
    }
    .msg-row.outbound .msg-reactions { justify-content:flex-end; margin-right:8px; margin-left:0; }
    .msg-reaction-pill {
      display:inline-flex; align-items:center; gap:3px;
      padding:2px 7px; border-radius:14px;
      background: rgba(15,30,55,.85);
      border:1px solid rgba(160,180,210,.20);
      font-size:.78rem; cursor:pointer;
      transition: all .12s;
    }
    .msg-reaction-pill:hover { background: rgba(37,99,235,.25); transform: translateY(-1px); }
    .msg-reaction-pill.me {
      background: rgba(37,99,235,.30);
      border-color: rgba(96,165,250,.45);
    }
    .msg-reaction-pill .cnt { font-size:.7rem; color:#A8BDD4; font-weight:600; }

    /* (Separador de dia movido pra cima — regra anterior aqui (chip cinza) foi
       removida porque vinha depois na cascade e sobrescrevia o estilo minimalista
       definido em .msg-date-sep / .msg-date-sep > span no inicio do <style>.) */

    /* — Quote (mensagem citada) na bubble (P0-J) — */
    .msg-quoted {
      display:flex; align-items:stretch; gap:8px;
      margin:0 0 6px;
      padding:6px 8px;
      background: rgba(255,255,255,.05);
      border-radius:6px; cursor:pointer;
      transition:background .12s;
    }
    .msg-quoted:hover { background: rgba(255,255,255,.10); }
    .msg-quoted-bar { width:3px; flex-shrink:0; background:#2563eb; border-radius:2px; }
    .msg-quoted-text { font-size:.76rem; color:#A8BDD4; flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    /* — Busca dentro da conversa (P1 2026-05-25) — */
    .chat-search-bar {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 12px; margin: 0;
      background: rgba(15,30,55,0.85);
      border-bottom: 1px solid rgba(160,180,210,0.10);
      flex-shrink: 0;
    }
    .chat-search-bar input {
      flex: 1; background: transparent; border: none; outline: none;
      color: #D8E4F0; font-size: .82rem; font-family: inherit;
    }
    .chat-search-bar input::placeholder { color: #6B7887; }
    .chat-search-count {
      font-size: .72rem; color: #7EB8F6; font-weight: 600;
      padding: 2px 8px; background: rgba(37,99,235,0.18);
      border-radius: 10px; flex-shrink: 0;
    }
    html[data-theme="light"] .chat-search-bar {
      background: #F4F7FB !important;
      border-bottom-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .chat-search-bar input { color: #0F1F36 !important; }
    html[data-theme="light"] .chat-search-bar input::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .chat-search-count {
      color: #1E4A8A !important; background: #DBEAFE !important;
    }
    /* Highlight de matches no resultado da busca */
    .search-match { background: rgba(245,158,11,.35) !important; color: #FFF !important; padding: 0 2px; border-radius: 3px; }
    html[data-theme="light"] .search-match { background: #FEF3C7 !important; color: #92400E !important; }

    /* — Msg apagada (P2-N) — */
    .msg-deleted {
      display:inline-flex; align-items:center;
      color:#6B7887; font-style:italic; font-size:.82rem;
    }

    /* — Infinite scroll loader (P0-A) — */
    .chat-older-loader, .chat-history-end, .chat-empty-msgs {
      text-align:center; padding:10px; font-size:.78rem;
      color:#6B7887; font-style:italic;
    }
    .chat-empty-msgs.error { color:#B06070; padding:16px; }
    .chat-history-end {
      border-top:1px dashed rgba(160,180,210,.15);
      margin-top:8px; padding-top:14px;
    }

    /* — Highlight pulsa quando navega via quote (P0-J) — */
    @keyframes highlight-flash-anim {
      0%   { background: rgba(37,99,235,.30); }
      100% { background: transparent; }
    }
    .msg-row.highlight-flash { animation: highlight-flash-anim 1.5s ease-out; border-radius:8px; }

    /* — Group members modal list (P1-I) — */
    .gm-list {
      display:flex; flex-direction:column; gap:6px;
      max-height:60vh; overflow-y:auto;
    }
    .gm-row {
      display:flex; align-items:center; gap:10px;
      padding:9px 10px; border-radius:8px;
      background: rgba(8,18,32,.65);
      border:1px solid rgba(160,180,210,.10);
    }
    .gm-row:hover { background: rgba(26,58,92,.30); }
    .gm-avatar {
      width:36px; height:36px; flex-shrink:0; border-radius:50%;
      background: linear-gradient(135deg, #1A3A5C, #244E7A);
      display:flex; align-items:center; justify-content:center;
      color:#C8D4E0; font-weight:700; font-size:.78rem;
    }
    .gm-info { flex:1; min-width:0; }
    .gm-name { font-size:.85rem; font-weight:600; color:#D8E4F0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .gm-phone { font-size:.74rem; color:#7A8898; }
    .gm-role {
      font-size:.65rem; font-weight:700; padding:2px 7px; border-radius:8px;
      text-transform:uppercase; letter-spacing:.04em; flex-shrink:0;
    }
    .gm-admin      { background: rgba(37,99,235,.25); color:#7EB8F6; border:1px solid rgba(96,165,250,.30); }
    .gm-superadmin { background: rgba(245,158,11,.20); color:#fbbf24; border:1px solid rgba(245,158,11,.30); }
    .gm-empty { text-align:center; padding:20px; color:#7A8898; font-size:.82rem; font-style:italic; }

    /* ═══════════════════════════════════════════════════════════════════════
       TEMA CLARO — Patches para Chat WhatsApp
       Tudo era dark hardcoded; mapeado via auditoria (38 itens).
       Estratégia: sobrescrever bg (resetar gradient com 'background:' shorthand
       quando container tem linear-gradient), borders e cores de texto pra
       paleta navy/cinza-azulado da paleta clara do sistema.
       ═══════════════════════════════════════════════════════════════════════ */

    /* 1) KPIs do topo (CONVERSAS / NÃO LIDAS / CONEXÃO / NÚMERO) */
    html[data-theme="light"] .chat-kpi {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 2px 8px rgba(15,31,54,0.04) !important;
    }
    html[data-theme="light"] .chat-kpi-icon {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .chat-kpi-label { color: #5A6B7E !important; }
    html[data-theme="light"] .chat-kpi-val   { color: #0F1F36 !important; }

    /* 2) Painel principal + sidebar */
    html[data-theme="light"] .chat-panel {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 2px 12px rgba(15,31,54,0.05) !important;
    }
    html[data-theme="light"] .chat-sidebar {
      background: #F8FAFC !important;
      border-right: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .chat-sidebar-header {
      background: #FFFFFF !important;
      border-bottom: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .chat-sidebar-title h2 { color: #0F1F36 !important; }

    /* 3) Busca + filtros */
    html[data-theme="light"] .chat-search input {
      background: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .chat-search input::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .chat-search-icon { color: #94A3B8 !important; }
    html[data-theme="light"] .chat-filter-btn {
      color: #5A6B7E !important;
      background: transparent !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .chat-filter-btn:hover {
      color: #1E4A8A !important;
      background: #F1F5F9 !important;
    }
    html[data-theme="light"] .chat-filter-btn.active {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
      border-color: #BFDBFE !important;
    }
    /* Dropdowns de setor/responsável */
    html[data-theme="light"] .chat-sector-dd {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 8px 24px rgba(15,31,54,0.12) !important;
    }
    html[data-theme="light"] .chat-sector-dd-item {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .chat-sector-dd-item:hover {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
    }

    /* 4) Lista de conversas */
    html[data-theme="light"] .chat-item {
      border-bottom: 1px solid #F1F5F9 !important;
    }
    /* Hover mais marcante — antes era #EFF6FF (quase invisivel sobre fundo claro).
       Agora #DBEAFE (blue-100), com borda esquerda azul pra reforcar foco. */
    html[data-theme="light"] .chat-item:hover {
      background: #DBEAFE !important;
      box-shadow: inset 3px 0 0 #93C5FD !important;
    }
    html[data-theme="light"] .chat-item.active {
      background: #BFDBFE !important;
      box-shadow: inset 3px 0 0 #2563EB !important;
    }
    html[data-theme="light"] .chat-item-name    { color: #0F1F36 !important; }
    html[data-theme="light"] .chat-item-time    { color: #94A3B8 !important; }
    html[data-theme="light"] .chat-item-preview { color: #5A6B7E !important; }
    html[data-theme="light"] .chat-unread-badge {
      background: #2563EB !important;
      color: #FFFFFF !important;
    }

    /* 5) Conteúdo direita (lista vazia ou conversa aberta) */
    html[data-theme="light"] .chat-content {
      background: #F8FAFC !important;
    }
    html[data-theme="light"] .chat-content-header {
      background: #FFFFFF !important;
      border-bottom: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .chat-contact-name { color: #0F1F36 !important; }
    html[data-theme="light"] .chat-icon-btn {
      background: #FFFFFF !important;
      border: 1px solid #BFDBFE !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .chat-icon-btn:hover { background: #EFF6FF !important; }
    /* Menu "Mais opções" (⋮) */
    html[data-theme="light"] .chat-more-dd {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 8px 24px rgba(15,31,54,0.12) !important;
    }
    html[data-theme="light"] .chat-more-item { color: #0F1F36 !important; }
    html[data-theme="light"] .chat-more-item:hover { background: #EFF6FF !important; color: #1D4ED8 !important; }

    /* @menções em grupos — estilo WhatsApp Web (azul + bold) */
    .msg-mention {
      color: #34B7F1;
      font-weight: 600;
      cursor: default;
    }
    .msg-row.outbound .msg-mention { color: #BFDBFE; }
    html[data-theme="light"] .msg-mention { color: #1D4ED8 !important; }
    html[data-theme="light"] .msg-row.outbound .msg-mention { color: #1E40AF !important; }

    /* Status de leitura ✓/✓✓ — azul WhatsApp pra mensagens lidas, cinzas pros demais.
       Vale tanto no dark quanto no light, mas o claro precisa de !important pra
       sobrescrever o ! important de outros temas. */
    .msg-status-icon.read     { color: #34B7F1 !important; }    /* azul WhatsApp p/ lidas */
    .msg-status-icon.delivered{ color: #7A8898 !important; }    /* cinza medio p/ entregues */
    .msg-status-icon.pending  { color: #B0BCC9 !important; }    /* cinza claro p/ pending */
    html[data-theme="light"] .msg-status-icon.read     { color: #2563EB !important; }
    html[data-theme="light"] .msg-status-icon.delivered{ color: #64748B !important; }
    html[data-theme="light"] .msg-status-icon.pending  { color: #94A3B8 !important; }

    /* 6) Mensagens — balões enviadas/recebidas */
    html[data-theme="light"] .chat-messages { background: #F8FAFC !important; }
    html[data-theme="light"] .msg-row.inbound .msg-bubble {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      color: #0F1F36 !important;
      box-shadow: 0 1px 3px rgba(15,31,54,0.06) !important;
    }
    html[data-theme="light"] .msg-row.outbound .msg-bubble {
      background: linear-gradient(135deg, #DBEAFE, #BFDBFE) !important;
      border: 1px solid #93C5FD !important;
      color: #0F1F36 !important;
      box-shadow: 0 1px 3px rgba(37,99,235,0.10) !important;
    }
    html[data-theme="light"] .msg-bubble .msg-time { color: #5A6B7E !important; }
    html[data-theme="light"] .msg-bubble .msg-author { color: #1D4ED8 !important; }

    /* 7) Input de digitar + botão enviar */
    html[data-theme="light"] .chat-input-area {
      background: #FFFFFF !important;
      border-top: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] #chatInput {
      background: #F8FAFC !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] #chatInput::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .chat-send-btn {
      background: linear-gradient(135deg, #2563EB, #1D4ED8) !important;
      color: #FFFFFF !important;
    }
    html[data-theme="light"] .chat-send-btn:hover { filter: brightness(1.08); }

    /* 8) Empty state ("Selecione uma conversa") + tela de conexão */
    html[data-theme="light"] .chat-empty-state { color: #5A6B7E !important; }
    html[data-theme="light"] #connectionScreen { color: #5A6B7E !important; }
    html[data-theme="light"] .conn-status-badge {
      background: #D1FAE5 !important;
      color: #047857 !important;
      border: 1px solid #6EE7B7 !important;
    }

    /* 9) Modais (Configurações Evolution API + Contatos + Membros) */
    html[data-theme="light"] .modal-overlay {
      background: rgba(15,31,54,0.45) !important;
    }
    html[data-theme="light"] .modal-box {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 24px 60px rgba(15,31,54,0.18) !important;
    }
    html[data-theme="light"] .modal-header {
      background: #F8FAFC !important;
      border-bottom: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .modal-header h3 { color: #0F1F36 !important; }
    html[data-theme="light"] .modal-close {
      color: #5A6B7E !important;
    }
    html[data-theme="light"] .modal-close:hover { color: #B91C1C !important; }
    html[data-theme="light"] .modal-body  { background: #FFFFFF !important; color: #0F1F36 !important; }
    html[data-theme="light"] .modal-body p,
    html[data-theme="light"] .modal-body small { color: #5A6B7E !important; }
    html[data-theme="light"] .form-label  { color: #475569 !important; }
    html[data-theme="light"] .form-input {
      background-color: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .form-input::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .form-input:focus {
      border-color: #2563EB !important;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
      outline: none;
    }
    html[data-theme="light"] .modal-footer {
      background: #F8FAFC !important;
      border-top: 1px solid #E2E8F0 !important;
    }
    /* Botão "Aplicar Webhook na Instância" e similares de outline */
    html[data-theme="light"] .modal-body button:not(.btn-primary):not(.btn-danger) {
      background: #FFFFFF !important;
      border: 1px solid #BFDBFE !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .modal-body button:not(.btn-primary):not(.btn-danger):hover {
      background: #EFF6FF !important;
    }

    /* ── 10) Ícone do botão Enviar — branco puro pra contrastar com fundo azul ── */
    html[data-theme="light"] .chat-send-btn svg { color: #FFFFFF !important; stroke: #FFFFFF !important; fill: none !important; }
    html[data-theme="light"] .chat-send-btn svg * { stroke: #FFFFFF !important; fill: none !important; }

    /* Botões de filtro "Todos os setores" / "Todos os responsáveis" na sidebar.
       Borda azul reforçada (era 1px rgba 10%, ficava praticamente invisivel). */
    html[data-theme="light"] .chat-sector-btn {
      background: #FFFFFF !important;
      border: 1px solid #BFDBFE !important;
      color: #1E4A8A !important;
    }
    html[data-theme="light"] .chat-sector-btn:hover {
      background: #EFF6FF !important;
      border-color: #2563EB !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .chat-sector-btn.active {
      background: #DBEAFE !important;
      border-color: #2563EB !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .chat-sector-btn .csf-chevron { opacity: 0.7 !important; color: #1D4ED8 !important; }

    /* ── 11) Dropdown "Setor" da topbar (.sector-dropdown + .sector-dd-*) ──
       Container é .sector-dropdown (linha 479); items são .sector-dd-item.
       Esse é o dropdown da topbar que aparece com Civil/Previdenciario/Trabalista.
       Distinto do .chat-sector-dd (filtro da sidebar). */
    html[data-theme="light"] .sector-dropdown {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 8px 24px rgba(15,31,54,0.12) !important;
    }
    html[data-theme="light"] .sector-dd-item {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .sector-dd-item:hover {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .sector-dd-item.active {
      color: #1D4ED8 !important;
      background: #DBEAFE !important;
    }
    html[data-theme="light"] .sector-dd-divider {
      border-top: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .sector-dd-remove {
      color: #B91C1C !important;
    }
    html[data-theme="light"] .sector-dd-remove:hover {
      background: #FEE2E2 !important;
    }

    /* ── 12) Modal "Vincular Conversa" — .link-* family ──
       Campos: Card (Lead), Processos Juridicos, Usuario Responsavel. */
    html[data-theme="light"] .link-select {
      background-color: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
      /* Chevron escuro pra ser visivel no fundo branco */
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%231E4A8A' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") !important;
    }
    html[data-theme="light"] .link-select:hover,
    html[data-theme="light"] .link-select:focus {
      border-color: #2563EB !important;
    }
    html[data-theme="light"] .link-select option { background: #FFFFFF !important; color: #0F1F36 !important; }

    /* Trigger (caixa cinza com "— Nenhum vinculado —") */
    html[data-theme="light"] .link-picker-trigger {
      background: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
    }
    html[data-theme="light"] .link-picker-trigger:hover { border-color: #93C5FD !important; }
    html[data-theme="light"] .link-picker-trigger.has-value { border-color: #2563EB !important; }
    html[data-theme="light"] .link-picker-label { color: #94A3B8 !important; }
    html[data-theme="light"] .link-picker-label.selected { color: #0F1F36 !important; }
    html[data-theme="light"] .link-picker-sub { color: #64748B !important; }
    html[data-theme="light"] .link-picker-clear { color: #64748B !important; }
    html[data-theme="light"] .link-picker-clear:hover { color: #B91C1C !important; }
    html[data-theme="light"] .link-picker-chevron { color: #1E4A8A !important; }

    /* Dropdown que abre quando clica no trigger */
    html[data-theme="light"] .link-picker-dropdown {
      background: #FFFFFF !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 8px 24px rgba(15,31,54,0.12) !important;
    }
    html[data-theme="light"] .link-picker-search {
      color: #0F1F36 !important;
      border-bottom: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .link-picker-search::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .link-picker-item { color: #0F1F36 !important; }
    html[data-theme="light"] .link-picker-item:hover {
      background: #EFF6FF !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .link-picker-empty { color: #94A3B8 !important; }

    /* Botão "+ Adicionar processo" dentro do modal Vincular */
    html[data-theme="light"] .modal-body .btn-add-process,
    html[data-theme="light"] .modal-body button[onclick*="adicionarProcesso"],
    html[data-theme="light"] .modal-body button[onclick*="addProcesso"] {
      background: #FFFFFF !important;
      border: 1px dashed #93C5FD !important;
      color: #1D4ED8 !important;
    }

    /* ── 13) Modal "Nomes dos Contatos" — linhas .contact-row ──
       JS antes renderizava com inline styles dark; refatorado pra classes
       (.contact-row, .contact-jid, .contact-phone, .contact-name-input). */
    .contact-row {
      display: flex; align-items: center; gap: 10px;
      background: #0D1A28;
      border: 1px solid rgba(100,150,200,.12);
      border-radius: 8px;
      padding: 9px 12px;
      margin-bottom: 6px;
    }
    .contact-row-info { flex: 1; min-width: 0; }
    .contact-jid   { font-size: .72rem; color: #4A5568; word-break: break-all; }
    .contact-phone { font-size: .75rem; color: #7EB8F7; }
    .contact-name-input {
      flex: 1; min-width: 0;
      background: #1A2740;
      border: 1px solid rgba(100,150,200,.2);
      border-radius: 6px;
      color: #D8E4F0;
      padding: 6px 10px;
      font-size: .82rem;
      transition: border-color .15s, box-shadow .15s;
    }
    .contact-name-input:focus {
      border-color: rgba(126,184,247,.5);
      outline: none;
    }

    /* Tema claro */
    html[data-theme="light"] .contact-row {
      background: #F8FAFC !important;
      border: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .contact-jid   { color: #64748B !important; }
    html[data-theme="light"] .contact-phone { color: #1D4ED8 !important; font-weight: 600; }
    html[data-theme="light"] .contact-name-input {
      background: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .contact-name-input::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .contact-name-input:focus {
      border-color: #2563EB !important;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    }

    /* ═══ Tema claro: menu de acoes/reactions/quoted (P2 wire-up 2026-05-25) ═══ */
    html[data-theme="light"] .msg-actions-menu {
      background: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      box-shadow: 0 12px 32px rgba(15,31,54,0.18) !important;
    }
    html[data-theme="light"] .msg-actions-menu button {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .msg-actions-menu button:hover {
      background: #EFF6FF !important;
    }
    html[data-theme="light"] .msg-actions-menu button.danger {
      color: #DC2626 !important;
    }
    html[data-theme="light"] .msg-actions-menu button.danger:hover {
      background: #FEE2E2 !important;
    }
    html[data-theme="light"] .msg-actions-reactions {
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .msg-act-react:hover {
      background: #EFF6FF !important;
    }
    /* Botao 3 pontos: contraste melhor em fundo claro */
    html[data-theme="light"] .msg-menu-btn {
      background: rgba(15,31,54,0.10) !important;
      color: #475569 !important;
    }
    html[data-theme="light"] .msg-menu-btn:hover {
      background: rgba(15,31,54,0.20) !important;
      color: #0F1F36 !important;
    }
    /* Reactions pills no tema claro */
    html[data-theme="light"] .msg-reaction-pill {
      background: #FFFFFF !important;
      border: 1px solid #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .msg-reaction-pill:hover {
      background: #DBEAFE !important;
    }
    html[data-theme="light"] .msg-reaction-pill.me {
      background: #DBEAFE !important;
      border-color: #2563EB !important;
    }
    html[data-theme="light"] .msg-reaction-pill .cnt {
      color: #475569 !important;
    }
    /* Quoted preview na bolha (msg citada) */
    html[data-theme="light"] .msg-quoted {
      background: rgba(15,31,54,0.06) !important;
    }
    html[data-theme="light"] .msg-quoted:hover {
      background: rgba(15,31,54,0.10) !important;
    }
    html[data-theme="light"] .msg-quoted-text {
      color: #475569 !important;
    }
    /* "Mensagem apagada" */
    html[data-theme="light"] .msg-deleted {
      color: #94A3B8 !important;
    }
    /* Reply bar (acima do input) */
    html[data-theme="light"] .chat-reply-bar {
      background: #EFF6FF !important;
      border-color: #93C5FD !important;
      border-left-color: #2563EB !important;
    }
    html[data-theme="light"] .chat-reply-bar-text {
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .chat-reply-bar-cancel {
      color: #475569 !important;
    }
    html[data-theme="light"] .chat-reply-bar-cancel:hover {
      color: #DC2626 !important;
    }
  </style>
</head>
<body>

<!-- Fundo animado (padrão das outras abas) -->
<div class="bg-fog-shared" id="sharedFog" aria-hidden="true">
  <div class="fog-blob-shared b1"></div>
  <div class="fog-blob-shared b2"></div>
  <div class="fog-blob-shared b3"></div>
</div>

<main class="w-full px-6 py-6 page-above-fog" style="height:100vh;overflow:hidden;display:flex;flex-direction:column">
  <div class="page-layout" style="flex:1;min-height:0;overflow:hidden;align-items:stretch">

    <?php include 'includes/sidebar.php'; ?>

    <!-- ── Conteúdo principal ── -->
    <section class="main-content" style="display:flex;flex-direction:column;gap:14px;min-height:0;overflow:hidden">

      <!-- Cabeçalho padrão (igual às outras abas) -->
      <div class="dash-panel page-header" style="flex-shrink:0">
        <div class="page-header-inner">
          <div class="page-header-text">
            <h2 class="page-header-title">
              Chat WhatsApp
            </h2>
            <p class="page-header-subtitle">Interface de mensagens integrada via Evolution API</p>
          </div>
          <div class="page-header-actions">
        <button class="conn-btn-secondary" onclick="ChatApp.openSettings()" title="Configurações">
          <svg style="display:inline;width:14px;height:14px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06A2 2 0 1 1 2.27 17.8l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09c.7 0 1.27-.43 1.51-1a1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.3 2.27l.06.06c.5.5 1.2.75 1.82.33A1.65 1.65 0 0 0 9.69 1.5 1.65 1.65 0 0 0 9.7 1H12a2 2 0 1 1 0 4h-.09c-.7 0-1.27.43-1.51 1a1.65 1.65 0 0 0 .33 1.82l.06.06A2 2 0 1 1 17.73 6.2l-.06.06c-.5.5-.75 1.2-.33 1.82.32.56.32 1.28.32 1.82V12a2 2 0 1 1 4 0v.09c0 .7.43 1.27 1 1.51z"/></svg>
          Configurações
        </button>
        <button class="conn-btn-primary" id="btnConnectTop" onclick="ChatApp.connectWhatsApp()" style="display:none">
          Conectar WhatsApp
        </button>
        <button class="conn-btn-secondary" id="btnSync" onclick="ChatApp.syncChats()" title="Importar conversas existentes da Evolution API" style="display:none">
          <svg style="display:inline;width:13px;height:13px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Sincronizar
        </button>
        <button class="conn-btn-secondary" id="btnContacts" onclick="ChatApp.openContacts()" style="display:none" title="Editar nomes dos contatos">
          <svg style="display:inline;width:13px;height:13px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          Contatos
        </button>
        <button class="conn-btn-secondary" id="btnDisconnect" onclick="ChatApp.disconnectWhatsApp()" style="display:none">
          Desconectar
        </button>
      </div><!-- /page-header-actions -->
        </div><!-- /page-header-inner -->
      </div><!-- /page-header -->

      <!-- Corpo do chat (preenche o restante da altura) -->
      <main class="chat-main">

    <!-- KPIs -->
    <div class="chat-kpis" id="chatKpis">
      <div class="chat-kpi">
        <div class="chat-kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div>
          <div class="chat-kpi-label">Conversas</div>
          <div class="chat-kpi-val" id="kpiChats">—</div>
        </div>
      </div>
      <div class="chat-kpi">
        <div class="chat-kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
        </div>
        <div>
          <div class="chat-kpi-label">Não lidas</div>
          <div class="chat-kpi-val" id="kpiUnread">—</div>
        </div>
      </div>
      <div class="chat-kpi">
        <div class="chat-kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div>
          <div class="chat-kpi-label">Conexão</div>
          <div class="chat-kpi-val" id="kpiStatus">—</div>
        </div>
      </div>
      <div class="chat-kpi">
        <div class="chat-kpi-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.62 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 9A16 16 0 0 0 15 16.09l1.27-.97a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        </div>
        <div>
          <div class="chat-kpi-label">Número</div>
          <div class="chat-kpi-val" id="kpiPhone" style="font-size:.82rem">—</div>
        </div>
      </div>
    </div>

    <!-- Painel principal split -->
    <div class="chat-panel">

      <!-- ── Coluna esquerda ── -->
      <div class="chat-sidebar">
        <div class="chat-sidebar-header">
          <div class="chat-sidebar-title">
            <h2>
              <span class="wa-status-dot disconnected" id="waDot"></span>
              Conversas
            </h2>
            <span id="waStatusLabel" style="font-size:.7rem;color:#7A8898">Desconectado</span>
          </div>
          <div class="chat-search">
            <svg class="chat-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" id="chatSearch" placeholder="Buscar conversa ou número..." oninput="ChatApp.searchChats(this.value)">
          </div>
          <div class="chat-filters">
            <button class="chat-filter-btn active" data-filter="all"        onclick="ChatApp.setFilter('all')">Todas</button>
            <button class="chat-filter-btn"         data-filter="unread"    onclick="ChatApp.setFilter('unread')">Não lidas</button>
            <button class="chat-filter-btn"         data-filter="groups"    onclick="ChatApp.setFilter('groups')">Grupos</button>
            <button class="chat-filter-btn"         data-filter="individual" onclick="ChatApp.setFilter('individual')">Individuais</button>
            <button class="chat-filter-btn"         data-filter="pinned"    onclick="ChatApp.setFilter('pinned')">Fixadas</button>
            <button class="chat-filter-btn"         data-filter="archived"  onclick="ChatApp.setFilter('archived')">Arquivadas</button>
          </div>

          <!-- Filtro por setor -->
          <div class="chat-sector-filter">
            <button id="sectorFilterBtn" class="chat-sector-btn" onclick="ChatApp.toggleTeamFilterDropdown(event)" title="Filtrar por setor">
              <span class="csf-dot" id="sectorFilterDot"></span>
              <span class="csf-label" id="sectorFilterLabel">Todos os setores</span>
              <svg class="csf-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div id="sectorFilterDd" class="chat-sector-dd"></div>
          </div>

          <!-- Filtro por responsável (mesmo padrão do setor) -->
          <div class="chat-sector-filter">
            <button id="userFilterBtn" class="chat-sector-btn" onclick="ChatApp.toggleUserFilterDropdown(event)" title="Filtrar por responsável">
              <span class="csf-dot" id="userFilterDot"></span>
              <span class="csf-label" id="userFilterLabel">Todos os responsáveis</span>
              <svg class="csf-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div id="userFilterDd" class="chat-sector-dd"></div>
          </div>
        </div>
        <div class="chat-list" id="chatList">
          <div class="chat-list-empty" id="chatListEmpty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Conecte o WhatsApp para ver as conversas</span>
          </div>
        </div>
      </div>

      <!-- ── Coluna direita ── -->
      <div class="chat-content" id="chatContent">

        <!-- Tela de conexão (estado desconectado) -->
        <div id="connectionScreen">
          <div style="display:flex;flex-direction:column;align-items:center;gap:16px">
            <svg style="width:80px;height:80px;opacity:.15" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.38 1.27 4.79L2.05 22l5.38-1.37c1.37.74 2.93 1.16 4.61 1.16 5.45 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.13-2.9-7A9.83 9.83 0 0 0 12.04 2z"/></svg>
            <div>
              <p class="conn-title">WhatsApp não conectado</p>
              <p class="conn-sub">Clique em Conectar para gerar o QR Code e vincular seu WhatsApp ao CRM</p>
            </div>
            <span class="conn-status-badge disconnected" id="connBadge">
              <span class="wa-status-dot disconnected" style="width:7px;height:7px"></span>
              Desconectado
            </span>
            <button class="conn-btn-primary" id="btnConnect" onclick="ChatApp.connectWhatsApp()">
              Conectar WhatsApp
            </button>
            <div class="qr-container" id="qrContainer">
              <p style="color:#9A7B28;font-size:.82rem;margin:0">Escaneie o QR Code com seu WhatsApp</p>
              <img id="qrCodeImg" src="" alt="QR Code">
              <p style="color:#4A5568;font-size:.75rem;margin:0">O código expira em 60 segundos. Atualizando automaticamente…</p>
              <button class="conn-btn-secondary" onclick="ChatApp.refreshQr()">Atualizar QR Code</button>
            </div>
          </div>
        </div>

        <!-- Área de mensagens (oculta até chat ser selecionado) -->
        <div id="chatArea" style="display:none;flex:1;flex-direction:column;overflow:hidden">
          <!-- Header do chat aberto -->
          <div class="chat-content-header" id="chatHeader">
            <button class="chat-icon-btn" onclick="ChatApp.closeChat()" title="Voltar" style="margin-right:2px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <div class="chat-avatar" id="activeAvatar"></div>
            <div class="chat-contact-info">
              <div class="chat-contact-name" id="activeName">—</div>
              <div class="chat-contact-sub"  id="activePhone">—</div>
            </div>
            <div class="chat-header-actions">

              <!-- ── Badge de Setor (aparece ao abrir uma conversa) ── -->
              <div id="sectorBadgeWrap" style="position:relative;display:none">
                <button id="sectorBadgeBtn" class="chat-icon-btn sector-badge-btn"
                        onclick="ChatApp.toggleSectorDropdown(event)"
                        title="Setor responsável">
                  <span id="sectorDot" class="sector-dot"></span>
                  <span id="sectorBadgeName" style="font-size:.72rem;font-weight:600;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">Setor</span>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px;flex-shrink:0;opacity:.6"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <!-- Dropdown de setores -->
                <div id="sectorDropdown" class="sector-dropdown" style="display:none"></div>
              </div>

              <!-- Busca dentro da conversa atual -->
              <button class="chat-icon-btn" onclick="ChatApp.toggleChatSearch()" title="Buscar nesta conversa" id="btnChatSearch">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </button>
              <button class="chat-icon-btn" onclick="ChatApp.openLinkModal()" title="Vincular ao sistema"
                      style="width:auto;padding:0 10px;gap:5px;font-size:.75rem;font-weight:600;color:#7EB8F7;border-color:rgba(126,184,247,.2)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:13px;height:13px;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                Vincular
              </button>
              <button class="chat-icon-btn" onclick="ChatApp.togglePin()" title="Fixar conversa">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              </button>
              <button class="chat-icon-btn" onclick="ChatApp.refreshMessages()" title="Atualizar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              </button>
              <!-- P1-I (auditoria 2026-05-24): botão de membros do grupo (só aparece em chat de grupo) -->
              <button class="chat-icon-btn" id="chatMembersBtn" onclick="ChatApp.openGroupMembers()" title="Membros do grupo" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </button>

              <!-- ── Mais opções ── -->
              <div class="chat-more-wrap">
                <button class="chat-icon-btn" onclick="ChatApp.toggleMoreMenu(event)" title="Mais opções">
                  <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                </button>
                <div id="chatMoreDd" class="chat-more-dd">
                  <div class="chat-more-item" onclick="ChatApp.togglePin();ChatApp.closeMoreMenu()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V17z"/></svg>
                    Fixar / Desafixar
                  </div>
                  <div class="chat-more-item" onclick="ChatApp.markChatUnread();ChatApp.closeMoreMenu()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Marcar como não lida
                  </div>
                  <div class="chat-more-item" onclick="ChatApp.toggleArchive();ChatApp.closeMoreMenu()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                    Arquivar / Desarquivar
                  </div>
                  <div class="chat-more-item danger" onclick="ChatApp.confirmDeleteChat()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Excluir conversa
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Barra de busca dentro da conversa (toggle via header) -->
          <div id="chatSearchBar" class="chat-search-bar" style="display:none">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;flex-shrink:0;color:#7EB8F6"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="chatSearchInput" placeholder="Buscar nesta conversa…" oninput="ChatApp.onChatSearchInput()" onkeydown="if(event.key==='Escape')ChatApp.closeChatSearch()">
            <span id="chatSearchCount" class="chat-search-count"></span>
            <button class="chat-icon-btn" onclick="ChatApp.closeChatSearch()" title="Fechar busca" style="width:24px;height:24px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <!-- Mensagens -->
          <div class="chat-messages" id="chatMessages">
            <!-- Renderizado via JS -->
          </div>

          <!-- Input area -->
          <div class="chat-input-area" id="chatInputArea">
            <!-- Preview de arquivo selecionado + Reply bar -->
            <div style="flex:1;min-width:0">
              <!-- P0-J (auditoria 2026-05-24): Reply bar — aparece ao responder mensagem -->
              <div class="chat-reply-bar" id="chatReplyBar" style="display:none">
                <div class="chat-reply-bar-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                </div>
                <div class="chat-reply-bar-content">
                  <div class="chat-reply-bar-label">Respondendo a</div>
                  <div class="chat-reply-bar-text" id="chatReplyText"></div>
                </div>
                <button class="chat-reply-bar-cancel" onclick="ChatApp.cancelReply()" title="Cancelar resposta">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
              <!-- P0-E (auditoria 2026-05-24): preview de arquivo com thumbnail e tamanho -->
              <div class="chat-file-preview" id="filePreview">
                <div class="chat-file-thumb" id="filePreviewThumb"></div>
                <div class="chat-file-info">
                  <span class="chat-file-preview-name" id="filePreviewName"></span>
                  <span class="chat-file-preview-size" id="filePreviewSize"></span>
                </div>
                <button class="chat-file-remove" onclick="ChatApp.clearFile()">
                  <svg style="width:14px;height:14px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
              <div style="display:flex;align-items:flex-end;gap:8px">
                <!-- Botão anexar -->
                <label class="chat-attach-btn" title="Anexar arquivo">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                  <input type="file" id="fileInput" style="display:none" onchange="ChatApp.onFileSelected(this)" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar">
                </label>

                <!-- Input de texto -->
                <div class="chat-text-wrap">
                  <textarea id="chatInput" rows="1" placeholder="Digite uma mensagem…"
                    onkeydown="ChatApp.onKeyDown(event)"
                    oninput="ChatApp.autoResize(this)"></textarea>
                </div>

                <!-- Botão áudio -->
                <button class="chat-audio-btn" id="audioBtn" onclick="ChatApp.toggleAudio()" title="Áudio">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                </button>

                <!-- Botão enviar -->
                <button class="chat-send-btn" onclick="ChatApp.send()" title="Enviar">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Estado sem chat selecionado (quando conectado) -->
        <div class="chat-empty-state" id="chatEmptyState" style="display:none">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <h3>Selecione uma conversa</h3>
          <p>Clique em uma conversa na lista para abrir o histórico de mensagens</p>
        </div>

      </div><!-- /chat-content -->
    </div><!-- /chat-panel -->

      </main><!-- /chat-main -->
    </section><!-- /main-content -->
  </div><!-- /page-layout -->
</main><!-- /w-full -->

<!-- ── Toast ── -->
<div id="chatToast"></div>

<!-- Alerta de desconexão WhatsApp -->
<div id="disconnectAlert" role="alert" aria-live="assertive">
  <svg class="disc-icon" style="width:22px;height:22px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
  </svg>
  <div class="disc-text">
    <strong>WhatsApp desconectado. Reconecte para continuar recebendo mensagens.</strong>
    <span id="reconnectStatus">Tentando reconectar automaticamente…</span>
  </div>
  <div class="disc-actions">
    <button class="disc-btn-qr" onclick="ChatApp.manualReconnect()">Gerar QR Code</button>
    <button class="disc-btn-dismiss" onclick="ChatApp.dismissDisconnectAlert()" title="Fechar alerta">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>

<!-- Banner de sincronização -->
<div id="syncBanner" aria-live="polite">
  <div class="sync-spinner"></div>
  <div class="sync-progress-wrap">
    <div class="sync-progress-text" id="syncBannerText">
      Sincronizando conversas e mensagens…
    </div>
    <div class="sync-progress-bar-track">
      <div class="sync-progress-bar-fill" id="syncProgressBar"></div>
    </div>
  </div>
  <div class="sync-timer" id="syncTimer">0s</div>
</div>

<!-- ── Modal: Configurações da Evolution API ── -->
<div class="modal-overlay" id="settingsModal" onclick="if(event.target===this)ChatApp.closeSettings()">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Configurações — Evolution API</h3>
      <button class="modal-close" onclick="ChatApp.closeSettings()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="form-field">
        <label class="form-label">URL da Evolution API</label>
        <input type="text" class="form-input" id="cfgBaseUrl" placeholder="http://localhost:8080">
      </div>
      <div class="form-field">
        <label class="form-label">API Key (Global)</label>
        <input type="text" class="form-input" id="cfgApiKey" placeholder="sua-chave-de-api">
      </div>
      <div class="form-field">
        <label class="form-label">Nome da Instância</label>
        <input type="text" class="form-input" id="cfgInstance" placeholder="yuris-crm">
      </div>
      <div class="form-field">
        <label class="form-label">URL do Webhook (para receber mensagens)</label>
        <input type="text" class="form-input" id="cfgWebhook" placeholder="https://seudominio.com/api/whatsapp/webhook.php">
        <span style="font-size:.72rem;color:#4A5568">A URL precisa ser acessível pela Evolution API. Em localhost, use ngrok ou similar.</span>
      </div>
      <button class="conn-btn-secondary" onclick="ChatApp.applyWebhook()" style="width:100%;justify-content:center">
        Aplicar Webhook na Instância
      </button>
    </div>
    <div class="modal-footer">
      <button class="conn-btn-secondary" onclick="ChatApp.closeSettings()">Cancelar</button>
      <button class="conn-btn-primary"   onclick="ChatApp.saveSettings()">Salvar</button>
    </div>
  </div>
</div>

<!-- ── Modal: Vincular Conversa ── -->
<div class="modal-overlay" id="linkModal" onclick="if(event.target===this)ChatApp.closeLinkModal()">
  <div class="modal-box" style="max-width:460px">
    <div class="modal-header">
      <h3>Vincular Conversa</h3>
      <button class="modal-close" onclick="ChatApp.closeLinkModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <p style="font-size:.82rem;color:#7A8898;margin:0 0 4px">Vincule esta conversa a um registro do CRM para facilitar o acompanhamento.</p>

      <!-- Card picker -->
      <div class="form-field">
        <label class="form-label">Card (Lead / Oportunidade)</label>
        <div class="link-picker" id="pickerCard">
          <div class="link-picker-trigger" onclick="ChatApp.openLinkPicker('card')">
            <span class="link-picker-label" id="pickerCardName">— Nenhum vinculado —</span>
            <button class="link-picker-clear" onclick="ChatApp.clearLinkItem('card',event)" title="Desvincular"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            <span class="link-picker-chevron">▾</span>
          </div>
          <div class="link-picker-dropdown" id="pickerCardDropdown">
            <input class="link-picker-search" id="pickerCardSearch" autocomplete="off"
                   placeholder="Buscar por nome ou empresa…"
                   oninput="ChatApp.filterLinkPicker('card',this.value)">
            <div class="link-picker-list" id="pickerCardList"></div>
          </div>
        </div>
        <a id="pickerCardGoBtn" href="#" class="btn-yuris btn-yuris-sm" style="display:none;margin-top:6px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Abrir card no CRM
        </a>
      </div>

      <!-- Processos — lista com múltiplos -->
      <div class="form-field">
        <label class="form-label" style="display:flex;align-items:center;justify-content:space-between">
          <span>Processos Jurídicos</span>
        </label>
        <div id="linkedProcessosList" style="display:flex;flex-direction:column;gap:5px;margin-bottom:6px">
          <span style="font-size:.78rem;color:#3A5A7A;font-style:italic">Nenhum processo vinculado</span>
        </div>
        <!-- Botão + picker de adição -->
        <div class="link-picker" id="pickerAddProcesso">
          <div class="link-picker-trigger" onclick="ChatApp.openLinkPicker('processo')"
               style="border-style:dashed;color:#4A7AAA;justify-content:center;gap:6px">
            <span style="font-size:1rem;line-height:1">＋</span>
            <span style="font-size:.78rem">Adicionar processo</span>
          </div>
          <div class="link-picker-dropdown" id="pickerAddProcessoDD">
            <input class="link-picker-search" id="pickerAddProcessoSearch" autocomplete="off"
                   placeholder="Buscar por número ou cliente…"
                   oninput="ChatApp.filterLinkPicker('processo',this.value)">
            <div class="link-picker-list" id="pickerAddProcessoList"></div>
          </div>
        </div>
      </div>

      <!-- Usuário picker -->
      <div class="form-field">
        <label class="form-label">Usuário Responsável</label>
        <div class="link-picker" id="pickerUser">
          <div class="link-picker-trigger" onclick="ChatApp.openLinkPicker('user')">
            <span class="link-picker-label" id="pickerUserName">— Nenhum vinculado —</span>
            <button class="link-picker-clear" onclick="ChatApp.clearLinkItem('user',event)" title="Desvincular"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            <span class="link-picker-chevron">▾</span>
          </div>
          <div class="link-picker-dropdown" id="pickerUserDropdown">
            <input class="link-picker-search" id="pickerUserSearch" autocomplete="off"
                   placeholder="Buscar por nome…"
                   oninput="ChatApp.filterLinkPicker('user',this.value)">
            <div class="link-picker-list" id="pickerUserList"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="conn-btn-secondary" onclick="ChatApp.closeLinkModal()">Cancelar</button>
      <button class="conn-btn-primary"   onclick="ChatApp.saveLink()">Salvar Vínculo</button>
    </div>
  </div>
</div>

<!-- P1-I (auditoria 2026-05-24): Modal de Membros do Grupo -->
<div class="modal-overlay" id="groupMembersModal" onclick="if(event.target===this)ChatApp.closeGroupMembers()">
  <div class="modal-box" style="max-width:460px">
    <div class="modal-header">
      <h3>Membros do grupo <span id="groupMembersCount" style="font-size:.78rem;font-weight:500;color:#7A8898;margin-left:6px"></span></h3>
      <button class="modal-close" onclick="ChatApp.closeGroupMembers()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <div id="groupMembersList" class="gm-list"></div>
    </div>
    <div class="modal-footer">
      <button class="conn-btn-secondary" onclick="ChatApp.closeGroupMembers()">Fechar</button>
    </div>
  </div>
</div>

<!-- Modal de Contatos -->
<div class="modal-overlay" id="contactsModal" onclick="if(event.target===this)ChatApp.closeContacts()">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-header">
      <h3>Nomes dos Contatos</h3>
      <button class="modal-close" onclick="ChatApp.closeContacts()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <p style="font-size:.82rem;color:#7A8898;margin:0 0 12px">Defina o nome exibido para cada participante. Mensagens futuras via webhook atualizam automaticamente.</p>
      <div id="contactsList" style="display:flex;flex-direction:column;gap:8px">
        <p style="color:#4A5568;font-size:.82rem">Carregando…</p>
      </div>
    </div>
    <div class="modal-footer">
      <button class="conn-btn-secondary" onclick="ChatApp.closeContacts()">Fechar</button>
    </div>
  </div>
</div>

<!-- Input de captura de áudio oculto -->
<input type="file" id="audioFileInput" style="display:none" accept="audio/*" onchange="ChatApp.onAudioFileSelected(this)">

<script>
const CSRF          = <?= json_encode($csrf) ?>;
const AUTO_OPEN_JID = <?= json_encode($auto_open_jid) ?>;
const API  = {
  config        : '/api/whatsapp/config.php',
  instances     : '/api/whatsapp/instances.php',
  chats         : '/api/whatsapp/chats.php',
  messages      : '/api/whatsapp/messages.php',
  send          : '/api/whatsapp/send.php',
  upload        : '/api/whatsapp/media_upload.php',
  sync          : '/api/whatsapp/sync.php',
  refresh       : '/api/whatsapp/refresh_chat.php',
  contacts      : '/api/whatsapp/contacts.php',
  discover      : '/api/whatsapp/discover.php',
  // Auditoria 2026-05-24 — novos endpoints
  groupMembers  : '/api/whatsapp/group_members.php',
  reaction      : '/api/whatsapp/reaction.php',
  messageAction : '/api/whatsapp/message_action.php',
};
</script>
<!-- ── Lightbox de Imagem ── -->
<div id="imgLightbox" aria-modal="true" role="dialog">
  <div id="imgLightboxBackdrop"></div>
  <button id="imgLightboxClose" title="Fechar (Esc)">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
  <div id="imgLightboxWrap">
    <img id="imgLightboxImg" src="" alt="Imagem">
    <div id="imgLightboxSpinner">
      <div class="lb-spin"></div>
    </div>
    <div id="imgLightboxErr" style="display:none;flex-direction:column;align-items:center;gap:12px;color:#9ab0c9;font-size:.85rem;">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <span>Não foi possível carregar a imagem</span>
      <a id="imgLightboxErrLink" href="#" target="_blank" rel="noopener" style="color:#7EB8F6;text-decoration:underline;font-size:.8rem;">Abrir no navegador</a>
    </div>
  </div>
  <a id="imgLightboxDownload" download="imagem.jpg" title="Baixar imagem">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Baixar
  </a>
</div>

<style>
#imgLightbox {
  position: fixed; inset: 0; z-index: 9999;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none;
  transition: opacity .25s ease;
}
#imgLightbox.lb-open {
  opacity: 1; pointer-events: all;
}
#imgLightboxBackdrop {
  position: absolute; inset: 0;
  background: rgba(4, 10, 22, 0.92);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}
#imgLightboxClose {
  position: absolute; top: 18px; right: 20px; z-index: 2;
  width: 40px; height: 40px; border-radius: 50%;
  border: 1px solid rgba(96,165,250,.25);
  background: rgba(8,22,44,.8);
  color: #93c5fd; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .18s, border-color .18s, transform .18s;
}
#imgLightboxClose:hover { background: rgba(37,99,235,.3); border-color: rgba(96,165,250,.5); transform: rotate(90deg); }

#imgLightboxWrap {
  position: relative; z-index: 1;
  display: flex; align-items: center; justify-content: center;
  max-width: min(90vw, 1200px); max-height: 88vh;
  transform: scale(.88) translateY(12px);
  transition: transform .28s cubic-bezier(.34,1.56,.64,1);
}
#imgLightbox.lb-open #imgLightboxWrap {
  transform: scale(1) translateY(0);
}
#imgLightboxImg {
  max-width: min(90vw, 900px);
  max-height: 88vh;
  min-width: min(60vw, 400px);
  object-fit: contain;
  border-radius: 12px;
  box-shadow: 0 30px 80px rgba(0,0,0,.7), 0 0 0 1px rgba(96,165,250,.12);
  opacity: 0; transition: opacity .2s .1s ease;
  display: block;
}
#imgLightboxImg.lb-loaded { opacity: 1; }

#imgLightboxSpinner {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  pointer-events: none;
}
#imgLightboxSpinner.lb-hidden { display: none; }
.lb-spin {
  width: 36px; height: 36px; border-radius: 50%;
  border: 3px solid rgba(96,165,250,.15);
  border-top-color: #3b82f6;
  animation: lb-rotate .7s linear infinite;
}
@keyframes lb-rotate { to { transform: rotate(360deg); } }

#imgLightboxDownload {
  position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 2;
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 18px; border-radius: 999px;
  background: rgba(8,22,44,.85); border: 1px solid rgba(96,165,250,.25);
  color: #93c5fd; font-size: .78rem; font-weight: 600;
  text-decoration: none; cursor: pointer;
  transition: background .18s, border-color .18s;
  opacity: 0; transition: opacity .2s .2s ease, background .18s, border-color .18s;
}
#imgLightbox.lb-open #imgLightboxDownload { opacity: 1; }
#imgLightboxDownload:hover { background: rgba(37,99,235,.3); border-color: rgba(96,165,250,.5); }
</style>

<script src="/assets/chat.js?v=50"></script>
<script>
// Lightbox init
(function(){
  const lb      = document.getElementById('imgLightbox');
  const lbImg   = document.getElementById('imgLightboxImg');
  const lbSpin  = document.getElementById('imgLightboxSpinner');
  const lbDl    = document.getElementById('imgLightboxDownload');
  const lbBg    = document.getElementById('imgLightboxBackdrop');
  const lbClose = document.getElementById('imgLightboxClose');
  const lbErr   = document.getElementById('imgLightboxErr');
  const lbErrLink = document.getElementById('imgLightboxErrLink');

  function open(url) {
    lbImg.classList.remove('lb-loaded');
    lbSpin.classList.remove('lb-hidden');
    lbImg.src = '';
    lb.classList.add('lb-open');
    document.body.style.overflow = 'hidden';
    lbImg.onload  = () => { lbImg.classList.add('lb-loaded'); lbSpin.classList.add('lb-hidden'); if(lbErr) lbErr.style.display='none'; };
    lbImg.onerror = () => {
      lbSpin.classList.add('lb-hidden');
      if (lbErr) { lbErr.style.display = 'flex'; lbErrLink.href = url; }
    };
    lbImg.src = url;
    lbDl.href = url;
  }
  function close() {
    lb.classList.remove('lb-open');
    document.body.style.overflow = '';
    setTimeout(() => { lbImg.src = ''; }, 280);
  }

  lbClose.addEventListener('click', close);
  lbBg.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && lb.classList.contains('lb-open')) close(); });

  // override ChatApp.openImage
  window._lbOpen = open;
})();
</script>

<!-- ── Modal de confirmação customizado ── -->
<div id="chatConfirmOverlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(2,6,23,.75);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:linear-gradient(165deg,rgba(15,34,61,.98),rgba(8,20,39,.99));border:1px solid rgba(96,165,250,.2);border-radius:14px;padding:28px 28px 22px;min-width:320px;max-width:420px;box-shadow:0 24px 60px rgba(2,6,23,.7);">
    <div id="chatConfirmIcon" style="margin-bottom:12px;"></div>
    <p id="chatConfirmMsg" style="margin:0 0 22px;color:#d6eaff;font-size:.92rem;line-height:1.5;"></p>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
      <button id="chatConfirmNo"  style="padding:8px 20px;border-radius:8px;border:1px solid rgba(148,163,184,.25);background:transparent;color:#9ab0c9;cursor:pointer;font-size:.85rem;">Cancelar</button>
      <button id="chatConfirmYes" style="padding:8px 20px;border-radius:8px;border:none;background:#dc2626;color:#fff;cursor:pointer;font-size:.85rem;font-weight:600;">Desconectar</button>
    </div>
  </div>
</div>
</body>
</html>

