<?php
/**
 * api/dashboard_comercial.php: dados do cockpit comercial da conta Fleetiflow
 * (public/includes/dashboard_fleetiflow.php). Uma chamada devolve tudo o que a
 * tela mostra: KPIs do período com o período anterior equivalente, distribuição
 * do pipeline (estado atual), meta do mês, atividades recentes, série do
 * período e evolução de 12 meses.
 *
 * Só lê. Toda query filtra por account_id IN (contas acessíveis), como o
 * api/dashboard.php. Parâmetros:
 *   start, end     YYYY-MM-DD (sem eles: últimos 30 dias)
 *   responsavel    id de usuário da conta (filtra cards.responsavel_user_id)
 *   origin         __matriz__ | __filiais__ | <id> (mesma regra do dashboard)
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Core\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$uid       = $ctx->getUserId();
$tenantIds = $ctx->getAccessibleAccountIds('dashboard');
if (empty($tenantIds)) $tenantIds = [0];

$pdo = Database::getConnection();

// Recorte de origem (matriz/filiais): espelho do api/dashboard.php.
$selected_origin = isset($_GET['origin']) ? trim((string)$_GET['origin']) : '';
if ($selected_origin !== '' && $ctx->isMatriz()) {
    try {
        $ph_oa = implode(',', array_fill(0, count($tenantIds), '?'));
        $st = $pdo->prepare("SELECT id, tipo FROM accounts WHERE id IN ($ph_oa) AND deleted_at IS NULL AND status IN ('active','trial','overdue')");
        $st->execute(array_map('intval', $tenantIds));
        $accs = $st->fetchAll(PDO::FETCH_ASSOC);
        $matrizIds  = array_map(fn($a) => (int)$a['id'], array_filter($accs, fn($a) => $a['tipo'] === 'matriz'));
        $filiaisIds = array_map(fn($a) => (int)$a['id'], array_filter($accs, fn($a) => $a['tipo'] !== 'matriz'));
        $allowed    = array_map(fn($a) => (int)$a['id'], $accs);
        if ($selected_origin === '__matriz__')       $tenantIds = $matrizIds ?: [0];
        elseif ($selected_origin === '__filiais__')  $tenantIds = $filiaisIds ?: [0];
        else { $sid = (int)$selected_origin; $tenantIds = (in_array($sid, $allowed, true) && $sid > 0) ? [$sid] : [0]; }
    } catch (\Throwable $e) { /* mantém o conjunto acessível */ }
}

$isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
$start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$end   = isset($_GET['end'])   ? trim((string)$_GET['end'])   : '';
if (!$isDate($start) || !$isDate($end)) {
    // Sem período válido: últimos 30 dias (hoje incluso).
    $end   = date('Y-m-d');
    $start = date('Y-m-d', strtotime('-29 days'));
}
if ($start > $end) { [$start, $end] = [$end, $start]; }
$dias = (int)round((strtotime($end) - strtotime($start)) / 86400) + 1;
// Período anterior equivalente: mesma quantidade de dias, terminando na véspera.
$antEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
$antStart = date('Y-m-d', strtotime($antEnd . ' -' . ($dias - 1) . ' days'));

$responsavel = isset($_GET['responsavel']) ? (int)$_GET['responsavel'] : 0;

// Fragmentos de SQL reutilizados. Alias "c" para cards em todas as queries.
$ph = []; $tp = [];
foreach (array_values($tenantIds) as $i => $aid) { $ph[] = ":acc$i"; $tp["acc$i"] = (int)$aid; }
$inAcc    = '(' . implode(',', $ph) . ')';
$baseCard = "c.deleted_at IS NULL AND c.account_id IN $inAcc";
if ($responsavel > 0) { $baseCard .= ' AND c.responsavel_user_id = :resp'; $tp['resp'] = $responsavel; }
$VALOR    = "COALESCE(NULLIF(c.valor_fechado_final,0), NULLIF(c.valor_proposta,0), IFNULL(c.valor_estimado,0))";
$FECHADO  = "c.data_fechamento IS NOT NULL AND c.data_fechamento > '0000-00-00'";
$ABERTO   = "(c.data_fechamento IS NULL OR c.data_fechamento = '0000-00-00')";

$q = function (string $sql, array $params) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
};

// Como agrupar uma data por granularidade (semana começa na segunda).
$TPL = [
    'dia'    => "DATE(%s)",
    'semana' => "DATE_FORMAT(DATE_SUB(%s, INTERVAL WEEKDAY(%s) DAY), '%%Y-%%m-%%d')",
    'mes'    => "DATE_FORMAT(%s, '%%Y-%%m')",
];

/** "set/26" sem depender de locale do servidor. */
function strftime_pt(int $ts): string
{
    static $meses = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
    return $meses[(int)date('n', $ts) - 1] . '/' . date('y', $ts);
}

try {
    // ── Colunas do funil (estado atual, sem filtro de data) ───────────────────
    // qtd = todos os cards da coluna (como a distribuição de sempre); abertos e
    // valor consideram só os sem data de fechamento, que é o que ainda está em jogo.
    // O "c.id IS NOT NULL" importa: coluna vazia vem do LEFT JOIN com tudo nulo,
    // e "data_fechamento IS NULL" contaria essa linha fantasma como um card aberto.
    $cols = $q("SELECT pc.id, pc.nome, pc.slug, pc.cor, pc.ordem, pc.conta_funil, pc.conta_oportunidade, pc.conta_fechado, pc.conta_perdido,
                       COUNT(c.id) AS qtd,
                       COALESCE(SUM(CASE WHEN c.id IS NOT NULL AND $ABERTO THEN 1 ELSE 0 END),0) AS abertos,
                       COALESCE(SUM(CASE WHEN c.id IS NOT NULL AND $ABERTO THEN $VALOR ELSE 0 END),0) AS valor
                FROM pipeline_columns pc
                LEFT JOIN cards c ON c.coluna_id = pc.id AND $baseCard
                WHERE pc.account_id IN $inAcc
                GROUP BY pc.id ORDER BY pc.ordem ASC, pc.id ASC", $tp)->fetchAll(PDO::FETCH_ASSOC);
    $temFunil = (bool)array_filter($cols, fn($c) => (int)$c['conta_funil'] === 1);
    $pipeline = [];
    $oportunidades = 0; $valorPipeline = 0.0; $emNegociacao = 0;
    foreach ($cols as $c) {
        $noFunil = $temFunil ? (int)$c['conta_funil'] === 1
                             : ((int)$c['conta_fechado'] === 0 && (int)$c['conta_perdido'] === 0);
        $qtd = (int)$c['qtd']; $abertos = (int)$c['abertos'];
        if ($noFunil) { $oportunidades += $abertos; $valorPipeline += (float)$c['valor']; }
        if ((int)$c['conta_oportunidade'] === 1) $emNegociacao += $abertos;
        $pipeline[] = [
            'id' => (int)$c['id'], 'nome' => $c['nome'], 'slug' => $c['slug'], 'cor' => $c['cor'],
            'qtd' => $qtd, 'valor' => round((float)$c['valor'], 2),
            'funil' => $noFunil, 'fechado' => (int)$c['conta_fechado'] === 1, 'perdido' => (int)$c['conta_perdido'] === 1,
        ];
    }

    // ── KPIs do período e do anterior ─────────────────────────────────────────
    $kpiPeriodo = function (string $s, string $e) use ($q, $baseCard, $FECHADO, $VALOR, $tp) {
        $p = $tp + ['s' => $s, 'e' => $e];
        $leads = (int)$q("SELECT COUNT(*) FROM cards c WHERE $baseCard AND DATE(c.created_at) BETWEEN :s AND :e", $p)->fetchColumn();
        $r = $q("SELECT COUNT(*) AS n, COALESCE(SUM($VALOR),0) AS v FROM cards c WHERE $baseCard AND $FECHADO AND c.data_fechamento BETWEEN :s AND :e", $p)->fetch(PDO::FETCH_ASSOC);
        $vendas = (int)$r['n']; $receita = (float)$r['v'];
        return [
            'leads' => $leads, 'vendas' => $vendas, 'receita' => round($receita, 2),
            'conversao' => $leads > 0 ? round($vendas / $leads * 100, 1) : null,
            'ticket' => $vendas > 0 ? round($receita / $vendas, 2) : null,
        ];
    };
    $atual    = $kpiPeriodo($start, $end);
    $anterior = $kpiPeriodo($antStart, $antEnd);

    // ── Meta do mês: o mês do fim do período (ou o mês corrente se o período
    //    termina no futuro). Meta = registro mais recente do usuário, senão da conta.
    $mes = min($end, date('Y-m-d'));
    $mesIni = date('Y-m-01', strtotime($mes)); $mesFim = date('Y-m-t', strtotime($mes));
    $metaValor = 0.0;
    $g = $q("SELECT valor_meta FROM goals WHERE user_id = :uid AND account_id IN $inAcc ORDER BY updated_at DESC, id DESC LIMIT 1", $tp + ['uid' => $uid])->fetchColumn();
    if ($g === false || (float)$g <= 0) $g = $q("SELECT valor_meta FROM goals WHERE account_id IN $inAcc ORDER BY updated_at DESC, id DESC LIMIT 1", $tp)->fetchColumn();
    if ($g !== false) $metaValor = (float)$g;
    $rm = $q("SELECT COUNT(*) AS n, COALESCE(SUM($VALOR),0) AS v FROM cards c WHERE $baseCard AND $FECHADO AND c.data_fechamento BETWEEN :s AND :e", $tp + ['s' => $mesIni, 'e' => $mesFim])->fetch(PDO::FETCH_ASSOC);
    $meta = [
        'mes' => substr($mesIni, 0, 7), 'valor' => round($metaValor, 2),
        'fechado' => round((float)$rm['v'], 2), 'vendas' => (int)$rm['n'],
        'progresso' => $metaValor > 0 ? round((float)$rm['v'] / $metaValor * 100, 1) : null,
        'dias_restantes' => max(0, (int)round((strtotime($mesFim) - strtotime(date('Y-m-d'))) / 86400)),
    ];

    // ── Série do período: dia (≤ 31 dias), semana (≤ 182) ou mês ─────────────
    $gran = $dias <= 31 ? 'dia' : ($dias <= 182 ? 'semana' : 'mes');
    $kF = sprintf($TPL[$gran], 'c.data_fechamento', 'c.data_fechamento');
    $kC = sprintf($TPL[$gran], 'c.created_at', 'c.created_at');
    $p = $tp + ['s' => $start, 'e' => $end];
    $porChave = [];
    foreach ($q("SELECT $kF AS k, COUNT(*) AS n, COALESCE(SUM($VALOR),0) AS v FROM cards c WHERE $baseCard AND $FECHADO AND c.data_fechamento BETWEEN :s AND :e GROUP BY k", $p)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porChave[$r['k']]['vendas'] = (int)$r['n']; $porChave[$r['k']]['receita'] = (float)$r['v'];
    }
    foreach ($q("SELECT $kC AS k, COUNT(*) AS n FROM cards c WHERE $baseCard AND DATE(c.created_at) BETWEEN :s AND :e GROUP BY k", $p)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porChave[$r['k']]['leads'] = (int)$r['n'];
    }
    // Eixo completo (buckets vazios entram com zero) e meta proporcional aos dias do bucket.
    $serie = [];
    $cursor = strtotime($start); $fimTs = strtotime($end);
    if ($gran === 'semana') $cursor = strtotime($start . ' -' . ((int)date('N', $cursor) - 1) . ' days');
    if ($gran === 'mes')    $cursor = strtotime(date('Y-m-01', $cursor));
    while ($cursor <= $fimTs) {
        if ($gran === 'dia')        { $k = date('Y-m-d', $cursor); $ini = $cursor; $fim = $cursor; $prox = strtotime('+1 day', $cursor); $label = date('d/m', $cursor); }
        elseif ($gran === 'semana') { $k = date('Y-m-d', $cursor); $ini = $cursor; $fim = strtotime('+6 days', $cursor); $prox = strtotime('+7 days', $cursor); $label = date('d/m', $cursor); }
        else                        { $k = date('Y-m', $cursor);   $ini = $cursor; $fim = strtotime(date('Y-m-t', $cursor)); $prox = strtotime('+1 month', $cursor); $label = ucfirst(strftime_pt($cursor)); }
        // Só a parte do bucket dentro do período conta para a meta proporcional.
        $iniR = max($ini, strtotime($start)); $fimR = min($fim, $fimTs);
        $diasBucket = max(0, (int)round(($fimR - $iniR) / 86400) + 1);
        $diasMes = (int)date('t', $iniR);
        $serie[] = [
            'chave' => $k, 'label' => $label,
            'leads' => (int)($porChave[$k]['leads'] ?? 0), 'vendas' => (int)($porChave[$k]['vendas'] ?? 0),
            'receita' => round((float)($porChave[$k]['receita'] ?? 0), 2),
            'meta' => $metaValor > 0 ? round($metaValor * $diasBucket / $diasMes, 2) : null,
        ];
        $cursor = $prox;
    }

    // ── Evolução: 12 meses, 16 semanas e 30 dias terminando no fim do período ──
    $evolucao = ['mes' => [], 'semana' => [], 'dia' => []];
    $fimEvo = min($end, date('Y-m-d'));
    $janelas = [
        'mes'    => [date('Y-m-01', strtotime($fimEvo . ' -11 months')), date('Y-m-t', strtotime($fimEvo))],
        'semana' => [date('Y-m-d', strtotime($fimEvo . ' -' . (15 * 7 + (int)date('N', strtotime($fimEvo)) - 1) . ' days')), $fimEvo],
        'dia'    => [date('Y-m-d', strtotime($fimEvo . ' -29 days')), $fimEvo],
    ];
    $metasMes = [];
    foreach ($q("SELECT referencia_mes, valor_meta FROM goals WHERE account_id IN $inAcc AND referencia_mes IS NOT NULL AND referencia_mes <> ''", $tp)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((float)$r['valor_meta'] > 0) $metasMes[$r['referencia_mes']] = (float)$r['valor_meta'];
    }
    foreach ($janelas as $g => [$js, $je]) {
        $kF = sprintf($TPL[$g], 'c.data_fechamento', 'c.data_fechamento');
        $kC = sprintf($TPL[$g], 'c.created_at', 'c.created_at');
        $p = $tp + ['s' => $js, 'e' => $je];
        $m = [];
        foreach ($q("SELECT $kF AS k, COUNT(*) AS n, COALESCE(SUM($VALOR),0) AS v FROM cards c WHERE $baseCard AND $FECHADO AND c.data_fechamento BETWEEN :s AND :e GROUP BY k", $p)->fetchAll(PDO::FETCH_ASSOC) as $r) { $m[$r['k']]['vendas'] = (int)$r['n']; $m[$r['k']]['receita'] = (float)$r['v']; }
        foreach ($q("SELECT $kC AS k, COUNT(*) AS n FROM cards c WHERE $baseCard AND DATE(c.created_at) BETWEEN :s AND :e GROUP BY k", $p)->fetchAll(PDO::FETCH_ASSOC) as $r) { $m[$r['k']]['leads'] = (int)$r['n']; }
        $cursor = strtotime($js); $fimTs = strtotime($je);
        while ($cursor <= $fimTs) {
            if ($g === 'dia')        { $k = date('Y-m-d', $cursor); $prox = strtotime('+1 day', $cursor);   $label = date('d/m', $cursor); $metaB = $metaValor > 0 ? $metaValor / (int)date('t', $cursor) : null; }
            elseif ($g === 'semana') { $k = date('Y-m-d', $cursor); $prox = strtotime('+7 days', $cursor);  $label = date('d/m', $cursor); $metaB = $metaValor > 0 ? $metaValor * 7 / (int)date('t', $cursor) : null; }
            else                     { $k = date('Y-m', $cursor);   $prox = strtotime('+1 month', $cursor); $label = ucfirst(strftime_pt($cursor)); $metaB = $metasMes[$k] ?? ($metaValor > 0 ? $metaValor : null); }
            $evolucao[$g][] = [
                'chave' => $k, 'label' => $label,
                'leads' => (int)($m[$k]['leads'] ?? 0), 'vendas' => (int)($m[$k]['vendas'] ?? 0),
                'receita' => round((float)($m[$k]['receita'] ?? 0), 2), 'meta' => $metaB !== null ? round($metaB, 2) : null,
            ];
            $cursor = $prox;
        }
    }

    // ── Prospecção: a coorte dos leads que ENTRARAM no período ────────────────
    // Cada coluna é classificada pelas flags: a primeira do funil é "novo", as
    // outras sem conta_oportunidade são "andamento" (qualificação, follow-up),
    // conta_oportunidade/conta_fechado é "avancou", e o resto (perdido, fora do
    // escopo, bloqueado) é "descartado". Serve para qualquer conta, sem slug fixo.
    $grupoCol = []; $primeiraFunil = null;
    foreach ($pipeline as $pc) {
        if ($pc['funil'] && $primeiraFunil === null) $primeiraFunil = $pc['id'];
        $col = null; foreach ($cols as $c0) { if ((int)$c0['id'] === $pc['id']) { $col = $c0; break; } }
        $oport = $col && (int)$col['conta_oportunidade'] === 1;
        if ($pc['id'] === $primeiraFunil)          $grupoCol[$pc['id']] = 'novo';
        elseif ($pc['fechado'] || $oport)          $grupoCol[$pc['id']] = 'avancou';
        elseif ($pc['funil'])                      $grupoCol[$pc['id']] = 'andamento';
        else                                       $grupoCol[$pc['id']] = 'descartado';
    }
    $p = $tp + ['s' => $start, 'e' => $end];
    // Série: leads criados por bucket, empilhados pelo grupo da etapa atual.
    $kC = sprintf($TPL[$gran], 'c.created_at', 'c.created_at');
    $porBucket = [];
    foreach ($q("SELECT $kC AS k, c.coluna_id, COUNT(*) AS n FROM cards c WHERE $baseCard AND DATE(c.created_at) BETWEEN :s AND :e GROUP BY k, c.coluna_id", $p)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = $grupoCol[(int)$r['coluna_id']] ?? 'novo';
        $porBucket[$r['k']][$g] = ($porBucket[$r['k']][$g] ?? 0) + (int)$r['n'];
    }
    $prospSerie = array_map(fn($b) => [
        'chave' => $b['chave'], 'label' => $b['label'],
        'novo' => $porBucket[$b['chave']]['novo'] ?? 0, 'andamento' => $porBucket[$b['chave']]['andamento'] ?? 0,
        'avancou' => $porBucket[$b['chave']]['avancou'] ?? 0, 'descartado' => $porBucket[$b['chave']]['descartado'] ?? 0,
    ], $serie);
    // Funil da coorte: quantos desses leads passaram por cada etapa (etapa atual
    // ou qualquer movimentação registrada em card_history para ela).
    $alcancaram = [];
    foreach ($q("SELECT x.coluna_id, COUNT(DISTINCT x.card_id) AS n FROM (
                    SELECT c.id AS card_id, c.coluna_id FROM cards c WHERE $baseCard AND DATE(c.created_at) BETWEEN :s AND :e
                    UNION
                    SELECT c.id, h.para_coluna_id FROM cards c JOIN card_history h ON h.card_id = c.id AND h.acao = 'moved' AND h.para_coluna_id IS NOT NULL
                     WHERE $baseCard AND DATE(c.created_at) BETWEEN :s AND :e
                 ) x WHERE x.coluna_id IS NOT NULL GROUP BY x.coluna_id", $p)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $alcancaram[(int)$r['coluna_id']] = (int)$r['n'];
    }
    $prospFunil = [];
    foreach ($pipeline as $pc) {
        if (!$pc['funil'] && !$pc['fechado']) continue;
        $n = $pc['id'] === $primeiraFunil ? $atual['leads'] : ($alcancaram[$pc['id']] ?? 0);
        $prospFunil[] = ['id' => $pc['id'], 'nome' => $pc['nome'], 'slug' => $pc['slug'], 'qtd' => $n, 'grupo' => $grupoCol[$pc['id']],
                         'pct' => $atual['leads'] > 0 ? round($n / $atual['leads'] * 100, 1) : null];
    }
    $prospDescartados = 0;
    foreach ($pipeline as $pc) { if (($grupoCol[$pc['id']] ?? '') === 'descartado') $prospDescartados += $alcancaram[$pc['id']] ?? 0; }

    // ── Atividade no pipeline: dia da semana × bloco de 3h, no período ────────
    // Conta toda movimentação registrada em card_history (criação, captação,
    // mudança de etapa, campo alterado) dos cards da conta.
    $mapa = [];
    foreach ($q("SELECT WEEKDAY(h.created_at) AS dia, FLOOR(HOUR(h.created_at) / 3) AS bloco, COUNT(*) AS n
                 FROM card_history h JOIN cards c ON c.id = h.card_id
                 WHERE $baseCard AND DATE(h.created_at) BETWEEN :s AND :e
                 GROUP BY dia, bloco", $tp + ['s' => $start, 'e' => $end])->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mapa[] = ['dia' => (int)$r['dia'], 'bloco' => (int)$r['bloco'], 'n' => (int)$r['n']];
    }

    // ── Atividades recentes: últimos cards movimentados ───────────────────────
    $ativ = $q("SELECT c.id, c.cliente_nome, c.empresa_nome, c.titulo, c.status, c.updated_at, c.created_at, c.data_fechamento,
                       $VALOR AS valor, pc.nome AS etapa, pc.cor AS etapa_cor, pc.slug AS etapa_slug, u.nome AS responsavel
                FROM cards c
                LEFT JOIN pipeline_columns pc ON pc.id = c.coluna_id
                LEFT JOIN users u ON u.id = c.responsavel_user_id
                WHERE $baseCard
                ORDER BY c.updated_at DESC, c.id DESC LIMIT 25", $tp)->fetchAll(PDO::FETCH_ASSOC);
    $atividades = array_map(fn($a) => [
        'id' => (int)$a['id'], 'cliente' => $a['cliente_nome'], 'empresa' => $a['empresa_nome'], 'titulo' => $a['titulo'],
        'valor' => round((float)$a['valor'], 2), 'etapa' => $a['etapa'], 'etapa_cor' => $a['etapa_cor'], 'etapa_slug' => $a['etapa_slug'],
        'responsavel' => $a['responsavel'], 'quando' => $a['updated_at'] ?: $a['created_at'],
        'fechado' => !empty($a['data_fechamento']) && $a['data_fechamento'] !== '0000-00-00',
    ], $ativ);

    // ── Responsáveis (filtro de equipe) ───────────────────────────────────────
    $resp = $q("SELECT id, nome FROM users WHERE account_id IN $inAcc AND deleted_at IS NULL AND status = 'active' ORDER BY nome", $tp)->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'periodo' => ['start' => $start, 'end' => $end, 'dias' => $dias, 'granularidade' => $gran,
                      'anterior' => ['start' => $antStart, 'end' => $antEnd]],
        'kpis' => [
            'atual' => $atual + ['oportunidades' => $oportunidades, 'em_negociacao' => $emNegociacao, 'valor_pipeline' => round($valorPipeline, 2)],
            'anterior' => $anterior,
        ],
        'pipeline'    => $pipeline,
        'meta'        => $meta,
        'serie'       => $serie,
        'atividade_semana' => $mapa,
        'prospeccao'  => ['serie' => $prospSerie, 'funil' => $prospFunil, 'entraram' => $atual['leads'], 'descartados' => $prospDescartados],
        'evolucao'    => $evolucao,
        'atividades'  => $atividades,
        'responsaveis'=> array_map(fn($u) => ['id' => (int)$u['id'], 'nome' => $u['nome']], $resp),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível montar o painel comercial.']);
}
exit;
