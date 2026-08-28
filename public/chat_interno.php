<?php
require_once __DIR__ . '/../app/bootstrap.php';
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
// Módulo por plano (402 + página de bloqueio). Só age com a trava mestra
// plan_enforcement_enabled ligada; antes disso apenas registra no log.
\App\Billing\PlanFeature::assertEnabledPage(
    (int)($_SESSION['account_id'] ?? 0),
    \App\Billing\PlanFeature::F_CHAT_INTERNO
);
$activePage = 'chat_interno';
$uid        = (int)$_SESSION['user_id'];
$uNome      = htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário');
$csrf       = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Chat Interno — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <style>
    :root {
      --bg:           #070F1C;
      --panel:        #0D1C30;
      --soft:         #0E1F36;
      --line:         rgba(160,180,210,.08);
      --line-md:      rgba(160,180,210,.14);
      --text:         #D8E4F0;
      --muted:        #7A8898;
      --accent:       #7EB8F6;
      --accent2:      #3B82F6;
      --bubble-out:   #0F2D52;
      --bubble-in:    #101E30;
      --r:            10px;
      /* cores por tipo */
      --c-direto:   #7EB8F6;
      --c-grupo:    #6EC88E;
      --c-geral:    #7EB8F6;
      --c-setor:    #F59E0B;
      --c-filial:   #A78BFA;
      --c-matriz:   #F87171;
      --c-advogado: #34D399;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; overflow: hidden; }

    .ci-wrap { display: flex; flex: 1; min-width: 0; overflow: hidden; gap: 0; align-items: stretch; }

    /* ── Sidebar ─────────────────────────────────────── */
    .ci-sidebar {
      width: 260px; min-width: 220px; background: var(--panel);
      border: 1px solid var(--line-md); border-radius: 12px;
      display: flex; flex-direction: column; overflow: hidden;
    }
    .ci-sidebar-header {
      padding: 12px 14px; border-bottom: 1px solid var(--line-md);
      display: flex; align-items: center; justify-content: space-between; gap: 8px;
    }
    .ci-sidebar-title { font-size: .82rem; font-weight: 600; color: var(--accent); text-transform: uppercase; letter-spacing: .06em; }
    .ci-sidebar-actions { display: flex; gap: 5px; align-items: center; }
    .ci-btn-new {
      background: rgba(59,130,246,.15); border: 1px solid rgba(59,130,246,.3);
      color: var(--accent); padding: 4px 10px; border-radius: 6px;
      font-size: .72rem; font-weight: 500; cursor: pointer; transition: background .15s;
    }
    .ci-btn-new:hover { background: rgba(59,130,246,.25); }
    .ci-btn-arch {
      background: transparent; border: 1px solid var(--line-md);
      color: var(--muted); padding: 4px 7px; border-radius: 6px;
      font-size: .78rem; cursor: pointer; transition: all .15s; line-height: 1;
    }
    .ci-btn-arch:hover, .ci-btn-arch.active { border-color: var(--accent); color: var(--accent); }
    .ci-conv-list { flex: 1; overflow-y: auto; padding: 6px 0; }
    .ci-conv-item {
      display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer;
      border-bottom: 1px solid var(--line); border-left: 3px solid transparent; transition: background .12s;
    }
    .ci-conv-item:last-child { border-bottom: none; }
    .ci-conv-item:hover  { background: rgba(255,255,255,.04); }
    .ci-conv-item.active { background: rgba(59,130,246,.1); border-left-color: var(--accent2); }

    /* avatares por tipo */
    .ci-conv-avatar {
      width: 36px; height: 36px; border-radius: 50%; background: var(--soft);
      border: 1px solid var(--line-md); display: flex; align-items: center; justify-content: center;
      font-size: .82rem; font-weight: 600; color: var(--c-direto); flex-shrink: 0;
    }
    .ci-conv-avatar--geral    { background: rgba(126,184,246,.1); color: var(--c-geral); }
    .ci-conv-avatar--grupo    { background: rgba(110,200,142,.1); color: var(--c-grupo); }
    .ci-conv-avatar--setor    { background: rgba(245,158,11,.1);  color: var(--c-setor); }
    .ci-conv-avatar--filial   { background: rgba(167,139,250,.1); color: var(--c-filial); }
    .ci-conv-avatar--matriz   { background: rgba(248,113,113,.1); color: var(--c-matriz); }
    .ci-conv-avatar--advogado { background: rgba(52,211,153,.1);  color: var(--c-advogado); }

    .ci-conv-info { flex: 1; min-width: 0; }
    .ci-conv-name { font-size: .82rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ci-conv-preview { font-size: .72rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
    .ci-conv-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0; }
    .ci-badge { background: var(--accent2); color: #fff; font-size: .67rem; font-weight: 600; padding: 1px 5px; border-radius: 10px; min-width: 18px; text-align: center; }
    .ci-conv-time { font-size: .67rem; color: var(--muted); }
    .ci-tipo-tag { font-size: .61rem; font-weight: 600; padding: 1px 5px; border-radius: 4px; text-transform: uppercase; letter-spacing: .04em; }
    .ci-tipo-tag--setor    { background: rgba(245,158,11,.12);  color: var(--c-setor); }
    .ci-tipo-tag--filial   { background: rgba(167,139,250,.12); color: var(--c-filial); }
    .ci-tipo-tag--matriz   { background: rgba(248,113,113,.12); color: var(--c-matriz); }
    .ci-tipo-tag--advogado { background: rgba(52,211,153,.12);  color: var(--c-advogado); }
    .ci-tipo-tag--grupo    { background: rgba(110,200,142,.12); color: var(--c-grupo); }

    /* ── Área principal ───────────────────────────────── */
    .ci-main {
      flex: 1; min-width: 0; display: flex; flex-direction: column; overflow: hidden;
      background: var(--panel); border: 1px solid var(--line-md); border-radius: 12px;
    }
    .ci-empty {
      flex: 1; display: flex; align-items: center; justify-content: center;
      flex-direction: column; gap: 12px; color: var(--muted);
    }
    .ci-empty svg { opacity: .25; }
    .ci-empty p { font-size: .85rem; }

    /* ── Header ───────────────────────────────────────── */
    .ci-header {
      padding: 11px 16px; border-bottom: 1px solid var(--line-md);
      display: flex; align-items: center; gap: 10px;
      background: var(--panel); flex-shrink: 0; position: relative;
    }
    .ci-header-avatar {
      width: 34px; height: 34px; border-radius: 50%; background: var(--soft);
      display: flex; align-items: center; justify-content: center;
      font-size: .78rem; font-weight: 600; color: var(--c-direto); flex-shrink: 0;
    }
    .ci-header-avatar--geral    { background: rgba(126,184,246,.1); color: var(--c-geral); }
    .ci-header-avatar--grupo    { background: rgba(110,200,142,.1); color: var(--c-grupo); }
    .ci-header-avatar--setor    { background: rgba(245,158,11,.1);  color: var(--c-setor); }
    .ci-header-avatar--filial   { background: rgba(167,139,250,.1); color: var(--c-filial); }
    .ci-header-avatar--matriz   { background: rgba(248,113,113,.1); color: var(--c-matriz); }
    .ci-header-avatar--advogado { background: rgba(52,211,153,.1);  color: var(--c-advogado); }
    .ci-header-info { flex: 1; min-width: 0; }
    .ci-header-name { font-size: .88rem; font-weight: 600; display: flex; align-items: center; gap: 7px; }
    .ci-header-badge {
      font-size: .61rem; font-weight: 600; padding: 2px 6px; border-radius: 4px;
      text-transform: uppercase; letter-spacing: .04em; display: none;
    }
    .ci-header-badge--setor    { background: rgba(245,158,11,.15);  color: var(--c-setor);    display: inline-block; }
    .ci-header-badge--filial   { background: rgba(167,139,250,.15); color: var(--c-filial);   display: inline-block; }
    .ci-header-badge--matriz   { background: rgba(248,113,113,.15); color: var(--c-matriz);   display: inline-block; }
    .ci-header-badge--advogado { background: rgba(52,211,153,.15);  color: var(--c-advogado); display: inline-block; }
    .ci-header-badge--grupo    { background: rgba(110,200,142,.15); color: var(--c-grupo);    display: inline-block; }
    .ci-header-badge--geral    { background: rgba(126,184,246,.15); color: var(--c-geral);    display: inline-block; }
    .ci-header-sub { font-size: .72rem; color: var(--muted); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* ── Menu 3 pontos ────────────────────────────────── */
    .ci-header-opts {
      background: transparent; border: 1px solid transparent; color: var(--muted);
      padding: 5px 8px; border-radius: 7px; font-size: 1.15rem; cursor: pointer;
      line-height: 1; flex-shrink: 0; transition: all .15s; letter-spacing: 1px;
    }
    .ci-header-opts:hover { background: rgba(255,255,255,.06); border-color: var(--line-md); color: var(--text); }
    .ci-options-menu {
      position: absolute; top: calc(100% - 2px); right: 12px; z-index: 400;
      background: #0B1929; border: 1px solid var(--line-md); border-radius: 10px;
      box-shadow: 0 8px 32px rgba(0,0,0,.5); padding: 6px; min-width: 190px; display: none;
    }
    .ci-options-menu.open { display: block; }
    .ci-opt-btn {
      display: flex; align-items: center; gap: 8px; width: 100%;
      background: transparent; border: none; color: var(--text);
      padding: 8px 10px; border-radius: 7px; font-size: .8rem;
      cursor: pointer; text-align: left; transition: background .12s; font-family: inherit;
    }
    .ci-opt-btn:hover    { background: rgba(255,255,255,.07); }
    .ci-opt-btn.danger   { color: #F87171; }
    .ci-opt-btn.danger:hover { background: rgba(248,113,113,.1); }
    .ci-opt-divider { height: 1px; background: var(--line); margin: 4px 0; }

    /* ── Mensagens ────────────────────────────────────── */
    .ci-messages { flex: 1; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 4px; }
    .ci-date-sep {
      text-align: center; font-size: .7rem; color: var(--muted); margin: 8px 0;
      display: flex; align-items: center; gap: 8px;
    }
    .ci-date-sep::before, .ci-date-sep::after { content: ''; flex: 1; height: 1px; background: var(--line); }
    .ci-msg { display: flex; flex-direction: column; max-width: 75%; }
    .ci-msg--out { align-self: flex-end; align-items: flex-end; }
    .ci-msg--in  { align-self: flex-start; align-items: flex-start; }
    .ci-msg-sender { font-size: .7rem; color: var(--muted); margin-bottom: 2px; padding: 0 4px; }
    .ci-msg-bubble { padding: 8px 12px; border-radius: 10px; font-size: .84rem; line-height: 1.55; word-break: break-word; }
    .ci-msg--out .ci-msg-bubble { background: var(--bubble-out); border-bottom-right-radius: 3px; }
    .ci-msg--in  .ci-msg-bubble { background: var(--bubble-in);  border-bottom-left-radius: 3px; }
    .ci-msg-time { font-size: .67rem; color: var(--muted); margin-top: 3px; padding: 0 4px; }
    .ci-mention { display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 4px; font-size: .8rem; font-weight: 500; text-decoration: none; cursor: pointer; }
    .ci-mention-label { font-size: .65rem; opacity: .7; }
    .ci-mention--user { background: #1E3A5F; color: #93C5FD; border: 1px solid #2563EB44; }
    .ci-mention--proc { background: #2D1B69; color: #C4B5FD; border: 1px solid #7C3AED44; }
    .ci-mention--card { background: #064E3B; color: #6EE7B7; border: 1px solid #05966944; }
    .ci-load-more { align-self: center; margin: 4px 0 12px; background: transparent; border: 1px solid var(--line-md); color: var(--muted); padding: 5px 16px; border-radius: 6px; font-size: .75rem; cursor: pointer; transition: all .15s; }
    .ci-load-more:hover { border-color: var(--accent); color: var(--accent); }

    /* ── Input ────────────────────────────────────────── */
    .ci-input-wrap { flex-shrink: 0; border-top: 1px solid var(--line-md); background: var(--panel); padding: 12px 16px; position: relative; }
    .ci-mention-panel { position: absolute; bottom: calc(100% + 6px); left: 16px; right: 16px; background: #0B1929; border: 1px solid var(--line-md); border-radius: 12px; box-shadow: 0 -12px 40px rgba(0,0,0,.5); z-index: 300; display: none; flex-direction: column; max-height: 360px; overflow: hidden; }
    .ci-mention-panel.open { display: flex; }
    .ci-mpanel-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px 0; }
    .ci-mpanel-search { flex: 1; background: rgba(255,255,255,.06); border: 1px solid var(--line-md); border-radius: 7px; color: var(--text); font-family: inherit; font-size: .83rem; padding: 7px 10px; outline: none; }
    .ci-mpanel-search:focus { border-color: rgba(59,130,246,.4); }
    .ci-mpanel-close { background: none; border: none; color: var(--muted); font-size: 1.1rem; cursor: pointer; padding: 2px 4px; }
    .ci-mpanel-close:hover { color: var(--text); }
    .ci-mpanel-tabs { display: flex; gap: 4px; padding: 8px 12px; border-bottom: 1px solid var(--line); }
    .ci-mtab { background: none; border: 1px solid transparent; color: var(--muted); font-size: .72rem; font-weight: 500; padding: 3px 10px; border-radius: 20px; cursor: pointer; transition: all .12s; }
    .ci-mtab:hover { color: var(--text); }
    .ci-mtab.active { background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.3); color: var(--accent); }
    .ci-mpanel-results { flex: 1; overflow-y: auto; padding: 4px 0; }
    .ci-mpanel-empty { padding: 20px; text-align: center; font-size: .78rem; color: var(--muted); }
    .ci-mpanel-section { padding: 4px 12px 2px; font-size: .67rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
    .ci-mention-item { display: flex; align-items: center; gap: 10px; padding: 8px 14px; cursor: pointer; transition: background .1s; }
    .ci-mention-item:hover, .ci-mention-item.focused { background: rgba(59,130,246,.12); }
    .ci-mention-icon { width: 30px; height: 30px; border-radius: 50%; background: var(--soft); border: 1px solid var(--line-md); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: .7rem; font-weight: 600; color: var(--accent); }
    .ci-mention-icon--proc { color: #A78BFA; background: rgba(167,139,250,.1); }
    .ci-mention-icon--card { color: #6EC88E; background: rgba(110,200,142,.1); }
    .ci-mention-text { flex: 1; min-width: 0; }
    .ci-mention-name { font-size: .82rem; font-weight: 500; }
    .ci-mention-sub { font-size: .7rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ci-input-row { display: flex; align-items: flex-end; gap: 8px; }
    .ci-textarea { flex: 1; background: rgba(255,255,255,.04); border: 1px solid var(--line-md); border-radius: 8px; color: var(--text); font-family: inherit; font-size: .85rem; padding: 9px 12px; resize: none; outline: none; min-height: 40px; max-height: 120px; line-height: 1.5; transition: border-color .15s; }
    .ci-textarea:focus { border-color: rgba(59,130,246,.4); }
    .ci-textarea::placeholder { color: var(--muted); }
    .ci-send-btn { width: 40px; height: 40px; background: var(--accent2); border: none; border-radius: 8px; color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s; flex-shrink: 0; }
    .ci-send-btn:hover    { background: #2563EB; }
    .ci-send-btn:disabled { opacity: .4; cursor: not-allowed; }
    .ci-mention-hint { font-size: .68rem; color: var(--muted); margin-top: 5px; padding: 0 2px; }

    /* ── Modais compartilhados ────────────────────────── */
    .ci-modal-overlay { position: fixed; inset: 0; z-index: 500; background: rgba(0,0,0,.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; }
    .ci-modal-overlay.open { display: flex; }
    .ci-modal { background: #0B1929; border: 1px solid var(--line-md); border-radius: 14px; padding: 22px; width: 440px; max-width: 95vw; max-height: 90vh; overflow-y: auto; }
    .ci-modal h3 { font-size: .93rem; font-weight: 600; margin: 0 0 16px; }
    .ci-modal label { font-size: .73rem; color: var(--muted); display: block; margin-bottom: 4px; }
    .ci-modal input, .ci-modal select { width: 100% !important; background: #0E1F36 !important; background-color: #0E1F36 !important; border: 1px solid var(--line-md) !important; border-radius: 7px !important; color: #D8E4F0 !important; -webkit-text-fill-color: #D8E4F0 !important; font-size: .83rem !important; font-family: inherit !important; padding: 8px 10px !important; outline: none !important; margin-bottom: 12px !important; color-scheme: dark; box-shadow: 0 0 0 9999px #0E1F36 inset !important; }
    .ci-modal input::placeholder { color: #7A8898 !important; opacity: 1 !important; }
    .ci-modal input:focus, .ci-modal select:focus { border-color: rgba(59,130,246,.4) !important; }
    .ci-user-checkboxes { max-height: 190px; overflow-y: auto; border: 1px solid var(--line-md); border-radius: 8px; padding: 4px; margin-bottom: 12px; background: rgba(0,0,0,.12); }
    .ci-user-check { display: flex !important; flex-direction: row !important; align-items: center !important; flex-wrap: nowrap !important; gap: 10px; padding: 8px 10px; border-radius: 7px; cursor: pointer; border: 1px solid transparent; transition: background .12s, border-color .12s; user-select: none; }
    .ci-user-check:hover { background: rgba(59,130,246,.07); border-color: rgba(59,130,246,.12); }
    .ci-user-check:has(input:checked) { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.3); }
    .ci-user-check input { display: none; }
    .ci-chk-avatar { width: 30px; height: 30px; flex-shrink: 0; border-radius: 50%; background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.18); display: flex; align-items: center; justify-content: center; font-size: .74rem; font-weight: 600; color: var(--accent); }
    .ci-user-check:has(input:checked) .ci-chk-avatar { background: rgba(59,130,246,.22); border-color: rgba(59,130,246,.4); }
    .ci-chk-name { flex: 1; font-size: .82rem; color: var(--text); }
    .ci-chk-sub  { font-size: .7rem; color: var(--muted); }
    .ci-chk-box { width: 18px; height: 18px; flex-shrink: 0; border-radius: 5px; border: 1.5px solid var(--line-md); background: transparent; display: flex; align-items: center; justify-content: center; transition: all .12s; color: transparent; }
    .ci-user-check:has(input:checked) .ci-chk-box { background: var(--accent2); border-color: var(--accent2); color: #fff; }
    .ci-chk-box svg { display: block; }
    /* ── Seletor de tipo em cards ─────────────────────── */
    .ci-type-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 14px;
    }
    .ci-type-card {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; border-radius: 9px;
      border: 1.5px solid var(--line-md);
      background: rgba(255,255,255,.02);
      cursor: pointer; transition: all .15s; user-select: none;
    }
    .ci-type-card:hover { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.18); }
    .ci-type-card.selected { background: rgba(0,0,0,.15); }
    .ci-type-card-icon {
      width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
    }
    .ci-type-card-text { flex: 1; min-width: 0; }
    .ci-type-card-name { font-size: .82rem; font-weight: 600; color: var(--text); line-height: 1.2; }
    .ci-type-card-desc { font-size: .7rem; color: var(--muted); margin-top: 2px; line-height: 1.3; }

    .ci-modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
    .ci-modal-cancel { background: transparent; border: 1px solid var(--line-md); color: var(--muted); padding: 7px 16px; border-radius: 7px; font-size: .8rem; cursor: pointer; }
    .ci-modal-confirm { background: var(--accent2); border: none; color: #fff; padding: 7px 16px; border-radius: 7px; font-size: .8rem; cursor: pointer; font-weight: 500; }
    .ci-modal-confirm:hover { background: #2563EB; }

    /* ── Toast ───────────────────────────────────────── */
    #ciToast { position: fixed; bottom: 20px; right: 20px; z-index: 999; background: #1A3A5C; color: var(--text); padding: 10px 18px; border-radius: 8px; font-size: .8rem; box-shadow: 0 4px 16px rgba(0,0,0,.4); display: none; }

    /* ── Scrollbar ───────────────────────────────────── */
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--line-md); border-radius: 3px; }

    /* Sub-cabecalho dentro do popup de menção (agrupa por matriz/filial) */
    .ci-mpanel-subsection {
      padding: 8px 12px 4px; font-size: .62rem; font-weight: 700;
      color: #7A8898; text-transform: uppercase; letter-spacing: .08em;
      background: rgba(255,255,255,.03);
      border-top: 1px solid rgba(160,180,210,.06);
    }
    .ci-mpanel-subsection:first-child { border-top: none; }

    /* Sub-cabecalho dentro de listas de checkbox (Nova Conversa + Editar) */
    .ci-userlist-section {
      padding: 8px 10px 4px; margin: 4px 2px 2px;
      font-size: .62rem; font-weight: 700;
      color: #7A8898; text-transform: uppercase; letter-spacing: .08em;
      border-bottom: 1px solid rgba(160,180,210,.08);
    }
    .ci-userlist-section:first-child { margin-top: 0; }

    /* Container "Membros que entrarão na conversa" — antes era inline com
       rgba(0,0,0,.12) que ficava cinza escuro feio no tema claro */
    .ci-setor-membros-box {
      display: flex; flex-wrap: wrap; gap: 6px;
      padding: 10px 12px;
      border: 1px solid var(--line-md);
      border-radius: 8px;
      background: rgba(255,255,255,.04);
      margin-bottom: 12px;
    }

    /* ═════════════════════════════════════════════════════════════════════
       TEMA CLARO — Chat Interno (correção 2026-05-26)
       Sem isso as bolhas, chips, modais, popup ficavam pretos em fundo branco
       ═════════════════════════════════════════════════════════════════════ */
    html[data-theme="light"] body { background: #F4F7FB; }

    /* Sidebar + lista de conversas */
    html[data-theme="light"] .ci-sidebar {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-sidebar-header {
      border-bottom-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-sidebar-title { color: #1E4A8A !important; }
    html[data-theme="light"] .ci-btn-new {
      background: #DBEAFE !important;
      border-color: #93C5FD !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .ci-btn-new:hover { background: #BFDBFE !important; }
    html[data-theme="light"] .ci-btn-arch {
      border-color: #CBD5E1 !important; color: #64748B !important;
    }
    html[data-theme="light"] .ci-btn-arch:hover,
    html[data-theme="light"] .ci-btn-arch.active {
      border-color: #2563EB !important; color: #1D4ED8 !important;
    }
    html[data-theme="light"] .ci-conv-item { border-bottom-color: #F1F5F9 !important; }
    html[data-theme="light"] .ci-conv-item:hover { background: #F8FAFC !important; }
    html[data-theme="light"] .ci-conv-item.active {
      background: #EFF6FF !important; border-left-color: #2563EB !important;
    }
    html[data-theme="light"] .ci-conv-avatar {
      background: #F1F5F9 !important; border-color: #CBD5E1 !important;
    }
    html[data-theme="light"] .ci-conv-name { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-conv-preview { color: #64748B !important; }
    html[data-theme="light"] .ci-conv-time { color: #94A3B8 !important; }

    /* Area principal */
    html[data-theme="light"] .ci-main {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-empty { color: #94A3B8 !important; }

    /* Header da conversa */
    html[data-theme="light"] .ci-header {
      background: #FFFFFF !important;
      border-bottom-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-header-avatar {
      background: #F1F5F9 !important; border: 1px solid #CBD5E1 !important;
    }
    html[data-theme="light"] .ci-header-name { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-header-sub  { color: #64748B !important; }
    html[data-theme="light"] .ci-header-opts { color: #64748B !important; }
    html[data-theme="light"] .ci-header-opts:hover {
      background: #F1F5F9 !important; border-color: #CBD5E1 !important; color: #0F1F36 !important;
    }
    html[data-theme="light"] .ci-options-menu {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
      box-shadow: 0 8px 24px rgba(15,31,54,0.12) !important;
    }
    html[data-theme="light"] .ci-opt-btn { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-opt-btn:hover { background: #F1F5F9 !important; }
    html[data-theme="light"] .ci-opt-btn.danger { color: #DC2626 !important; }
    html[data-theme="light"] .ci-opt-btn.danger:hover { background: #FEE2E2 !important; }
    html[data-theme="light"] .ci-opt-divider { background: #E2E8F0 !important; }

    /* Mensagens — bolhas */
    html[data-theme="light"] .ci-messages { background: transparent; }
    html[data-theme="light"] .ci-date-sep { color: #94A3B8 !important; }
    html[data-theme="light"] .ci-date-sep::before,
    html[data-theme="light"] .ci-date-sep::after { background: #E2E8F0 !important; }
    html[data-theme="light"] .ci-msg-bubble {
      background: #FFFFFF !important;
      color: #0F1F36 !important;
      border: 1px solid #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-msg--out .ci-msg-bubble {
      background: #DBEAFE !important;
      color: #0F1F36 !important;
      border-color: #93C5FD !important;
    }
    html[data-theme="light"] .ci-msg-sender { color: #64748B !important; }
    html[data-theme="light"] .ci-msg-time   { color: #94A3B8 !important; }

    /* Chips de menção (@usuario, @processo, @card) */
    html[data-theme="light"] .ci-mention--user {
      background: #DBEAFE !important; color: #1D4ED8 !important;
      border-color: #93C5FD !important;
    }
    html[data-theme="light"] .ci-mention--proc {
      background: #EDE9FE !important; color: #6D28D9 !important;
      border-color: #C4B5FD !important;
    }
    html[data-theme="light"] .ci-mention--card {
      background: #D1FAE5 !important; color: #047857 !important;
      border-color: #6EE7B7 !important;
    }

    /* Input area */
    html[data-theme="light"] .ci-input-wrap {
      background: #FFFFFF !important;
      border-top-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-textarea {
      background: #FFFFFF !important;
      border-color: #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .ci-textarea::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .ci-textarea:focus { border-color: #2563EB !important; }
    html[data-theme="light"] .ci-mention-hint { color: #94A3B8 !important; }

    /* Popup de menção (@ usuário/processo/card) */
    html[data-theme="light"] .ci-mention-panel {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
      box-shadow: 0 -12px 40px rgba(15,31,54,0.15) !important;
    }
    html[data-theme="light"] .ci-mpanel-search {
      background: #F8FAFC !important;
      border-color: #CBD5E1 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .ci-mpanel-search:focus { border-color: #2563EB !important; }
    html[data-theme="light"] .ci-mpanel-close { color: #94A3B8 !important; }
    html[data-theme="light"] .ci-mpanel-close:hover { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-mpanel-tabs { border-bottom-color: #E2E8F0 !important; }
    html[data-theme="light"] .ci-mtab { color: #64748B !important; }
    html[data-theme="light"] .ci-mtab:hover { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-mtab.active {
      background: #DBEAFE !important;
      border-color: #93C5FD !important;
      color: #1D4ED8 !important;
    }
    html[data-theme="light"] .ci-mpanel-empty { color: #94A3B8 !important; }
    html[data-theme="light"] .ci-mpanel-section { color: #475569 !important; }
    html[data-theme="light"] .ci-mpanel-subsection {
      color: #64748B !important;
      background: #F8FAFC !important;
      border-top-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-userlist-section {
      color: #475569 !important;
      border-bottom-color: #E2E8F0 !important;
    }
    /* Container Membros do Setor — fundo claro com borda discreta */
    html[data-theme="light"] .ci-setor-membros-box {
      background: #F8FAFC !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-mention-item:hover,
    html[data-theme="light"] .ci-mention-item.focused {
      background: #EFF6FF !important;
    }
    html[data-theme="light"] .ci-mention-icon {
      background: #F1F5F9 !important; border-color: #CBD5E1 !important;
    }
    html[data-theme="light"] .ci-mention-name { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-mention-sub  { color: #64748B !important; }

    /* Modal — Nova Conversa + Editar Participantes */
    html[data-theme="light"] .ci-modal-overlay { background: rgba(15,31,54,0.45) !important; }
    html[data-theme="light"] .ci-modal {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
      color: #0F1F36 !important;
    }
    html[data-theme="light"] .ci-modal h3 { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-modal label { color: #475569 !important; }
    html[data-theme="light"] .ci-modal input,
    html[data-theme="light"] .ci-modal select {
      background: #FFFFFF !important;
      background-color: #FFFFFF !important;
      border-color: #CBD5E1 !important;
      color: #0F1F36 !important;
      -webkit-text-fill-color: #0F1F36 !important;
      box-shadow: 0 0 0 9999px #FFFFFF inset !important;
      color-scheme: light;
    }
    html[data-theme="light"] .ci-modal input::placeholder { color: #94A3B8 !important; }
    html[data-theme="light"] .ci-modal input:focus,
    html[data-theme="light"] .ci-modal select:focus { border-color: #2563EB !important; }

    /* Tipos de conversa (cards Chat privado / Vários / Canal / Filial) */
    html[data-theme="light"] .ci-type-card {
      background: #FFFFFF !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-type-card:hover {
      background: #F8FAFC !important; border-color: #CBD5E1 !important;
    }
    html[data-theme="light"] .ci-type-card.selected {
      background: #EFF6FF !important; border-color: #2563EB !important;
    }
    html[data-theme="light"] .ci-type-card-name { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-type-card-desc { color: #64748B !important; }

    /* Lista de usuários no modal (checkboxes) */
    html[data-theme="light"] .ci-user-checkboxes {
      background: #F8FAFC !important;
      border-color: #E2E8F0 !important;
    }
    html[data-theme="light"] .ci-user-check:hover {
      background: #EFF6FF !important; border-color: #93C5FD !important;
    }
    html[data-theme="light"] .ci-user-check:has(input:checked) {
      background: #DBEAFE !important; border-color: #2563EB !important;
    }
    html[data-theme="light"] .ci-chk-avatar {
      background: #EFF6FF !important; border-color: #BFDBFE !important; color: #1D4ED8 !important;
    }
    html[data-theme="light"] .ci-chk-name { color: #0F1F36 !important; }
    html[data-theme="light"] .ci-chk-sub  { color: #64748B !important; }
    html[data-theme="light"] .ci-chk-box  { border-color: #CBD5E1 !important; }
    html[data-theme="light"] .ci-user-check:has(input:checked) .ci-chk-box {
      background: #2563EB !important; border-color: #2563EB !important; color: #FFFFFF !important;
    }

    /* Botões do modal */
    html[data-theme="light"] .ci-modal-cancel {
      background: #FFFFFF !important;
      border-color: #CBD5E1 !important;
      color: #475569 !important;
    }
    html[data-theme="light"] .ci-modal-cancel:hover { background: #F1F5F9 !important; }
    html[data-theme="light"] .ci-modal-confirm {
      background: #2563EB !important; color: #FFFFFF !important;
    }
    html[data-theme="light"] .ci-modal-confirm:hover { background: #1D4ED8 !important; }

    /* Toast */
    html[data-theme="light"] #ciToast {
      background: #FFFFFF !important; color: #0F1F36 !important;
      border: 1px solid #E2E8F0 !important;
      box-shadow: 0 4px 16px rgba(15,31,54,0.12) !important;
    }
  </style>
</head>
<body>
<main style="width:100%;padding:24px;height:100vh;overflow:hidden;display:flex;flex-direction:column;box-sizing:border-box">
<div class="page-layout" style="flex:1;min-height:0;overflow:hidden;align-items:stretch">
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="ci-wrap">

  <!-- ── Sidebar de conversas ── -->
  <aside class="ci-sidebar">
    <div class="ci-sidebar-header">
      <span class="ci-sidebar-title" id="ciSidebarTitle">Conversas</span>
      <div class="ci-sidebar-actions">
        <button class="ci-btn-arch" id="ciBtnArch" title="Ver arquivadas" onclick="CI.toggleArquivadas()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>
          </svg>
        </button>
        <button class="ci-btn-new" onclick="CI.openNewModal()">+ Nova</button>
      </div>
    </div>
    <div class="ci-conv-list" id="ciConvList">
      <div style="padding:20px;text-align:center;color:var(--muted);font-size:.78rem">Carregando…</div>
    </div>
  </aside>

  <!-- ── Área principal ── -->
  <main class="ci-main" id="ciMain">
    <div class="ci-empty" id="ciEmpty">
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <p>Selecione uma conversa para começar</p>
    </div>

    <!-- Header (oculto até abrir conversa) -->
    <div class="ci-header" id="ciHeader" style="display:none">
      <div class="ci-header-avatar" id="ciHeaderAvatar">?</div>
      <div class="ci-header-info">
        <div class="ci-header-name">
          <span id="ciHeaderName">—</span>
          <span class="ci-header-badge" id="ciHeaderBadge"></span>
        </div>
        <div class="ci-header-sub" id="ciHeaderSub">—</div>
      </div>
      <!-- Menu 3 pontos -->
      <button class="ci-header-opts" onclick="CI.toggleOptionsMenu(event)" title="Opções">···</button>
      <div class="ci-options-menu" id="ciOptionsMenu">
        <button class="ci-opt-btn" id="ciOptEditPart" onclick="CI.openEditParticipants()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          Editar Participantes
        </button>
        <div class="ci-opt-divider"></div>
        <button class="ci-opt-btn" id="ciOptArchive" onclick="CI.archiveConv()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
          Arquivar
        </button>
        <button class="ci-opt-btn danger" id="ciOptDelete" onclick="CI.deleteConv()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
          Excluir Conversa
        </button>
      </div>
    </div>

    <!-- Mensagens -->
    <div class="ci-messages" id="ciMessages" style="display:none"></div>

    <!-- Input -->
    <div class="ci-input-wrap" id="ciInputWrap" style="display:none">
      <div class="ci-mention-panel" id="ciMentionPanel">
        <div class="ci-mpanel-head">
          <input class="ci-mpanel-search" id="ciMpanelSearch"
                 placeholder="Buscar usuário, processo ou card…"
                 oninput="CI.onMpanelSearch(this.value)"
                 onkeydown="CI.onMpanelKey(event)">
          <button class="ci-mpanel-close" onclick="CI.closeMentionPanel()">×</button>
        </div>
        <div class="ci-mpanel-tabs">
          <button class="ci-mtab active" data-tab="auto"     onclick="CI.setMentionTab('auto',this)">Todos</button>
          <button class="ci-mtab"        data-tab="usuario"  onclick="CI.setMentionTab('usuario',this)">Usuários</button>
          <button class="ci-mtab"        data-tab="processo" onclick="CI.setMentionTab('processo',this)">Processos</button>
          <button class="ci-mtab"        data-tab="card"     onclick="CI.setMentionTab('card',this)">Cards</button>
        </div>
        <div class="ci-mpanel-results" id="ciMpanelResults">
          <div class="ci-mpanel-empty">Digite para buscar…</div>
        </div>
      </div>
      <div class="ci-input-row">
        <textarea class="ci-textarea" id="ciTextarea" rows="1"
          placeholder="Digite uma mensagem… Use @ para mencionar"
          oninput="CI.onInput(this)"
          onkeydown="CI.onKeyDown(event)"></textarea>
        <button class="ci-send-btn" onclick="CI.send()" title="Enviar">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"/>
            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
        </button>
      </div>
      <div class="ci-mention-hint">@ para mencionar usuário, processo ou card</div>
    </div>
  </main>
</div>

<!-- ════════════════════════════════════════════════════════════════
     Modal: Nova Conversa
═════════════════════════════════════════════════════════════════ -->
<div class="ci-modal-overlay" id="ciModalOverlay" onclick="if(event.target===this)CI.closeNewModal()">
  <div class="ci-modal">
    <h3>Nova Conversa</h3>

    <input type="hidden" id="ciModalTipo" value="direto">
    <label style="margin-bottom:8px">Tipo</label>
    <div class="ci-type-grid" id="ciTypeGrid"></div>

    <!-- Nome: para grupo e conversas cross-account -->
    <div id="ciModalNomeWrap" style="display:none">
      <label>Nome da Conversa</label>
      <input type="text" id="ciModalNome" placeholder="Ex: Equipe Processual SP">
    </div>

    <!-- Setor: radio de teams + membros -->
    <div id="ciModalSetorWrap" style="display:none">
      <label>Selecione o Setor</label>
      <div class="ci-user-checkboxes" id="ciTeamList">
        <div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Carregando setores…</div>
      </div>
      <div id="ciSetorMembrosWrap" style="display:none">
        <label style="margin-top:4px">Membros que entrarão na conversa</label>
        <div id="ciSetorMembros" class="ci-setor-membros-box"></div>
      </div>
    </div>

    <!-- Filial: select de qual filial (só quando há mais de uma) -->
    <div id="ciModalFilialWrap" style="display:none">
      <label>Filial</label>
      <select id="ciFilialSelect" onchange="CI.onFilialChange()"></select>
    </div>

    <!-- Participantes: checkboxes para direto/grupo/filial/matriz -->
    <div id="ciModalParticipantesWrap" style="display:none">
      <label id="ciParticipantesLabel">Participantes</label>
      <div class="ci-user-checkboxes" id="ciUserCheckboxes">
        <div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Carregando usuários…</div>
      </div>
    </div>

    <!-- Advogado: radio de advogados aceitos -->
    <div id="ciModalAdvogadoWrap" style="display:none">
      <label>Advogado Associado</label>
      <div class="ci-user-checkboxes" id="ciAdvogadoList">
        <div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Carregando…</div>
      </div>
    </div>

    <div class="ci-modal-actions">
      <button class="ci-modal-cancel" onclick="CI.closeNewModal()">Cancelar</button>
      <button class="ci-modal-confirm" onclick="CI.confirmNewConv()">Criar</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     Modal: Editar Participantes
═════════════════════════════════════════════════════════════════ -->
<div class="ci-modal-overlay" id="ciEditModalOverlay" onclick="if(event.target===this)CI.closeEditModal()">
  <div class="ci-modal">
    <h3>Editar Participantes</h3>
    <p style="font-size:.78rem;color:var(--muted);margin:0 0 14px">
      Marque os usuários que devem participar desta conversa.
    </p>
    <div class="ci-user-checkboxes" id="ciEditUserCheckboxes" style="max-height:260px">
      <div style="padding:12px;text-align:center;color:var(--muted);font-size:.78rem">Carregando…</div>
    </div>
    <div class="ci-modal-actions">
      <button class="ci-modal-cancel" onclick="CI.closeEditModal()">Cancelar</button>
      <button class="ci-modal-confirm" onclick="CI.saveParticipants()">Salvar</button>
    </div>
  </div>
</div>

</div><!-- /page-layout -->
</main>

<div id="ciToast"></div>

<script>
const CI_UID  = <?= $uid ?>;
const CI_NOME = <?= json_encode($_SESSION['user_nome'] ?? 'Usuário') ?>;
const CI_CSRF = <?= json_encode($csrf) ?>;
const CI_API  = {
  conversas : '/api/chat/conversas.php',
  mensagens : '/api/chat/mensagens.php',
  mencoes   : '/api/chat/mencoes.php',
  users     : '/api/users.php',
};
</script>
<script src="/assets/chat_interno.js?v=4"></script>
</body>
</html>
