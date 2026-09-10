<?php
/**
 * relatorios.php — os relatórios de conjunto: a carteira, não a pessoa.
 *
 * "Todos os clientes ativos", "todos os processos em aberto", "as prospecções
 * paradas há mais de X dias". O retrato de UMA pessoa é o dossiê, em
 * `dossie.php`, aberto pelo botão dentro da ficha.
 *
 * ---------------------------------------------------------------------------
 * QUEM VÊ ESTA TELA
 * ---------------------------------------------------------------------------
 * Quem já enxerga PELO MENOS UMA das três fontes. Não foi criada uma permissão
 * nova obrigatória, de propósito: uma permissão nova nasce desmarcada para todo
 * mundo, e o escritório inteiro abriria o menu sem ver o item, concluindo que o
 * módulo não foi entregue.
 *
 * A permissão `relatorios` existe e é reconhecida (está em `$_validPages`), para
 * o administrador poder tirar de alguém explicitamente. O que ela NÃO faz é
 * ampliar: cada fonte continua sendo filtrada pelo módulo dela dentro do
 * `/api/relatorios.php`. Quem não vê Processos não lista processos, tendo ou não
 * `relatorios`.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\AccountContext;
use App\Relatorios\Listagem;

session_start();
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

$_isAdmin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
$_perms   = (array) ($_SESSION['user_permissions'] ?? []);

/** Pode ver um módulo? Admin e curinga passam, como no resto do sistema. */
function rel_pode(string $modulo): bool
{
    global $_isAdmin, $_perms;
    return $_isAdmin || in_array('*', $_perms, true) || in_array($modulo, $_perms, true);
}

// As fontes que ESTA pessoa pode listar. A tela mostra só essas abas.
$fontesPermitidas = [];
foreach (Listagem::FONTES as $chave => $meta) {
    if (rel_pode($meta['modulo'])) {
        $fontesPermitidas[$chave] = $meta;
    }
}

if ($fontesPermitidas === []) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403 — Yuris</title>';
    echo '<style>body{font-family:system-ui;background:#0a0f1e;color:#cbd5e1;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>';
    echo '<div><h1 style="margin:0 0 8px">Acesso negado</h1>';
    echo '<p>Você não tem permissão para nenhum dos módulos que geram relatório.<br>Solicite ao administrador.</p></div>';
    exit;
}

// Fonte pedida, ou a primeira que a pessoa pode ver.
$fonte = strtolower(trim((string) ($_GET['fonte'] ?? '')));
if (!isset($fontesPermitidas[$fonte])) {
    $fonte = (string) array_key_first($fontesPermitidas);
}

$tenantIds = $ctx->getAccessibleAccountIds($fontesPermitidas[$fonte]['modulo']);
$opcoes    = Listagem::opcoes($fonte, $tenantIds);

$filtros = [
    'status'         => (string) ($_GET['status'] ?? ''),
    'setor_id'       => (int) ($_GET['setor_id'] ?? 0),
    'responsavel_id' => (int) ($_GET['responsavel_id'] ?? 0),
    'etapa_id'       => (int) ($_GET['etapa_id'] ?? 0),
    'de'             => (string) ($_GET['de'] ?? ''),
    'ate'            => (string) ($_GET['ate'] ?? ''),
    'busca'          => (string) ($_GET['busca'] ?? ''),
    'ordem'          => (string) ($_GET['ordem'] ?? ''),
];

$res = Listagem::montar($fonte, $filtros, $tenantIds);

// A mesma query string que gerou a tela vai para o CSV, para o arquivo baixado
// ser exatamente o que está sendo visto. Montar de novo seria a chance de os
// dois divergirem.
$qs = http_build_query(array_merge(['acao' => 'listagem', 'fonte' => $fonte], array_filter($filtros, static fn($v) => $v !== '' && $v !== 0)));
$csvUrl = '/api/relatorios.php?' . $qs . '&formato=csv';

$activePage = 'relatorios';
$csrf       = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(16));

function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Relatórios — Yuris</title>
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
  <link rel="icon" type="image/png" sizes="32x32"  href="/assets/favicon-32.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script>/* yuris_theme_boot */(function(){try{var t=localStorage.getItem("yuris_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/yuris-theme.css?v=42">
  <link rel="stylesheet" href="/assets/sidebar.css?v=19">
  <link rel="stylesheet" href="/assets/relatorios.css?v=<?= @filemtime(__DIR__ . '/assets/relatorios.css') ?: 1 ?>">
  <style>
    body { margin:0; background:#070F1C; color:#e2e8f0; font-family:Inter,system-ui,sans-serif; }
    .rel-wrap { padding: 22px 26px 60px; }
    .rel-topo { display:flex; align-items:baseline; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .rel-topo h1 { margin:0; font-size:22px; font-weight:700; }
    .rel-topo p  { margin:4px 0 0; color:#94a3b8; font-size:13px; }
    .rel-abas { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
    .rel-aba {
      padding:8px 16px; border-radius:9px; text-decoration:none; font-size:13px; font-weight:500;
      background:#0d1c30; border:1px solid #1c3050; color:#cbd5e1;
    }
    .rel-aba:hover { background:#132845; }
    .rel-aba.ativa { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
    /* Fundo branco herdando cor clara do body deixaria a tabela invisivel. */
    .rel-resultado, .rel-resultado * { color: var(--doc-tinta); }
    .rel-resultado .rel-meta, .rel-resultado .rel-vazio, .rel-resultado thead th { color: var(--doc-tinta-fraca); }
    @media print {
      .sidebar, .rel-topo, .rel-abas, .rel-filtros, .no-print { display:none !important; }
      .page-layout { display:block !important; }
      body { background:#fff !important; }
      .rel-wrap { padding:0; }
      .rel-resultado { padding:0; border-radius:0; }
      .content, main { margin-left:0 !important; }
    }
  </style>
</head>
<body>
  <!-- .page-layout e a convencao do resto do sistema (ver processos.php): e ele
       que poe a barra lateral e o conteudo lado a lado. Sem ele o conteudo cai
       ABAIXO do menu, ocupando a largura toda. -->
  <main class="rel-wrap">
    <div class="page-layout">
      <?php include __DIR__ . '/includes/sidebar.php'; ?>
      <div class="main-content">

    <div class="rel-topo no-print">
      <div>
        <h1>Relatórios</h1>
        <p>A carteira inteira, filtrada do seu jeito. Para o histórico completo de uma pessoa ou de um processo, use o botão Relatório dentro da ficha.</p>
      </div>
    </div>

    <nav class="rel-abas no-print">
      <?php foreach ($fontesPermitidas as $chave => $meta): ?>
        <a class="rel-aba<?= $chave === $fonte ? ' ativa' : '' ?>" href="?fonte=<?= e($chave) ?>"><?= e($meta['titulo']) ?></a>
      <?php endforeach; ?>
    </nav>

    <form class="rel-filtros no-print" method="get" action="/relatorios.php">
      <input type="hidden" name="fonte" value="<?= e($fonte) ?>">

      <div class="rel-campo">
        <label for="f-status">Situação</label>
        <select id="f-status" name="status">
          <option value="">Todas</option>
          <?php foreach ($opcoes['status'] as $valor => $rotulo): ?>
            <option value="<?= e($valor) ?>"<?= $filtros['status'] === (string) $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($fonte === 'prospeccoes' && $opcoes['etapas']): ?>
      <div class="rel-campo">
        <label for="f-etapa">Etapa do funil</label>
        <select id="f-etapa" name="etapa_id">
          <option value="0">Todas</option>
          <?php foreach ($opcoes['etapas'] as $id => $nome): ?>
            <option value="<?= (int) $id ?>"<?= $filtros['etapa_id'] === (int) $id ? ' selected' : '' ?>><?= e($nome) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($fonte !== 'prospeccoes' && $opcoes['setores']): ?>
      <div class="rel-campo">
        <label for="f-setor">Setor</label>
        <select id="f-setor" name="setor_id">
          <option value="0">Todos</option>
          <?php foreach ($opcoes['setores'] as $id => $nome): ?>
            <option value="<?= (int) $id ?>"<?= $filtros['setor_id'] === (int) $id ? ' selected' : '' ?>><?= e($nome) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($opcoes['responsaveis']): ?>
      <div class="rel-campo">
        <label for="f-resp">Responsável</label>
        <select id="f-resp" name="responsavel_id">
          <option value="0">Todos</option>
          <?php foreach ($opcoes['responsaveis'] as $id => $nome): ?>
            <option value="<?= (int) $id ?>"<?= $filtros['responsavel_id'] === (int) $id ? ' selected' : '' ?>><?= e($nome) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="rel-campo">
        <label for="f-de">Cadastrado de</label>
        <input type="date" id="f-de" name="de" value="<?= e($filtros['de']) ?>">
      </div>
      <div class="rel-campo">
        <label for="f-ate">até</label>
        <input type="date" id="f-ate" name="ate" value="<?= e($filtros['ate']) ?>">
      </div>

      <div class="rel-campo" style="min-width:180px">
        <label for="f-busca">Buscar</label>
        <input type="search" id="f-busca" name="busca" value="<?= e($filtros['busca']) ?>" placeholder="nome, documento, telefone">
      </div>

      <div class="rel-campo">
        <label for="f-ordem">Ordenar por</label>
        <select id="f-ordem" name="ordem">
          <?php
            $ordens = match ($fonte) {
                'clientes'    => ['nome' => 'Nome', 'recente' => 'Mais recentes', 'antigo' => 'Mais antigos', 'setor' => 'Setor'],
                'prospeccoes' => ['recente' => 'Mais recentes', 'antigo' => 'Mais antigas', 'nome' => 'Nome', 'valor' => 'Maior valor', 'parada' => 'Paradas há mais tempo'],
                'processos'   => ['prazo' => 'Próximo prazo', 'cliente' => 'Cliente', 'recente' => 'Mais recentes', 'antigo' => 'Mais antigos', 'parada' => 'Sem movimentação há mais tempo'],
            };
            foreach ($ordens as $valor => $rotulo):
          ?>
            <option value="<?= e($valor) ?>"<?= $filtros['ordem'] === (string) $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="rel-acoes">
        <button type="submit" class="btn-doc btn-doc-primario">Aplicar</button>
        <a class="btn-doc" href="/relatorios.php?fonte=<?= e($fonte) ?>">Limpar</a>
        <a class="btn-doc" href="<?= e($csvUrl) ?>">Baixar planilha</a>
        <button type="button" class="btn-doc" id="btnImprimirLista">Imprimir</button>
      </div>
    </form>

    <?php if (!empty($res['truncado'])): ?>
      <p class="rel-aviso no-print">
        Esta lista foi cortada em <?= (int) $res['limite'] ?> linhas porque o resultado é maior que isso.
        Use os filtros para reduzir, senão o relatório fica incompleto sem avisar.
      </p>
    <?php endif; ?>

    <section class="rel-resultado">
      <div class="rel-cabeca">
        <h2>Relatório de <?= e($res['titulo']) ?></h2>
        <span class="rel-meta"><?= (int) $res['total'] ?> registro<?= $res['total'] === 1 ? '' : 's' ?> · gerado em <?= e($res['gerado_em']) ?></span>
      </div>

      <?php if ($res['total'] === 0): ?>
        <p class="rel-vazio">Nenhum registro com esses filtros.</p>
      <?php else: ?>
        <div class="tabela-rolagem">
          <table>
            <thead>
              <tr><?php foreach ($res['colunas'] as $i => $c): ?><th<?= in_array($i, $res['colunas_livres'], true) ? ' class="livre"' : '' ?>><?= e($c) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
              <?php foreach ($res['linhas'] as $l): ?>
                <tr><?php foreach ($l as $i => $celula): ?><td<?= in_array($i, $res['colunas_livres'], true) ? ' class="livre"' : '' ?>><?= e($celula) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

      </div>
    </div>
  </main>

  <!-- yuris-ui.js (Yuris.toast) ja vem pela sidebar; carregar de novo daria dois registros do mesmo helper -->
  <script>
    document.getElementById('btnImprimirLista').addEventListener('click', function () { window.print(); });
  </script>
</body>
</html>
