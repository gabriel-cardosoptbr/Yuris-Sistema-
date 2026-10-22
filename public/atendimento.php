<?php
/**
 * atendimento.php: métricas de atendimento do WhatsApp (Comunicação › Atendimento).
 *
 * Quanto tempo o lead esperou por resposta e como está cada operador: tempo
 * médio e mediana de resposta, % respondido em até 5 minutos, primeira resposta,
 * conversas sem resposta, quem está esperando agora. A conta e o porquê de cada
 * escolha estão em App\WhatsAppAgente\MetricasAtendimento.
 *
 * QUEM VÊ O QUÊ
 *   - precisa enxergar o Chat (admin, curinga ou permissão `chat`), a mesma regra
 *     do item WhatsApp no menu; sem permissão nova
 *   - dono/admin vê a equipe inteira dos números da conta e filtra por operador
 *   - o vendedor da edição CRM vê só o próprio atendimento (as conversas de que é
 *     dono ou que respondeu)
 *   - dono/admin configura o horário comercial da conta (POST nesta página);
 *     com ele ligado, as métricas contam só o tempo dentro do expediente, e a
 *     chave "Tempo corrido" mostra as 24 horas
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\WhatsAppAgente\MetricasAtendimento as M;
use App\WhatsAppAgente\WhatsAppChannelAccessService;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$isFleetiflow = $ctx->getProduto() === 'fleetiflow';

$perfilAdmin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
$perms       = (array) ($_SESSION['user_permissions'] ?? []);
if (!$perfilAdmin && !in_array('*', $perms, true) && !in_array('chat', $perms, true)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403</title>';
    echo '<style>body{font-family:system-ui;background:#0a0f1e;color:#cbd5e1;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>';
    echo '<div><h1 style="margin:0 0 8px">Acesso negado</h1><p>As métricas são calculadas sobre o Chat do WhatsApp, e você não tem acesso a esse módulo.</p></div>';
    exit;
}

$pdo = \App\Core\Database::getConnection();
$accountId = (int) $ctx->getAccountId();
$numeros = WhatsAppChannelAccessService::viewableChannelIds($pdo, $accountId);
$soVendedor = AccountContext::vendedorDaSessao();
$veEquipe = $soVendedor === null;
[$de, $ate] = M::periodo($_GET['de'] ?? null, $_GET['ate'] ?? null);
$foco   = $veEquipe && isset($_GET['operador']) && $_GET['operador'] !== '' ? max(0, (int) $_GET['operador']) : null;
$numero = isset($_GET['numero']) && $_GET['numero'] !== '' ? max(0, (int) $_GET['numero']) : null;

// Horário comercial da conta (configuracoes.atendimento.horario; padrão seg a sex, 08:00 às 18:00).
$podeConfigurar = $ctx->isOwnerOrAdmin() || $perfilAdmin;
$csrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'horario') {
    if (!$podeConfigurar || !hash_equals($csrf, (string) ($_POST['_csrf'] ?? ''))) { http_response_code(403); exit('Sem permissão.'); }
    $dias = [];
    foreach (M::DIAS_SEMANA as $n => $_) {
        if (!empty($_POST['dia'][$n])) $dias[$n] = [(string) ($_POST['ini'][$n] ?? ''), (string) ($_POST['fim'][$n] ?? '')];
    }
    M::salvarHorario($pdo, $accountId, ['ativo' => !empty($_POST['ativo']), 'dias' => $dias]);
    header('Location: /atendimento?' . http_build_query(array_filter(['de' => $_POST['de'] ?? null, 'ate' => $_POST['ate'] ?? null, 'salvo' => 1])));
    exit;
}
$horarioConta = M::horarioDaConta(\App\Master\Account::findById($accountId) ?? []);
$modo = ($_GET['modo'] ?? '') === 'corrido' || !$horarioConta['ativo'] ? 'corrido' : 'comercial';
$horarioUsado = $modo === 'comercial' ? $horarioConta : null;

$rel = M::relatorio($accountId, $numeros, $de, $ate, $soVendedor, $foco ?: null, $numero ?: null, $horarioUsado);
$eq  = $rel['foco'] ? $rel['foco']['metricas'] : $rel['equipe'];
$faixasMostradas = $rel['foco'] ? $rel['foco']['faixas'] : $rel['faixas'];
$serie = M::serieDiaria($rel['foco'] ? $rel['foco']['dias'] : $rel['dias'], $de, $ate);
$totalFaixas = array_sum($faixasMostradas);

$hoje = M::hoje();
$atalhos = [
    'Hoje'            => [$hoje, $hoje],
    'Últimos 7 dias'  => [date('Y-m-d', strtotime("$hoje -6 days")), $hoje],
    'Últimos 30 dias' => [date('Y-m-d', strtotime("$hoje -29 days")), $hoje],
    'Este mês'        => [date('Y-m-01', strtotime($hoje)), $hoje],
    'Mês passado'     => [date('Y-m-01', strtotime(date('Y-m-01', strtotime($hoje)) . ' -1 month')), date('Y-m-t', strtotime(date('Y-m-01', strtotime($hoje)) . ' -1 month'))],
];
$qsBase = ['de' => $de, 'ate' => $ate] + ($numero ? ['numero' => $numero] : []) + ($modo === 'corrido' && $horarioConta['ativo'] ? ['modo' => 'corrido'] : []);
$url = static fn(array $extra = []) => '/atendimento?' . http_build_query(array_merge($qsBase, $extra));
$activePage = 'atendimento';

function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pct(?float $v): string { return $v === null ? '-' : number_format($v, 0, ',', '.') . '%'; }
function dataBr(?string $v, bool $hora = true): string
{
    if (!$v) return '-';
    $t = strtotime($v);
    return $t ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $t) : '-';
}
$rotuloFaixa = ['bom' => 'Bom', 'atencao' => 'Atenção', 'ruim' => 'Crítico', 'sem' => 'Sem dados'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $isFleetiflow ? 'Atendimento · ' . e($ctx->getMarca()['nome']) . ' CRM' : 'Atendimento · Yuris' ?></title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
  <style>
    /* Os mesmos tokens da tela Desempenho (.o-*): escuro por padrão; o claro e o Fleetiflow só trocam valores. */
    :root{
      --o-fundo:#070F1C; --o-card:#0D1C30; --o-borda:rgba(160,180,210,.14); --o-sutil:rgba(160,180,210,.08);
      --o-texto:#E2E8F0; --o-forte:#F1F5F9; --o-fraco:#94A3B8; --o-apagado:#64748B;
      --o-acento:#3B82F6; --o-acento-suave:rgba(59,130,246,.16); --o-trilho:rgba(148,163,184,.14);
      --o-bom:#34D399; --o-bom-bg:rgba(52,211,153,.14);
      --o-atencao:#FBBF24; --o-atencao-bg:rgba(251,191,36,.14);
      --o-ruim:#F87171; --o-ruim-bg:rgba(248,113,113,.14);
      --o-input:#0A1626; --o-raio:14px; --o-fonte:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    html[data-theme="light"]{
      --o-fundo:#F4F6FA; --o-card:#FFFFFF; --o-borda:rgba(15,31,54,.10); --o-sutil:rgba(15,31,54,.06);
      --o-texto:#1E293B; --o-forte:#0F1F36; --o-fraco:#5A6B7E; --o-apagado:#8B97A8;
      --o-acento:#2563EB; --o-acento-suave:#DBEAFE; --o-trilho:#EEF1F5;
      --o-bom:#047857; --o-bom-bg:#DCFCE7; --o-atencao:#92400E; --o-atencao-bg:#FEF3C7;
      --o-ruim:#B91C1C; --o-ruim-bg:#FEE2E2; --o-input:#FFFFFF;
    }
    body{ margin:0; background:var(--o-fundo); color:var(--o-texto); font-family:var(--o-fonte); }
    .o-wrap{ padding:22px 26px 60px; }
    .o-topo{ display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .o-topo h1{ margin:0; font-size:22px; font-weight:800; color:var(--o-forte); letter-spacing:-.01em; }
    .o-topo p{ margin:4px 0 0; font-size:13px; color:var(--o-fraco); }
    .o-card{ background:var(--o-card); border:1px solid var(--o-borda); border-radius:var(--o-raio); padding:20px; min-width:0; }
    .o-card h2{ margin:0; font-size:16px; font-weight:700; color:var(--o-forte); }
    .o-card .o-sub{ margin:2px 0 0; font-size:12px; color:var(--o-fraco); }
    .o-filtros{ display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:16px; }
    .o-campo{ display:flex; flex-direction:column; gap:4px; }
    .o-campo label{ font-size:11px; font-weight:600; color:var(--o-fraco); text-transform:uppercase; letter-spacing:.05em; }
    .o-campo input, .o-campo select{ min-height:36px; padding:6px 10px; border-radius:10px; border:1px solid var(--o-borda); background:var(--o-input); color:var(--o-texto); font:inherit; font-size:13px; }
    .o-atalhos{ display:flex; gap:6px; flex-wrap:wrap; }
    .o-pilula{ display:inline-flex; align-items:center; height:32px; padding:0 12px; border-radius:999px; border:1px solid var(--o-borda); background:var(--o-card); color:var(--o-fraco); font-size:12px; font-weight:600; text-decoration:none; white-space:nowrap; }
    .o-pilula:hover{ color:var(--o-forte); border-color:var(--o-acento); }
    .o-pilula.ativa{ background:var(--o-acento-suave); color:var(--o-acento); border-color:transparent; }
    .o-acoes{ display:flex; gap:8px; margin-left:auto; }
    .o-btn{ display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:999px; border:1px solid var(--o-borda); background:var(--o-card); color:var(--o-texto); font:inherit; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
    .o-btn:hover{ border-color:var(--o-acento); color:var(--o-acento); }
    .o-btn.primario{ background:var(--o-acento); border-color:var(--o-acento); color:#fff; }
    .o-btn.primario:hover{ filter:brightness(1.08); color:#fff; }
    .o-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:16px; }
    .o-kpi{ display:flex; flex-direction:column; gap:12px; }
    .o-kpi .o-rot{ font-size:13px; font-weight:600; color:var(--o-fraco); display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .o-kpi .o-num{ font-size:30px; font-weight:800; line-height:1; letter-spacing:-.02em; color:var(--o-forte); }
    .o-kpi .o-det{ font-size:12px; color:var(--o-apagado); }
    .o-selo{ display:inline-flex; align-items:center; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
    .o-bom{ color:var(--o-bom); } .o-selo.o-bom{ background:var(--o-bom-bg); }
    .o-atencao{ color:var(--o-atencao); } .o-selo.o-atencao{ background:var(--o-atencao-bg); }
    .o-ruim{ color:var(--o-ruim); } .o-selo.o-ruim{ background:var(--o-ruim-bg); }
    .o-sem{ color:var(--o-apagado); } .o-selo.o-sem{ background:var(--o-sutil); }
    .o-grade{ display:grid; grid-template-columns:minmax(0,1fr); gap:16px; margin-bottom:16px; }
    .o-grade-2{ display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:16px; margin-bottom:16px; }
    @media (max-width:1100px){ .o-grade-2{ grid-template-columns:minmax(0,1fr); } }
    .o-rolagem{ overflow-x:auto; margin-top:14px; }
    .o-tabela{ width:100%; border-collapse:collapse; font-size:13px; min-width:720px; }
    .o-tabela th{ text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--o-apagado); padding:8px 10px; border-bottom:1px solid var(--o-borda); white-space:nowrap; }
    .o-tabela td{ padding:10px; border-bottom:1px solid var(--o-sutil); vertical-align:middle; white-space:nowrap; }
    .o-tabela td.num, .o-tabela th.num{ text-align:right; font-variant-numeric:tabular-nums; }
    .o-tabela tr.clicavel{ cursor:pointer; }
    .o-tabela tr.clicavel:hover td{ background:var(--o-sutil); }
    .o-tabela tr.focado td{ background:var(--o-acento-suave); }
    .o-tabela td.nome a{ color:var(--o-forte); font-weight:700; text-decoration:none; }
    .o-tabela td.quebra{ white-space:normal; min-width:200px; }
    .o-barra{ display:flex; align-items:center; gap:10px; min-width:170px; }
    .o-trilho{ flex:1; height:8px; border-radius:999px; background:var(--o-trilho); overflow:hidden; }
    .o-trilho > span{ display:block; height:100%; border-radius:999px; }
    .o-trilho > span.o-bom{ background:var(--o-bom); } .o-trilho > span.o-atencao{ background:var(--o-atencao); }
    .o-trilho > span.o-ruim{ background:var(--o-ruim); } .o-trilho > span.o-sem{ background:transparent; } .o-trilho > span.o-marca{ background:var(--o-acento); }
    .o-barra b{ min-width:64px; text-align:right; font-variant-numeric:tabular-nums; }
    .o-zero{ color:var(--o-apagado); }
    .o-vazio{ padding:34px 12px; text-align:center; color:var(--o-fraco); font-size:13px; }
    .o-aviso{ margin:0 0 16px; padding:10px 14px; border-radius:10px; font-size:12.5px; background:var(--o-atencao-bg); color:var(--o-atencao); }
    .o-explica summary{ cursor:pointer; font-weight:700; color:var(--o-forte); font-size:14px; }
    .o-explica ul{ margin:12px 0 0; padding-left:18px; color:var(--o-fraco); font-size:13px; line-height:1.7; }
    .o-explica b{ color:var(--o-texto); }
    .o-faixas{ display:flex; flex-direction:column; gap:10px; margin-top:14px; }
    .o-faixa{ display:grid; grid-template-columns:110px 1fr 56px; gap:10px; align-items:center; font-size:13px; }
    .o-faixa span:first-child{ color:var(--o-fraco); }
    .o-faixa b{ text-align:right; font-variant-numeric:tabular-nums; color:var(--o-forte); }
    .o-modo{ display:inline-flex; padding:3px; border-radius:999px; background:var(--o-sutil); gap:2px; }
    .o-modo a{ height:28px; display:inline-flex; align-items:center; padding:0 12px; border-radius:999px; font-size:12px; font-weight:700; color:var(--o-fraco); text-decoration:none; white-space:nowrap; }
    .o-modo a.ativa{ background:var(--o-card); color:var(--o-acento); box-shadow:0 1px 3px rgba(15,31,54,.12); }
    .o-ok{ margin:0 0 16px; padding:10px 14px; border-radius:10px; font-size:12.5px; background:var(--o-bom-bg); color:var(--o-bom); }
    .o-horario summary{ cursor:pointer; font-weight:700; color:var(--o-forte); font-size:14px; list-style:none; display:flex; justify-content:space-between; gap:12px; align-items:center; }
    .o-horario summary::-webkit-details-marker{ display:none; }
    .o-horario summary small{ font-weight:600; color:var(--o-fraco); font-size:12px; }
    .o-dias{ display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:10px; margin:14px 0; }
    .o-dia{ display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--o-borda); border-radius:10px; font-size:13px; }
    .o-dia label{ display:flex; align-items:center; gap:6px; flex:1 1 auto; min-width:0; font-weight:600; color:var(--o-forte); }
    .o-dia input[type=time]{ flex:none; width:104px; min-height:32px; padding:4px 6px; border-radius:8px; border:1px solid var(--o-borda); background:var(--o-input); color:var(--o-texto); font:inherit; font-size:12.5px; }
    .o-chave{ display:flex; align-items:center; gap:8px; font-size:13px; color:var(--o-forte); font-weight:600; }
  </style>
  <?php if ($isFleetiflow): ?>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html[data-theme="light"]{
      --o-fundo:#F6F7F9; --o-borda:rgba(17,29,45,.08); --o-sutil:rgba(17,29,45,.05);
      --o-texto:#575757; --o-forte:#3D3D3D; --o-fraco:#676767; --o-apagado:#767676;
      --o-acento:var(--ff-marca, #015DFC); --o-acento-suave:var(--ff-marca-suave, #D6E4FF); --o-trilho:#F1F1F2;
      --o-bom:#017801; --o-bom-bg:#DCFFDC; --o-atencao:#8F6000; --o-atencao-bg:#FFF6E6;
      --o-ruim:#B00000; --o-ruim-bg:#FFCCCC; --o-input:#F6F7F9;
      --o-fonte:'Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;
    }
  </style>
  <?php endif; ?>
</head>
<body>
  <main class="o-wrap">
    <div class="page-layout">
      <?php include __DIR__ . '/includes/sidebar.php'; ?>
      <div class="main-content">

    <div class="o-topo">
      <div>
        <h1><?= $veEquipe ? 'Atendimento da equipe' : 'Meu atendimento' ?></h1>
        <p>Quanto tempo o lead esperou por resposta no WhatsApp. <?= e(dataBr($de, false)) ?> a <?= e(dataBr($ate, false)) ?>,
          <?= $modo === 'comercial' ? 'contando só o horário comercial (' . e(M::descreverHorario($horarioConta)) . ')' : 'em tempo corrido, 24 horas por dia' ?>.</p>
      </div>
      <?php if ($horarioConta['ativo']): ?>
      <div class="o-modo" role="tablist" aria-label="Como contar o tempo">
        <a class="<?= $modo === 'comercial' ? 'ativa' : '' ?>" href="<?= e('/atendimento?' . http_build_query(array_merge(array_diff_key($qsBase, ['modo' => 1]), $foco ? ['operador' => $foco] : []))) ?>">Horário comercial</a>
        <a class="<?= $modo === 'corrido' ? 'ativa' : '' ?>" href="<?= e('/atendimento?' . http_build_query(array_merge($qsBase, ['modo' => 'corrido'], $foco ? ['operador' => $foco] : []))) ?>">Tempo corrido</a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($_GET['salvo'])): ?><p class="o-ok">Horário comercial salvo. As métricas abaixo já usam o expediente novo.</p><?php endif; ?>

    <form class="o-card o-filtros" method="get" action="/atendimento">
      <div class="o-campo"><label for="f-de">De</label><input type="date" id="f-de" name="de" value="<?= e($de) ?>"></div>
      <div class="o-campo"><label for="f-ate">Até</label><input type="date" id="f-ate" name="ate" value="<?= e($ate) ?>"></div>
      <?php if ($veEquipe): ?>
      <div class="o-campo">
        <label for="f-op">Operador</label>
        <select id="f-op" name="operador">
          <option value="">Equipe toda</option>
          <?php foreach ($rel['operadores_lista'] as $o): ?>
            <option value="<?= (int) $o['user_id'] ?>"<?= $foco === (int) $o['user_id'] ? ' selected' : '' ?>><?= e($o['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if (count($rel['numeros']) > 1): ?>
      <div class="o-campo">
        <label for="f-num">Número</label>
        <select id="f-num" name="numero">
          <option value="">Todos os números</option>
          <?php foreach ($rel['numeros'] as $n): ?>
            <option value="<?= (int) $n['id'] ?>"<?= $numero === (int) $n['id'] ? ' selected' : '' ?>><?= e($n['nome']) ?><?= $n['phone'] ? ' · ' . e(M::telefoneLegivel($n['phone'])) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="o-atalhos">
        <?php foreach ($atalhos as $rotulo => [$a, $b]): ?>
          <a class="o-pilula<?= ($a === $de && $b === $ate) ? ' ativa' : '' ?>" href="<?= e('/atendimento?' . http_build_query(array_merge(['de' => $a, 'ate' => $b], $foco ? ['operador' => $foco] : [], $numero ? ['numero' => $numero] : []))) ?>"><?= e($rotulo) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($modo === 'corrido' && $horarioConta['ativo']): ?><input type="hidden" name="modo" value="corrido"><?php endif; ?>
      <div class="o-acoes"><button type="submit" class="o-btn primario">Aplicar</button></div>
    </form>

    <?php if ($podeConfigurar): ?>
    <details class="o-card o-horario" style="margin-bottom:16px">
      <summary>Horário comercial <small><?= e(M::descreverHorario($horarioConta)) ?> · editar</small></summary>
      <form method="post" action="/atendimento">
        <input type="hidden" name="acao" value="horario">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="de" value="<?= e($de) ?>"><input type="hidden" name="ate" value="<?= e($ate) ?>">
        <p class="o-sub" style="margin-top:10px">Com o horário ligado, o tempo de resposta conta só o que cai dentro do expediente: mensagem às 22h respondida às 8h05 vale 5 minutos. Dia desmarcado é fechado.</p>
        <div class="o-dias">
          <?php foreach (M::DIAS_SEMANA as $n => $nomeDia): $j = $horarioConta['dias'][$n] ?? null; ?>
          <div class="o-dia">
            <label><input type="checkbox" name="dia[<?= $n ?>]" value="1"<?= $j ? ' checked' : '' ?>> <?= e($nomeDia) ?></label>
            <input type="time" name="ini[<?= $n ?>]" value="<?= e($j[0] ?? '08:00') ?>" aria-label="<?= e($nomeDia) ?>, início">
            <span class="o-zero">às</span>
            <input type="time" name="fim[<?= $n ?>]" value="<?= e($j[1] ?? '18:00') ?>" aria-label="<?= e($nomeDia) ?>, fim">
          </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <label class="o-chave"><input type="checkbox" name="ativo" value="1"<?= $horarioConta['ativo'] ? ' checked' : '' ?>> Contar só o horário comercial nas métricas</label>
          <button type="submit" class="o-btn primario">Salvar horário</button>
        </div>
      </form>
    </details>
    <?php endif; ?>

    <?php if (!$rel['tem_sent_by']): ?>
      <p class="o-aviso">A coluna que registra quem enviou cada mensagem ainda não existe neste banco (migration 165): as respostas valem para o dono da conversa.</p>
    <?php endif; ?>

    <section class="o-kpis">
      <?php $fx = M::faixa($eq['media']); ?>
      <div class="o-card o-kpi">
        <span class="o-rot">Tempo médio de resposta <span class="o-selo o-<?= $fx ?>"><?= $rotuloFaixa[$fx] ?></span></span>
        <span class="o-num o-<?= $fx ?>"><?= e(M::duracao($eq['media'])) ?></span>
        <span class="o-det">mediana <?= e(M::duracao($eq['mediana'])) ?> · maior espera respondida <?= e(M::duracao($eq['respostas'] ? $eq['maior'] : null)) ?></span>
      </div>
      <div class="o-card o-kpi">
        <span class="o-rot">Respondido em até <?= M::META_BOM ?> min <span class="o-selo o-<?= $eq['ate_meta_pct'] === null ? 'sem' : ($eq['ate_meta_pct'] >= 70 ? 'bom' : ($eq['ate_meta_pct'] >= 40 ? 'atencao' : 'ruim')) ?>">Meta</span></span>
        <span class="o-num"><?= pct($eq['ate_meta_pct']) ?></span>
        <span class="o-det"><?= (int) $eq['ate_meta'] ?> de <?= (int) $eq['respostas'] ?> resposta(s)</span>
      </div>
      <div class="o-card o-kpi">
        <span class="o-rot">Primeira resposta</span>
        <span class="o-num"><?= e(M::duracao($eq['media_primeira'])) ?></span>
        <span class="o-det">tempo médio até a primeira resposta em <?= (int) $eq['primeiras'] ?> conversa(s)</span>
      </div>
      <div class="o-card o-kpi">
        <span class="o-rot">Conversas</span>
        <span class="o-num"><?= (int) $eq['conversas'] ?></span>
        <span class="o-det"><?= (int) ($eq['recebidas'] ?? 0) ?> mensagem(ns) recebida(s) · <?= (int) $eq['enviadas'] ?> enviada(s) · <?= (int) $eq['sem_resposta'] ?> sem resposta</span>
      </div>
      <?php $esp = count($rel['aguardando']); $fxE = M::faixa($rel['aguardando_maior']); ?>
      <div class="o-card o-kpi">
        <span class="o-rot">Esperando agora <span class="o-selo o-<?= $esp ? $fxE : 'bom' ?>"><?= $esp ? 'maior ' . e(M::duracao($rel['aguardando_maior'])) : 'Tudo respondido' ?></span></span>
        <span class="o-num o-<?= $esp ? $fxE : 'bom' ?>"><?= $esp ?></span>
        <span class="o-det">contato(s) cuja última mensagem ainda não foi respondida</span>
      </div>
    </section>

    <div class="o-grade-2">
      <section class="o-card">
        <h2>Dia a dia</h2>
        <p class="o-sub"><?= $rel['foco'] ? e($rel['foco']['nome']) : 'Equipe toda' ?>: tempo médio de resposta (linha) e respostas dadas (barras).</p>
        <div style="margin-top:14px;height:260px"><canvas id="oDias"></canvas></div>
      </section>
      <section class="o-card">
        <h2>Quanto o lead esperou</h2>
        <p class="o-sub">Distribuição das <?= (int) $totalFaixas ?> resposta(s) do período.</p>
        <?php if (!$totalFaixas): ?><p class="o-vazio">Nenhuma resposta no período.</p><?php else: ?>
        <div class="o-faixas">
          <?php foreach (M::FAIXAS as [$k]): $n = (int) $faixasMostradas[$k]; $p = $n * 100 / $totalFaixas; $cls = $k === 'ate_5' ? 'bom' : ($k === 'ate_30' ? 'atencao' : 'ruim'); ?>
          <div class="o-faixa"><span><?= e(M::ROTULO_FAIXA[$k]) ?></span><div class="o-trilho"><span class="o-<?= $cls ?>" style="width:<?= round($p, 1) ?>%"></span></div><b><?= $n ?> <span class="o-zero" style="font-weight:500">(<?= round($p) ?>%)</span></b></div>
          <?php endforeach; ?>
        </div>
        <?php if ($rel['bot']['respostas']): ?>
        <p class="o-sub" style="margin-top:14px">O agente de IA respondeu outras <?= (int) $rel['bot']['respostas'] ?> vez(es), em <?= e(M::duracao($rel['bot']['soma'] / $rel['bot']['respostas'])) ?> em média. Não entram no tempo dos operadores.</p>
        <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>

    <div class="o-grade">
      <?php if ($veEquipe): ?>
      <section class="o-card">
        <h2>Por operador</h2>
        <p class="o-sub">Clique numa linha para ver só esse operador. Quem respondeu pelo Chat conta para si; o resto conta para o dono da conversa.</p>
        <?php if (!$rel['operadores']): ?>
          <p class="o-vazio">Nenhuma mensagem no período.</p>
        <?php else: ?>
        <div class="o-rolagem">
          <table class="o-tabela">
            <thead><tr>
              <th>Operador</th><th>Tempo médio</th><th class="num">Mediana</th><th class="num">Até <?= M::META_BOM ?> min</th><th class="num">Primeira resposta</th>
              <th class="num">Respostas</th><th class="num">Conversas</th><th class="num">Enviadas</th><th class="num">Sem resposta</th>
            </tr></thead>
            <tbody>
            <?php $maiorMedia = max(1.0, (float) max(array_map(fn($o) => (float) ($o['media'] ?? 0), $rel['operadores'])));
            foreach ($rel['operadores'] as $o):
                $link = $o['user_id'] ? $url(['operador' => $o['user_id']]) : null;
                $zero = static fn($n) => (int) $n === 0 ? '<span class="o-zero">0</span>' : (string) (int) $n;
                $larg = $o['media'] !== null ? min(100, $o['media'] * 100 / $maiorMedia) : 0; ?>
              <tr class="<?= $link ? 'clicavel' : '' ?><?= $foco === (int) $o['user_id'] && $o['user_id'] ? ' focado' : '' ?>"<?= $link ? ' data-href="' . e($link) . '"' : '' ?>>
                <td class="nome"><?= $link ? '<a href="' . e($link) . '">' . e($o['nome']) . '</a>' : '<span class="o-zero">' . e($o['nome']) . '</span>' ?></td>
                <td><div class="o-barra"><div class="o-trilho"><span class="o-<?= $o['faixa'] ?>" style="width:<?= round($larg, 1) ?>%"></span></div><b class="o-<?= $o['faixa'] ?>"><?= e(M::duracao($o['media'])) ?></b></div></td>
                <td class="num"><?= e(M::duracao($o['mediana'])) ?></td>
                <td class="num"><?= pct($o['ate_meta_pct']) ?></td>
                <td class="num"><?= e(M::duracao($o['media_primeira'])) ?></td>
                <td class="num"><?= $zero($o['respostas']) ?></td>
                <td class="num"><?= $zero($o['conversas']) ?></td>
                <td class="num"><?= $zero($o['enviadas']) ?></td>
                <td class="num"><?= $zero($o['sem_resposta']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="o-card" id="esperando">
        <h2>Quem está esperando agora</h2>
        <p class="o-sub">Contatos cuja última mensagem é deles e ainda não teve resposta, do que espera há mais tempo para o mais recente.</p>
        <?php if (!$rel['aguardando']): ?>
          <p class="o-vazio">Ninguém esperando. Tudo respondido.</p>
        <?php else: ?>
        <div class="o-rolagem">
          <table class="o-tabela" style="min-width:640px">
            <thead><tr><th>Contato</th><th>Esperando há</th><th>Desde</th><th>Operador</th><?php if (count($rel['numeros']) > 1): ?><th>Número</th><?php endif; ?><th></th></tr></thead>
            <tbody>
            <?php foreach ($rel['aguardando'] as $a): ?>
              <tr>
                <td class="quebra" style="font-weight:600;color:var(--o-forte)"><?= e($a['nome']) ?></td>
                <td><span class="o-selo o-<?= $a['faixa'] ?>"><?= e(M::duracao($a['minutos'])) ?></span></td>
                <td><?= e(dataBr($a['desde'])) ?></td>
                <td><?= e($a['operador']) ?></td>
                <?php if (count($rel['numeros']) > 1): ?><td><?= e($a['numero']) ?></td><?php endif; ?>
                <td class="num"><a class="o-pilula" href="/chat?channel_id=<?= (int) $a['instance_id'] ?>">Abrir o Chat</a><?php if ($a['card_id']): ?> <a class="o-pilula" href="/prospeccao?open=<?= (int) $a['card_id'] ?>">Lead</a><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>
    </div>

    <details class="o-card o-explica">
      <summary>Como o tempo de resposta é calculado</summary>
      <ul>
        <li>Numa conversa, a <b>primeira mensagem do contato sem resposta</b> abre uma espera; a próxima mensagem nossa fecha, e o tempo entre as duas é o <b>tempo de resposta</b>. Se o contato manda várias seguidas, conta uma espera só, a partir da primeira.</li>
        <li><b>Tempo médio</b> e <b>mediana</b> somam todas as respostas do período. A mediana ignora um ou outro caso extremo.</li>
        <li><b>Primeira resposta</b>: só a primeira resposta de cada conversa no período, o tempo que o lead novo esperou para ser atendido.</li>
        <li><b>Até <?= M::META_BOM ?> min</b>: parte das respostas dadas em até <?= M::META_BOM ?> minutos. Faixas do tempo médio: <span class="o-bom">bom até <?= M::META_BOM ?> min</span>, <span class="o-atencao">atenção até <?= M::META_ATENCAO ?> min</span>, <span class="o-ruim">crítico acima disso</span>.</li>
        <li><b>Quem respondeu</b>: a mensagem enviada pelo Chat conta para quem a digitou. Mensagem do agente de IA fecha a espera do lead mas fica fora do tempo dos operadores. O resto (automação, mensagem antiga) conta para o dono da conversa.</li>
        <li><b>Sem resposta</b>: conversa que terminou o período com a mensagem do contato ainda sem retorno. <b>Esperando agora</b> olha o momento atual, não o período.</li>
        <li><b>Horário comercial</b>: com ele ligado, só conta o tempo dentro do expediente da conta (<?= e(M::descreverHorario($horarioConta)) ?>). Mensagem fora do expediente começa a contar quando ele abre. A chave <b>Tempo corrido</b> mostra as 24 horas, madrugada e fim de semana incluídos.</li>
        <li>Os horários são de Brasília. Grupos ficam de fora. Conversa excluída no Chat continua contando: as mensagens ficam guardadas.</li>
      </ul>
    </details>

      </div>
    </div>
  </main>

  <script>
  (function(){
    document.querySelectorAll('.o-tabela tr.clicavel').forEach(function(tr){
      tr.addEventListener('click', function(ev){ if (ev.target.closest('a')) return; location.href = tr.getAttribute('data-href'); });
    });
    var s = <?= json_encode($serie, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var el = document.getElementById('oDias');
    if (!el || typeof Chart === 'undefined') return;
    var css = getComputedStyle(document.documentElement);
    var v = function(n){ return css.getPropertyValue(n).trim(); };
    Chart.defaults.font.family = v('--o-fonte');
    Chart.defaults.color = v('--o-fraco');
    var acento = v('--amb-c') || v('--o-acento');
    var duracao = function(m){ if (m === null || m === undefined) return '-'; m = Math.round(m); if (m < 1) return '< 1 min'; if (m < 60) return m + ' min'; var h = Math.floor(m / 60), r = m % 60; if (h < 24) return h + ' h ' + String(r).padStart(2, '0'); return Math.floor(h / 24) + ' d ' + String(h % 24).padStart(2, '0') + ' h'; };
    new Chart(el, {
      data: {
        labels: s.rotulos,
        datasets: [
          { type: 'line', label: 'Tempo médio (min)', data: s.media, yAxisID: 'y', borderColor: acento, backgroundColor: acento, borderWidth: 2, tension: .3, pointRadius: 3, pointBackgroundColor: v('--o-card'), pointBorderColor: acento, pointBorderWidth: 2, spanGaps: true },
          { type: 'bar', label: 'Respostas', data: s.respostas, yAxisID: 'y2', backgroundColor: v('--o-trilho'), borderRadius: 4, barPercentage: .7 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        scales: {
          y:  { position: 'left', min: 0, ticks: { callback: function(x){ return duracao(x); } }, grid: { color: v('--o-sutil') } },
          y2: { position: 'right', min: 0, ticks: { precision: 0 }, grid: { display: false } },
          x:  { grid: { display: false } }
        },
        plugins: {
          legend: { position: 'top', align: 'start', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8 } },
          tooltip: { callbacks: { label: function(c){ return ' ' + c.dataset.label + ': ' + (c.dataset.yAxisID === 'y' ? duracao(c.raw) : c.raw); } } }
        }
      }
    });
  })();
  </script>
</body>
</html>
