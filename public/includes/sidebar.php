<?php
/**
 * sidebar.php — componente global da sidebar
 * Defina $activePage antes de incluir este arquivo.
 * Valores: 'dashboard' | 'funil' | 'prospeccao' | 'dre' |
 *          'processos' | 'intimacoes' | 'juridico' | 'tarefas' | 'usuarios' | 'agente' | 'configuracoes'
 */
$_ap          = (string)($activePage ?? '');
$_userName    = (string)($_SESSION['user_nome']   ?? 'Usuário');
// Token CSRF para o sino de notificações (PATCH marcar lida). Garante que o
// token exista mesmo em páginas que não o definem (ex.: dashboard.php) — o
// sidebar é incluído em todas as telas autenticadas, então centralizamos aqui.
$_notifCsrf   = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
$_userRole    = strtolower((string)($_SESSION['user_perfil'] ?? ''));
$_userInitial = mb_strtoupper(mb_substr(trim($_userName), 0, 1, 'UTF-8'), 'UTF-8') ?: '?';

// permissions: admin (*) sees everything; others only see granted pages
$_isAdmin = $_userRole === 'admin';
$_perms   = $_SESSION['user_permissions'] ?? [];

// Reload permissions from DB if session lost them (e.g. after session regeneration)
if (!$_isAdmin && empty($_perms) && !empty($_SESSION['user_id'])) {
    try {
        $__pdo = \App\Core\Database::getConnection();
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
    'clientes'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8" cy="11" r="2.5"/><path d="M4 18a4 4 0 0 1 8 0"/><line x1="14" y1="9" x2="20" y2="9"/><line x1="14" y1="13" x2="20" y2="13"/><line x1="14" y1="17" x2="18" y2="17"/></svg>',
    'financas'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 9h4.5a1.5 1.5 0 0 1 0 3h-5a1.5 1.5 0 0 0 0 3H15"/></svg>',
    'processos' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'juridico'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
    'intimacoes'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/><circle cx="18" cy="6" r="2.5" fill="currentColor" stroke="none"/></svg>',
    'relatorios'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="18" x2="8" y2="13"/><line x1="12" y1="18" x2="12" y2="11"/><line x1="16" y1="18" x2="16" y2="15"/></svg>',
    'desempenho'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>',
    'usuarios'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'agente'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M12 11V7"/><circle cx="12" cy="5" r="2"/><path d="M8 15h.01M12 15h.01M16 15h.01"/><path d="M7 11V9a5 5 0 0 1 10 0v2"/></svg>',
    'tarefas'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    'chat'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
    'chat_interno' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 6h-6a5 5 0 0 0 0 10h1l3 3 3-3h2a3 3 0 0 0 3-3V9a3 3 0 0 0-3-3z"/><path d="M3 8v8a3 3 0 0 0 3 3"/></svg>',
    'webhooks'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.68-2.48 1.61A4 4 0 0 1 2 17c0-2.22 1.8-4 4-4h4"/><path d="m13 10 3-3-3-3"/><path d="M7.07 7.07A8.35 8.35 0 0 1 16 6c1.55 0 3 .43 4.23 1.17"/><path d="M22 12.5a9.94 9.94 0 0 1-.89 4.12"/></svg>',
    'escritorios' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'config'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06A2 2 0 1 1 2.27 17.8l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09c.7 0 1.27-.43 1.51-1a1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.3 2.27l.06.06c.5.5 1.2.75 1.82.33.56-.32 1.28-.32 1.82-.32H12a2 2 0 1 1 0 4h-.09c-.7 0-1.27.43-1.51 1a1.65 1.65 0 0 0 .33 1.82l.06.06A2 2 0 1 1 17.73 6.2l-.06.06c-.5.5-.75 1.2-.33 1.82.32.56.32 1.28.32 1.82V12a2 2 0 1 1 4 0v.09c0 .7.43 1.27 1 1.51z"/></svg>',
    'sair'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    'sino'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
];
$_uiLibPath  = __DIR__ . '/../assets/yuris-ui.js';
$_uiLibVer   = file_exists($_uiLibPath) ? @filemtime($_uiLibPath) : '1';
$_notifJsPath = __DIR__ . '/../assets/notifications.js';
$_notifJsVer  = file_exists($_notifJsPath) ? @filemtime($_notifJsPath) : '1';

// ── Notificações renderizadas NO SERVIDOR (à prova de falha de fetch/JS/cache no
//    cliente). O notifications.js só atualiza em silêncio depois + marca lida.
$_notifItems  = null;  // null => mantém "Carregando…" e o JS assume o carregamento
$_notifUnread = 0;
try {
    require_once __DIR__ . '/../../app/Master/AccountNotification.php';
    require_once __DIR__ . '/../../app/Core/AccountContext.php';
    $__nctx      = \App\Core\AccountContext::fromSession();
    // Só NÃO-LIDAS no sino (caixa de entrada de pendências): ao concluir/marcar
    // lida, o item sai e não reaparece no reload. Histórico fica no banco.
    $_notifItems = \App\Master\AccountNotification::listForUser($__nctx->getUserId(), $__nctx->getAccountId(), true);
    foreach ($_notifItems as $__n) { if ((int)($__n['lida'] ?? 0) === 0) $_notifUnread++; }
} catch (\Throwable $__e) { $_notifItems = null; }

// Produto da conta: 'yuris' (padrão) ou 'fleetiflow'. Reaproveita o contexto
// já resolvido acima para notificações quando existir, para não abrir uma
// segunda conexão à toa. Falha => assume 'yuris' (identidade de sempre).
$_isFleetiflow = false;
$_marca        = null;
try {
    $__brandCtx = $__nctx ?? \App\Core\AccountContext::fromSession();
    $_isFleetiflow = $__brandCtx->getProduto() === 'fleetiflow';
    // Edição CRM: a marca (nome, cor, logo) é da conta. A Fleetiflow original,
    // sem marca gravada, recebe os mesmos valores que estavam escritos aqui.
    if ($_isFleetiflow) $_marca = $__brandCtx->getMarca();
} catch (\Throwable $__e) { /* mantém identidade Yuris se algo falhar */ }
if ($_isFleetiflow && $_marca === null) $_marca = \App\Master\Marca::padraoFleetiflow();

// ── Aviso de WhatsApp fora do ar (edição CRM) ─────────────────────────────
// Em 30/09/2026 o canal caiu (Evolution, código 401: o aparelho desconectou o
// WhatsApp) e ninguém percebeu: as respostas da especialista e os leads novos
// ficaram só no celular, e o funil parou. Aqui, se o canal da conta está
// configurado e não está conectado, toda tela mostra um aviso no topo, com a
// hora do último evento recebido e o caminho para reconectar. Fora do Chat, que
// já tem o próprio aviso e a tela de QR. Uma consulta leve por página; falha
// silenciosa (nunca derruba a tela).
$_avisoWa = null;
// Fora do WhatsApp (que tem o próprio aviso de conexão) e do Chat Interno, que é
// conversa da equipe e nada tem a ver com o canal.
if ($_isFleetiflow && !in_array($_ap, ['chat', 'chat_interno'], true)) {
    try {
        $__accW = (int)$__brandCtx->getAccountId();
        $__stW  = \App\Core\Database::getConnection()->prepare(
            "SELECT wi.status, wi.last_event_at
               FROM whatsapp_instances wi
              WHERE wi.account_id = ?
                AND EXISTS (SELECT 1 FROM whatsapp_settings s
                             WHERE s.account_id = wi.account_id AND s.config_key = 'evolution_api_key'
                               AND COALESCE(s.config_value, '') <> '')
           ORDER BY (wi.status = 'open') DESC, wi.id DESC LIMIT 1"
        );
        $__stW->execute([$__accW]);
        $__canalW = $__stW->fetch(\PDO::FETCH_ASSOC);
        if ($__canalW && $__canalW['status'] !== 'open') {
            $__ultW = $__canalW['last_event_at'] ? strtotime((string)$__canalW['last_event_at'] . ' UTC') : 0;
            $_avisoWa = [
                'desde'  => $__ultW ? (new \DateTime('@' . $__ultW))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m \à\s H:i') : null,
                'admin'  => $__brandCtx->isOwnerOrAdmin(),
            ];
        }
    } catch (\Throwable $__e) { $_avisoWa = null; }
}

$_notifTempo = function ($raw) {
    $ts = $raw ? strtotime((string)$raw) : 0; if (!$ts) return '';
    $d = max(0, time() - $ts);
    if ($d < 60)     return 'agora mesmo';
    if ($d < 3600)   return 'há ' . intdiv($d, 60) . ' min';
    if ($d < 86400)  return 'há ' . intdiv($d, 3600) . ' h';
    if ($d < 604800) return 'há ' . intdiv($d, 86400) . ' d';
    return date('d/m/Y', $ts);
};
?>
<?php if ($_isFleetiflow): ?>
<!-- Nome na aba do navegador: as páginas escrevem "Yuris" ou "Fleetiflow" no <title>. Em vez de
     uma condição em cada uma, a troca pelo nome da marca é feita aqui, só para a edição CRM. -->
<script>window.MARCA=<?= json_encode(['nome' => $_marca['nome'], 'cor' => $_marca['cor'], 'paleta' => $_marca['paleta'], 'agente' => $_marca['agente']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
(function(){try{var re=/Yuris|Fleetiflow/g;if(re.test(document.title))document.title=document.title.replace(re,window.MARCA.nome);}catch(e){}})();</script>
<!-- Ícone da aba: 24 páginas apontam para o favicon do Yuris (a balança). Aqui ele é
     trocado pelo ícone da marca (o "F" da Fleetiflow, ou o enviado no Master). O ?v= muda a URL de propósito: o Chrome guarda o
     favicon por endereço, e sem isso a aba continuaria com a balança por dias. -->
<script>(function(){try{
  var L=document.querySelectorAll('link[rel~="icon"],link[rel="apple-touch-icon"]');
  for(var i=0;i<L.length;i++){L[i].parentNode.removeChild(L[i]);}
  <?php if ($_marca['personalizada']): ?>[['icon','any',<?= json_encode($_marca['favicon_url'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>]]<?php else: ?>[['icon','32x32','/assets/fleetiflow-favicon-32.png?v=1'],['icon','192x192','/assets/fleetiflow-favicon-192.png?v=1'],['apple-touch-icon','180x180','/assets/fleetiflow-apple-touch-icon.png?v=1']]<?php endif; ?>.forEach(function(d){
    var l=document.createElement('link');l.rel=d[0];l.sizes=d[1];l.href=d[2];document.head.appendChild(l);
  });
}catch(e){}})();</script>
<!-- Identidade Fleetiflow: o produto real é claro, então a conta entra no
     tema claro do Yuris (já existe e é testado em yuris-theme.css; não
     reinventamos um) na PRIMEIRA visita, sem brigar depois com uma escolha
     que a pessoa já fez (ver localStorage.yuris_theme).
     As variáveis de cor abaixo são as MESMAS que o tema claro do Yuris já usa
     em toda a sidebar/cards/inputs — só trocamos o valor pelo azul do
     Fleetiflow. Nenhum seletor novo, nenhum arquivo novo, zero risco pras
     contas Yuris (que nunca carregam este bloco, condicionado a
     $_isFleetiflow). -->
<script>
(function(){
  try {
    if (localStorage.getItem('yuris_theme') === null) {
      document.documentElement.setAttribute('data-theme', 'light');
      localStorage.setItem('yuris_theme', 'light');
    }
  } catch (e) {}
})();
</script>
<?php /* O CSS claro abaixo é capturado para gerar a cópia do tema escuro (TemaEscuroCrm). */ ob_start(); ?>
<style>
  /* Cores da marca da conta (App\Master\Marca::paleta). Todo o bloco abaixo
     usa estas variáveis em vez do azul do Fleetiflow escrito à mão. */
  :root{
<?php foreach (['marca' => 'ff-marca', 'forte' => 'ff-marca-forte', 'suave' => 'ff-marca-suave', 'media' => 'ff-marca-media',
                'clara' => 'ff-marca-clara', 'escura' => 'ff-marca-escura', 'rgb' => 'ff-marca-rgb', 'texto' => 'ff-marca-texto'] as $__k => $__v): ?>
    --<?= $__v ?>: <?= htmlspecialchars($_marca['paleta'][$__k]) ?>;
<?php endforeach; ?>
  }
  :root{
    --yuris-primary:   var(--ff-marca-escura) !important;
    --yuris-accent:    var(--ff-marca) !important;
    --yuris-blue-deep: var(--ff-marca-escura) !important;
    --primary:         var(--ff-marca) !important;
    --brand:           var(--ff-marca) !important;
    --icon-color:      var(--ff-marca) !important;
  }

  /* ── Menu lateral transcrito da AppShell.tsx + global.css do Fleetiflow:
       coluna branca inteira encostada na borda (260px, borda direita sutil),
       item plano só com ícone + texto, hover cinza, ativo em azul claro com
       texto escuro e ícone azul. Reescreve sidebar.css e yuris-theme.css (que
       são !important em tudo) e só entra em vigor pra esta conta. ── */
  /* REGRA DO BLOCO: todo seletor leva o prefixo html[data-theme="light"] e,
     onde o yuris-theme.css desce até .label / svg *, descemos também. Sem
     isso o tema claro do Yuris vence por especificidade (foi o que deixou o
     item ativo com letra branca sobre azul claro e uma sombra por item). */
  html[data-theme="light"] body, html[data-theme="light"] .page-layout{
    background:#F6F7F9 !important; background-image:none !important;
    font-family:'Manrope',system-ui,-apple-system,'Segoe UI',sans-serif !important;
  }
  /* O envoltório da barra muda de página para página: main.px-6 (a maioria),
     main.rel-wrap, main.o-wrap, main com padding inline (chat_interno,
     webhooks) e .layout sem .page-layout (clientes.php). Uma regra por classe
     deixava a barra colada em umas telas e flutuando com margem cinza em
     outras. Regra genérica: quem CONTÉM a barra perde o padding, e o irmão da
     barra recupera o respiro. */
  html[data-theme="light"] main:has(> .page-layout > .sidebar),
  html[data-theme="light"] main:has(> .sidebar),
  html[data-theme="light"] .layout:has(> .sidebar),
  html[data-theme="light"] .page-layout:has(> .sidebar){ padding:0 !important; }
  html[data-theme="light"] .page-layout:has(> .sidebar) > :not(.sidebar),
  html[data-theme="light"] .layout:has(> .sidebar) > :not(.sidebar){ padding:24px 24px 24px 0 !important; min-width:0; box-sizing:border-box; }
  @media (max-width:900px){
    html[data-theme="light"] .page-layout:has(> .sidebar) > :not(.sidebar),
    html[data-theme="light"] .layout:has(> .sidebar) > :not(.sidebar){ padding:16px !important; }
  }
  /* ── Menu lateral no mesmo desenho do app Fleetiflow (30/09/2026, redesign
       "clean/premium"): branco, 248px, itens de 38px sem caixa, títulos de
       grupo miúdos em caixa alta, e o ATIVO marcado por três coisas somadas:
       texto e ícone no azul da marca, o ícone dentro de um disco azul e uma
       barra fina de 3px na extremidade DIREITA. A área do menu rola sozinha
       (fina, só no hover); marca no topo e rodapé fixos. ── */
  html[data-theme="light"] .sidebar{
    width:248px !important; min-width:248px !important; max-width:248px !important;
    height:100vh !important; min-height:100vh !important; max-height:100vh !important;
    padding:0 !important; gap:0 !important; border-radius:0 !important;
    display:flex !important; flex-direction:column !important;
    background:#FFFFFF !important; background-image:none !important;
    border:none !important; border-right:1px solid rgba(17,29,45,0.08) !important;
    box-shadow:none !important; overflow:hidden !important;
    position:sticky !important; top:0 !important;
  }
  html[data-theme="light"] .sidebar::before{ display:none !important; }

  /* Topo: marca. */
  html[data-theme="light"] .sidebar-brand{
    flex:none !important; height:64px !important; display:flex !important; align-items:center !important;
    background:none !important; border:none !important; box-shadow:none !important;
    border-radius:0 !important; padding:0 18px !important; margin:0 !important; text-align:left !important;
  }
  html[data-theme="light"] .sidebar-brand a img{ height:28px !important; }
  html[data-theme="light"] .sidebar-brand a span{ color:#3D3D3D !important; font-size:17px !important; }

  /* Pessoa logada: vai para o RODAPÉ (order), sem caixa, com o sino ao lado. */
  html[data-theme="light"] .sidebar-user{
    order:10 !important; flex:none !important;
    display:flex !important; align-items:center !important; gap:10px !important;
    background:none !important; border:none !important; border-top:1px solid rgba(17,29,45,0.08) !important;
    border-radius:0 !important; box-shadow:none !important; margin:0 !important; padding:10px 14px 12px 16px !important;
  }
  html[data-theme="light"] .sidebar-user-avatar{ width:32px !important; height:32px !important; font-size:12px !important; background:var(--ff-marca) !important; background-image:none !important; color:var(--ff-marca-texto) !important; box-shadow:none !important; border-radius:50% !important; }
  html[data-theme="light"] .sidebar-user-info{ min-width:0 !important; }
  html[data-theme="light"] .sidebar-user-name{ color:#3D3D3D !important; font-size:12.5px !important; font-weight:600 !important; white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important; }
  html[data-theme="light"] .sidebar-user-badge--admin,
  html[data-theme="light"] .sidebar-user-badge--manager,
  html[data-theme="light"] .sidebar-user-badge--user,
  html[data-theme="light"] .sidebar-user-badge--default{ background:none !important; color:#767676 !important; border:none !important; padding:0 !important; font-size:10px !important; letter-spacing:.05em !important; text-transform:uppercase !important; }
  html[data-theme="light"] .yuris-notif-btn{ width:30px !important; height:30px !important; background:none !important; border:none !important; color:#767676 !important; border-radius:8px !important; }
  html[data-theme="light"] .yuris-notif-btn:hover{ background:#F1F1F2 !important; color:#3D3D3D !important; }
  html[data-theme="light"] .yuris-notif-badge{ border-color:#FFFFFF !important; }
  html[data-theme="light"] .yuris-notif-panel{ top:auto !important; bottom:16px !important; left:256px !important; }
  /* Sem "última atualização" no menu: informação de painel, não de navegação. */
  html[data-theme="light"] .sidebar-status, html[data-theme="light"] #dashboardStatus{ display:none !important; }

  /* A área que rola. */
  html[data-theme="light"] .sidebar nav.sidebar-nav-grouped{
    flex:1 1 auto !important; min-height:0 !important; overflow-y:auto !important; overflow-x:hidden !important;
    display:flex !important; flex-direction:column !important; gap:0 !important;
    padding:8px 12px 12px !important; margin:0 !important;
    scrollbar-width:thin; scrollbar-color:transparent transparent;
  }
  html[data-theme="light"] .sidebar nav.sidebar-nav-grouped:hover{ scrollbar-color:rgba(17,29,45,0.18) transparent; }
  html[data-theme="light"] .sidebar nav.sidebar-nav-grouped::-webkit-scrollbar{ width:5px; }
  html[data-theme="light"] .sidebar nav.sidebar-nav-grouped::-webkit-scrollbar-thumb{ background:transparent; border-radius:999px; }
  html[data-theme="light"] .sidebar nav.sidebar-nav-grouped:hover::-webkit-scrollbar-thumb{ background:rgba(17,29,45,0.18); }

  /* Título miúdo de seção (só a conta Fleetiflow imprime) e 22px de ar entre grupos. */
  html[data-theme="light"] .sb-titulo{ display:block; margin:0 0 6px; padding:0 12px; font-size:10.5px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#767676; }
  html[data-theme="light"] .sidebar-group{ gap:0 !important; margin-top:22px !important; padding:0 !important; }
  html[data-theme="light"] .sidebar-group-items{ gap:2px !important; padding:2px 0 0 8px !important; margin:0 !important; }
  html[data-theme="light"] .sidebar-group.open .sidebar-group-items{ margin-top:0 !important; }

  /* Linha do item e do cabeçalho de grupo: 38px, raio 9px, ícone 17px num círculo de 30px. */
  html[data-theme="light"] .sidebar nav a,
  html[data-theme="light"] .sidebar-group-toggle{
    position:relative !important; display:flex !important; align-items:center !important;
    min-height:38px !important; width:100% !important;
    background:none !important; border:none !important; box-shadow:none !important;
    border-radius:9px !important; padding:0 10px !important; gap:10px !important; margin:0 !important;
    color:#575757 !important; font-size:13.5px !important; font-weight:500 !important;
    transition:background 180ms cubic-bezier(.2,0,0,1), color 180ms cubic-bezier(.2,0,0,1) !important;
  }
  html[data-theme="light"] .sidebar nav a .label,
  html[data-theme="light"] .sidebar-group-toggle .sidebar-group-label{ flex:1 !important; min-width:0 !important; overflow:hidden !important; text-overflow:ellipsis !important; white-space:nowrap !important; color:inherit !important; font-size:13.5px !important; font-weight:inherit !important; }
  html[data-theme="light"] .sidebar nav a .icon,
  html[data-theme="light"] .sidebar-group-toggle .sidebar-group-icon{
    display:inline-flex !important; align-items:center !important; justify-content:center !important;
    width:30px !important; height:30px !important; min-width:30px !important; border-radius:50% !important;
    background:none !important; transition:background 180ms cubic-bezier(.2,0,0,1) !important;
  }
  html[data-theme="light"] .sidebar nav a .icon svg,
  html[data-theme="light"] .sidebar-group-toggle .sidebar-group-icon svg{ width:17px !important; height:17px !important; min-width:17px !important; }
  html[data-theme="light"] .sidebar nav a .icon svg, html[data-theme="light"] .sidebar nav a .icon svg *,
  html[data-theme="light"] .sidebar-group-toggle .sidebar-group-icon svg, html[data-theme="light"] .sidebar-group-toggle .sidebar-group-icon svg *{ stroke:#676767 !important; stroke-width:1.75 !important; }
  html[data-theme="light"] .sidebar-group-chevron{ width:14px !important; height:14px !important; stroke:#767676 !important; stroke-width:2 !important; opacity:1 !important; }

  html[data-theme="light"] .sidebar nav a:hover,
  html[data-theme="light"] .sidebar-group-toggle:hover{ background:#F1F1F2 !important; color:#3D3D3D !important; box-shadow:none !important; }
  html[data-theme="light"] .sidebar nav a:hover .icon svg, html[data-theme="light"] .sidebar nav a:hover .icon svg *,
  html[data-theme="light"] .sidebar-group-toggle:hover .sidebar-group-icon svg, html[data-theme="light"] .sidebar-group-toggle:hover .sidebar-group-icon svg *{ stroke:#3D3D3D !important; }

  /* ATIVO: texto e ícone na marca, disco azul atrás do ícone, fundo quase nada, barra à direita. */
  /* Sem faixa colorida na lateral dos avisos: o ícone já diz o tipo. */
  .yui-toast{ border-left-width:1px !important; }
  html[data-theme="light"] .sidebar nav a.active{ background:rgba(var(--ff-marca-rgb),0.07) !important; color:var(--ff-marca-forte) !important; font-weight:600 !important; box-shadow:none !important; }
  html[data-theme="light"] .sidebar nav a.active .label{ color:var(--ff-marca-forte) !important; }
  html[data-theme="light"] .sidebar nav a.active .icon{ background:var(--ff-marca) !important; }
  html[data-theme="light"] .sidebar nav a.active .icon svg, html[data-theme="light"] .sidebar nav a.active .icon svg *{ stroke:var(--ff-marca-texto) !important; stroke-width:2.1 !important; }
  html[data-theme="light"] .sidebar nav a.active::after,
  html[data-theme="light"] .sidebar-group.has-active:not(.open) .sidebar-group-toggle::after{
    content:''; position:absolute; right:0; top:50%; width:3px; height:22px; border-radius:999px;
    background:var(--ff-marca); transform:translateY(-50%);
  }

  /* Grupo com filho ativo: texto e ícone na marca, sem disco (o disco é do filho).
     Fechado, a barra vai para ele, senão a pessoa perde a pista. */
  html[data-theme="light"] .sidebar-group.has-active .sidebar-group-toggle{ background:none !important; color:var(--ff-marca-forte) !important; font-weight:600 !important; box-shadow:none !important; }
  html[data-theme="light"] .sidebar-group.has-active .sidebar-group-toggle:hover{ background:#F1F1F2 !important; }
  html[data-theme="light"] .sidebar-group.has-active .sidebar-group-toggle .sidebar-group-label{ color:var(--ff-marca-forte) !important; }
  html[data-theme="light"] .sidebar-group.has-active .sidebar-group-toggle .sidebar-group-icon svg,
  html[data-theme="light"] .sidebar-group.has-active .sidebar-group-toggle .sidebar-group-icon svg *{ stroke:var(--ff-marca) !important; stroke-width:2.1 !important; }
  html[data-theme="light"] .sidebar-group.has-active .sidebar-group-chevron{ stroke:var(--ff-marca) !important; }

  html[data-theme="light"] .sidebar nav a.is-logout:hover{ background:#FFF1F1 !important; color:#B00000 !important; }
  html[data-theme="light"] .sidebar nav a.is-logout:hover .icon svg, html[data-theme="light"] .sidebar nav a.is-logout:hover .icon svg *{ stroke:#B00000 !important; }

  /* ── Superfícies planas em TODAS as telas (superficies.ts do Fleetiflow:
       "cartão parado é PLANO: só borda sobre o canvas, sem sombra nenhuma").
       Cada tela do Yuris tem a própria classe de painel, e todas carregam a
       sombra do tema claro do Yuris (ou, em clientes.php, a do tema escuro
       vazando). Inventário feito em 28/09/2026 com a conta Fleetiflow, tela a
       tela. Modal, gaveta e o painel do sino continuam com sombra: flutuam. ── */
  html[data-theme="light"] .page-header, html[data-theme="light"] .card-shell, html[data-theme="light"] .card,
  html[data-theme="light"] .kpi-card, html[data-theme="light"] .chart-card, html[data-theme="light"] .chart-box,
  html[data-theme="light"] .dash-panel, html[data-theme="light"] .proc-panel, html[data-theme="light"] .jur-panel,
  html[data-theme="light"] .dre-panel, html[data-theme="light"] .cfg-panel, html[data-theme="light"] .usr-panel,
  html[data-theme="light"] .agt-panel, html[data-theme="light"] .panel, html[data-theme="light"] .es-card,
  html[data-theme="light"] .chat-kpi, html[data-theme="light"] .chat-panel, html[data-theme="light"] .summary-box,
  html[data-theme="light"] .bottleneck-note, html[data-theme="light"] .yuris-card,
  html[data-theme="light"] #projectionPanel, html[data-theme="light"] #funnelPanel{
    background:#FFFFFF !important; background-image:none !important;
    border:1px solid rgba(17,29,45,0.08) !important; border-radius:14px !important; box-shadow:none !important;
  }
  html[data-theme="light"] .page-header h1, html[data-theme="light"] .page-header h2, html[data-theme="light"] .page-header .page-header-title{ color:#3D3D3D !important; }
  html[data-theme="light"] .page-header p, html[data-theme="light"] .page-header .page-header-subtitle{ color:#676767 !important; }

  /* Botões primários: pílula azul da marca, sem sombra (btn-primary do Fleetiflow). */
  html[data-theme="light"] .btn.primary, html[data-theme="light"] .btn-primary, html[data-theme="light"] .usr-btn-primary,
  html[data-theme="light"] .cfg-btn-primary, html[data-theme="light"] .agt-btn-primary, html[data-theme="light"] .conn-btn-primary,
  html[data-theme="light"] .tk-modal-btn-primary, html[data-theme="light"] .modal-btn-save{
    background:var(--ff-marca) !important; background-image:none !important; color:var(--ff-marca-texto) !important;
    border-color:transparent !important; border-radius:999px !important; box-shadow:none !important;
  }
  html[data-theme="light"] .btn.primary:hover, html[data-theme="light"] .btn-primary:hover, html[data-theme="light"] .usr-btn-primary:hover,
  html[data-theme="light"] .cfg-btn-primary:hover, html[data-theme="light"] .agt-btn-primary:hover, html[data-theme="light"] .conn-btn-primary:hover,
  html[data-theme="light"] .tk-modal-btn-primary:hover, html[data-theme="light"] .modal-btn-save:hover{ background:var(--ff-marca-forte) !important; }

  /* Aba ativa: fundo azul-claro da marca, sem sombra. */
  html[data-theme="light"] .tab-btn.active, html[data-theme="light"] .cfg-tab.active, html[data-theme="light"] .es-tab.active{
    background:var(--ff-marca-suave) !important; color:var(--ff-marca) !important; border-color:transparent !important; box-shadow:none !important;
  }

  /* Rodapé fixo: linhas de menu (mesmo desenho dos itens) + a pessoa logada. */
  html[data-theme="light"] .sidebar-footer.sb-rodape{
    order:9 !important; flex:none !important; margin:0 !important;
    display:flex !important; flex-direction:column !important; gap:2px !important;
    padding:10px 12px 8px !important; border-top:1px solid rgba(17,29,45,0.08) !important; text-align:left !important;
  }
  html[data-theme="light"] .sidebar-footer.sb-rodape a{
    position:relative !important; display:flex !important; align-items:center !important;
    min-height:38px !important; padding:0 10px !important; gap:10px !important;
    border-radius:9px !important; text-decoration:none !important;
    color:#575757 !important; font-size:13.5px !important; font-weight:500 !important; letter-spacing:0 !important;
    transition:background 180ms cubic-bezier(.2,0,0,1), color 180ms cubic-bezier(.2,0,0,1) !important;
  }
  html[data-theme="light"] .sidebar-footer.sb-rodape a .icon{ display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:50%; }
  html[data-theme="light"] .sidebar-footer.sb-rodape a .icon svg{ width:17px; height:17px; stroke:#676767; stroke-width:1.75; }
  html[data-theme="light"] .sidebar-footer.sb-rodape a:hover{ background:#F1F1F2 !important; color:#3D3D3D !important; }
  html[data-theme="light"] .sidebar-footer.sb-rodape a:hover .icon svg{ stroke:#3D3D3D; }
  html[data-theme="light"] .sidebar-footer.sb-rodape a.active{ background:rgba(var(--ff-marca-rgb),0.07) !important; color:var(--ff-marca-forte) !important; font-weight:600 !important; }
  html[data-theme="light"] .sidebar-footer.sb-rodape a.active .icon{ background:var(--ff-marca); }
  html[data-theme="light"] .sidebar-footer.sb-rodape a.active .icon svg{ stroke:var(--ff-marca-texto); stroke-width:2.1; }
  html[data-theme="light"] .sidebar-footer.sb-rodape a.active::after{ content:''; position:absolute; right:0; top:50%; width:3px; height:22px; border-radius:999px; background:var(--ff-marca); transform:translateY(-50%); }
  html[data-theme="light"] .sidebar-footer.sb-rodape a.is-logout:hover{ background:#FFF1F1 !important; color:#B00000 !important; }
  html[data-theme="light"] .sidebar-footer.sb-rodape a.is-logout:hover .icon svg{ stroke:#B00000; }

  /* Tela estreita (mesmo corte em que sidebar.css mostra a .mobile-tabbar):
     248px de menu espremiam o conteúdo. A barra de abas de baixo já faz a
     navegação; o menu lateral some, e os dois nunca aparecem juntos. */
  @media (max-width:900px){
    html[data-theme="light"] .sidebar{ display:none !important; }
    html[data-theme="light"] .page-layout:has(> .sidebar) > :not(.sidebar),
    html[data-theme="light"] .layout:has(> .sidebar) > :not(.sidebar){ padding:16px 16px 84px !important; }
  }
</style>
<?php $__cssClaroCrm = (string) ob_get_clean(); echo $__cssClaroCrm, \App\Master\TemaEscuroCrm::estilo($__cssClaroCrm); ?>
<script src="/assets/ff-escuro.js?v=<?= @filemtime(__DIR__ . '/../assets/ff-escuro.js') ?: 1 ?>"></script>
<link rel="stylesheet" href="/api/tema_escuro.php?arquivo=ff-ficha&amp;v=<?= max((int) @filemtime(__DIR__ . '/../assets/ff-ficha.css'), (int) @filemtime(__DIR__ . '/../../app/Master/TemaEscuroCrm.php')) ?>">
<link rel="stylesheet" href="/api/tema_escuro.php?arquivo=chat-numeros&amp;v=<?= max((int) @filemtime(__DIR__ . '/../assets/chat-numeros.css'), (int) @filemtime(__DIR__ . '/../../app/Master/TemaEscuroCrm.php')) ?>">
<link rel="stylesheet" href="/assets/ff-escuro.css?v=<?= @filemtime(__DIR__ . '/../assets/ff-escuro.css') ?: 1 ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php endif; ?>
<!-- Yuris UI lib (notify/confirm/prompt sem "localhost diz"). Auto-polyfills window.alert. -->
<script src="/assets/yuris-ui.js?v=<?= $_uiLibVer ?>"></script>
<!-- LGPD Etapa 5: banner de cookies — auto-inicializa, só aparece se ainda não respondeu -->
<script src="/assets/cookie-consent.js?v=1"></script>
<aside class="sidebar" role="complementary" aria-label="Menu lateral">

  <!-- ── Marca ── -->
  <div class="sidebar-brand" style="display:block;background:rgba(30,58,95,0.22);border:1px solid rgba(191,199,213,0.12);border-radius:11px;padding:4px 8px;margin-bottom:10px;text-align:center;">
    <?php if ($_isFleetiflow): ?>
    <!-- Ícone + texto, igual à AppShell real do Fleetiflow (não a imagem
         composta): lá a marca também é ícone + <span> renderizado, não um PNG
         com o nome desenhado dentro. -->
    <a href="dashboard.php" style="display:flex;align-items:center;gap:11px;text-decoration:none">
      <?php if (!empty($_marca['icone_url'])): ?>
      <img src="<?= htmlspecialchars($_marca['icone_url']) ?>" alt="" style="height:30px;width:auto;max-width:44px;object-fit:contain;flex:none;display:block">
      <?php else: ?>
      <!-- Marca sem ícone enviado: a inicial na cor da marca. -->
      <span aria-hidden="true" style="width:30px;height:30px;border-radius:9px;flex:none;display:flex;align-items:center;justify-content:center;background:var(--ff-marca);color:var(--ff-marca-texto);font-weight:800;font-size:16px"><?= htmlspecialchars($_marca['inicial']) ?></span>
      <?php endif; ?>
      <span style="font-size:18px;font-weight:800;letter-spacing:-.4px;color:#3D3D3D;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($_marca['nome']) ?></span>
    </a>
    <?php else: ?>
    <img src="/sistema_vendas/Imagens/Logo.png" alt="Yuris" style="max-width:100%;max-height:160px;object-fit:contain;display:block;margin:0 auto;">
    <?php endif; ?>
  </div>

  <!-- ── Usuário logado ── -->
  <div class="sidebar-user">
    <div class="sidebar-user-avatar"><?= htmlspecialchars($_userInitial) ?></div>
    <div class="sidebar-user-info">
      <div class="sidebar-user-name"><?= htmlspecialchars($_userName) ?></div>
      <span class="sidebar-user-badge sidebar-user-badge--<?= htmlspecialchars($_roleCss) ?>"><?= htmlspecialchars($_roleLabel) ?></span>
    </div>

    <!-- ── Sino de notificações (Auditoria 2026-06-01 #29/#7) ──
         Pluga a central account_notifications na UI: badge via ?count=1,
         lista via GET, PATCH ao marcar lida. Lógica em /assets/notifications.js. -->
    <div class="yuris-notif" id="yurisNotif">
      <button type="button" class="yuris-notif-btn" id="yurisNotifBtn"
              aria-label="Notificações" aria-haspopup="true" aria-expanded="false" title="Notificações">
        <span class="yuris-notif-ico" aria-hidden="true"><?= $_svg['sino'] ?></span>
        <span class="yuris-notif-badge" id="yurisNotifBadge"<?= $_notifUnread > 0 ? '' : ' hidden' ?>><?= $_notifUnread > 99 ? '99+' : (int)$_notifUnread ?></span>
      </button>

      <div class="yuris-notif-panel" id="yurisNotifPanel" role="dialog" aria-label="Notificações" hidden>
        <div class="yuris-notif-head">
          <span class="yuris-notif-title">Notificações</span>
          <button type="button" class="yuris-notif-all" id="yurisNotifMarkAll"<?= $_notifUnread > 0 ? '' : ' disabled' ?>>Marcar todas como lidas</button>
        </div>
        <div class="yuris-notif-list" id="yurisNotifList">
          <?php if ($_notifItems === null): ?>
            <div class="yuris-notif-empty">Carregando…</div>
          <?php elseif (empty($_notifItems)): ?>
            <div class="yuris-notif-empty">Nenhuma notificação.</div>
          <?php else: foreach ($_notifItems as $__n):
              $__unread = ((int)($__n['lida'] ?? 0) === 0);
              // 'movimento' e acompanhamento do escritorio: fica mais apagado e
              // marcado, para o olho separar na hora do que e dirigido a voce.
              $__mov = (($__n['natureza'] ?? 'dirigido') === 'movimento');
              $__url = trim((string)($__n['url'] ?? '')); ?>
            <button type="button" class="yuris-notif-item<?= $__unread ? ' is-unread' : '' ?><?= $__mov ? ' is-mov' : '' ?>" data-id="<?= (int)($__n['id'] ?? 0) ?>" data-unread="<?= $__unread ? '1' : '0' ?>"<?= $__url !== '' ? ' data-url="' . htmlspecialchars($__url, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
              <span class="yuris-notif-item-titulo"><?php if ($__unread && !$__mov): ?><span class="yuris-notif-dot" aria-hidden="true"></span><?php endif; ?><?php if ($__mov): ?><span class="notif-tag-mov">movimento</span><?php endif; ?><?= htmlspecialchars((string)($__n['titulo'] ?? 'Notificação'), ENT_QUOTES, 'UTF-8') ?></span>
              <?php if (!empty($__n['mensagem'])): ?><p class="yuris-notif-item-msg"><?= htmlspecialchars((string)$__n['mensagem'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
              <span class="yuris-notif-item-time"><?= htmlspecialchars($_notifTempo($__n['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <style>
    /* Sino de notificações — estilo alinhado ao tema escuro da sidebar. */
    .yuris-notif { position: relative; margin-left: auto; }
    /* CRÍTICO: sem isto o display:flex/inline-flex abaixo VENCE o atributo [hidden]
       e o painel/badge ficam SEMPRE visíveis (era a causa do sino "espalhado"). */
    .yuris-notif-panel[hidden], .yuris-notif-badge[hidden] { display: none !important; }
    .yuris-notif-btn {
      position: relative; display: inline-flex; align-items: center; justify-content: center;
      width: 34px; height: 34px; padding: 0; border-radius: 9px;
      background: rgba(96,165,250,0.08); border: 1px solid rgba(96,165,250,0.18);
      color: #cbd9ec; cursor: pointer; transition: background .15s, color .15s, border-color .15s;
    }
    .yuris-notif-btn:hover { background: rgba(96,165,250,0.18); color: #e8f4ff; }
    .yuris-notif-btn.has-unread { color: #e8f4ff; }
    /* Movimento e acompanhamento, nao cobranca: chega, mas nao grita. */
    .yuris-notif-item.is-mov { opacity: .72; }
    .yuris-notif-item.is-mov:hover { opacity: 1; }
    .notif-tag-mov {
      display: inline-block; margin-right: 6px; padding: 1px 6px; border-radius: 999px;
      font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
      background: rgba(148,163,184,.16); border: 1px solid rgba(148,163,184,.3); color: #94a3b8;
      vertical-align: middle;
    }
    .yuris-notif-ico svg { width: 18px; height: 18px; display: block; }
    .yuris-notif-badge {
      position: absolute; top: -5px; right: -5px; min-width: 17px; height: 17px; padding: 0 4px;
      display: inline-flex; align-items: center; justify-content: center;
      background: #ef4444; color: #fff; font-size: 10px; font-weight: 700; line-height: 1;
      border-radius: 9px; border: 2px solid #0f172b; box-sizing: border-box;
    }
    /* position:fixed escapa do clipping/overflow da sidebar (antes cortava o painel).
       JS (positionPanel) ancora top/left no botão do sino; aqui só o fallback. */
    .yuris-notif-panel {
      position: fixed; z-index: 3000; top: 64px; left: 16px;
      width: 340px; max-width: calc(100vw - 16px); max-height: 72vh; overflow: hidden;
      display: flex; flex-direction: column;
      background: #0f172b; border: 1px solid rgba(96,165,250,0.25); border-radius: 12px;
      box-shadow: 0 16px 40px rgba(0,0,0,0.5);
      font-family: Inter, system-ui, sans-serif;
    }
    .yuris-notif-head {
      display: flex; align-items: center; justify-content: space-between; gap: 8px;
      padding: 12px 14px; border-bottom: 1px solid rgba(96,165,250,0.14);
    }
    .yuris-notif-title { font-size: 13px; font-weight: 700; color: #e8f4ff; letter-spacing: .3px; }
    .yuris-notif-all {
      background: none; border: none; color: #7eb8f7; font-size: 11px; font-weight: 600;
      cursor: pointer; padding: 0; transition: color .12s;
    }
    .yuris-notif-all:hover { color: #a9d0fb; text-decoration: underline; }
    .yuris-notif-all[disabled] { color: #4a5a72; cursor: default; text-decoration: none; }
    .yuris-notif-list { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 4px; }
    .yuris-notif-item {
      display: block; width: 100%; text-align: left; padding: 10px 10px; border: none;
      background: none; border-radius: 8px; cursor: pointer; transition: background .12s;
    }
    .yuris-notif-item + .yuris-notif-item { border-top: 1px solid rgba(96,165,250,0.08); }
    .yuris-notif-item:hover { background: rgba(96,165,250,0.10); }
    .yuris-notif-item.is-unread { background: rgba(96,165,250,0.06); }
    .yuris-notif-item-titulo {
      display: flex; align-items: flex-start; gap: 6px;
      font-size: 12.5px; font-weight: 600; color: #e8f4ff; margin: 0 0 3px;
      overflow-wrap: anywhere; line-height: 1.35;
    }
    .yuris-notif-dot { width: 7px; height: 7px; border-radius: 50%; background: #60a5fa; flex: 0 0 auto; margin-top: 5px; }
    .yuris-notif-item-msg { font-size: 11.5px; color: #9fb2cc; margin: 0 0 3px; line-height: 1.4; overflow-wrap: anywhere; }
    .yuris-notif-item-time { font-size: 10.5px; color: #6b8299; }
    .yuris-notif-empty { padding: 22px 14px; text-align: center; color: #6b8299; font-size: 12px; }
  </style>

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
    <?php if ($_isFleetiflow): ?><div class="sb-titulo">Geral</div><?php endif; ?>
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
        ['perm'=>'planejamento','href'=>'planejamento.php','active'=>'funil',     'icon'=>'funil',    'label'=>'Planejamento'],
        ['perm'=>'prospeccao', 'href'=>'prospeccao.php', 'active'=>'prospeccao', 'icon'=>'prosp',     'label'=>'Prospecção'],
        ['perm'=>'clientes',   'href'=>'clientes.php',   'active'=>'clientes',   'icon'=>'clientes',  'label'=>'Clientes'],
        ['perm'=>'tarefas',    'href'=>'tarefas.php',    'active'=>'tarefas',    'icon'=>'tarefas',   'label'=>'Tarefas'],
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
        // Relatorios ve quem ja enxerga PELO MENOS UMA das tres fontes. Nao
        // exigimos uma permissao nova e sozinha porque ela nasceria desmarcada
        // para todo mundo, e o escritorio inteiro concluiria que o modulo nao
        // foi entregue. A chave 'relatorios' existe e continua valendo: ela
        // aparece em perm_any e pode ser concedida explicitamente. O que ela
        // nao faz e AMPLIAR: cada fonte e filtrada pelo modulo dela dentro de
        // /api/relatorios.php.
        ['perm'=>null,'perm_any'=>['relatorios','clientes','prospeccao','processos'],
         'href'=>'relatorios.php','active'=>'relatorios','icon'=>'relatorios','label'=>'Relatórios'],
        // OTIF das tarefas: mesma permissao do modulo Tarefas, sem chave nova.
        ['perm'=>'tarefas',    'href'=>'desempenho.php', 'active'=>'desempenho',  'icon'=>'desempenho',  'label'=>'Desempenho'],
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

    // Conta Fleetiflow (CRM comercial) não enxerga o grupo Jurídico: nem o
    // link, nem a aba. Isolamento de dado já é automático por account_id;
    // isto é só a navegação não oferecer o que a conta não pode acessar.
    // Configurações e Sair vão para o rodapé fixo (mesmo desenho do app
    // Fleetiflow), então o grupo Sistema some do meio da lista para eles não
    // aparecerem duas vezes.
    if ($_isFleetiflow) {
        $_sections = array_values(array_filter($_sections, fn($_s) => $_s['key'] !== 'juridico' && $_s['key'] !== 'sistema'));

        // Financeiro em grupo próprio, logo depois de Gestão (pedido do dono do
        // produto, 2026-09-30): dinheiro não é mais um item perdido entre
        // Usuários e Escritórios. Mesmo item, mesma permissão, só muda de lugar.
        $_itemFinancas = null;
        foreach ($_sections as &$_s) {
            if ($_s['key'] !== 'gestao') continue;
            foreach ($_s['items'] as $_i => $_it) {
                if ($_it['href'] === 'financas.php') { $_itemFinancas = $_it; unset($_s['items'][$_i]); }
            }
            $_s['items'] = array_values($_s['items']);
        }
        unset($_s);
        if ($_itemFinancas) {
            $_posGestao = array_search('gestao', array_column($_sections, 'key'), true);
            array_splice($_sections, $_posGestao === false ? count($_sections) : $_posGestao + 1, 0, [[
                'key' => 'financeiro',
                'label' => 'Financeiro',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>',
                'items' => [$_itemFinancas],
            ]]);
        }
    }

    foreach ($_sections as $_sec):
        // Filtra items que o user pode ver
        $_visible = [];
        foreach ($_sec['items'] as $_it) {
            $_allowed = !empty($_it['always'])
                || (!empty($_it['admin_only']) && $_isAdmin)
                || (!empty($_it['perm']) && _sidebarCan($_it['perm']));
            // perm_any: basta UMA das chaves. Serve para item que agrega varios
            // modulos, como Relatorios, onde exigir uma permissao propria e
            // exclusiva esconderia a tela de quem ja pode ver o conteudo dela.
            if (!$_allowed && !empty($_it['perm_any']) && is_array($_it['perm_any'])) {
                foreach ($_it['perm_any'] as $_pk) {
                    if (_sidebarCan($_pk)) { $_allowed = true; break; }
                }
            }
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
     * Acesso EXCLUSIVO via portal isolado: /master_login.php
     * Mesmo super_admins não vêem link aqui. Garantia de "qualquer outra conta
     * não deve ter acesso ao painel master nunca" — não há trilha visual.
     */
    ?>

  </nav>

  <!-- ── Rodapé da sidebar ── -->
  <?php if ($_isFleetiflow): ?>
  <!-- Rodapé fixo, igual ao do app Fleetiflow: Configurações, Privacidade e
       Sair como linhas de menu; a pessoa logada (.sidebar-user) vem logo
       abaixo por `order` no CSS. Sem o letreiro "Fleetiflow / Central
       Comercial": a marca já está no topo. -->
  <div class="sidebar-footer sb-rodape">
    <?php if (_sidebarCan('configuracoes')): ?>
    <a href="configuracoes.php"<?= $_ap === 'configuracoes' ? ' class="active" aria-current="page"' : '' ?>>
      <span class="icon" aria-hidden="true"><?= $_svg['config'] ?></span>
      <span class="label">Configurações</span>
    </a>
    <a href="configuracoes/privacidade.php"<?= $_ap === 'privacidade' ? ' class="active" aria-current="page"' : '' ?> title="Privacidade e consentimentos LGPD">
      <span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
      <span class="label">Privacidade</span>
    </a>
    <?php endif; ?>
    <a href="logout.php" class="is-logout">
      <span class="icon" aria-hidden="true"><?= $_svg['sair'] ?></span>
      <span class="label">Sair</span>
    </a>
  </div>
  <?php else: ?>
  <div class="sidebar-footer" style="padding:10px 18px 0;text-align:center;border-top:1px solid rgba(96,165,250,0.1);">
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
  <?php endif; ?>

</aside>
<?php if ($_avisoWa): ?>
<!-- Aviso de WhatsApp fora do ar (ver o bloco PHP no topo). Nasce aqui e o script
     abaixo o coloca no topo da área de conteúdo, ao lado do menu. -->
<div id="avisoWhatsapp" role="alert" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 16px;padding:12px 16px;border-radius:12px;background:#FEF2F2;border:1px solid #FECACA;color:#7F1D1D;font-family:'Manrope',system-ui,sans-serif;font-size:.88rem;line-height:1.4">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#B91C1C" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  <div style="flex:1;min-width:220px">
    <strong style="color:#991B1B">WhatsApp desconectado.</strong>
    Mensagens novas não estão entrando no sistema, os cards não andam sozinhos e o agente de IA não responde<?= $_avisoWa['desde'] ? ' (último evento recebido em ' . htmlspecialchars($_avisoWa['desde']) . ')' : '' ?>.
    <?= $_avisoWa['admin'] ? 'Reconecte lendo o QR Code com o celular do número.' : 'Avise um administrador da conta para reconectar.' ?>
  </div>
  <?php if ($_avisoWa['admin']): ?>
  <a href="/chat.php" style="flex:none;display:inline-flex;align-items:center;height:34px;padding:0 16px;border-radius:999px;background:#B91C1C;color:#FFFFFF;font-weight:700;text-decoration:none">Reconectar agora</a>
  <?php endif; ?>
</div>
<script>
(function () {
  // Roda depois do carregamento: o menu vem ANTES do conteúdo no HTML, então
  // enquanto este script é lido a área de conteúdo ainda nem existe.
  function posicionar() {
  try {
    var aviso = document.getElementById('avisoWhatsapp');
    var menu  = document.querySelector('aside.sidebar');
    if (!aviso || !menu) return;
    // A área de conteúdo é o primeiro irmão do menu que não é script/estilo.
    var alvo = menu.nextElementSibling;
    while (alvo && /^(SCRIPT|STYLE|LINK|NAV|DIV)$/.test(alvo.tagName) && (alvo.tagName !== 'DIV' || alvo === aviso)) alvo = alvo.nextElementSibling;
    if (alvo) { alvo.insertBefore(aviso, alvo.firstChild); return; }
    // Sem irmão de conteúdo: fica fixo no rodapé da tela, sem cobrir o menu.
    aviso.style.cssText += ';position:fixed;left:276px;right:16px;bottom:16px;z-index:9000;margin:0;box-shadow:0 10px 30px rgba(127,29,29,.18)';
  } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', posicionar); else posicionar();
})();
</script>
<?php endif; ?>

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

  <?php if ($_isFleetiflow): ?>
  <a href="clientes.php"<?= $_ap === 'clientes' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['clientes'] ?></span>
    <span>Clientes</span>
  </a>

  <a href="tarefas.php"<?= $_ap === 'tarefas' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['tarefas'] ?></span>
    <span>Tarefas</span>
  </a>
  <?php else: ?>
  <a href="processos.php"<?= $_ap === 'processos' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['processos'] ?></span>
    <span>Processos</span>
  </a>

  <a href="juridico.php"<?= $_ap === 'juridico' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['juridico'] ?></span>
    <span>Jurídico</span>
  </a>
  <?php endif; ?>

<?php if (!($_isFleetiflow && !in_array($_SESSION['user_role'] ?? '', ['owner', 'admin'], true))): ?>
  <a href="financas.php"<?= $_ap === 'dre' ? ' class="active"' : '' ?>>
    <span class="mob-icon" aria-hidden="true"><?= $_svg['financas'] ?></span>
    <span>Finanças</span>
  </a>
<?php endif; ?>

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

<!-- Sino de notificações: config (token CSRF) + lógica. notifications.js lê window.YURIS_NOTIF. -->
<script>
window.YURIS_NOTIF = Object.assign({}, window.YURIS_NOTIF, {
  csrf: <?= json_encode($_notifCsrf) ?>,
  api:  '/api/account_notifications.php'
});
</script>
<script src="/assets/notifications.js?v=<?= $_notifJsVer ?>" defer></script>
