<?php
/**
 * sidebar.php — componente global da sidebar
 * Defina $activePage antes de incluir este arquivo.
 * Valores: 'dashboard' | 'funil' | 'prospeccao' | 'dre' |
 *          'processos' | 'juridico' | 'tarefas' | 'usuarios' | 'agente' | 'configuracoes'
 */
$_ap          = (string)($activePage ?? '');
$_userName    = (string)($_SESSION['user_nome']   ?? 'Usuário');
$_userRole    = strtolower((string)($_SESSION['user_perfil'] ?? ''));
$_userInitial = mb_strtoupper(mb_substr(trim($_userName), 0, 1, 'UTF-8'), 'UTF-8') ?: '?';

// permissions: admin (*) sees everything; others only see granted pages
$_isAdmin = $_userRole === 'admin';
$_perms   = $_SESSION['user_permissions'] ?? [];

// Reload permissions from DB if session lost them (e.g. after session regeneration)
if (!$_isAdmin && empty($_perms) && !empty($_SESSION['user_id'])) {
    try {
        $__pdo = \App\Models\Database::getConnection();
        $__ps  = $__pdo->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
        $__ps->execute([$_SESSION['user_id']]);
        $_perms = $__ps->fetchAll(\PDO::FETCH_COLUMN);
        $_SESSION['user_permissions'] = $_perms;
    } catch (\Throwable $__e) { /* mantém vazio se DB falhar */ }
}
function _sidebarCan(string $page): bool {
    global $_isAdmin, $_perms;
    return $_isAdmin || in_array('*', $_perms) || in_array($page, $_perms);
}

$_roleLabel   = match($_userRole) {
    'admin'   => 'Admin',
    'manager' => 'Gerente',
    'user'    => 'Usuário',
    default   => ucfirst($_userRole) ?: 'Usuário',
};
$_roleCss = match($_userRole) {
    'admin'   => 'admin',
    'manager' => 'manager',
    'user'    => 'user',
    default   => 'default',
};

/* SVG icons */
$_svg = [
    'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
    'funil'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l7 8v6l6 4v-10l7-8z"/></svg>',
    'prosp'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'financas'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg>',
    'processos' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'juridico'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4"/><path d="M6 7h12"/><path d="M8 10v4"/><path d="M16 10v4"/><path d="M5 18c1.5-1 3.5-1 5 0"/><path d="M19 18c-1.5-1-3.5-1-5 0"/></svg>',
    'usuarios'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'agente'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M12 11V7"/><circle cx="12" cy="5" r="2"/><path d="M8 15h.01M12 15h.01M16 15h.01"/><path d="M7 11V9a5 5 0 0 1 10 0v2"/></svg>',
    'tarefas'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    'chat'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
    'chat_interno' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 6h-6a5 5 0 0 0 0 10h1l3 3 3-3h2a3 3 0 0 0 3-3V9a3 3 0 0 0-3-3z"/><path d="M3 8v8a3 3 0 0 0 3 3"/></svg>',
    'webhooks'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/><path d="m13 10 3-3-3-3"/><path d="M7.07 7.07A8.35 8.35 0 0 1 16 6c1.55 0 3 .43 4.23 1.17"/><path d="M22 12.5a9.94 9.94 0 0 1-.89 4.12"/></svg>',
    'escritorios' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'config'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06A2 2 0 1 1 2.27 17.8l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09c.7 0 1.27-.43 1.51-1a1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.3 2.27l.06.06c.5.5 1.2.75 1.82.33.56-.32 1.28-.32 1.82-.32H12a2 2 0 1 1 0 4h-.09c-.7 0-1.27.43-1.51 1a1.65 1.65 0 0 0 .33 1.82l.06.06A2 2 0 1 1 17.73 6.2l-.06.06c-.5.5-.75 1.2-.33 1.82.32.56.32 1.28.32 1.82V12a2 2 0 1 1 4 0v.09c0 .7.43 1.27 1 1.51z"/></svg>',
    'sair'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
];
$_uiLibPath  = __DIR__ . '/../assets/yuris-ui.js';
$_uiLibVer   = file_exists($_uiLibPath) ? @filemtime($_uiLibPath) : '1';
?>
<!-- Yuris UI lib (notify/confirm/prompt sem "localhost diz"). Auto-polyfills window.alert. -->
<script src="/sistema_vendas/public/assets/yuris-ui.js?v=<?= $_uiLibVer ?>"></script>
<!-- LGPD Etapa 5: banner de cookies — auto-inicializa, só aparece se ainda não respondeu -->
<script src="/sistema_vendas/public/assets/cookie-consent.js?v=1"></script>
<aside class="sidebar" role="complementary" aria-label="Menu lateral">

  <!-- ── Marca ── -->
  <div class="sidebar-brand" style="display:block;background:rgba(30,58,95,0.22);border:1px solid rgba(191,199,213,0.12);border-radius:11px;padding:4px 8px;margin-bottom:10px;text-align:center;">
    <img src="/sistema_vendas/Imagens/Logo.png" alt="Yuris" style="max-width:100%;max-height:160px;object-fit:contain;display:block;margin:0 auto;">
  </div>

  <!-- ── Usuário logado ── -->
  <div class="sidebar-user">
    <div class="sidebar-user-avatar"><?= htmlspecialchars($_userInitial) ?></div>
    <div class="sidebar-user-info">
      <div class="sidebar-user-name"><?= htmlspecialchars($_userName) ?></div>
      <span class="sidebar-user-badge sidebar-user-badge--<?= htmlspecialchars($_roleCss) ?>"><?= htmlspecialchars($_roleLabel) ?></span>
    </div>
  </div>

  <!-- ── Status (última atualização) ── -->
  <div id="dashboardStatus" class="sidebar-status">—</div>

  <!-- ── Navegação ── -->
  <nav aria-label="Páginas do sistema">

    <?php if (_sidebarCan('dashboard')): ?>
    <a href="dashboard.php"<?= $_ap === 'dashboard' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['dashboard'] ?></span>
      <span class="label">Dashboard</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('planejamento')): ?>
    <a href="planejamento.php"<?= $_ap === 'funil' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['funil'] ?></span>
      <span class="label">Planejamento</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('prospeccao')): ?>
    <a href="prospeccao.php"<?= $_ap === 'prospeccao' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['prosp'] ?></span>
      <span class="label">Prospecção</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('financas')): ?>
    <a href="financas.php"<?= $_ap === 'dre' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['financas'] ?></span>
      <span class="label">Finanças</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('processos')): ?>
    <a href="processos.php"<?= $_ap === 'processos' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['processos'] ?></span>
      <span class="label">Processos</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('juridico')): ?>
    <a href="juridico.php"<?= $_ap === 'juridico' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['juridico'] ?></span>
      <span class="label">Jurídico</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('tarefas')): ?>
    <a href="tarefas.php"<?= $_ap === 'tarefas' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['tarefas'] ?></span>
      <span class="label">Tarefas</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('usuarios')): ?>
    <a href="usuarios.php"<?= $_ap === 'usuarios' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['usuarios'] ?></span>
      <span class="label">Usuários</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('agente')): ?>
    <a href="agente.php"<?= $_ap === 'agente' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['agente'] ?></span>
      <span class="label">Agente</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('chat')): ?>
    <a href="chat.php"<?= $_ap === 'chat' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['chat'] ?></span>
      <span class="label">WhatsApp</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('chat_interno')): ?>
    <a href="chat_interno.php"<?= $_ap === 'chat_interno' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['chat_interno'] ?></span>
      <span class="label">Chat Interno</span>
    </a>
    <?php endif; ?>

    <?php if ($_isAdmin): ?>
    <a href="webhooks.php"<?= $_ap === 'webhooks' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['webhooks'] ?></span>
      <span class="label">Webhooks</span>
    </a>
    <?php endif; ?>

    <?php if (_sidebarCan('configuracoes')): ?>
    <a href="configuracoes.php"<?= $_ap === 'configuracoes' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['config'] ?></span>
      <span class="label">Configurações</span>
    </a>
    <?php endif; ?>
    <?php /* Link "Privacidade" movido para o rodape da sidebar (abaixo do
             "Sistema Juridico Inteligente"), como discreto link tipo footer.
             Acesso permanece o mesmo: /configuracoes/privacidade.php */ ?>

    <?php if (_sidebarCan('escritorios')): ?>
    <a href="escritorios.php"<?= $_ap === 'escritorios' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['escritorios'] ?></span>
      <span class="label">Escritórios</span>
    </a>
    <?php endif; ?>

    <?php
    /* Painel Master — REMOVIDO da sidebar do app normal.
     * Acesso EXCLUSIVO via portal isolado: /sistema_vendas/public/master_login.php
     * Mesmo super_admins não vêem link aqui. Garantia de "qualquer outra conta
     * não deve ter acesso ao painel master nunca" — não há trilha visual.
     */
    ?>

  </nav>

  <a href="logout.php" class="sidebar-logout-card" style="display:flex;align-items:center;gap:11px;padding:12px 13px;border-radius:11px;background:rgba(30,58,95,0.22);border:1px solid rgba(191,199,213,0.12);color:#6B7887;text-decoration:none;font-size:.9rem;font-weight:600;">
    <span style="width:20px;height:20px;min-width:20px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $_svg['sair'] ?></span>
    <span>Sair</span>
  </a>

  <!-- ── Rodapé da sidebar ── -->
  <div style="padding:10px 18px 0;text-align:center;border-top:1px solid rgba(96,165,250,0.1);">
    <p style="font-size:.9rem;font-weight:700;color:#e8f4ff;margin:0 0 2px;letter-spacing:.5px;">Yuris</p>
    <p style="font-size:.72rem;color:#6b8299;margin:0 0 8px;">Sistema Jurídico Inteligente</p>
    <?php if (_sidebarCan('configuracoes')): ?>
    <a href="configuracoes/privacidade.php"
       title="Privacidade e consentimentos LGPD"
       style="font-size:.7rem;color:<?= $_ap === 'privacidade' ? '#93C5FD' : '#6b8299' ?>;text-decoration:none;letter-spacing:.3px;transition:color .15s;<?= $_ap === 'privacidade' ? 'font-weight:600;border-bottom:1px solid rgba(147,197,253,.35);padding-bottom:1px;' : '' ?>"
       onmouseover="this.style.color='#93C5FD'"
       onmouseout="this.style.color='<?= $_ap === 'privacidade' ? '#93C5FD' : '#6b8299' ?>'">
      Privacidade
    </a>
    <?php endif; ?>
  </div>

</aside>

<!-- ── Barra de navegação mobile ── -->
<nav class="mobile-tabbar" role="navigation" aria-label="Navegação principal">

  <a href="dashboard.php"<?= $_ap === 'dashboard' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['dashboard'] ?></span>
    <span>Dashboard</span>
  </a>

  <a href="prospeccao.php"<?= $_ap === 'prospeccao' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['prosp'] ?></span>
    <span>Prospecção</span>
  </a>

  <a href="processos.php"<?= $_ap === 'processos' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['processos'] ?></span>
    <span>Processos</span>
  </a>

  <a href="juridico.php"<?= $_ap === 'juridico' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['juridico'] ?></span>
    <span>Jurídico</span>
  </a>

  <a href="financas.php"<?= $_ap === 'dre' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['financas'] ?></span>
    <span>Finanças</span>
  </a>

</nav>

<script>
(function () {
  try {
    var el    = document.getElementById('dashboardStatus');
    var saved = localStorage.getItem('dashboard_last_update');
    if (el && saved) { el.textContent = saved; el.style.color = '#4ade80'; }
    window.addEventListener('storage', function (e) {
      if (!e || e.key !== 'dashboard_last_update') return;
      var el2 = document.getElementById('dashboardStatus');
      if (el2) { el2.textContent = e.newValue; el2.style.color = '#4ade80'; }
    });
  } catch (e) { /* silently fail */ }
})();
</script>
