<?php
/**
 * desempenho.php: o OTIF (On Time In Full) das tarefas, por colaborador.
 *
 * On Time = entregue até o prazo. In Full = entregue com o checklist inteiro.
 * A conta, as regras e o porquê de cada escolha estão em App\Tarefas\Otif; a
 * foto de cada entrega é gravada por App\Tarefas\TaskEntrega.
 *
 * QUEM VÊ O QUÊ
 *   - precisa enxergar Tarefas (admin, curinga ou permissão `tarefas`), a mesma
 *     regra do item Tarefas no menu. Não há permissão nova: ela nasceria
 *     desmarcada para todo mundo e o módulo pareceria não entregue.
 *   - dono/admin vê a equipe inteira das contas acessíveis e abre o detalhe de
 *     qualquer um
 *   - os demais veem só o próprio desempenho
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\Tarefas\Otif;
use App\Tarefas\TaskEntrega;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
$isFleetiflow = $ctx->getProduto() === 'fleetiflow';

$perfilAdmin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
$perms       = (array) ($_SESSION['user_permissions'] ?? []);
if (!$perfilAdmin && !in_array('*', $perms, true) && !in_array('tarefas', $perms, true)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403</title>';
    echo '<style>body{font-family:system-ui;background:#0a0f1e;color:#cbd5e1;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>';
    echo '<div><h1 style="margin:0 0 8px">Acesso negado</h1><p>O desempenho é calculado sobre as Tarefas, e você não tem acesso a esse módulo.<br>Solicite ao administrador.</p></div>';
    exit;
}

$veEquipe = $ctx->isOwnerOrAdmin() || $perfilAdmin;
[$de, $ate] = Otif::periodo($_GET['de'] ?? null, $_GET['ate'] ?? null);
$foco = $veEquipe && isset($_GET['colaborador']) && $_GET['colaborador'] !== '' ? max(0, (int) $_GET['colaborador']) : null;

$rel = Otif::relatorio(
    $ctx->getAccessibleAccountIds('tarefas'),
    $de, $ate,
    $veEquipe ? null : $ctx->getUserId(),
    $foco
);
$eq = $veEquipe ? $rel['equipe'] : ($rel['foco']['metricas'] ?? $rel['equipe']);

// Atalhos de período, calculados no fuso local.
$hoje   = substr(TaskEntrega::agoraLocal(), 0, 10);
$atalhos = [
    'Últimos 30 dias' => [date('Y-m-d', strtotime("$hoje -29 days")), $hoje],
    'Este mês'        => [date('Y-m-01', strtotime($hoje)), $hoje],
    'Mês passado'     => [date('Y-m-01', strtotime(date('Y-m-01', strtotime($hoje)) . ' -1 month')), date('Y-m-t', strtotime(date('Y-m-01', strtotime($hoje)) . ' -1 month'))],
    'Últimos 90 dias' => [date('Y-m-d', strtotime("$hoje -89 days")), $hoje],
];

$qsBase = ['de' => $de, 'ate' => $ate];
$url    = static fn(array $extra = []) => '/desempenho.php?' . http_build_query(array_merge($qsBase, $extra));
$csvUrl = '/api/otif.php?' . http_build_query(array_merge($qsBase, ['formato' => 'csv'], $foco !== null ? ['colaborador' => $foco] : []));

$activePage = 'desempenho';

function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pct(?float $v): string { return $v === null ? '-' : number_format($v, 1, ',', '.') . '%'; }
function dataBr(?string $v, bool $hora = true): string
{
    if (!$v) return '-';
    $t = strtotime($v);
    return $t ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $t) : '-';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $isFleetiflow ? 'Desempenho · Fleetiflow CRM' : 'Desempenho · Yuris' ?></title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
  <style>
    /* Tokens da tela. Escuro é o padrão do Yuris; o claro e o Fleetiflow só
       trocam os valores, os componentes são os mesmos. */
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
    .o-campo input, .o-campo select{ min-height:36px; padding:6px 10px; border-radius:10px; border:1px solid var(--o-borda);
      background:var(--o-input); color:var(--o-texto); font:inherit; font-size:13px; }
    .o-atalhos{ display:flex; gap:6px; flex-wrap:wrap; }
    .o-pilula{ display:inline-flex; align-items:center; height:32px; padding:0 12px; border-radius:999px; border:1px solid var(--o-borda);
      background:var(--o-card); color:var(--o-fraco); font-size:12px; font-weight:600; text-decoration:none; white-space:nowrap; }
    .o-pilula:hover{ color:var(--o-forte); border-color:var(--o-acento); }
    .o-pilula.ativa{ background:var(--o-acento-suave); color:var(--o-acento); border-color:transparent; }
    .o-acoes{ display:flex; gap:8px; margin-left:auto; }
    .o-btn{ display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:999px; border:1px solid var(--o-borda);
      background:var(--o-card); color:var(--o-texto); font:inherit; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
    .o-btn:hover{ border-color:var(--o-acento); color:var(--o-acento); }
    .o-btn.primario{ background:var(--o-acento); border-color:var(--o-acento); color:#fff; }
    .o-btn.primario:hover{ filter:brightness(1.08); color:#fff; }

    .o-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:16px; }
    .o-kpi{ display:flex; flex-direction:column; gap:12px; }
    .o-kpi .o-rot{ font-size:13px; font-weight:600; color:var(--o-fraco); display:flex; justify-content:space-between; align-items:center; }
    .o-kpi .o-num{ font-size:30px; font-weight:800; line-height:1; letter-spacing:-.02em; color:var(--o-forte); }
    .o-kpi .o-det{ font-size:12px; color:var(--o-apagado); }
    .o-selo{ display:inline-flex; align-items:center; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
    .o-bom{ color:var(--o-bom); } .o-selo.o-bom{ background:var(--o-bom-bg); }
    .o-atencao{ color:var(--o-atencao); } .o-selo.o-atencao{ background:var(--o-atencao-bg); }
    .o-ruim{ color:var(--o-ruim); } .o-selo.o-ruim{ background:var(--o-ruim-bg); }
    .o-sem{ color:var(--o-apagado); } .o-selo.o-sem{ background:var(--o-sutil); }

    /* Uma coluna: o ranking tem 9 colunas e precisa da largura toda. */
    .o-grade{ display:grid; grid-template-columns:minmax(0,1fr); gap:16px; margin-bottom:16px; }

    .o-rolagem{ overflow-x:auto; margin-top:14px; }
    .o-tabela{ width:100%; border-collapse:collapse; font-size:13px; min-width:760px; }
    .o-tabela th{ text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--o-apagado);
      padding:8px 10px; border-bottom:1px solid var(--o-borda); white-space:nowrap; }
    .o-tabela td{ padding:10px; border-bottom:1px solid var(--o-sutil); vertical-align:middle; white-space:nowrap; }
    .o-tabela td.num, .o-tabela th.num{ text-align:right; font-variant-numeric:tabular-nums; }
    .o-tabela tr.clicavel{ cursor:pointer; }
    .o-tabela tr.clicavel:hover td{ background:var(--o-sutil); }
    .o-tabela tr.focado td{ background:var(--o-acento-suave); }
    .o-tabela td.nome a{ color:var(--o-forte); font-weight:700; text-decoration:none; }
    .o-tabela td.quebra{ white-space:normal; min-width:220px; }
    .o-barra{ display:flex; align-items:center; gap:10px; min-width:170px; }
    .o-trilho{ flex:1; height:8px; border-radius:999px; background:var(--o-trilho); overflow:hidden; }
    .o-trilho > span{ display:block; height:100%; border-radius:999px; }
    .o-trilho > span.o-bom{ background:var(--o-bom); } .o-trilho > span.o-atencao{ background:var(--o-atencao); }
    .o-trilho > span.o-ruim{ background:var(--o-ruim); } .o-trilho > span.o-sem{ background:transparent; }
    .o-barra b{ min-width:48px; text-align:right; font-variant-numeric:tabular-nums; }
    .o-zero{ color:var(--o-apagado); }
    .o-vazio{ padding:34px 12px; text-align:center; color:var(--o-fraco); font-size:13px; }
    .o-aviso{ margin:0 0 16px; padding:10px 14px; border-radius:10px; font-size:12.5px; background:var(--o-atencao-bg); color:var(--o-atencao); }
    .o-tag{ display:inline-block; margin-left:6px; padding:1px 7px; border-radius:999px; font-size:10px; font-weight:700; background:var(--o-sutil); color:var(--o-apagado); }
    .o-explica summary{ cursor:pointer; font-weight:700; color:var(--o-forte); font-size:14px; }
    .o-explica ul{ margin:12px 0 0; padding-left:18px; color:var(--o-fraco); font-size:13px; line-height:1.7; }
    .o-explica b{ color:var(--o-texto); }
    .o-mini{ display:flex; gap:18px; flex-wrap:wrap; margin-top:12px; font-size:13px; color:var(--o-fraco); }
    .o-mini b{ color:var(--o-forte); font-size:15px; }
  </style>
  <?php if ($isFleetiflow): ?>
  <!-- Identidade Fleetiflow (tokens.css / superficies.ts do app real): Manrope,
       canvas #F6F7F9, marca #015DFC, semânticas good/alert/bad. Só troca tokens. -->
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    html[data-theme="light"]{
      --o-fundo:#F6F7F9; --o-borda:rgba(17,29,45,.08); --o-sutil:rgba(17,29,45,.05);
      --o-texto:#575757; --o-forte:#3D3D3D; --o-fraco:#676767; --o-apagado:#767676;
      --o-acento:#015DFC; --o-acento-suave:#D6E4FF; --o-trilho:#F1F1F2;
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
        <h1><?= $veEquipe ? 'Desempenho da equipe' : 'Meu desempenho' ?></h1>
        <p>OTIF das tarefas: entregue <b>no prazo</b> e <b>completa</b>. <?= e(dataBr($de, false)) ?> a <?= e(dataBr($ate, false)) ?>.</p>
      </div>
    </div>

    <form class="o-card o-filtros" method="get" action="/desempenho.php">
      <div class="o-campo">
        <label for="f-de">De</label>
        <input type="date" id="f-de" name="de" value="<?= e($de) ?>">
      </div>
      <div class="o-campo">
        <label for="f-ate">Até</label>
        <input type="date" id="f-ate" name="ate" value="<?= e($ate) ?>">
      </div>
      <?php if ($veEquipe): ?>
      <div class="o-campo">
        <label for="f-col">Colaborador</label>
        <select id="f-col" name="colaborador">
          <option value="">Equipe toda</option>
          <?php foreach ($rel['colaboradores'] as $c): ?>
            <option value="<?= (int) $c['user_id'] ?>"<?= $foco === (int) $c['user_id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="o-atalhos">
        <?php foreach ($atalhos as $rotulo => [$a, $b]): ?>
          <a class="o-pilula<?= ($a === $de && $b === $ate) ? ' ativa' : '' ?>" href="<?= e('/desempenho.php?' . http_build_query(array_merge(['de' => $a, 'ate' => $b], $foco !== null ? ['colaborador' => $foco] : []))) ?>"><?= e($rotulo) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="o-acoes">
        <a class="o-btn" href="<?= e($csvUrl) ?>">Baixar planilha</a>
        <button type="submit" class="o-btn primario">Aplicar</button>
      </div>
    </form>

    <?php if ($eq['retroativas'] > 0): ?>
      <p class="o-aviso">
        <?= (int) $eq['retroativas'] ?> registro(s) deste período são anteriores à gravação automática e foram
        reconstruídos pelo histórico. Para eles, o checklist considerado é o atual, não o do dia da entrega.
      </p>
    <?php endif; ?>

    <section class="o-kpis">
      <?php $fx = Otif::faixa($eq['otif_pct']); ?>
      <div class="o-card o-kpi">
        <span class="o-rot">OTIF <span class="o-selo o-<?= $fx ?>"><?= ['bom' => 'Bom', 'atencao' => 'Atenção', 'ruim' => 'Crítico', 'sem' => 'Sem dados'][$fx] ?></span></span>
        <span class="o-num o-<?= $fx ?>"><?= pct($eq['otif_pct']) ?></span>
        <span class="o-det"><?= (int) $eq['otif'] ?> de <?= (int) $eq['compromissos'] ?> no prazo e completas</span>
      </div>
      <div class="o-card o-kpi">
        <span class="o-rot">No prazo <span class="o-selo o-<?= Otif::faixa($eq['ot_pct']) ?>">On Time</span></span>
        <span class="o-num"><?= pct($eq['ot_pct']) ?></span>
        <span class="o-det"><?= (int) $eq['atrasadas'] ?> entregue(s) com atraso</span>
      </div>
      <div class="o-card o-kpi">
        <span class="o-rot">Completas <span class="o-selo o-<?= Otif::faixa($eq['if_pct']) ?>">In Full</span></span>
        <span class="o-num"><?= pct($eq['if_pct']) ?></span>
        <span class="o-det"><?= (int) $eq['incompletas'] ?> entregue(s) com checklist incompleto</span>
      </div>
      <div class="o-card o-kpi">
        <span class="o-rot">Compromissos</span>
        <span class="o-num"><?= (int) $eq['compromissos'] ?></span>
        <span class="o-det"><?= (int) $eq['nao_entregues'] ?> não entregue(s) · <?= (int) $eq['sem_prazo'] ?> entregue(s) sem prazo</span>
      </div>
    </section>

    <div class="o-grade">
      <?php if ($veEquipe): ?>
      <section class="o-card">
        <h2>Ranking por colaborador</h2>
        <p class="o-sub">Clique numa linha para ver o que tirou o OTIF de cada um.</p>
        <?php if (!$rel['colaboradores']): ?>
          <p class="o-vazio">Nenhuma tarefa com prazo entregue ou vencida neste período.</p>
        <?php else: ?>
        <div class="o-rolagem">
          <table class="o-tabela">
            <thead><tr>
              <th>Colaborador</th><th>OTIF</th><th class="num">Compromissos</th><th class="num">No prazo</th>
              <th class="num">Completas</th><th class="num">Atrasadas</th><th class="num">Incompletas</th>
              <th class="num">Não entregues</th><th class="num">Sem prazo</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rel['colaboradores'] as $c):
                $fxc  = Otif::faixa($c['otif_pct']);
                $link = $url(['colaborador' => $c['user_id']]) . '#detalhe';
                $zero = static fn($n) => (int) $n === 0 ? '<span class="o-zero">0</span>' : (string) (int) $n; ?>
              <tr class="clicavel<?= $foco === (int) $c['user_id'] ? ' focado' : '' ?>" data-href="<?= e($link) ?>">
                <td class="nome"><a href="<?= e($link) ?>"><?= e($c['nome']) ?></a></td>
                <td><div class="o-barra"><div class="o-trilho"><span class="o-<?= $fxc ?>" style="width:<?= (float) ($c['otif_pct'] ?? 0) ?>%"></span></div><b class="o-<?= $fxc ?>"><?= pct($c['otif_pct']) ?></b></div></td>
                <td class="num"><?= $zero($c['compromissos']) ?></td>
                <td class="num"><?= pct($c['ot_pct']) ?></td>
                <td class="num"><?= pct($c['if_pct']) ?></td>
                <td class="num"><?= $zero($c['atrasadas']) ?></td>
                <td class="num"><?= $zero($c['incompletas']) ?></td>
                <td class="num"><?= $zero($c['nao_entregues']) ?></td>
                <td class="num"><?= $zero($c['sem_prazo']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="o-card">
        <h2>Evolução mês a mês</h2>
        <p class="o-sub"><?= $rel['foco'] ? e($rel['foco']['nome']) : 'Equipe toda' ?>, últimos <?= Otif::MESES_EVOLUCAO ?> meses até <?= e(dataBr($ate, false)) ?>.</p>
        <div style="margin-top:14px;height:260px"><canvas id="oEvolucao"></canvas></div>
      </section>
    </div>

    <?php if ($rel['foco']): $fm = $rel['foco']['metricas']; ?>
    <section class="o-card" id="detalhe" style="margin-bottom:16px">
      <h2><?= $veEquipe ? 'O que tirou o OTIF: ' . e($rel['foco']['nome']) : 'O que tirou o seu OTIF' ?></h2>
      <p class="o-sub">Entregas fora do prazo, incompletas ou não entregues no período. Mais recentes primeiro.</p>
      <div class="o-mini">
        <span>OTIF <b class="o-<?= Otif::faixa($fm['otif_pct']) ?>"><?= pct($fm['otif_pct']) ?></b></span>
        <span>No prazo <b><?= pct($fm['ot_pct']) ?></b></span>
        <span>Completas <b><?= pct($fm['if_pct']) ?></b></span>
        <span>Compromissos <b><?= (int) $fm['compromissos'] ?></b></span>
        <span>Não entregues <b><?= (int) $fm['nao_entregues'] ?></b></span>
      </div>
      <?php if (!$rel['foco']['pendencias']): ?>
        <p class="o-vazio">Nada fora do OTIF neste período.</p>
      <?php else: ?>
      <div class="o-rolagem">
        <table class="o-tabela">
          <thead><tr><th>Tarefa</th><th>Prazo</th><th>Entregue em</th><th>Motivo</th></tr></thead>
          <tbody>
          <?php foreach ($rel['foco']['pendencias'] as $p): ?>
            <tr>
              <td class="quebra"><span style="font-weight:600;color:var(--o-forte)"><?= e($p['titulo']) ?></span><?php if ($p['estimado']): ?><span class="o-tag" title="Reconstruído pelo histórico: checklist atual">estimado</span><?php endif; ?></td>
              <td><?= e(dataBr($p['prazo'])) ?></td>
              <td><?= e(dataBr($p['entregue'])) ?></td>
              <td class="quebra <?= $p['tipo'] === 'concluida' ? 'o-atencao' : 'o-ruim' ?>"><?= e($p['motivo']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <details class="o-card o-explica">
      <summary>Como o OTIF é calculado</summary>
      <ul>
        <li><b>Compromisso</b> é toda tarefa com prazo que foi entregue ou que venceu sem entrega no período.</li>
        <li><b>No prazo (On Time)</b>: concluída até a data e hora do prazo, no horário de Brasília.</li>
        <li><b>Completa (In Full)</b>: concluída com todos os itens do checklist marcados. Tarefa sem checklist conta como completa.</li>
        <li><b>OTIF</b> = entregas no prazo <b>e</b> completas ÷ compromissos. Não entregue conta contra, para ninguém melhorar a nota deixando vencer.</li>
        <li><b>Não entregue</b>: tarefa que segue aberta com o prazo vencido, ou ocorrência recorrente que venceu e foi renovada sem conclusão.</li>
        <li>Cada tarefa conta para o <b>responsável</b> no momento da entrega. Entregue cai no dia da entrega; não entregue, no dia do prazo.</li>
        <li>Tarefas <b>sem prazo</b> não entram no OTIF e aparecem à parte, na coluna "Sem prazo".</li>
        <li>Faixas: <span class="o-bom">bom a partir de <?= (int) Otif::META_BOM ?>%</span>, <span class="o-atencao">atenção de <?= (int) Otif::META_ATENCAO ?>% a <?= (int) Otif::META_BOM - 1 ?>%</span>, <span class="o-ruim">crítico abaixo de <?= (int) Otif::META_ATENCAO ?>%</span>.</li>
        <li>A foto do prazo e do checklist é gravada na hora da conclusão: marcar item depois não muda o resultado.</li>
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

    var ev = <?= json_encode($rel['evolucao'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var el = document.getElementById('oEvolucao');
    if (!el || typeof Chart === 'undefined') return;
    var css = getComputedStyle(document.documentElement);
    var v = function(n){ return css.getPropertyValue(n).trim(); };
    Chart.defaults.font.family = v('--o-fonte');
    Chart.defaults.color = v('--o-fraco');
    var serie = function(rot, dados, cor, tracejado){
      return { label: rot, data: dados, borderColor: cor, backgroundColor: cor, borderWidth: 2, tension: 0,
               borderDash: tracejado ? [5, 4] : [], pointRadius: 3.5, pointBackgroundColor: v('--o-card'),
               pointBorderColor: cor, pointBorderWidth: 2, spanGaps: true };
    };
    new Chart(el, {
      type: 'line',
      data: { labels: ev.rotulos, datasets: [
        serie('OTIF', ev.otif, v('--o-acento'), false),
        serie('No prazo', ev.ot, v('--o-bom'), true),
        serie('Completas', ev['if'], v('--o-atencao'), true)
      ]},
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: { min: 0, max: 100, ticks: { callback: function(x){ return x + '%'; }, stepSize: 25 }, grid: { color: v('--o-sutil') } },
          x: { grid: { display: false } }
        },
        plugins: {
          legend: { position: 'top', align: 'start', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8 } },
          tooltip: { callbacks: {
            label: function(c){ return ' ' + c.dataset.label + ': ' + (c.parsed.y === null ? 'sem dados' : c.parsed.y.toLocaleString('pt-BR') + '%'); },
            afterBody: function(items){ var n = ev.compromissos[items[0].dataIndex]; return n + ' compromisso(s)'; }
          }}
        }
      }
    });
  })();
  </script>
</body>
</html>
