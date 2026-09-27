<?php
namespace App\Tarefas;

use App\Core\Database;

/**
 * Otif: On Time In Full das tarefas, por colaborador.
 *
 * ---------------------------------------------------------------------------
 * A CONTA
 * ---------------------------------------------------------------------------
 * Compromisso = tarefa COM PRAZO que foi entregue ou que venceu sem entrega.
 *
 *   On Time   entregue até o prazo (horário local, ver TaskEntrega)
 *   In Full   entregue com o checklist inteiro marcado (sem checklist = inteiro)
 *   OTIF      as duas coisas juntas
 *
 *   OTIF %    = entregas no prazo E completas / compromissos
 *   No prazo% = entregas no prazo / compromissos
 *   Completas%= entregas completas / compromissos
 *
 * O denominador é COMPROMISSOS, e não entregas, de propósito: quem deixa a
 * tarefa vencer sem concluir não pode ficar com nota melhor do que quem entrega
 * atrasado. Não entregue conta contra as três.
 *
 * Não entregue é:
 *   perdida    recorrente que venceu e o cron renovou (gravada em task_entregas)
 *   em_atraso  tarefa ainda aberta com prazo vencido (lida ao vivo de `tasks`)
 *
 * Tarefa SEM prazo não tem como estar no prazo: fica fora do OTIF e aparece à
 * parte, em "sem prazo", para ninguém escapar da métrica deixando o prazo vazio
 * sem que isso fique visível.
 *
 * ---------------------------------------------------------------------------
 * EM QUE DIA CADA COISA CAI
 * ---------------------------------------------------------------------------
 * Entregue cai no dia da ENTREGA. Não entregue cai no dia do PRAZO. É o que
 * "desempenho do período" significa na prática: o que a pessoa fez, e o que
 * venceu na mão dela, naquele intervalo.
 *
 * Quem é avaliado: o responsável pela tarefa no momento da entrega (sem
 * responsável, quem concluiu). Tarefa sem responsável nenhum vai para a linha
 * "Sem responsável", que também é informação.
 */
final class Otif
{
    /** Faixas de cor. 90% é a referência usual de OTIF em operação. */
    public const META_BOM     = 90.0;
    public const META_ATENCAO = 75.0;

    public const MESES_EVOLUCAO = 6;
    /** Período máximo aceito, para uma URL editada à mão não varrer anos. */
    public const DIAS_MAXIMOS = 731;

    /**
     * @param int[]  $contas         contas acessíveis (AccountContext, módulo tarefas)
     * @param string $de             Y-m-d
     * @param string $ate            Y-m-d
     * @param ?int   $apenasUsuario  quem não é admin só vê a si mesmo
     * @param ?int   $foco           colaborador aberto em detalhe (0 = sem responsável)
     */
    public static function relatorio(array $contas, string $de, string $ate, ?int $apenasUsuario = null, ?int $foco = null): array
    {
        if ($apenasUsuario !== null) $foco = $apenasUsuario;

        $inicioEvolucao = date('Y-m-01', strtotime(date('Y-m-01', strtotime($ate)) . ' -' . (self::MESES_EVOLUCAO - 1) . ' months'));
        $desdeBusca     = min($de, $inicioEvolucao);

        $todas   = self::linhas($contas, $desdeBusca, $ate, $apenasUsuario);
        $periodo = array_values(array_filter($todas, static fn($l) => $l['dia'] >= $de && $l['dia'] <= $ate));

        $grupos = self::porColaborador($periodo);
        $nomes  = self::nomes(array_keys($grupos));

        $colaboradores = [];
        foreach ($grupos as $uid => $m) {
            $colaboradores[] = ['user_id' => (int) $uid, 'nome' => $nomes[(int) $uid] ?? ('Usuário #' . $uid)] + $m;
        }
        usort($colaboradores, static function ($a, $b) {
            $pa = $a['otif_pct'] ?? -1; $pb = $b['otif_pct'] ?? -1;
            if ($pa !== $pb) return $pb <=> $pa;
            if ($a['compromissos'] !== $b['compromissos']) return $b['compromissos'] <=> $a['compromissos'];
            return strcmp($a['nome'], $b['nome']);
        });

        $focoLinhas = $foco === null ? $todas : array_values(array_filter($todas, static fn($l) => (int) ($l['responsavel_id'] ?? 0) === $foco));
        $focoPeriodo = $foco === null ? [] : array_values(array_filter($focoLinhas, static fn($l) => $l['dia'] >= $de && $l['dia'] <= $ate));

        return [
            'de'            => $de,
            'ate'           => $ate,
            'equipe'        => self::metricas($periodo),
            'colaboradores' => $colaboradores,
            'evolucao'      => self::porMes($focoLinhas, $ate, self::MESES_EVOLUCAO),
            'foco'          => $foco === null ? null : [
                'user_id'    => $foco,
                'nome'       => $foco === 0 ? 'Sem responsável' : (self::nomes([$foco])[$foco] ?? ('Usuário #' . $foco)),
                'metricas'   => self::metricas($focoPeriodo),
                'pendencias' => self::pendencias($focoPeriodo),
            ],
        ];
    }

    /**
     * As linhas normalizadas do intervalo, já com `dia` (Y-m-d local) e
     * `concluida_local`. Busca com um dia de folga de cada lado porque a
     * conclusão está em UTC; o corte exato é feito aqui, no fuso local.
     */
    public static function linhas(array $contas, string $de, string $ate, ?int $apenasUsuario = null): array
    {
        $contas = array_values(array_unique(array_filter(array_map('intval', $contas), static fn($c) => $c > 0)));
        if ($contas === []) return [];

        $pdo   = Database::getConnection();
        $in    = implode(',', array_fill(0, count($contas), '?'));
        $deB   = date('Y-m-d', strtotime($de . ' -1 day'));
        $ateB  = date('Y-m-d', strtotime($ate . ' +2 days'));
        $filtroUsuario = $apenasUsuario !== null ? ' AND e.responsavel_id = ?' : '';

        $sql = "SELECT e.task_id, e.responsavel_id, e.tipo, e.prazo, e.concluida_em, e.no_prazo, e.completa,
                       e.origem, e.checklist_total, e.checklist_feitos, t.titulo
                  FROM task_entregas e
                  LEFT JOIN tasks t ON t.id = e.task_id
                 WHERE e.account_id IN ($in)
                   AND ( (e.concluida_em >= ? AND e.concluida_em < ?)
                      OR (e.concluida_em IS NULL AND e.prazo >= ? AND e.prazo < ?) )
                   $filtroUsuario";
        $params = array_merge($contas, [$deB, $ateB, $deB, $ateB]);
        if ($apenasUsuario !== null) $params[] = $apenasUsuario;

        $out = [];
        $st  = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $local = $r['concluida_em'] !== null ? TaskEntrega::paraHorarioLocal((string) $r['concluida_em']) : null;
            $ref   = $local ?? (string) $r['prazo'];
            $out[] = [
                'task_id'          => (int) $r['task_id'],
                'titulo'           => (string) ($r['titulo'] ?? ('Tarefa #' . $r['task_id'])),
                'responsavel_id'   => $r['responsavel_id'] !== null ? (int) $r['responsavel_id'] : null,
                'tipo'             => (string) $r['tipo'],
                'prazo'            => $r['prazo'] !== null ? (string) $r['prazo'] : null,
                'concluida_local'  => $local,
                'dia'              => substr($ref, 0, 10),
                'no_prazo'         => $r['no_prazo'] !== null ? (int) $r['no_prazo'] : null,
                'completa'         => (int) $r['completa'],
                'origem'           => (string) $r['origem'],
                'checklist_total'  => (int) $r['checklist_total'],
                'checklist_feitos' => (int) $r['checklist_feitos'],
            ];
        }

        // Em atraso, ao vivo: aberta, com prazo vencido no horário local.
        // Quadro desativado não assombra a nota de ninguém, e de recorrência só
        // conta a instância que o quadro mostra (ver TaskEntrega::instanciaVisivel).
        $filtroUsuarioT = $apenasUsuario !== null ? ' AND t.responsavel_id = ?' : '';
        $sqlAberto = "SELECT t.id, t.titulo, t.responsavel_id, t.prazo
                        FROM tasks t
                        JOIN task_boards b ON b.id = t.board_id
                       WHERE b.account_id IN ($in) AND b.ativo = 1
                         AND t.status = 'ativa' AND t.prazo IS NOT NULL
                         AND t.prazo < ? AND t.prazo >= ? AND t.prazo < ?
                         AND (t.recorrencia_id IS NULL OR t.id = (
                               SELECT t2.id FROM tasks t2
                                WHERE t2.recorrencia_id = t.recorrencia_id AND t2.status <> 'arquivada'
                                ORDER BY t2.prazo DESC, t2.id DESC LIMIT 1))
                         $filtroUsuarioT";
        $p2 = array_merge($contas, [TaskEntrega::agoraLocal(), $de . ' 00:00:00', date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00']);
        if ($apenasUsuario !== null) $p2[] = $apenasUsuario;

        $st2 = $pdo->prepare($sqlAberto);
        $st2->execute($p2);
        foreach ($st2->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            [$tot, $feitos] = TaskEntrega::checklist((int) $r['id']);
            $out[] = [
                'task_id'          => (int) $r['id'],
                'titulo'           => (string) $r['titulo'],
                'responsavel_id'   => $r['responsavel_id'] !== null ? (int) $r['responsavel_id'] : null,
                'tipo'             => 'em_atraso',
                'prazo'            => (string) $r['prazo'],
                'concluida_local'  => null,
                'dia'              => substr((string) $r['prazo'], 0, 10),
                'no_prazo'         => 0,
                'completa'         => 0,
                'origem'           => 'registro',
                'checklist_total'  => $tot,
                'checklist_feitos' => $feitos,
            ];
        }

        return array_values(array_filter($out, static fn($l) => $l['dia'] >= $de && $l['dia'] <= $ate));
    }

    /** Os números de um conjunto de linhas. Pura: é o que o teste confere. */
    public static function metricas(array $linhas): array
    {
        $m = [
            'compromissos' => 0, 'no_prazo' => 0, 'completas' => 0, 'otif' => 0,
            'atrasadas' => 0, 'incompletas' => 0, 'nao_entregues' => 0,
            'perdidas' => 0, 'em_atraso' => 0, 'sem_prazo' => 0, 'retroativas' => 0,
        ];
        foreach ($linhas as $l) {
            if (($l['origem'] ?? '') === 'retroativo') $m['retroativas']++;
            $tipo = $l['tipo'] ?? 'concluida';

            if ($tipo === 'perdida' || $tipo === 'em_atraso') {
                $m['compromissos']++;
                $m['nao_entregues']++;
                $m[$tipo === 'perdida' ? 'perdidas' : 'em_atraso']++;
                continue;
            }

            if (($l['prazo'] ?? null) === null || ($l['no_prazo'] ?? null) === null) {
                $m['sem_prazo']++;
                continue;
            }

            $m['compromissos']++;
            $ot  = (int) $l['no_prazo'] === 1;
            $if  = (int) ($l['completa'] ?? 0) === 1;
            if ($ot) $m['no_prazo']++;   else $m['atrasadas']++;
            if ($if) $m['completas']++;  else $m['incompletas']++;
            if ($ot && $if) $m['otif']++;
        }

        $pct = static fn(int $n) => $m['compromissos'] > 0 ? round($n * 100 / $m['compromissos'], 1) : null;
        $m['otif_pct'] = $pct($m['otif']);
        $m['ot_pct']   = $pct($m['no_prazo']);
        $m['if_pct']   = $pct($m['completas']);
        return $m;
    }

    /** @return array<int, array> chave = user_id (0 = sem responsável) */
    public static function porColaborador(array $linhas): array
    {
        $g = [];
        foreach ($linhas as $l) $g[(int) ($l['responsavel_id'] ?? 0)][] = $l;
        $out = [];
        foreach ($g as $uid => $ls) $out[$uid] = self::metricas($ls);
        return $out;
    }

    /** Série mensal dos últimos $n meses terminando no mês de $ate. Pura. */
    public static function porMes(array $linhas, string $ate, int $n): array
    {
        $rot = ['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez'];
        $base = date('Y-m-01', strtotime($ate));
        $serie = ['meses' => [], 'rotulos' => [], 'otif' => [], 'ot' => [], 'if' => [], 'compromissos' => []];
        for ($i = $n - 1; $i >= 0; $i--) {
            $mes = date('Y-m', strtotime($base . " -$i months"));
            $m   = self::metricas(array_filter($linhas, static fn($l) => substr($l['dia'], 0, 7) === $mes));
            $serie['meses'][]        = $mes;
            $serie['rotulos'][]      = $rot[substr($mes, 5, 2)] . '/' . substr($mes, 2, 2);
            $serie['otif'][]         = $m['otif_pct'];
            $serie['ot'][]           = $m['ot_pct'];
            $serie['if'][]           = $m['if_pct'];
            $serie['compromissos'][] = $m['compromissos'];
        }
        return $serie;
    }

    /** O que tirou o OTIF, mais recente primeiro. Pura. */
    public static function pendencias(array $linhas, int $limite = 100): array
    {
        $out = [];
        foreach ($linhas as $l) {
            $tipo = $l['tipo'] ?? 'concluida';
            if ($tipo === 'concluida') {
                if (($l['no_prazo'] ?? null) === null) continue;
                $ot = (int) $l['no_prazo'] === 1; $if = (int) $l['completa'] === 1;
                if ($ot && $if) continue;
                $faltam = sprintf('%d de %d itens', (int) $l['checklist_feitos'], (int) $l['checklist_total']);
                $motivo = !$ot && !$if ? "Entregue com atraso e incompleta ($faltam)"
                        : (!$ot ? 'Entregue com atraso' : "Entregue incompleta ($faltam)");
            } elseif ($tipo === 'perdida') {
                $motivo = 'Não entregue: venceu e a recorrência renovou';
            } else {
                $motivo = 'Não entregue: segue aberta, prazo vencido';
            }
            $out[] = [
                'task_id' => $l['task_id'], 'titulo' => $l['titulo'], 'prazo' => $l['prazo'],
                'entregue' => $l['concluida_local'], 'motivo' => $motivo, 'tipo' => $tipo,
                'estimado' => ($l['origem'] ?? '') === 'retroativo', 'dia' => $l['dia'],
            ];
        }
        usort($out, static fn($a, $b) => strcmp($b['dia'], $a['dia']));
        return array_slice($out, 0, $limite);
    }

    /**
     * Período vindo da URL, validado. Padrão: últimos 30 dias até hoje (local).
     * Datas inválidas voltam ao padrão, invertidas são trocadas, e o intervalo
     * é limitado a DIAS_MAXIMOS.
     *
     * @return array{0:string,1:string} [de, ate] em Y-m-d
     */
    public static function periodo(?string $de, ?string $ate): array
    {
        $valida = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false
                                   && date('Y-m-d', strtotime($d)) === $d;
        $hoje = substr(TaskEntrega::agoraLocal(), 0, 10);
        $ate  = $valida($ate) ? $ate : $hoje;
        $de   = $valida($de)  ? $de  : date('Y-m-d', strtotime($ate . ' -29 days'));
        if ($de > $ate) [$de, $ate] = [$ate, $de];
        $minimo = date('Y-m-d', strtotime($ate . ' -' . (self::DIAS_MAXIMOS - 1) . ' days'));
        if ($de < $minimo) $de = $minimo;
        return [$de, $ate];
    }

    public static function faixa(?float $pct): string
    {
        if ($pct === null) return 'sem';
        if ($pct >= self::META_BOM) return 'bom';
        if ($pct >= self::META_ATENCAO) return 'atencao';
        return 'ruim';
    }

    /** @return array<int,string> */
    private static function nomes(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        $out = [0 => 'Sem responsável'];
        if ($ids === []) return $out;
        $st = Database::getConnection()->prepare(
            'SELECT id, nome FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $st->execute($ids);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(int) $r['id']] = (string) $r['nome'];
        return $out;
    }
}
