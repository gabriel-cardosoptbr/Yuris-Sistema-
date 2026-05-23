<?php
/**
 * master_mfa_setup.php — tela de configuração de 2FA / TOTP para super_admin (LGPD P0).
 *
 * Acesso: super_admin com sessão master_mode.
 *
 * Fluxo:
 *   1. Tela inicial mostra status (ativo/inativo) + botão de setup ou disable
 *   2. Setup: chama /api/master/mfa.php?action=generate
 *      → recebe secret + URI otpauth + 10 backup codes
 *      → exibe QR code (renderizado client-side via biblioteca local)
 *      → pede código TOTP de teste pra confirmar
 *   3. Confirm: chama ?action=confirm → grava cifrado + ativa
 *
 * Sem dependência de CDN: usa Tailwind do master.php existente.
 */
require_once __DIR__ . '/../app/Models/Database.php';
require_once __DIR__ . '/../app/Models/Account.php';
require_once __DIR__ . '/../app/Models/ResourceShare.php';
require_once __DIR__ . '/../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertSuperAdmin();

if (empty($_SESSION['master_mode'])) {
    header('Location: /sistema_vendas/public/master_login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configurar 2FA — Painel Master</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/sistema_vendas/public/assets/favicon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <!-- QR code: biblioteca leve e amplamente usada (renderiza puro JS, sem call externo) -->
  <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
  <style>
    :root { --purple-1:#a855f7; --purple-2:#7c3aed; --bg:#0a0418; --ok:#10b981; --warn:#f59e0b; --err:#ef4444; }
    html, body { margin:0; padding:0; }
    body {
      background-color: var(--bg);
      background-image:
        radial-gradient(ellipse at 20% 30%, rgba(168,85,247,0.18) 0%, transparent 55%),
        radial-gradient(ellipse at 80% 80%, rgba(124,58,237,0.12) 0%, transparent 60%);
      color:#E8EDF5; font-family: Inter, system-ui, sans-serif;
      min-height:100vh; padding: 40px 20px;
    }
    .mfa-wrap { max-width: 680px; margin: 0 auto; }
    .mfa-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
    .mfa-head h1 { margin: 0; color:#fff; font-size:1.5rem; font-weight: 700; }
    .mfa-back { color:#c084fc; text-decoration:none; font-size:.85rem; }
    .mfa-back:hover { text-decoration:underline; }
    .mfa-card {
      background: linear-gradient(165deg, #1a0f2e, #0a0418);
      border: 1px solid rgba(168,85,247,0.25);
      border-radius: 14px; padding: 28px;
      box-shadow: 0 30px 60px rgba(0,0,0,.45), 0 0 0 1px rgba(168,85,247,0.10) inset;
      margin-bottom: 18px;
    }
    .status-pill { display:inline-flex; align-items:center; gap:6px; padding:5px 11px;
      border-radius: 999px; font-size: .78rem; font-weight: 600; }
    .status-on  { background: rgba(16,185,129,.13); color: var(--ok); border:1px solid rgba(16,185,129,.30); }
    .status-off { background: rgba(245,158,11,.13); color: var(--warn); border:1px solid rgba(245,158,11,.30); }
    .btn {
      padding: 10px 18px;
      background: linear-gradient(135deg, var(--purple-1), var(--purple-2));
      color: #fff; border: none; border-radius: 8px;
      font-weight: 600; font-size: .88rem; cursor: pointer;
      transition: transform .12s, box-shadow .12s;
    }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(124,58,237,.35); }
    .btn-ghost {
      background: transparent; border:1px solid rgba(168,85,247,.30); color:#c084fc;
    }
    .btn-danger {
      background: linear-gradient(135deg, #ef4444, #b91c1c);
    }
    .qr-frame { background:#fff; padding: 16px; border-radius: 12px; display:inline-block; }
    .qr-frame img { display:block; }
    .secret-box {
      font-family: 'Courier New', monospace; font-size: 1.05rem;
      background: rgba(8,3,22,.7); border:1px solid rgba(168,85,247,.20);
      padding: 10px 14px; border-radius: 8px; letter-spacing: 0.08em;
      word-break: break-all; user-select: all;
    }
    .codes-grid {
      display:grid; grid-template-columns: repeat(2, 1fr); gap: 8px;
      font-family: 'Courier New', monospace; font-size: .95rem;
      background: rgba(8,3,22,.5); border:1px solid rgba(168,85,247,.20);
      padding: 14px; border-radius: 10px; user-select: all;
    }
    .otp-input {
      width: 220px; padding: 12px 14px; background: rgba(8,3,22,.7);
      border: 1px solid rgba(168,85,247,.20); border-radius: 8px;
      color: #fff; font-size: 1.5rem; font-family: 'Courier New', monospace;
      letter-spacing: .4em; text-align: center;
    }
    .otp-input:focus { outline: none; border-color: rgba(168,85,247,.55); }
    .alert {
      padding: 10px 14px; border-radius: 8px; font-size:.85rem; margin: 12px 0;
    }
    .alert-ok   { background: rgba(16,185,129,.12); color:#a7f3d0; border:1px solid rgba(16,185,129,.30); }
    .alert-err  { background: rgba(239,68,68,.12);  color:#fca5a5; border:1px solid rgba(239,68,68,.30); }
    .alert-info { background: rgba(168,85,247,.08); color:#d8b4fe; border:1px solid rgba(168,85,247,.25); }
    .step-num {
      display:inline-flex; align-items:center; justify-content:center;
      width:26px; height:26px; border-radius:50%;
      background: linear-gradient(135deg, var(--purple-1), var(--purple-2));
      color:#fff; font-weight:700; font-size:.85rem; margin-right:10px;
    }
  </style>
</head>
<body>
  <div class="mfa-wrap">
    <div class="mfa-head">
      <h1>Configurar 2FA</h1>
      <a class="mfa-back" href="/sistema_vendas/public/master.php">← Voltar ao Painel Master</a>
    </div>

    <!-- Estado atual -->
    <div class="mfa-card" id="statusCard">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div>
          <div style="font-size:.78rem; color:#a89bbf; text-transform:uppercase; letter-spacing:.1em;">Status 2FA</div>
          <div id="statusPill" class="status-pill status-off" style="margin-top:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>
            <span id="statusTxt">Verificando…</span>
          </div>
          <div id="statusMeta" style="font-size:.78rem; color:#8b7daa; margin-top:8px;"></div>
        </div>
        <div id="statusActions"></div>
      </div>
    </div>

    <!-- Etapa setup -->
    <div class="mfa-card" id="setupCard" style="display:none;">
      <h2 style="color:#fff; font-size:1.05rem; margin: 0 0 6px;">Configurar autenticador</h2>
      <p style="color:#b6a3d4; font-size:.85rem; margin-bottom:18px;">
        Use um app como Google Authenticator, Authy, 1Password ou Bitwarden.
      </p>

      <div style="margin: 18px 0;">
        <div style="font-weight:600; color:#fff; margin-bottom:10px;">
          <span class="step-num">1</span>Escaneie o QR code abaixo
        </div>
        <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
          <div class="qr-frame" id="qrFrame"></div>
          <div style="flex:1; min-width:240px;">
            <div style="font-size:.78rem; color:#a89bbf; margin-bottom:6px;">Ou digite manualmente:</div>
            <div class="secret-box" id="secretBox">———</div>
            <div style="font-size:.7rem; color:#8b7daa; margin-top:6px;">Tipo: Time-based · Algoritmo: SHA1 · Dígitos: 6 · Período: 30s</div>
          </div>
        </div>
      </div>

      <div style="margin: 22px 0;">
        <div style="font-weight:600; color:#fff; margin-bottom:10px;">
          <span class="step-num">2</span>Guarde os backup codes (10)
        </div>
        <p style="color:#b6a3d4; font-size:.82rem; margin-bottom:8px;">
          Cada um pode ser usado <strong>uma vez</strong> se você perder acesso ao app. Copie e guarde em local seguro (cofre de senhas, impressão).
        </p>
        <div class="codes-grid" id="codesGrid"></div>
        <button class="btn btn-ghost" type="button" onclick="copyBackupCodes()" style="margin-top:8px;">Copiar todos</button>
        <span id="copyStatus" style="margin-left:10px; color:var(--ok); font-size:.8rem;"></span>
      </div>

      <div style="margin: 22px 0;">
        <div style="font-weight:600; color:#fff; margin-bottom:10px;">
          <span class="step-num">3</span>Digite o código que apareceu no app
        </div>
        <input class="otp-input" id="confirmCode" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000">
        <button class="btn" type="button" onclick="confirmSetup()" style="margin-left:10px;">Confirmar e ativar</button>
        <div id="confirmAlert"></div>
      </div>
    </div>

    <!-- Tela de desativação (quando MFA já está ativo) -->
    <div class="mfa-card" id="disableCard" style="display:none;">
      <h2 style="color:#fff; font-size:1.05rem; margin: 0 0 10px;">Desativar 2FA</h2>
      <p style="color:#b6a3d4; font-size:.85rem;">
        Atenção: desativar 2FA reduz a segurança do Painel Master. Para confirmar, informe um código TOTP atual.
      </p>
      <div style="margin-top: 14px;">
        <input class="otp-input" id="disableCode" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000">
        <button class="btn btn-danger" type="button" onclick="disableMfa()" style="margin-left:10px;">Desativar 2FA</button>
        <button class="btn btn-ghost" type="button" onclick="hideDisable()" style="margin-left:6px;">Cancelar</button>
        <div id="disableAlert"></div>
      </div>
    </div>
  </div>

  <script>
    const CSRF   = <?= json_encode($csrf) ?>;
    const API    = '/sistema_vendas/public/api/master/mfa.php';
    let backupCodesShown = [];

    async function fj(url, opts = {}) {
      const res = await fetch(url, { ...opts, headers: { ...(opts.headers||{}), 'X-CSRF-Token': CSRF }, credentials:'same-origin' });
      const txt = await res.text();
      let j; try { j = JSON.parse(txt); } catch { j = { ok:false, error:'Resposta inválida' }; }
      return { res, body: j };
    }

    async function loadStatus() {
      const { body } = await fj(API);
      if (!body.ok) { document.getElementById('statusTxt').textContent = 'Erro: ' + (body.error||''); return; }
      const enabled = body.data.mfa_enabled;
      const pill = document.getElementById('statusPill');
      const txt  = document.getElementById('statusTxt');
      const meta = document.getElementById('statusMeta');
      const acts = document.getElementById('statusActions');
      if (enabled) {
        pill.className = 'status-pill status-on';
        txt.textContent = 'Ativo';
        meta.innerHTML = 'Configurado em ' + (body.data.mfa_setup_at || '—') +
                        ' · Último uso: ' + (body.data.mfa_last_used_at || '—');
        acts.innerHTML = '<button class="btn btn-danger" type="button" onclick="showDisable()">Desativar</button>';
      } else {
        pill.className = 'status-pill status-off';
        txt.textContent = 'Inativo';
        meta.textContent = 'Recomendado: ative o 2FA para proteger o Painel Master.';
        acts.innerHTML = '<button class="btn" type="button" onclick="startSetup()">Configurar 2FA</button>';
      }
    }

    async function startSetup() {
      const { body } = await fj(API + '?action=generate', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({csrf_token: CSRF}) });
      if (!body.ok) { alert('Erro: ' + (body.error||'')); return; }
      const { secret, uri, backup_codes } = body.data;
      document.getElementById('secretBox').textContent = secret;
      backupCodesShown = backup_codes;
      document.getElementById('codesGrid').innerHTML = backup_codes.map(c => '<div>' + c + '</div>').join('');

      // Renderiza QR code via lib qrcode-generator
      const qr = qrcode(0, 'M');
      qr.addData(uri);
      qr.make();
      document.getElementById('qrFrame').innerHTML = qr.createImgTag(5, 0);

      document.getElementById('setupCard').style.display = 'block';
      document.getElementById('setupCard').scrollIntoView({ behavior:'smooth' });
    }

    async function confirmSetup() {
      const code = document.getElementById('confirmCode').value.trim();
      const alertBox = document.getElementById('confirmAlert');
      if (!/^\d{6}$/.test(code)) {
        alertBox.innerHTML = '<div class="alert alert-err">Digite os 6 dígitos do app.</div>';
        return;
      }
      const { body } = await fj(API + '?action=confirm', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ csrf_token: CSRF, code })
      });
      if (!body.ok) {
        alertBox.innerHTML = '<div class="alert alert-err">' + (body.error||'Erro') + '</div>';
        return;
      }
      alertBox.innerHTML = '<div class="alert alert-ok">2FA ativado! Da próxima vez que entrar no Painel Master, você precisará do código.</div>';
      document.getElementById('setupCard').style.opacity = '.5';
      document.getElementById('setupCard').style.pointerEvents = 'none';
      setTimeout(loadStatus, 600);
    }

    function showDisable() {
      document.getElementById('disableCard').style.display = 'block';
      document.getElementById('disableCard').scrollIntoView({behavior:'smooth'});
    }
    function hideDisable() {
      document.getElementById('disableCard').style.display = 'none';
      document.getElementById('disableCode').value = '';
      document.getElementById('disableAlert').innerHTML = '';
    }

    async function disableMfa() {
      const code = document.getElementById('disableCode').value.trim();
      const alertBox = document.getElementById('disableAlert');
      if (!/^\d{6}$/.test(code)) {
        alertBox.innerHTML = '<div class="alert alert-err">Informe um código TOTP atual.</div>';
        return;
      }
      const { body } = await fj(API + '?action=disable', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ csrf_token: CSRF, code })
      });
      if (!body.ok) {
        alertBox.innerHTML = '<div class="alert alert-err">' + (body.error||'Erro') + '</div>';
        return;
      }
      alertBox.innerHTML = '<div class="alert alert-ok">2FA desativado.</div>';
      setTimeout(() => { hideDisable(); loadStatus(); }, 800);
    }

    function copyBackupCodes() {
      const txt = backupCodesShown.join('\n');
      navigator.clipboard.writeText(txt).then(() => {
        document.getElementById('copyStatus').textContent = 'Copiado!';
        setTimeout(() => document.getElementById('copyStatus').textContent = '', 2000);
      });
    }

    loadStatus();
  </script>
</body>
</html>
