<?php
namespace App\Ambientes;

use App\Core\Database;
use App\Digital\Entrega;
use App\Digital\Material;

/**
 * Os números das Visões Gerais (20/10/2026). Cada método recebe só o que a
 * pessoa já pode ver (quadros de `TaskBoard::findForUser`, clientes com a regra
 * do vendedor) e conta em cima disso. Toda consulta filtra `account_id`.
 *
 * REGRAS QUE EVITAM CONTAR DUAS VEZES
 *   - cliente em Software e em Digital conta uma vez no total da Empresa e uma vez
 *     em cada área; "nas duas áreas" mostra a sobreposição;
 *   - receita recorrente soma cada linha de valor uma vez, e a divisão por área é
 *     a área da linha (linha sem área fica em "sem área", nunca em duas);
 *   - venda do mês segue a regra do painel comercial e do DRE: o card fechado cujo
 *     cliente já tem valores sai (CobrancaDoCliente::sqlFechamentoSemDuplicar), e
 *     o mesmo cliente na mesma data é uma venda só.
 */
final class Visao
{
    /** Quadros de uma área entre os que a pessoa vê. @return array<int, array> id => quadro */
    public static function quadrosDaArea(array $quadrosVisiveis, string $area): array
    {
        $out = [];
        foreach ($quadrosVisiveis as $b) if (($b['area'] ?? null) === $area) $out[(int) $b['id']] = $b;
        return $out;
    }

    private static function in(array $ids): string
    {
        return implode(',', array_fill(0, max(1, count($ids)), '?'));
    }

    // ── Tarefas dos quadros ───────────────────────────────────────────────

    /** Números das tarefas ativas de um conjunto de quadros. */
    public static function tarefas(array $boardIds, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $r = ['abertas' => 0, 'atrasadas' => 0, 'sem_responsavel' => 0, 'proximos' => [], 'por_responsavel' => [], 'por_quadro' => []];
        if (!$boardIds) return $r;
        $boardIds = array_values(array_map('intval', $boardIds));
        $pdo = Database::getConnection();
        $in = self::in($boardIds);
        // Concluída fica fora; coluna "concluído" do quadro também.
        $base = "FROM tasks t LEFT JOIN task_columns col ON col.id = t.column_id
                 WHERE t.board_id IN ($in) AND t.status = 'ativa' AND COALESCE(col.is_coluna_concluido, 0) = 0";
        $st = $pdo->prepare("SELECT t.board_id, COUNT(*) AS abertas, SUM(t.prazo IS NOT NULL AND t.prazo < ?) AS atrasadas,
                                    SUM(t.responsavel_id IS NULL) AS sem_resp, MIN(CASE WHEN t.prazo >= ? THEN t.prazo END) AS proximo
                               $base GROUP BY t.board_id");
        $st->execute(array_merge([$agora, $agora], $boardIds));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $x) {
            $r['por_quadro'][(int) $x['board_id']] = ['abertas' => (int) $x['abertas'], 'atrasadas' => (int) $x['atrasadas'], 'proximo' => $x['proximo']];
            $r['abertas'] += (int) $x['abertas'];
            $r['atrasadas'] += (int) $x['atrasadas'];
            $r['sem_responsavel'] += (int) $x['sem_resp'];
        }
        $st = $pdo->prepare("SELECT t.responsavel_id, u.nome, COUNT(*) AS abertas, SUM(t.prazo IS NOT NULL AND t.prazo < ?) AS atrasadas,
                                    MIN(CASE WHEN t.prazo >= ? THEN t.prazo END) AS proximo
                               FROM tasks t LEFT JOIN task_columns col ON col.id = t.column_id JOIN users u ON u.id = t.responsavel_id
                              WHERE t.board_id IN ($in) AND t.status = 'ativa' AND COALESCE(col.is_coluna_concluido, 0) = 0
                              GROUP BY t.responsavel_id, u.nome ORDER BY atrasadas DESC, abertas DESC LIMIT 20");
        $st->execute(array_merge([$agora, $agora], $boardIds));
        $r['por_responsavel'] = array_map(fn($x) => ['id' => (int) $x['responsavel_id'], 'nome' => $x['nome'], 'abertas' => (int) $x['abertas'],
            'atrasadas' => (int) $x['atrasadas'], 'proximo' => $x['proximo']], $st->fetchAll(\PDO::FETCH_ASSOC));
        $ate = date('Y-m-d H:i:s', strtotime($agora . ' +14 days'));
        $st = $pdo->prepare("SELECT t.id, t.titulo, t.prazo, t.board_id, t.prioridade, u.nome AS responsavel
                               FROM tasks t LEFT JOIN task_columns col ON col.id = t.column_id LEFT JOIN users u ON u.id = t.responsavel_id
                              WHERE t.board_id IN ($in) AND t.status = 'ativa' AND COALESCE(col.is_coluna_concluido, 0) = 0
                                AND t.prazo IS NOT NULL AND t.prazo <= ?
                              ORDER BY t.prazo LIMIT 12");
        $st->execute(array_merge($boardIds, [$ate]));
        $r['proximos'] = array_map(fn($x) => ['id' => (int) $x['id'], 'titulo' => $x['titulo'], 'prazo' => $x['prazo'], 'board_id' => (int) $x['board_id'],
            'prioridade' => $x['prioridade'], 'responsavel' => $x['responsavel'], 'atrasada' => $x['prazo'] < $agora], $st->fetchAll(\PDO::FETCH_ASSOC));
        return $r;
    }

    // ── Empresa ───────────────────────────────────────────────────────────

    public static function receita(int $accountId, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT COALESCE(area, 'sem_area') AS area, SUM(CASE tipo WHEN 'mensal' THEN valor WHEN 'anual' THEN valor / 12 ELSE 0 END) AS mrr
                               FROM crm_cliente_valores
                              WHERE account_id = ? AND ativo = 1 AND tipo IN ('mensal', 'anual') AND inicio <= ? AND (fim IS NULL OR fim >= ?)
                              GROUP BY COALESCE(area, 'sem_area')");
        $st->execute([$accountId, $hoje, $hoje]);
        $porArea = ['software' => 0.0, 'digital' => 0.0, 'outro' => 0.0, 'sem_area' => 0.0];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $x) $porArea[$x['area']] = round(($porArea[$x['area']] ?? 0) + (float) $x['mrr'], 2);
        $mes = substr($hoje, 0, 7);
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'paga' AND pago_em BETWEEN ? AND ? THEN COALESCE(valor_pago, valor) END), 0) AS recebido,
                                    COALESCE(SUM(CASE WHEN status = 'aberta' AND vencimento < ? THEN valor END), 0) AS atrasado,
                                    COALESCE(SUM(status = 'aberta' AND vencimento < ?), 0) AS atrasadas
                               FROM crm_cliente_cobrancas WHERE account_id = ?");
        $st->execute([$mes . '-01', date('Y-m-t', strtotime($mes . '-01')), $hoje, $hoje, $accountId]);
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        return ['mrr' => round(array_sum($porArea), 2), 'mrr_por_area' => $porArea, 'recebido_mes' => round((float) $c['recebido'], 2),
                'atrasado' => round((float) $c['atrasado'], 2), 'cobrancas_atrasadas' => (int) $c['atrasadas']];
    }

    public static function clientes(int $accountId): array
    {
        $st = Database::getConnection()->prepare("SELECT id FROM clientes WHERE account_id = ? AND deleted_at IS NULL AND status = 'ativo'");
        $st->execute([$accountId]);
        $ativos = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        $mapa = AreaDoCliente::mapa($accountId, $ativos ?: [0]);
        $sw = $dg = $ambos = 0;
        foreach ($mapa as $areas) {
            $s = in_array('software', $areas, true); $d = in_array('digital', $areas, true);
            if ($s) $sw++;
            if ($d) $dg++;
            if ($s && $d) $ambos++;
        }
        return ['ativos' => count($ativos), 'software' => $sw, 'digital' => $dg, 'nas_duas' => $ambos, 'sem_area' => count($ativos) - count($mapa)];
    }

    /** Vendas do mês pela regra do painel comercial (sem contar duas vezes). */
    public static function vendas(int $accountId, string $mes): array
    {
        $ini = substr($mes, 0, 7) . '-01'; $fim = date('Y-m-t', strtotime($ini));
        $valor = "COALESCE(NULLIF(c.valor_fechado_final,0), NULLIF(c.valor_proposta,0), IFNULL(c.valor_estimado,0))";
        $sql = "SELECT x.area, COUNT(DISTINCT x.venda) AS qtd, COALESCE(SUM(x.valor), 0) AS valor FROM (
                    SELECT CONVERT(COALESCE(c.area_servico, 'sem_area') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS area, $valor AS valor, CONVERT(CONCAT('k', c.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS venda
                      FROM cards c WHERE c.account_id = ? AND c.deleted_at IS NULL AND c.data_fechamento BETWEEN ? AND ?"
                    . \App\Clientes\CobrancaDoCliente::sqlFechamentoSemDuplicar('c') . "
                    UNION ALL
                    SELECT CONVERT(COALESCE(v.area, 'sem_area') USING utf8mb4) COLLATE utf8mb4_unicode_ci, v.valor, CONVERT(CONCAT('c', v.cliente_id, '-', v.inicio) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                      FROM crm_cliente_valores v JOIN clientes cl ON cl.id = v.cliente_id AND cl.account_id = v.account_id AND cl.deleted_at IS NULL
                     WHERE v.account_id = ? AND v.tipo <> 'demanda' AND v.inicio BETWEEN ? AND ?
                ) x GROUP BY x.area";
        $st = Database::getConnection()->prepare($sql);
        $st->execute([$accountId, $ini, $fim, $accountId, $ini, $fim]);
        $por = [];
        $qtd = 0; $tot = 0.0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $x) {
            $por[$x['area']] = ['qtd' => (int) $x['qtd'], 'valor' => round((float) $x['valor'], 2)];
            $tot += (float) $x['valor'];
        }
        // O total de vendas conta cada venda uma vez, mesmo que ela tenha linhas de áreas diferentes.
        $st = Database::getConnection()->prepare("SELECT COUNT(DISTINCT venda) FROM (
                    SELECT CONVERT(CONCAT('k', c.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS venda FROM cards c WHERE c.account_id = ? AND c.deleted_at IS NULL AND c.data_fechamento BETWEEN ? AND ?"
                    . \App\Clientes\CobrancaDoCliente::sqlFechamentoSemDuplicar('c') . "
                    UNION ALL
                    SELECT CONVERT(CONCAT('c', v.cliente_id, '-', v.inicio) USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM crm_cliente_valores v
                      JOIN clientes cl ON cl.id = v.cliente_id AND cl.account_id = v.account_id AND cl.deleted_at IS NULL
                     WHERE v.account_id = ? AND v.tipo <> 'demanda' AND v.inicio BETWEEN ? AND ?) y");
        $st->execute([$accountId, $ini, $fim, $accountId, $ini, $fim]);
        $qtd = (int) $st->fetchColumn();
        return ['qtd' => $qtd, 'valor' => round($tot, 2), 'por_area' => $por];
    }

    public static function projetosCarteira(int $accountId, ?string $area = null): array
    {
        $sql = "SELECT id, slug, nome, cliente, status, responsavel, area FROM projetos
                 WHERE account_id = ? AND deleted_at IS NULL AND status IN ('desenvolvimento', 'producao', 'pausado')";
        $args = [$accountId];
        if ($area === 'software') $sql .= " AND (area IS NULL OR area = 'software')";
        elseif ($area !== null) { $sql .= ' AND area = ?'; $args[] = $area; }
        $sql .= " ORDER BY FIELD(status, 'desenvolvimento', 'producao', 'pausado'), nome";
        try {
            $st = Database::getConnection()->prepare($sql);
            $st->execute($args);
        } catch (\Throwable $e) { return []; }
        return array_map(fn($p) => ['id' => (int) $p['id'], 'slug' => $p['slug'], 'nome' => $p['nome'], 'cliente' => $p['cliente'], 'status' => $p['status'],
            'responsavel' => $p['responsavel'], 'area' => $p['area'] ?: 'software'], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function contratosAguardando(int $accountId): int
    {
        try {
            $st = Database::getConnection()->prepare("SELECT COUNT(*) FROM crm_contratos WHERE account_id = ? AND status IN ('aguardando', 'parcial')");
            $st->execute([$accountId]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }

    /**
     * A Empresa: só para dono, administrador ou quem vê Finanças (Ambiente::podeEntrar).
     * @param array $quadrosVisiveis TaskBoard::findForUser
     */
    public static function empresa(int $accountId, array $quadrosVisiveis, string $mes, bool $veContratos, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $hoje = substr($agora, 0, 10);
        $sw = self::quadrosDaArea($quadrosVisiveis, 'software');
        $dg = self::quadrosDaArea($quadrosVisiveis, 'digital');
        $tSw = self::tarefas(array_keys($sw), $agora);
        $tDg = self::tarefas(array_keys($dg), $agora);
        $clientesDigitais = AreaDoCliente::clientesDaArea($accountId, 'digital');
        $ent = Entrega::resumo($accountId, $mes, $clientesDigitais, $agora)['total'];
        $aprov = 0;
        if ($dg) {
            $st = Database::getConnection()->prepare("SELECT COUNT(*) FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id
                WHERE e.account_id = ? AND e.aprovacao = 'aguardando' AND t.status = 'ativa' AND t.board_id IN (" . self::in(array_keys($dg)) . ')');
            $st->execute(array_merge([$accountId], array_keys($dg)));
            $aprov = (int) $st->fetchColumn();
        }
        $receita = self::receita($accountId, $hoje);
        $carteira = self::projetosCarteira($accountId);
        $marketing = self::digital($accountId, $quadrosVisiveis, $clientesDigitais, $mes, $agora);
        $verba = 0.0;
        try {
            $vb = Database::getConnection()->prepare("SELECT COALESCE(SUM(verba_mensal), 0) FROM crm_campanhas WHERE account_id = ? AND status = 'ativa'");
            $vb->execute([$accountId]);
            $verba = round((float) $vb->fetchColumn(), 2);
        } catch (\Throwable $e) { $verba = 0.0; }
        $marketing['total']['verba_ativa'] = $verba;
        return [
            // Cada área com o seu bloco: a Empresa é a visão de tudo.
            'financeiro' => self::financeiro($accountId, $mes, $hoje),
            'clientes_detalhe' => self::clientesDetalhe($accountId, $mes, $hoje),
            'software' => self::software($accountId, $quadrosVisiveis, $agora),
            'marketing' => $marketing,
            'comercial_areas' => self::comercialPorArea($accountId, $mes),
            'contratos' => $veContratos ? self::contratos($accountId, $mes) : null,
            'equipe' => self::equipe($accountId, $quadrosVisiveis, $mes, $agora),
            'mes' => substr($mes, 0, 7),
            'receita' => $receita,
            'clientes' => self::clientes($accountId),
            'vendas' => self::vendas($accountId, $mes),
            'projetos' => [
                'carteira' => count($carteira),
                'carteira_por_area' => array_count_values(array_column($carteira, 'area')),
                'quadros' => ['software' => count($sw), 'digital' => count($dg)],
            ],
            'entregas' => $ent,
            'atrasos' => [
                'tarefas_software' => $tSw['atrasadas'], 'tarefas_digital' => $tDg['atrasadas'],
                'entregas' => $ent['atrasadas'], 'cobrancas' => $receita['cobrancas_atrasadas'], 'cobrancas_valor' => $receita['atrasado'],
            ],
            'pendencias' => [
                'aprovacoes' => $aprov,
                'materiais' => Material::faltando($accountId, array_keys($dg)),
                'contratos' => $veContratos ? self::contratosAguardando($accountId) : null,
                'sem_responsavel' => $tSw['sem_responsavel'] + $tDg['sem_responsavel'],
            ],
        ];
    }

    // ── Empresa: as seções de cada área ───────────────────────────────────

    /** Dinheiro do mês: recebido (por área), a receber, em atraso, próximos 7 dias e o plano do DRE. */
    public static function financeiro(int $accountId, string $mes, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $ini = substr($mes, 0, 7) . '-01'; $fim = date('Y-m-t', strtotime($ini));
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT CONVERT(COALESCE(v.area, 'sem_area') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS area,
                                    COALESCE(SUM(CASE WHEN c.status = 'paga' AND c.pago_em BETWEEN ? AND ? THEN COALESCE(c.valor_pago, c.valor) END), 0) AS recebido,
                                    COALESCE(SUM(CASE WHEN c.status = 'aberta' AND c.vencimento BETWEEN ? AND ? THEN c.valor END), 0) AS a_receber,
                                    COALESCE(SUM(CASE WHEN c.status <> 'cancelada' AND c.vencimento BETWEEN ? AND ? THEN c.valor END), 0) AS previsto
                               FROM crm_cliente_cobrancas c LEFT JOIN crm_cliente_valores v ON v.id = c.valor_id AND v.account_id = c.account_id
                              WHERE c.account_id = ? GROUP BY area");
        $st->execute([$ini, $fim, $ini, $fim, $ini, $fim, $accountId]);
        $porArea = []; $tot = ['recebido' => 0.0, 'a_receber' => 0.0, 'previsto' => 0.0];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $x) {
            $porArea[$x['area']] = ['recebido' => round((float) $x['recebido'], 2), 'a_receber' => round((float) $x['a_receber'], 2), 'previsto' => round((float) $x['previsto'], 2)];
            foreach ($tot as $k => $_) $tot[$k] += (float) $x[$k];
        }
        $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS v FROM crm_cliente_cobrancas
                              WHERE account_id = ? AND status = 'aberta' AND vencimento BETWEEN ? AND ?");
        $st->execute([$accountId, $hoje, date('Y-m-d', strtotime($hoje . ' +7 days'))]);
        $prox = $st->fetch(\PDO::FETCH_ASSOC);
        $dre = ['receita' => 0.0, 'despesa' => 0.0, 'resultado' => 0.0];
        try { $dre = \App\Financas\DREAccount::summary(['account_ids' => [$accountId]]); } catch (\Throwable $e) { /* sem DRE */ }
        $rec = self::receita($accountId, $hoje);
        return [
            'mrr' => $rec['mrr'], 'mrr_por_area' => $rec['mrr_por_area'],
            'recebido' => round($tot['recebido'], 2), 'a_receber' => round($tot['a_receber'], 2), 'previsto' => round($tot['previsto'], 2),
            'por_area' => $porArea, 'atrasado' => $rec['atrasado'], 'cobrancas_atrasadas' => $rec['cobrancas_atrasadas'],
            'proximos_7_dias' => ['qtd' => (int) $prox['n'], 'valor' => round((float) $prox['v'], 2)],
            'dre' => ['receita' => round((float) $dre['receita'], 2), 'despesa' => round((float) $dre['despesa'], 2), 'resultado' => round((float) $dre['resultado'], 2)],
        ];
    }

    /** A base de clientes: ativos, novos no mês, por área, inadimplentes, sem responsável. */
    public static function clientesDetalhe(int $accountId, string $mes, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $ini = substr($mes, 0, 7) . '-01'; $fim = date('Y-m-t', strtotime($ini));
        $pdo = Database::getConnection();
        $base = self::clientes($accountId);
        $st = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE account_id = ? AND deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?");
        $st->execute([$accountId, $ini, $fim]);
        $novos = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(DISTINCT c.cliente_id) FROM crm_cliente_cobrancas c JOIN clientes cl ON cl.id = c.cliente_id AND cl.account_id = c.account_id AND cl.deleted_at IS NULL
                              WHERE c.account_id = ? AND c.status = 'aberta' AND c.vencimento < ?");
        $st->execute([$accountId, $hoje]);
        $inad = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE account_id = ? AND deleted_at IS NULL AND status = 'ativo' AND responsavel_id IS NULL");
        $st->execute([$accountId]);
        $semResp = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(status, ''), 'sem status') AS s, COUNT(*) AS n FROM clientes WHERE account_id = ? AND deleted_at IS NULL GROUP BY s ORDER BY n DESC");
        $st->execute([$accountId]);
        return $base + ['novos_mes' => $novos, 'inadimplentes' => $inad, 'sem_responsavel' => $semResp, 'por_status' => $st->fetchAll(\PDO::FETCH_KEY_PAIR)];
    }

    public static function contratos(int $accountId, string $mes): array
    {
        $ini = substr($mes, 0, 7) . '-01'; $fim = date('Y-m-t 23:59:59', strtotime($ini));
        try {
            $st = Database::getConnection()->prepare("SELECT
                    SUM(status IN ('aguardando', 'parcial')) AS aguardando, SUM(status = 'rascunho') AS rascunhos,
                    SUM(status = 'concluido' AND concluido_em BETWEEN ? AND ?) AS concluidos_mes, SUM(status = 'concluido') AS concluidos,
                    SUM(status IN ('expirado', 'recusado')) AS perdidos
                  FROM crm_contratos WHERE account_id = ?");
            $st->execute([$ini, $fim, $accountId]);
            return array_map('intval', $st->fetch(\PDO::FETCH_ASSOC) ?: []);
        } catch (\Throwable $e) { return []; }
    }

    /** A equipe: tarefas abertas e atrasadas por pessoa (todos os quadros que se vê) e o OTIF do mês. */
    public static function equipe(int $accountId, array $quadrosVisiveis, string $mes, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $t = self::tarefas(array_map(fn($b) => (int) $b['id'], $quadrosVisiveis), $agora);
        $de = substr($mes, 0, 7) . '-01';
        $ate = min(date('Y-m-t', strtotime($de)), substr($agora, 0, 10));
        $otif = null; $porPessoa = [];
        if ($ate >= $de) {
            try {
                $r = \App\Tarefas\Otif::relatorio([$accountId], $de, $ate);
                $otif = $r['equipe'];
                foreach ($r['colaboradores'] as $c) $porPessoa[(int) $c['user_id']] = $c['otif_pct'];
            } catch (\Throwable $e) { $otif = null; }
        }
        $pessoas = array_map(fn($p) => $p + ['otif_pct' => $porPessoa[$p['id']] ?? null], $t['por_responsavel']);
        return ['abertas' => $t['abertas'], 'atrasadas' => $t['atrasadas'], 'sem_responsavel' => $t['sem_responsavel'], 'otif' => $otif, 'pessoas' => $pessoas];
    }

    // ── Software ──────────────────────────────────────────────────────────

    public static function software(int $accountId, array $quadrosVisiveis, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $sw = QuadroDaArea::comNomeDoCliente($accountId, array_values(self::quadrosDaArea($quadrosVisiveis, 'software')));
        $ids = array_map(fn($b) => (int) $b['id'], $sw);
        $t = self::tarefas($ids, $agora);
        $quadros = [];
        $suporte = 0;
        foreach ($sw as $b) {
            $n = $t['por_quadro'][(int) $b['id']] ?? ['abertas' => 0, 'atrasadas' => 0, 'proximo' => null];
            if (($b['modelo'] ?? '') === 'suporte') $suporte += $n['abertas'];
            $quadros[] = ['id' => (int) $b['id'], 'nome' => $b['nome'], 'cliente' => $b['cliente_nome'] ?? null, 'modelo' => $b['modelo'] ?? null] + $n;
        }
        $nomes = array_column($quadros, 'nome', 'id');
        foreach ($t['proximos'] as &$p) $p['quadro'] = $nomes[$p['board_id']] ?? '';
        unset($p);
        return [
            'carteira' => self::projetosCarteira($accountId, 'software'),
            'quadros' => $quadros,
            'tarefas' => ['abertas' => $t['abertas'], 'atrasadas' => $t['atrasadas'], 'sem_responsavel' => $t['sem_responsavel'], 'suporte_abertos' => $suporte],
            'responsaveis' => $t['por_responsavel'],
            'proximos' => $t['proximos'],
        ];
    }

    // ── Digital ───────────────────────────────────────────────────────────

    /**
     * @param int[] $clienteIds clientes digitais que a pessoa pode ver
     */
    public static function digital(int $accountId, array $quadrosVisiveis, array $clienteIds, string $mes, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $dg = self::quadrosDaArea($quadrosVisiveis, 'digital');
        $t = self::tarefas(array_keys($dg), $agora);
        $res = Entrega::resumo($accountId, $mes, $clienteIds, $agora);
        $pdo = Database::getConnection();
        $nomes = [];
        if ($clienteIds) {
            $st = $pdo->prepare('SELECT id, nome FROM clientes WHERE account_id = ? AND id IN (' . self::in($clienteIds) . ') ORDER BY nome');
            $st->execute(array_merge([$accountId], $clienteIds));
            $nomes = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        $quadrosPorCliente = [];
        foreach ($dg as $b) if (!empty($b['cliente_id'])) $quadrosPorCliente[(int) $b['cliente_id']][] = ['id' => (int) $b['id'], 'nome' => $b['nome']];
        $aguardando = []; $campanhas = [];
        if ($clienteIds) {
            $st = $pdo->prepare("SELECT e.cliente_id, COUNT(*) FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id
                                  WHERE e.account_id = ? AND e.aprovacao = 'aguardando' AND t.status = 'ativa' AND e.cliente_id IN (" . self::in($clienteIds) . ')
                                  GROUP BY e.cliente_id');
            $st->execute(array_merge([$accountId], $clienteIds));
            $aguardando = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
            $st = $pdo->prepare("SELECT cliente_id, COUNT(*) FROM crm_campanhas WHERE account_id = ? AND status = 'ativa' AND cliente_id IN (" . self::in($clienteIds) . ')
                                  GROUP BY cliente_id');
            $st->execute(array_merge([$accountId], $clienteIds));
            $campanhas = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        $clientes = [];
        foreach ($nomes as $id => $nome) {
            $r = $res['clientes'][(int) $id] ?? [];
            $clientes[] = ['id' => (int) $id, 'nome' => $nome, 'quadros' => $quadrosPorCliente[(int) $id] ?? [],
                'aprovacoes' => (int) ($aguardando[$id] ?? 0), 'campanhas_ativas' => (int) ($campanhas[$id] ?? 0)] + $r;
        }
        $nomesQ = array_map(fn($b) => $b['nome'], $dg);
        foreach ($t['proximos'] as &$p) $p['quadro'] = $nomesQ[$p['board_id']] ?? '';
        unset($p);
        return [
            'mes' => substr($mes, 0, 7),
            'total' => $res['total'] + ['aprovacoes' => array_sum(array_map('intval', $aguardando)), 'campanhas_ativas' => array_sum(array_map('intval', $campanhas)),
                                         'materiais_faltando' => Material::faltando($accountId, array_keys($dg))],
            'clientes' => $clientes,
            'responsaveis' => $t['por_responsavel'],
            'proximos' => $t['proximos'],
            'sem_quadro' => count(array_filter($clientes, fn($c) => !$c['quadros'])),
        ];
    }

    // ── Comercial ─────────────────────────────────────────────────────────

    /** Leads abertos e vendas do mês por área do serviço (cards.area_servico). */
    public static function comercialPorArea(int $accountId, string $mes, ?int $soDoResponsavel = null): array
    {
        $valor = "COALESCE(NULLIF(c.valor_fechado_final,0), NULLIF(c.valor_proposta,0), IFNULL(c.valor_estimado,0))";
        $sql = "SELECT COALESCE(c.area_servico, 'sem_area') AS area, COUNT(*) AS qtd, COALESCE(SUM($valor), 0) AS valor
                  FROM cards c WHERE c.account_id = ? AND c.deleted_at IS NULL AND (c.data_fechamento IS NULL OR c.data_fechamento = '0000-00-00')";
        $args = [$accountId];
        if ($soDoResponsavel !== null) { $sql .= ' AND c.responsavel_user_id = ?'; $args[] = $soDoResponsavel; }
        $st = Database::getConnection()->prepare($sql . ' GROUP BY COALESCE(c.area_servico, \'sem_area\')');
        $st->execute($args);
        $abertos = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $x) $abertos[$x['area']] = ['qtd' => (int) $x['qtd'], 'valor' => round((float) $x['valor'], 2)];

        $ini = substr($mes, 0, 7) . '-01'; $fim = date('Y-m-t', strtotime($ini));
        $sql = "SELECT COALESCE(c.area_servico, 'sem_area') AS area, COUNT(*) AS qtd, COALESCE(SUM($valor), 0) AS valor
                  FROM cards c WHERE c.account_id = ? AND c.deleted_at IS NULL AND c.data_fechamento BETWEEN ? AND ?";
        $args = [$accountId, $ini, $fim];
        if ($soDoResponsavel !== null) { $sql .= ' AND c.responsavel_user_id = ?'; $args[] = $soDoResponsavel; }
        $st = Database::getConnection()->prepare($sql . ' GROUP BY COALESCE(c.area_servico, \'sem_area\')');
        $st->execute($args);
        $fechados = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $x) $fechados[$x['area']] = ['qtd' => (int) $x['qtd'], 'valor' => round((float) $x['valor'], 2)];
        return ['mes' => substr($mes, 0, 7), 'abertos' => $abertos, 'fechados' => $fechados];
    }
}
