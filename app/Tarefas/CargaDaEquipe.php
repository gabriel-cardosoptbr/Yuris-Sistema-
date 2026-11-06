<?php
namespace App\Tarefas;

use App\Core\Database;

/**
 * CargaDaEquipe: o que está na mão de cada pessoa AGORA.
 *
 * O OTIF olha para trás (o que foi entregue ou venceu no período). Esta classe olha
 * para o presente, sem filtro de data: tarefas abertas por responsável, separadas
 * em vencidas, vencendo em até 7 dias, em dia e sem prazo.
 *
 * "Aberta" é a mesma regra do OTIF e das Visões Gerais: tarefa ativa, em quadro
 * ativo, fora da coluna de concluído. De recorrência conta só a instância que o
 * quadro mostra (ver TaskEntrega::instanciaVisivel). O prazo é hora de Brasília,
 * então a comparação usa TaskEntrega::agoraLocal, nunca NOW().
 */
final class CargaDaEquipe
{
    public const DIAS_VENCENDO = 7;

    /**
     * @param int[] $contas         contas acessíveis (módulo tarefas)
     * @param ?int  $apenasUsuario  quem não é admin só vê a si mesmo
     * @return array{equipe:array, pessoas:array<int,array>} chave de `pessoas` = user_id (0 = sem responsável)
     */
    public static function agora(array $contas, ?int $apenasUsuario = null, ?string $agora = null): array
    {
        $contas = array_values(array_unique(array_filter(array_map('intval', $contas), static fn($c) => $c > 0)));
        if ($contas === []) return self::consolidar([], $agora ?? TaskEntrega::agoraLocal());

        $in  = implode(',', array_fill(0, count($contas), '?'));
        $sql = "SELECT t.responsavel_id, t.prazo
                  FROM tasks t
                  JOIN task_boards b ON b.id = t.board_id
                  LEFT JOIN task_columns col ON col.id = t.column_id
                 WHERE b.account_id IN ($in) AND b.ativo = 1 AND t.status = 'ativa'
                   AND COALESCE(col.is_coluna_concluido, 0) = 0
                   AND (t.recorrencia_id IS NULL OR t.id = (
                         SELECT t2.id FROM tasks t2
                          WHERE t2.recorrencia_id = t.recorrencia_id AND t2.status <> 'arquivada'
                          ORDER BY t2.prazo DESC, t2.id DESC LIMIT 1))"
             . ($apenasUsuario !== null ? ' AND t.responsavel_id = ?' : '');
        $params = $contas;
        if ($apenasUsuario !== null) $params[] = $apenasUsuario;

        $st = Database::getConnection()->prepare($sql);
        $st->execute($params);
        return self::consolidar($st->fetchAll(\PDO::FETCH_ASSOC), $agora ?? TaskEntrega::agoraLocal());
    }

    /**
     * A conta, sem banco.
     *
     * @param array<int, array{responsavel_id:mixed, prazo:?string}> $linhas
     */
    public static function consolidar(array $linhas, string $agora): array
    {
        $zero   = ['abertas' => 0, 'vencidas' => 0, 'vencendo' => 0, 'em_dia' => 0, 'sem_prazo' => 0];
        $limite = date('Y-m-d H:i:s', strtotime($agora . ' +' . self::DIAS_VENCENDO . ' days'));
        $equipe = $zero; $pessoas = [];
        foreach ($linhas as $l) {
            $uid   = (int) ($l['responsavel_id'] ?? 0);
            $prazo = $l['prazo'] ?? null;
            $faixa = $prazo === null || $prazo === '' ? 'sem_prazo'
                   : ($prazo < $agora ? 'vencidas' : ($prazo <= $limite ? 'vencendo' : 'em_dia'));
            $pessoas[$uid] ??= $zero;
            foreach ([&$pessoas[$uid], &$equipe] as &$alvo) { $alvo['abertas']++; $alvo[$faixa]++; }
            unset($alvo);
        }
        return ['equipe' => $equipe, 'pessoas' => $pessoas];
    }
}
