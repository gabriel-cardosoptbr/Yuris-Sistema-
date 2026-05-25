<?php
/**
 * sidebar.php — componente global da sidebar
 * Defina $activePage antes de incluir este arquivo.
 * Valores: 'dashboard' | 'funil' | 'prospeccao' | 'dre' |
 *          'processos' | 'intimacoes' | 'juridico' | 'tarefas' | 'usuarios' | 'agente' | 'configuracoes'
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
    'juridico'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
    'intimacoes'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/><circle cx="18" cy="6" r="2.5" fill="currentColor" stroke="none"/></svg>',
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

  <!-- ── Navegação agrupada em seções colapsáveis ──
       Estrutura: Dashboard fixo no topo, demais módulos em grupos.
       Auto-expand server-side via classe .open quando $_ap pertence ao grupo
       (evita flash de conteúdo). Estado persiste em localStorage (override do
       auto-expand: se user fechou explicitamente, respeitamos).
  -->
  <nav aria-label="Páginas do sistema" class="sidebar-nav-grouped">

    <?php if (_sidebarCan('dashboard')): ?>
    <a href="dashboard.php" class="sidebar-overview-link<?= $_ap === 'dashboard' ? ' active' : '' ?>"<?= $_ap === 'dashboard' ? ' aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['dashboard'] ?></span>
      <span class="label">Visão Geral</span>
    </a>
    <?php endif; ?>

    <?php
    // Definição das seções (mantém todas as rotas atuais — só agrupa visualmente).
    // perm = chave usada em _sidebarCan(); admin_only = visível só pra admins.
    // active = valor de $activePage que faz o item brilhar (e abre a seção pai).
    // SVGs Feather/Lucide pros toggles dos grupos (mesma familia visual dos demais)
    $_grpSvg = [
      'operacao'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
      'juridico'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16h6l-3-7-3 7zM2 16h6l-3-7-3 7zM12 3v19M3 22h18M5 9h14"/></svg>',
      'comunicacao' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
      'gestao'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
      'automacoes'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>',
      'sistema'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    ];
    $_sections = [
      [ 'key' => 'operacao', 'label' => 'Operação', 'icon' => $_grpSvg['operacao'], 'items' => [
        ['perm'=>'planejamento','href'=>'planejamento.php','active'=>'funil',     'icon'=>'funil',   'label'=>'Planejamento'],
        ['perm'=>'prospeccao', 'href'=>'prospeccao.php', 'active'=>'prospeccao', 'icon'=>'prosp',    'label'=>'Prospecção'],
        ['perm'=>'tarefas',    'href'=>'tarefas.php',    'active'=>'tarefas',    'icon'=>'tarefas',  'label'=>'Tarefas'],
      ]],
      [ 'key' => 'juridico', 'label' => 'Jurídico', 'icon' => $_grpSvg['juridico'], 'items' => [
        ['perm'=>'processos', 'href'=>'processos.php', 'active'=>'processos', 'icon'=>'processos', 'label'=>'Processos'],
        ['perm'=>'intimacoes','href'=>'intimacoes.php','active'=>'intimacoes','icon'=>'intimacoes','label'=>'Intimações'],
        ['perm'=>'juridico',  'href'=>'juridico.php',  'active'=>'juridico',  'icon'=>'juridico',  'label'=>'Painel'],
      ]],
      [ 'key' => 'comunicacao', 'label' => 'Comunicação', 'icon' => $_grpSvg['comunicacao'], 'items' => [
        ['perm'=>'chat',         'href'=>'chat.php',         'active'=>'chat',         'icon'=>'chat',         'label'=>'WhatsApp'],
        ['perm'=>'chat_interno', 'href'=>'chat_interno.php', 'active'=>'chat_interno', 'icon'=>'chat_interno', 'label'=>'Chat Interno'],
      ]],
      [ 'key' => 'gestao', 'label' => 'Gestão', 'icon' => $_grpSvg['gestao'], 'items' => [
        ['perm'=>'financas',   'href'=>'financas.php',   'active'=>'dre',         'icon'=>'financas',    'label'=>'Finanças'],
        ['perm'=>'usuarios',   'href'=>'usuarios.php',   'active'=>'usuarios',    'icon'=>'usuarios',    'label'=>'Usuários'],
        ['perm'=>'escritorios','href'=>'escritorios.php','active'=>'escritorios', 'icon'=>'escritorios', 'label'=>'Escritórios'],
      ]],
      [ 'key' => 'automacoes', 'label' => 'Automações', 'icon' => $_grpSvg['automacoes'], 'items' => [
        ['perm'=>'agente',                 'href'=>'agente.php',   'active'=>'agente',   'icon'=>'agente',   'label'=>'Agente'],
        ['perm'=>null,'admin_only'=>true, 'href'=>'webhooks.php', 'active'=>'webhooks', 'icon'=>'webhooks', 'label'=>'Webhooks'],
      ]],
      [ 'key' => 'sistema', 'label' => 'Sistema', 'icon' => $_grpSvg['sistema'], 'items' => [
        ['perm'=>'configuracoes','href'=>'configuracoes.php','active'=>'configuracoes','icon'=>'config','label'=>'Configurações'],
        // Sair: sem permissão (sempre visível); marca is_logout pra hover vermelho
        ['perm'=>null,'always'=>true,'is_logout'=>true,'href'=>'logout.php','active'=>'__never__','icon'=>'sair','label'=>'Sair'],
      ]],
    ];

    foreach ($_sections as $_sec):
        // Filtra items que o user pode ver
        $_visible = [];
        foreach ($_sec['items'] as $_it) {
            $_allowed = !empty($_it['always'])
                || (!empty($_it['admin_only']) && $_isAdmin)
                || (!empty($_it['perm']) && _sidebarCan($_it['perm']));
            if ($_allowed) $_visible[] = $_it;
        }
        if (empty($_visible)) continue;  // grupo vazio não renderiza

        // Se a página atual está num item dessa seção → abre + destaca o título
        $_hasActive = false;
        foreach ($_visible as $_it) {
            if ($_ap === $_it['active']) { $_hasActive = true; break; }
        }
    ?>
    <div class="sidebar-group<?= $_hasActive ? ' open has-active' : '' ?>" data-group="<?= htmlspecialchars($_sec['key']) ?>">
      <button type="button" class="sidebar-group-toggle" aria-expanded="<?= $_hasActive ? 'true' : 'false' ?>" aria-controls="grp-<?= htmlspecialchars($_sec['key']) ?>">
        <?php if (!empty($_sec['icon'])): ?>
        <span class="sidebar-group-icon" aria-hidden="true"><?= $_sec['icon'] ?></span>
        <?php endif; ?>
        <span class="sidebar-group-label"><?= htmlspecialchars($_sec['label']) ?></span>
        <svg class="sidebar-group-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>
      <div class="sidebar-group-items" id="grp-<?= htmlspecialchars($_sec['key']) ?>">
        <?php foreach ($_visible as $_it):
            $_isActive = ($_ap === $_it['active']);
            $_extraClass = !empty($_it['is_logout']) ? ' is-logout' : '';
        ?>
        <a href="<?= htmlspecialchars($_it['href']) ?>"<?= $_isActive ? ' class="active' . $_extraClass . '" aria-current="page"' : ($_extraClass ? ' class="' . trim($_extraClass) . '"' : '') ?>>
          <span class="icon" aria-hidden="true"><?= $_svg[$_it['icon']] ?></span>
          <span class="label"><?= htmlspecialchars($_it['label']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php
    /* Painel Master — REMOVIDO da sidebar do app normal.
     * Acesso EXCLUSIVO via portal isolado: /sistema_vendas/public/master_login.php
     * Mesmo super_admins não vêem link aqui. Garantia de "qualquer outra conta
     * não deve ter acesso ao painel master nunca" — não há trilha visual.
     */
    ?>

  </nav>

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
    <span>Visão Geral</span>
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

// Sidebar: expandir/recolher seções + persistência em localStorage.
// Auto-expand server-side (classe .open) é mantido. localStorage só sobrescreve
// quando o user clicou em alguma seção explicitamente — assim a página atual
// sempre abre, mas a preferência manual ganha precedência.
(function () {
  var KEY = 'yuris_sidebar_groups_v2';
  // Limpa chave antiga (estado herdado de sessão anterior onde grupos abriam automaticamente)
  try { localStorage.removeItem('yuris_sidebar_groups'); } catch (e) {}
  var stored = {};
  try { stored = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}

  document.querySelectorAll('.sidebar-group').forEach(function (g) {
    var key = g.getAttribute('data-group');
    if (!key) return;
    if (Object.prototype.hasOwnProperty.call(stored, key)) {
      g.classList.toggle('open', !!stored[key]);
      var btn0 = g.querySelector('.sidebar-group-toggle');
      if (btn0) btn0.setAttribute('aria-expanded', stored[key] ? 'true' : 'false');
    }
    var btn = g.querySelector('.sidebar-group-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var open = g.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      stored[key] = open;
      try { localStorage.setItem(KEY, JSON.stringify(stored)); } catch (e) {}
    });
  });
})();
</script>
