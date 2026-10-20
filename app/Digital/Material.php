<?php
namespace App\Digital;

use App\Core\Database;
use App\Tarefas\Task;

/**
 * O material que uma entrega precisa (20/10/2026): "logo em PNG", "fotos do
 * produto", "roteiro aprovado". Cadastrar os esperados mostra o que falta.
 *
 * Receber é associar um arquivo (do Drive do cliente, ou um enviado pela tela,
 * que vai para o Drive) a um esperado: grava quem, quando e a referência do
 * arquivo. Receber NÃO aprova, não entrega e não publica: esses estados são da
 * Entrega e mudam só por decisão de alguém.
 */
final class Material
{
    public static function doCartao(int $taskId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT m.*, u.nome AS recebido_por_nome FROM task_arquivos_esperados m LEFT JOIN users u ON u.id = m.recebido_por
              WHERE m.task_id = ? ORDER BY m.id'
        );
        $st->execute([$taskId]);
        return array_map(fn($m) => [
            'id' => (int) $m['id'], 'nome' => $m['nome'], 'recebido' => $m['recebido_em'] !== null, 'recebido_em' => $m['recebido_em'],
            'recebido_por' => $m['recebido_por_nome'], 'drive_file_id' => $m['drive_file_id'], 'arquivo_nome' => $m['arquivo_nome'], 'arquivo_link' => $m['arquivo_link'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public static function adicionar(array $cartao, int $userId, string $nome): int
    {
        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > 160) throw new \InvalidArgumentException('Diga que material é esperado (até 160 caracteres).');
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO task_arquivos_esperados (account_id, task_id, nome, criado_por) VALUES (?, ?, ?, ?)')
            ->execute([(int) $cartao['board']['account_id'], (int) $cartao['task']['id'], $nome, $userId ?: null]);
        $id = (int) $pdo->lastInsertId();
        Task::history((int) $cartao['task']['id'], $userId, 'material_esperado', null, ['nome' => $nome]);
        return $id;
    }

    private static function doMaterial(array $cartao, int $id): array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM task_arquivos_esperados WHERE id = ? AND task_id = ? AND account_id = ?');
        $st->execute([$id, (int) $cartao['task']['id'], (int) $cartao['board']['account_id']]);
        $m = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$m) throw new \InvalidArgumentException('Material não encontrado.');
        return $m;
    }

    /**
     * Marca o recebimento com a referência do arquivo. O arquivo continua no
     * Drive; aqui fica só id, nome e link.
     *
     * @param array{id?:string, nome?:string, link?:string} $arquivo
     */
    public static function receber(array $cartao, int $userId, int $id, array $arquivo): void
    {
        $m = self::doMaterial($cartao, $id);
        $nome = trim((string) ($arquivo['nome'] ?? ''));
        $link = trim((string) ($arquivo['link'] ?? ''));
        if ($nome === '') throw new \InvalidArgumentException('Escolha o arquivo recebido.');
        if ($link !== '' && !preg_match('#^https://#i', $link)) throw new \InvalidArgumentException('Link de arquivo inválido.');
        Database::getConnection()->prepare(
            'UPDATE task_arquivos_esperados SET drive_file_id = ?, arquivo_nome = ?, arquivo_link = ?, recebido_em = NOW(), recebido_por = ? WHERE id = ?'
        )->execute([($arquivo['id'] ?? '') ?: null, mb_substr($nome, 0, 255), $link ?: null, $userId ?: null, (int) $m['id']]);
        Task::history((int) $cartao['task']['id'], $userId, 'material_recebido', null, ['material' => $m['nome'], 'arquivo' => $nome]);
    }

    /** Desfaz o recebimento (arquivo errado). O esperado continua. */
    public static function desfazer(array $cartao, int $userId, int $id): void
    {
        $m = self::doMaterial($cartao, $id);
        Database::getConnection()->prepare('UPDATE task_arquivos_esperados SET drive_file_id = NULL, arquivo_nome = NULL, arquivo_link = NULL, recebido_em = NULL, recebido_por = NULL WHERE id = ?')
            ->execute([(int) $m['id']]);
        Task::history((int) $cartao['task']['id'], $userId, 'material_desfeito', ['material' => $m['nome'], 'arquivo' => $m['arquivo_nome']], null);
    }

    public static function remover(array $cartao, int $userId, int $id): void
    {
        $m = self::doMaterial($cartao, $id);
        Database::getConnection()->prepare('DELETE FROM task_arquivos_esperados WHERE id = ?')->execute([(int) $m['id']]);
        Task::history((int) $cartao['task']['id'], $userId, 'material_removido', ['nome' => $m['nome']], null);
    }

    /** Quantos materiais esperados ainda faltam, nos cartões ativos das contas. */
    public static function faltando(int $accountId, ?array $boardIds = null): int
    {
        $sql = "SELECT COUNT(*) FROM task_arquivos_esperados m JOIN tasks t ON t.id = m.task_id
                 WHERE m.account_id = ? AND m.recebido_em IS NULL AND t.status = 'ativa'";
        $args = [$accountId];
        if ($boardIds !== null) {
            if (!$boardIds) return 0;
            $sql .= ' AND t.board_id IN (' . implode(',', array_fill(0, count($boardIds), '?')) . ')';
            array_push($args, ...array_map('intval', $boardIds));
        }
        $st = Database::getConnection()->prepare($sql);
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
}
