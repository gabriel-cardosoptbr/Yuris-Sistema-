<?php
namespace App\Models;

class Task
{
    public static function findByBoard(int $boardId, array $filtros = []): array
    {
        $pdo    = Database::getConnection();
        // Exclui arquivadas e concluídas do board principal.
        // Exceção: tarefas concluídas SEM recorrência ficam visíveis (histórico manual).
        // Tarefas recorrentes concluídas são ocultadas pois já geraram nova instância.
        $where  = [
            "t.board_id = :board_id",
            "t.status != 'arquivada'",
            // Tarefas recorrentes: mostra apenas a instância com o prazo mais recente.
            // Com a abordagem in-place só existe 1 ativa por recorrência, mas esta
            // cláusula protege contra duplicatas deixadas pelo sistema anterior.
            "(t.recorrencia_id IS NULL OR t.id = (
                SELECT t2.id FROM tasks t2
                WHERE t2.recorrencia_id = t.recorrencia_id
                  AND t2.status != 'arquivada'
                ORDER BY t2.prazo DESC, t2.id DESC
                LIMIT 1
            ))",
        ];
        $params = ['board_id' => $boardId];

        if (!empty($filtros['column_id'])) {
            $where[] = 't.column_id = :column_id';
            $params['column_id'] = $filtros['column_id'];
        }
        if (!empty($filtros['responsavel_id'])) {
            $where[] = 't.responsavel_id = :responsavel_id';
            $params['responsavel_id'] = $filtros['responsavel_id'];
        }
        if (!empty($filtros['prioridade'])) {
            $where[] = 't.prioridade = :prioridade';
            $params['prioridade'] = $filtros['prioridade'];
        }
        if (!empty($filtros['prazo'])) {
            switch ($filtros['prazo']) {
                case 'hoje':
                    $where[] = 'DATE(t.prazo) = CURDATE()'; break;
                case 'atrasadas':
                    $where[] = "t.prazo < NOW() AND t.status = 'ativa'"; break;
                case '7dias':
                    $where[] = 't.prazo BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)'; break;
            }
        }
        if (!empty($filtros['busca'])) {
            $where[] = 't.titulo LIKE :busca';
            $params['busca'] = '%' . $filtros['busca'] . '%';
        }

        // Origem é puxada pelo board (tarefas não têm account_id direto — herdam do board).
        $sql = '
            SELECT t.*,
                   u.nome AS responsavel_nome,
                   c.nome AS criador_nome,
                   col.nome AS coluna_nome,
                   b.account_id AS origin_account_id,
                   a.nome       AS origin_account_nome,
                   a.tipo       AS origin_account_tipo,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id) AS checklist_total,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id AND ci.concluido = 1) AS checklist_feito,
                   (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS total_comentarios
            FROM tasks t
            LEFT JOIN users u   ON u.id = t.responsavel_id
            LEFT JOIN users c   ON c.id = t.criado_por_id
            LEFT JOIN task_columns col ON col.id = t.column_id
            LEFT JOIN task_boards  b   ON b.id  = t.board_id
            LEFT JOIN accounts     a   ON a.id  = b.account_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY t.column_id, t.ordem, t.id
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function findById(int $id): array|false
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT t.*,
                   u.nome AS responsavel_nome,
                   c.nome AS criador_nome,
                   b.account_id AS origin_account_id,
                   a.nome       AS origin_account_nome,
                   a.tipo       AS origin_account_tipo
            FROM tasks t
            LEFT JOIN users u ON u.id = t.responsavel_id
            LEFT JOIN users c ON c.id = t.criado_por_id
            LEFT JOIN task_boards b ON b.id = t.board_id
            LEFT JOIN accounts    a ON a.id = b.account_id
            WHERE t.id = ?
        ');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public static function withDetails(int $id): array|false
    {
        $task = self::findById($id);
        if (!$task) return false;

        $pdo = Database::getConnection();

        $s = $pdo->prepare('SELECT * FROM task_checklist_items WHERE task_id = ? ORDER BY ordem, id');
        $s->execute([$id]);
        $task['checklist'] = $s->fetchAll(\PDO::FETCH_ASSOC);

        $s = $pdo->prepare('SELECT tc.*, u.nome AS autor FROM task_comments tc JOIN users u ON u.id = tc.user_id WHERE tc.task_id = ? ORDER BY tc.created_at');
        $s->execute([$id]);
        $task['comentarios'] = $s->fetchAll(\PDO::FETCH_ASSOC);

        $s = $pdo->prepare('SELECT * FROM task_attachments WHERE task_id = ? ORDER BY created_at DESC');
        $s->execute([$id]);
        $task['anexos'] = $s->fetchAll(\PDO::FETCH_ASSOC);

        try {
            $s = $pdo->prepare('SELECT te.*, u.nome AS autor FROM task_time_entries te LEFT JOIN users u ON u.id = te.user_id WHERE te.task_id = ? ORDER BY te.inicio DESC');
            $s->execute([$id]);
            $task['tempo'] = $s->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $task['tempo'] = []; }

        try { $task['links'] = TaskLink::findByTask($id); } catch (\Throwable $e) { $task['links'] = []; }

        if ($task['recorrencia_id']) {
            $s = $pdo->prepare('SELECT * FROM task_recurrences WHERE id = ?');
            $s->execute([$task['recorrencia_id']]);
            $task['recorrencia'] = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
        } else {
            $task['recorrencia'] = null;
        }

        $s = $pdo->prepare('SELECT th.*, u.nome AS autor FROM task_history th LEFT JOIN users u ON u.id = th.user_id WHERE th.task_id = ? ORDER BY th.created_at');
        $s->execute([$id]);
        $task['historico'] = $s->fetchAll(\PDO::FETCH_ASSOC);

        return $task;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();

        $maxOrdem = $pdo->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM tasks WHERE column_id = ?');
        $maxOrdem->execute([$data['column_id']]);
        $ordem = $maxOrdem->fetchColumn();

        $stmt = $pdo->prepare('
            INSERT INTO tasks
              (board_id, column_id, titulo, descricao, prioridade, prazo, prazo_tipo,
               responsavel_id, criado_por_id, ordem, recorrencia_id, origem_task_id)
            VALUES
              (:board_id, :column_id, :titulo, :descricao, :prioridade, :prazo, :prazo_tipo,
               :responsavel_id, :criado_por_id, :ordem, :recorrencia_id, :origem_task_id)
        ');
        $stmt->execute([
            'board_id'       => $data['board_id'],
            'column_id'      => $data['column_id'],
            'titulo'         => $data['titulo'],
            'descricao'      => $data['descricao'] ?? null,
            'prioridade'     => $data['prioridade'] ?? 'media',
            'prazo'          => $data['prazo'] ?? null,
            'prazo_tipo'     => $data['prazo_tipo'] ?? 'interno',
            'responsavel_id' => $data['responsavel_id'] ?? null,
            'criado_por_id'  => $data['criado_por_id'],
            'ordem'          => $data['ordem'] ?? $ordem,
            'recorrencia_id' => $data['recorrencia_id'] ?? null,
            'origem_task_id' => $data['origem_task_id'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();
        self::history($id, $data['criado_por_id'] ?? null, 'criada', null, ['titulo' => $data['titulo']]);
        return $id;
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo = Database::getConnection();
        $old = self::findById($id);

        $fields = [];
        $params = [];
        foreach (['titulo','descricao','prioridade','prazo','prazo_tipo','responsavel_id','column_id','ordem'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = :$f";
                $params[$f] = $data[$f];
            }
        }
        if (!$fields) return;
        $params['id'] = $id;
        $pdo->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);

        self::history($id, $userId, 'atualizada', $old, $data);
    }

    public static function move(int $id, int $columnId, int $ordem, int $userId): void
    {
        $pdo = Database::getConnection();
        $old = self::findById($id);
        $pdo->prepare('UPDATE tasks SET column_id = ?, ordem = ? WHERE id = ?')->execute([$columnId, $ordem, $id]);
        self::history($id, $userId, 'movida', ['column_id' => $old['column_id']], ['column_id' => $columnId]);
    }

    /**
     * Conclui a tarefa.
     * - Tarefa simples: marca como 'concluida' (sai do board).
     * - Tarefa recorrente: renova IN-PLACE — mantém na mesma coluna, atualiza o prazo
     *   para a próxima ocorrência e volta para 'ativa'. O card não sai do lugar.
     * Retorna array com informação sobre a renovação para o endpoint devolver ao frontend.
     */
    public static function complete(int $id, int $userId): array
    {
        $pdo  = Database::getConnection();
        $task = self::findById($id);
        if (!$task) return ['renovada' => false];

        // Registra a conclusão no histórico (auditoria)
        self::history($id, $userId, 'concluida',
            ['status' => $task['status'], 'prazo' => $task['prazo']],
            ['status' => 'concluida']);

        if ($task['recorrencia_id']) {
            $rec = TaskRecurrence::loadById((int)$task['recorrencia_id']);
            if ($rec) {
                // Calcula sempre a partir de HOJE (não do prazo agendado).
                // Assim uma tarefa diária concluída hoje tem próxima = amanhã,
                // independente de o prazo ter ficado defasado por dias antigos.
                $proximaData = $rec->calcularProximaData(date('Y-m-d H:i:s'));

                if ($proximaData) {
                    // Renova in-place: mesma coluna, mesmo card, novo prazo
                    $pdo->prepare(
                        "UPDATE tasks SET status = 'ativa', prazo = ?, concluida_em = NOW() WHERE id = ?"
                    )->execute([$proximaData, $id]);

                    self::history($id, $userId, 'renovada',
                        ['prazo' => $task['prazo']],
                        ['prazo' => $proximaData]);

                    return ['renovada' => true, 'proxima_data' => $proximaData];
                }

                // Recorrência encerrada (data_fim atingida): conclui definitivamente
                TaskRecurrence::deactivate((int)$task['recorrencia_id']);
                $pdo->prepare(
                    "UPDATE tasks SET status = 'concluida', concluida_em = NOW(), recorrencia_id = NULL WHERE id = ?"
                )->execute([$id]);
                self::history($id, $userId, 'recorrencia_encerrada', null, null);

                return ['renovada' => false, 'encerrada' => true];
            }
        }

        // Tarefa simples: conclui normalmente
        $pdo->prepare("UPDATE tasks SET status = 'concluida', concluida_em = NOW() WHERE id = ?")
            ->execute([$id]);

        return ['renovada' => false];
    }

    public static function archive(int $id, int $userId): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE tasks SET status = 'arquivada' WHERE id = ?")->execute([$id]);
        self::history($id, $userId, 'arquivada', null, null);
    }

    public static function history(int $taskId, ?int $userId, string $acao, mixed $antes, mixed $depois): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO task_history (task_id, user_id, acao, antes_json, depois_json) VALUES (?,?,?,?,?)')
            ->execute([
                $taskId, $userId, $acao,
                $antes  ? json_encode($antes)  : null,
                $depois ? json_encode($depois) : null,
            ]);
    }

    public static function dueToday(int $userId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT t.*, col.nome AS coluna_nome, b.nome AS board_nome
            FROM tasks t
            JOIN task_columns col ON col.id = t.column_id
            JOIN task_boards b ON b.id = t.board_id
            WHERE t.responsavel_id = ? AND DATE(t.prazo) = CURDATE() AND t.status = 'ativa'
            ORDER BY t.prazo
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function overdue(int $userId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT t.*, col.nome AS coluna_nome, b.nome AS board_nome
            FROM tasks t
            JOIN task_columns col ON col.id = t.column_id
            JOIN task_boards b ON b.id = t.board_id
            WHERE t.responsavel_id = ? AND t.prazo < NOW() AND t.status = 'ativa'
            ORDER BY t.prazo
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
