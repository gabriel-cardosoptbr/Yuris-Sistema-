<?php
namespace App\Tarefas;

use App\Core\Database;

/**
 * Responsáveis além do principal (20/10/2026, pedido da Inovaize: um cartão de
 * produção tem roteirista, editor e social media). O principal continua em
 * `tasks.responsavel_id`, que é o que o OTIF, os lembretes e os filtros usam;
 * estes aparecem no cartão e na Visão Geral de cada área.
 */
final class TaskParticipante
{
    /** @return array<int, array{id:int, nome:string}> */
    public static function daTarefa(int $taskId): array
    {
        $st = Database::getConnection()->prepare('SELECT u.id, u.nome FROM task_participantes p JOIN users u ON u.id = p.user_id WHERE p.task_id = ? ORDER BY u.nome');
        $st->execute([$taskId]);
        return array_map(fn($r) => ['id' => (int) $r['id'], 'nome' => $r['nome']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Participantes dos cartões de um quadro, por cartão. */
    public static function doQuadro(int $boardId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT p.task_id, u.id, u.nome FROM task_participantes p JOIN users u ON u.id = p.user_id JOIN tasks t ON t.id = p.task_id
              WHERE t.board_id = ? ORDER BY u.nome'
        );
        $st->execute([$boardId]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(int) $r['task_id']][] = ['id' => (int) $r['id'], 'nome' => $r['nome']];
        return $out;
    }

    /**
     * Troca a lista inteira. Só gente da conta do quadro; o principal não entra
     * aqui (já é responsável).
     *
     * @param int[] $userIds
     */
    public static function definir(int $taskId, int $accountId, int $porUserId, array $userIds, ?int $principal): array
    {
        $pdo = Database::getConnection();
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($u) => $u > 0 && $u !== (int) $principal)));
        if (count($ids) > 20) throw new \InvalidArgumentException('No máximo 20 responsáveis por cartão.');
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id FROM users WHERE account_id = ? AND deleted_at IS NULL AND id IN ($in)");
            $st->execute(array_merge([$accountId], $ids));
            $ok = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
            if (count($ok) !== count($ids)) throw new \InvalidArgumentException('Responsável não encontrado nesta conta.');
        }
        $antes = array_column(self::daTarefa($taskId), 'id');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM task_participantes WHERE task_id = ?')->execute([$taskId]);
            $ins = $pdo->prepare('INSERT INTO task_participantes (task_id, user_id, account_id) VALUES (?, ?, ?)');
            foreach ($ids as $u) $ins->execute([$taskId, $u, $accountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        sort($antes); $depois = $ids; sort($depois);
        if ($antes !== $depois) Task::history($taskId, $porUserId, 'participantes', ['user_ids' => $antes], ['user_ids' => $depois]);
        return self::daTarefa($taskId);
    }
}
