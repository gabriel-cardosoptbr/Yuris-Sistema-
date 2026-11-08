<?php
namespace App\Tarefas;

use App\Core\Database;

/**
 * CENTRAL DE TAREFAS (05/11/2026): tudo o que o escritório tem para fazer, numa tela.
 *
 * O PROBLEMA. O sistema tem três lugares onde "algo a fazer" é gravado:
 *
 *   tasks             quadros (Kanban), o que a tela Tarefas mostrava
 *   processo_tarefas  tarefas processuais, feitas dentro do processo
 *   processo_prazos   prazos do processo, inclusive os criados pelas intimações
 *
 * Quem criava uma tarefa dentro do processo não a via em Tarefas, nem no
 * calendário. Precisava abrir processo por processo para lembrar.
 *
 * A DECISÃO: LEITURA, NÃO CÓPIA. A central lê as três tabelas e devolve uma lista
 * só; nada é copiado. Cada tarefa continua sendo UM registro, então concluir ou
 * editar pela central é o mesmo que fazer pelo processo, e vice-versa. As escritas
 * continuam nos endpoints de sempre (`tasks.php`, `processo_tarefas.php`,
 * `processo_prazos.php`), que já têm auditoria, webhook e checagem de conta.
 *
 * Fundir as tabelas numa só foi descartado: `processo_tarefas` tem webhook público
 * (`processo.tarefa_created`), timeline, dossiê e o responsável em texto, e `tasks`
 * exige quadro e coluna. Seria mexer em tudo para ganhar o que esta leitura já dá.
 *
 * ESCOPO. Cada lado usa a regra da sua tela e nada além dela:
 *   quadros   mesma visibilidade de TaskBoard::findForUser, mais o que a pessoa
 *             criou ou recebeu em quadro alheio dentro das contas dela
 *   processos mesma regra de Processo::list: contas acessíveis + resource_shares
 *
 * PERÍODOS. "Esta semana" é de segunda a domingo e "Este mês" é o mês do
 * calendário, os dois inteiros: o que venceu na segunda sem ser feito continua
 * sendo desta semana. A classificação fica aqui, e a tela só filtra, para contador,
 * Kanban e lista nunca discordarem entre si.
 */
class Central
{
    /** Colunas do Kanban da central, na ordem. */
    public const COLUNAS = ['atrasadas', 'hoje', 'amanha', 'semana', 'mes', 'depois', 'sem_data', 'concluidas'];

    /** Etiquetas do filtro de período, que uma mesma tarefa pode acumular. */
    public const PERIODOS = ['atrasadas', 'hoje', 'amanha', 'semana', 'mes', 'sem_data'];

    private const LIMITE_POR_ORIGEM = 2000;

    /**
     * Em quais períodos a data cai, e em que coluna do Kanban fica.
     *
     * @return array{periodos: string[], coluna: string}
     */
    public static function classificar(?string $data, string $hoje, bool $concluida = false): array
    {
        $data = $data ? substr($data, 0, 10) : null;
        if ($data === null || $data === '') {
            return ['periodos' => ['sem_data'], 'coluna' => $concluida ? 'concluidas' : 'sem_data'];
        }

        $dia     = new \DateTimeImmutable($hoje);
        $amanha  = $dia->modify('+1 day')->format('Y-m-d');
        $segunda = $dia->modify('-' . ((int) $dia->format('N') - 1) . ' days')->format('Y-m-d');
        $domingo = $dia->modify('+' . (7 - (int) $dia->format('N')) . ' days')->format('Y-m-d');
        $iniMes  = $dia->format('Y-m-01');
        $fimMes  = $dia->format('Y-m-t');

        $periodos = [];
        if ($data < $hoje && !$concluida)              $periodos[] = 'atrasadas';
        if ($data === $hoje)                           $periodos[] = 'hoje';
        if ($data === $amanha)                         $periodos[] = 'amanha';
        if ($data >= $segunda && $data <= $domingo)    $periodos[] = 'semana';
        if ($data >= $iniMes && $data <= $fimMes)      $periodos[] = 'mes';

        if ($concluida)            $coluna = 'concluidas';
        elseif ($data < $hoje)     $coluna = 'atrasadas';
        elseif ($data === $hoje)   $coluna = 'hoje';
        elseif ($data === $amanha) $coluna = 'amanha';
        elseif ($data <= $domingo) $coluna = 'semana';
        elseif ($data <= $fimMes)  $coluna = 'mes';
        else                       $coluna = 'depois';

        return ['periodos' => $periodos, 'coluna' => $coluna];
    }

    /**
     * A lista unificada, já classificada.
     *
     * @param array{user_id:int, is_admin:bool, contas_tarefas:int[], contas_processos:int[]} $escopo
     * @param array $filtros tipo (juridica|escritorio), situacao (abertas|concluidas|todas),
     *                       responsavel_id, prioridade, busca, processo_id
     * @return array{itens: array, contagem: array, hoje: string}
     */
    public static function listar(array $escopo, array $filtros = []): array
    {
        $pdo  = Database::getConnection();
        $hoje = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();

        $tipo = (string) ($filtros['tipo'] ?? '');
        $brutos = [];
        if ($tipo !== 'juridica' || !empty($filtros['processo_id'])) {
            $brutos = array_merge($brutos, self::tarefasDeQuadro($escopo, $filtros));
        }
        if ($tipo !== 'escritorio' && !empty($escopo['contas_processos'])) {
            $brutos = array_merge($brutos, self::tarefasDeProcesso($escopo, $filtros));
            $brutos = array_merge($brutos, self::prazosDeProcesso($escopo, $filtros));
        }

        $contagem = array_fill_keys(self::PERIODOS, 0);
        $itens    = [];
        foreach ($brutos as $i) {
            $i['tipo'] = $i['processo_id'] ? 'juridica' : 'escritorio';
            if ($tipo !== '' && $i['tipo'] !== $tipo) continue;

            $c = self::classificar($i['data'], $hoje, $i['concluida']);
            $i['periodos']       = $c['periodos'];
            $i['coluna_central'] = $c['coluna'];
            foreach ($c['periodos'] as $p) $contagem[$p]++;
            $itens[] = $i;
        }

        usort($itens, static function (array $a, array $b): int {
            if ($a['concluida'] !== $b['concluida']) return $a['concluida'] ? 1 : -1;
            if (($a['data'] === null) !== ($b['data'] === null)) return $a['data'] === null ? 1 : -1;
            return [$a['data'], $a['hora'] ?? '', $a['titulo']] <=> [$b['data'], $b['hora'] ?? '', $b['titulo']];
        });

        return ['itens' => $itens, 'contagem' => $contagem, 'hoje' => $hoje];
    }

    /**
     * Procura processo por nome do cliente ou número, para a tarefa jurídica.
     * Só devolve o que a pessoa já enxerga em Processos.
     */
    public static function buscarProcessos(array $contas, int $userId, string $termo, int $limite = 12, string $cliente = ''): array
    {
        $termo = trim($termo); $cliente = trim($cliente);
        if (!$contas) return [];

        // Sem termo, a lista já aparece com os mais recentes ("já tem que estar aparecendo");
        // com o cliente escolhido, só os processos dele.
        $params = [];
        $escopo = self::clausulaProcesso($contas, $userId, $params);
        $filtro = '';
        if ($termo !== '') {
            $params += ['q1' => "%{$termo}%", 'q2' => "%{$termo}%", 'q3' => "%{$termo}%"];
            // Número se procura só pelos dígitos: quem digita "1009347" tem de achar
            // "1009347-41.2024.8.26.0100".
            // Só quando o termo é um número: nome com algarismos não vira busca por dígito.
            $digitos   = preg_replace('/\D+/', '', $termo);
            $porNumero = "p.numero LIKE :q2 OR p.numero_cnj LIKE :q3";
            if (strlen($digitos) >= 3 && preg_match('/^[\d.\-\/\s]+$/', $termo)) {
                $params['dig'] = "%{$digitos}%";
                $porNumero .= " OR " . self::soDigitos('p.numero_cnj') . " LIKE :dig";
            }
            $filtro .= " AND (p.cliente_nome LIKE :q1 OR p.tipo_acao LIKE :q1 OR {$porNumero})";
        }
        if ($cliente !== '') { $params['cli'] = $cliente; $filtro .= " AND p.cliente_nome = :cli"; }

        $st = Database::getConnection()->prepare(
            "SELECT p.id,
                    COALESCE(NULLIF(p.numero_cnj,''), NULLIF(p.numero,''), CONCAT('#', p.id)) AS numero,
                    COALESCE(p.cliente_nome, '') AS cliente_nome,
                    COALESCE(p.tipo_acao, '')    AS tipo_acao,
                    COALESCE(p.status, '')       AS status
               FROM processos p
              WHERE p.deleted_at IS NULL
                AND {$escopo}{$filtro}
           ORDER BY p.updated_at DESC
              LIMIT " . max(1, min($limite, 30))
        );
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Os clientes que têm processo, para o primeiro campo da tarefa jurídica: o nome e
     * quantos processos ele tem. Mesmo escopo de Processos (conta e compartilhamento).
     */
    public static function buscarClientes(array $contas, int $userId, string $termo, int $limite = 20): array
    {
        $termo = trim($termo);
        if (!$contas) return [];
        $params = [];
        $escopo = self::clausulaProcesso($contas, $userId, $params);
        $filtro = '';
        if ($termo !== '') { $params['q'] = "%{$termo}%"; $filtro = " AND p.cliente_nome LIKE :q"; }
        $st = Database::getConnection()->prepare(
            "SELECT p.cliente_nome AS nome, COUNT(*) AS processos, MAX(p.updated_at) AS ultimo
               FROM processos p
              WHERE p.deleted_at IS NULL AND p.cliente_nome IS NOT NULL AND p.cliente_nome <> ''
                AND {$escopo}{$filtro}
           GROUP BY p.cliente_nome
           ORDER BY ultimo DESC
              LIMIT " . max(1, min($limite, 40))
        );
        $st->execute($params);
        return array_map(fn ($r) => ['nome' => $r['nome'], 'processos' => (int) $r['processos']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /* ------------------------------------------------------------------ */

    private static function tarefasDeQuadro(array $escopo, array $filtros): array
    {
        $contas = array_values(array_filter(array_map('intval', $escopo['contas_tarefas'] ?? [])));
        if (!$contas) return [];

        $params = [
            'adm'   => !empty($escopo['is_admin']) ? 1 : 0,
            'uid1'  => (int) $escopo['user_id'],
            'uid2'  => (int) $escopo['user_id'],
            'uid3'  => (int) $escopo['user_id'],
            'uid4'  => (int) $escopo['user_id'],
        ];
        $in = self::emLista($contas, 'tc', $params);

        $where = [
            "b.ativo = 1",
            "b.account_id IN ({$in})",
            "t.status <> 'arquivada'",
            // Regra do Task::findByBoard: uma tarefa recorrente aparece uma vez só.
            "(t.recorrencia_id IS NULL OR t.id = (
                SELECT t2.id FROM tasks t2
                 WHERE t2.recorrencia_id = t.recorrencia_id AND t2.status <> 'arquivada'
              ORDER BY t2.prazo DESC, t2.id DESC LIMIT 1))",
            "(:adm = 1 OR b.owner_id = :uid1 OR b.tipo = 'compartilhado'
              OR EXISTS (SELECT 1 FROM task_board_members m WHERE m.board_id = b.id AND m.user_id = :uid2)
              OR t.responsavel_id = :uid3 OR t.criado_por_id = :uid4)",
        ];
        $situacao = (string) ($filtros['situacao'] ?? 'abertas');
        if ($situacao === 'abertas')    $where[] = "t.status = 'ativa'";
        if ($situacao === 'concluidas') $where[] = "t.status = 'concluida'";

        if (!empty($filtros['responsavel_id'])) {
            $where[] = 't.responsavel_id = :resp';
            $params['resp'] = (int) $filtros['responsavel_id'];
        }
        if (!empty($filtros['prioridade'])) {
            $where[] = 't.prioridade = :prio';
            $params['prio'] = (string) $filtros['prioridade'];
        }

        // O vínculo com processo só aparece se o processo for visível para a pessoa.
        $contasProc = array_values(array_filter(array_map('intval', $escopo['contas_processos'] ?? [])));
        $joinProc   = '';
        $camposProc = "NULL AS processo_id, NULL AS processo_numero, NULL AS cliente_nome";
        if ($contasProc) {
            $inP = self::clausulaProcesso($contasProc, (int) $escopo['user_id'], $params, 'lk');
            $joinProc = "LEFT JOIN (
                            SELECT tl.task_id, MIN(tl.link_id) AS processo_id
                              FROM task_links tl
                              JOIN processos p ON p.id = tl.link_id
                             WHERE tl.link_type = 'processo' AND p.deleted_at IS NULL AND {$inP}
                          GROUP BY tl.task_id
                         ) vinc ON vinc.task_id = t.id
                         LEFT JOIN processos pv ON pv.id = vinc.processo_id";
            $camposProc = "vinc.processo_id,
                           COALESCE(NULLIF(pv.numero_cnj,''), NULLIF(pv.numero,'')) AS processo_numero,
                           pv.cliente_nome";
        }
        if (!empty($filtros['processo_id'])) {
            if (!$contasProc) return [];
            $where[] = 'vinc.processo_id = :pid';
            $params['pid'] = (int) $filtros['processo_id'];
        }
        if (($filtros['busca'] ?? '') !== '') {
            $params['b1'] = '%' . $filtros['busca'] . '%';
            $trechos = ['t.titulo LIKE :b1'];
            if ($contasProc) {
                $params['b2'] = $params['b3'] = $params['b4'] = $params['b1'];
                $trechos[] = 'pv.cliente_nome LIKE :b2';
                $trechos[] = 'pv.numero LIKE :b3';
                $trechos[] = 'pv.numero_cnj LIKE :b4';
            }
            $where[] = '(' . implode(' OR ', $trechos) . ')';
        }

        $st = Database::getConnection()->prepare(
            "SELECT t.id, t.titulo, t.prioridade, t.prazo, t.status, t.responsavel_id, t.board_id,
                    u.nome AS responsavel_nome,
                    b.nome AS quadro_nome, col.nome AS coluna_nome,
                    b.account_id AS origin_account_id, a.nome AS origin_account_nome, a.tipo AS origin_account_tipo,
                    {$camposProc}
               FROM tasks t
               JOIN task_boards b ON b.id = t.board_id
          LEFT JOIN task_columns col ON col.id = t.column_id
          LEFT JOIN users u    ON u.id = t.responsavel_id
          LEFT JOIN accounts a ON a.id = b.account_id
               {$joinProc}
              WHERE " . implode(' AND ', $where) . "
           ORDER BY t.prazo IS NULL, t.prazo, t.id
              LIMIT " . self::LIMITE_POR_ORIGEM
        );
        $st->execute($params);

        $saida = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $l) {
            $hora = $l['prazo'] ? substr((string) $l['prazo'], 11, 5) : '';
            $saida[] = [
                'chave'               => 'tarefa:' . $l['id'],
                'origem'              => 'tarefa',
                'id'                  => (int) $l['id'],
                'titulo'              => (string) $l['titulo'],
                'prioridade'          => (string) $l['prioridade'],
                'data'                => $l['prazo'] ? substr((string) $l['prazo'], 0, 10) : null,
                'hora'                => $hora !== '' && $hora !== '00:00' ? $hora : null,
                'concluida'           => $l['status'] === 'concluida',
                'responsavel_nome'    => $l['responsavel_nome'],
                'processo_id'         => $l['processo_id'] ? (int) $l['processo_id'] : null,
                'processo_numero'     => $l['processo_numero'],
                'cliente_nome'        => $l['cliente_nome'],
                'quadro_nome'         => $l['quadro_nome'],
                'coluna_nome'         => $l['coluna_nome'],
                'origin_account_id'   => (int) $l['origin_account_id'],
                'origin_account_nome' => $l['origin_account_nome'],
                'origin_account_tipo' => $l['origin_account_tipo'],
            ];
        }
        return $saida;
    }

    private static function tarefasDeProcesso(array $escopo, array $filtros): array
    {
        $situacao = (string) ($filtros['situacao'] ?? 'abertas');
        $extra = [];
        if ($situacao === 'abertas')    $extra[] = 'pt.concluido = 0';
        if ($situacao === 'concluidas') $extra[] = 'pt.concluido = 1';

        return self::linhasDeProcesso('processo_tarefas', 'pt', 'titulo', 'data_tarefa', 'processo_tarefa',
            "pt.id, pt.titulo AS titulo, pt.prioridade, pt.data_tarefa AS data_item, pt.responsavel, pt.concluido",
            $extra, $escopo, $filtros,
            static fn (array $l): bool => (int) $l['concluido'] === 1);
    }

    private static function prazosDeProcesso(array $escopo, array $filtros): array
    {
        $situacao = (string) ($filtros['situacao'] ?? 'abertas');
        $extra = [];
        if ($situacao === 'abertas')    $extra[] = "(pz.status IS NULL OR pz.status <> 'concluido')";
        if ($situacao === 'concluidas') $extra[] = "pz.status = 'concluido'";

        return self::linhasDeProcesso('processo_prazos', 'pz', 'descricao', 'data_limite', 'prazo',
            "pz.id, pz.descricao AS titulo, pz.prioridade, pz.data_limite AS data_item, pz.responsavel, pz.status",
            $extra, $escopo, $filtros,
            static fn (array $l): bool => $l['status'] === 'concluido');
    }

    private static function linhasDeProcesso(
        string $tabela, string $alias, string $colTitulo, string $colData, string $origem,
        string $campos, array $extra, array $escopo, array $filtros, callable $concluida
    ): array {
        // Tarefa e prazo de processo só têm baixa, média e alta. Filtrar por
        // "urgente" não acha nada nestas tabelas.
        if (($filtros['prioridade'] ?? '') === 'urgente') return [];

        $contas = array_values(array_filter(array_map('intval', $escopo['contas_processos'] ?? [])));
        if (!$contas) return [];

        $params = [];
        $where  = ['p.deleted_at IS NULL', self::clausulaProcesso($contas, (int) $escopo['user_id'], $params)];
        foreach ($extra as $e) $where[] = $e;

        if (!empty($filtros['prioridade'])) {
            $where[] = "{$alias}.prioridade = :prio";
            $params['prio'] = (string) $filtros['prioridade'];
        }
        if (!empty($filtros['processo_id'])) {
            $where[] = 'p.id = :pid';
            $params['pid'] = (int) $filtros['processo_id'];
        }
        // O responsável destas tabelas é TEXTO (o nome). O filtro chega como id.
        if (!empty($filtros['responsavel_id'])) {
            $st = Database::getConnection()->prepare('SELECT nome FROM users WHERE id = ? LIMIT 1');
            $st->execute([(int) $filtros['responsavel_id']]);
            $nome = $st->fetchColumn();
            if ($nome === false) return [];
            $where[] = "{$alias}.responsavel = :respnome";
            $params['respnome'] = (string) $nome;
        }
        if (($filtros['busca'] ?? '') !== '') {
            $params['b1'] = $params['b2'] = $params['b3'] = $params['b4'] = '%' . $filtros['busca'] . '%';
            $where[] = "({$alias}.{$colTitulo} LIKE :b1 OR p.cliente_nome LIKE :b2 OR p.numero LIKE :b3 OR p.numero_cnj LIKE :b4)";
        }

        $st = Database::getConnection()->prepare(
            "SELECT {$campos}, p.id AS processo_id, p.cliente_nome,
                    COALESCE(NULLIF(p.numero_cnj,''), NULLIF(p.numero,''), CONCAT('#', p.id)) AS processo_numero,
                    p.account_id AS origin_account_id, a.nome AS origin_account_nome, a.tipo AS origin_account_tipo
               FROM {$tabela} {$alias}
               JOIN processos p ON p.id = {$alias}.processo_id
          LEFT JOIN accounts a  ON a.id = p.account_id
              WHERE " . implode(' AND ', $where) . "
           ORDER BY {$alias}.{$colData} IS NULL, {$alias}.{$colData}, {$alias}.id
              LIMIT " . self::LIMITE_POR_ORIGEM
        );
        $st->execute($params);

        $saida = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $l) {
            $saida[] = [
                'chave'               => $origem . ':' . $l['id'],
                'origem'              => $origem,
                'id'                  => (int) $l['id'],
                'titulo'              => (string) $l['titulo'],
                'prioridade'          => (string) ($l['prioridade'] ?: 'media'),
                'data'                => $l['data_item'] ? substr((string) $l['data_item'], 0, 10) : null,
                'hora'                => null,
                'concluida'           => $concluida($l),
                'responsavel_nome'    => $l['responsavel'] !== '' ? $l['responsavel'] : null,
                'processo_id'         => (int) $l['processo_id'],
                'processo_numero'     => $l['processo_numero'],
                'cliente_nome'        => $l['cliente_nome'],
                'quadro_nome'         => null,
                'coluna_nome'         => null,
                'origin_account_id'   => (int) $l['origin_account_id'],
                'origin_account_nome' => $l['origin_account_nome'],
                'origin_account_tipo' => $l['origin_account_tipo'],
            ];
        }
        return $saida;
    }

    /** A mesma regra de Processo::list: contas acessíveis + resource_shares. */
    private static function clausulaProcesso(array $contas, int $userId, array &$params, string $pref = 'pc'): string
    {
        $proprias    = self::emLista($contas, $pref . 'o', $params);
        $compartilha = self::emLista($contas, $pref . 's', $params);
        $porUsuario  = '';
        if ($userId > 0) {
            $params[$pref . 'u'] = $userId;
            $porUsuario = " OR rs.to_user_id = :{$pref}u";
        }
        return "(p.account_id IN ({$proprias}) OR EXISTS (
                    SELECT 1 FROM resource_shares rs
                     WHERE rs.resource_type = 'processo' AND rs.resource_id = p.id AND rs.status = 'active'
                       AND (rs.to_account_id IN ({$compartilha}) OR rs.to_account_id IS NULL{$porUsuario})))";
    }

    private static function emLista(array $ids, string $pref, array &$params): string
    {
        $ph = [];
        foreach (array_values($ids) as $i => $id) {
            $k = "{$pref}{$i}";
            $ph[] = ":{$k}";
            $params[$k] = (int) $id;
        }
        return implode(',', $ph);
    }

    private static function soDigitos(string $coluna): string
    {
        return "REPLACE(REPLACE(REPLACE({$coluna}, '-', ''), '.', ''), '/', '')";
    }
}
