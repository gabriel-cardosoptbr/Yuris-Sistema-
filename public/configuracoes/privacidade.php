<?php
/**
 * /configuracoes/privacidade.php — Centro de Privacidade do user logado (LGPD).
 *
 * Permite ao titular:
 *   • Ver consentimentos ativos e revogados (cookies, marketing, IA, etc)
 *   • Revogar consentimentos a qualquer momento (Art. 18 IX LGPD)
 *   • Acessar links para Política de Privacidade, Termos, DPO
 *
 * Para exportação de dados e solicitação de exclusão (Art. 18 II/VI),
 * encaminhamos ao DPO (será automatizado na Etapa 6 do roadmap).
 */
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Models/Consent.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;
use App\Models\Consent;

session_start();
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$userId    = $ctx->getUserId();
$accountId = $ctx->getAccountId();
$csrf      = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

$consentimentos = Consent::listAll($userId, null);
$activePage = 'configuracoes';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Fix: esta página está em subpasta /configuracoes/. Sem <base>, os links
       relativos da sidebar (dashboard.php, etc) resolvem como
       /configuracoes/dashboard.php → 404. <base> força resolução a partir
       da raiz public/, igual às outras páginas. -->
  <base href="/sistema_vendas/public/">
  <title>Privacidade — Yuris</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/sistema_vendas/public/assets/favicon-32.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/sistema_vendas/public/assets/yuris-theme.css?v=27">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/fog.css">
  <link rel="stylesheet" href="/sistema_vendas/public/assets/sidebar.css?v=8">
  <style>
    body { font-family: Inter, system-ui, sans-serif; background:#0a1224; color:#e8edf5; }
    main { margin-left: 230px; padding: 32px 28px; }
    h1 { font-size: 1.6rem; font-weight: 700; color:#fff; margin: 0 0 4px; }
    .lead { color:#94a3b8; margin-bottom: 28px; font-size: .92rem; }
    .card { background:#0f1c33; border:1px solid rgba(96,165,250,.18); border-radius: 12px; padding: 22px; margin-bottom: 18px; }
    .card h2 { font-size: 1.05rem; font-weight: 600; color:#fff; margin: 0 0 12px; }
    .cons-row { display:flex; justify-content:space-between; align-items:center; padding: 10px 0; border-bottom: 1px solid rgba(96,165,250,.10); gap: 14px; }
    .cons-row:last-child { border-bottom: none; }
    .cons-fin { font-weight: 600; color: #e8edf5; }
    .cons-meta { font-size: .8rem; color:#94a3b8; }
    .pill { display:inline-block; padding: 2px 9px; border-radius: 999px; font-size: .72rem; font-weight: 600; margin-left: 6px; }
    .pill-ativo { background: rgba(16,185,129,.13); color: #6ee7b7; border:1px solid rgba(16,185,129,.30); }
    .pill-revogado { background: rgba(148,163,184,.13); color: #cbd5e1; border:1px solid rgba(148,163,184,.30); }
    .btn { padding: 7px 14px; border-radius: 7px; border: none; font: inherit; cursor: pointer; font-weight: 600; font-size: .82rem; transition: background .12s; }
    .btn-revoke { background: rgba(239,68,68,.13); color: #fca5a5; border:1px solid rgba(239,68,68,.30); }
    .btn-revoke:hover { background: rgba(239,68,68,.22); }
    .btn-link { background: rgba(96,165,250,.10); color: #7eb8f7; border:1px solid rgba(96,165,250,.25); text-decoration: none; display:inline-block; }
    .btn-link:hover { background: rgba(96,165,250,.18); }
    .empty { padding: 22px; color:#94a3b8; text-align:center; font-size: .9rem; }
    .links-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
    @media (max-width: 720px) { main { margin-left: 0; padding: 20px; } }
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main>
  <h1>Privacidade &amp; Consentimentos</h1>
  <p class="lead">
    Gerencie aqui suas autorizações específicas (cookies, marketing, processamento por IA etc).
    Você pode revogar qualquer consentimento a qualquer momento (LGPD Art. 18 IX).
  </p>

  <!-- ── Consentimentos atuais ── -->
  <section class="card">
    <h2>Seus consentimentos</h2>
    <?php if (empty($consentimentos)): ?>
      <div class="empty">
        Nenhum consentimento registrado ainda. Quando você responder o banner de cookies
        ou aceitar algo novo, aparece aqui.
      </div>
    <?php else: ?>
      <?php foreach ($consentimentos as $c):
        $status = $c['status'];
        $pillCls = $status === 'ativo' ? 'pill-ativo' : 'pill-revogado';
        $finalidadeLabel = ucfirst(str_replace('_', ' ', $c['finalidade']));
      ?>
        <div class="cons-row">
          <div>
            <span class="cons-fin"><?= htmlspecialchars($finalidadeLabel) ?></span>
            <span class="pill <?= $pillCls ?>"><?= $status ?></span>
            <div class="cons-meta">
              Base: <?= htmlspecialchars($c['base_legal']) ?> ·
              Concedido em <?= htmlspecialchars($c['concedido_em']) ?>
              <?php if ($c['revogado_em']): ?>· Revogado em <?= htmlspecialchars($c['revogado_em']) ?><?php endif; ?>
              <?php if ($c['fonte']): ?>· Origem: <?= htmlspecialchars($c['fonte']) ?><?php endif; ?>
            </div>
          </div>
          <?php if ($status === 'ativo'): ?>
            <button class="btn btn-revoke" type="button"
                    onclick="revoke('<?= htmlspecialchars($c['finalidade'], ENT_QUOTES) ?>')">Revogar</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <!-- ── Direitos do titular (Art. 18) ── -->
  <section class="card">
    <h2>Seus direitos como titular (LGPD Art. 18)</h2>
    <p style="color:#94a3b8;font-size:.88rem;line-height:1.55;margin-bottom: 14px">
      Você pode exercer os seguintes direitos. Para solicitações de acesso, correção,
      anonimização, eliminação ou portabilidade de dados, entre em contato com o
      Encarregado de Dados (DPO) pelo link abaixo.
    </p>
    <div class="links-grid">
      <a class="btn btn-link" href="/sistema_vendas/public/lgpd/solicitar.php" target="_blank" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none">Abrir solicitação LGPD →</a>
      <a class="btn btn-link" href="/sistema_vendas/public/dpo.php" target="_blank">Falar com o DPO</a>
      <a class="btn btn-link" href="/sistema_vendas/public/privacidade.php" target="_blank">Política de Privacidade</a>
      <a class="btn btn-link" href="/sistema_vendas/public/termos.php" target="_blank">Termos de Uso</a>
      <a class="btn btn-link" href="/sistema_vendas/public/cookies.php" target="_blank">Política de Cookies</a>
      <a class="btn btn-link" href="/sistema_vendas/public/lgpd.php" target="_blank">LGPD &amp; Segurança</a>
      <a class="btn btn-link" href="javascript:if(window.YurisCookies){YurisCookies.open()}">Gerenciar cookies</a>
    </div>
  </section>
</main>

<script>
  const CSRF = <?= json_encode($csrf) ?>;
  async function revoke(finalidade) {
    if (!confirm('Tem certeza? Vamos revogar o consentimento para "' + finalidade + '".')) return;
    try {
      const res = await fetch('/sistema_vendas/public/api/legal/consent.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        credentials: 'same-origin',
        body: JSON.stringify({ csrf_token: CSRF, finalidade })
      });
      const j = await res.json();
      if (j && j.ok) location.reload();
      else alert('Erro: ' + (j.error || 'desconhecido'));
    } catch (e) { alert('Erro de rede: ' + e.message); }
  }
</script>
</body>
</html>
