<?php
namespace App\Drive;

use App\Core\Database;
use App\Tarefas\Task;

/**
 * Arquivo do Drive vinculado a um cartão (20/10/2026): a arte aprovada, o vídeo
 * final, o roteiro. Guarda só a referência (id, nome, link). Só arquivo visto
 * dentro da pasta do cliente do quadro (Pasta::itemDoCliente). Fica no histórico
 * do cartão.
 */
final class ArquivoDoCartao
{
    public static function doCartao(int $taskId): array
    {
        $st = Database::getConnection()->prepare('SELECT a.*, u.nome AS por FROM task_drive_arquivos a LEFT JOIN users u ON u.id = a.vinculado_por WHERE a.task_id = ? ORDER BY a.id');
        $st->execute([$taskId]);
        return array_map(fn($a) => ['id' => (int) $a['id'], 'file_id' => $a['file_id'], 'nome' => $a['nome'], 'mime' => $a['mime'], 'tipo' => Pasta::tipo((string) $a['mime']),
            'link' => $a['link'], 'vinculado_por' => $a['por'], 'vinculado_em' => $a['vinculado_em']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param array{task:array, board:array} $cartao (Entrega::cartaoEditavel) */
    public static function vincular(array $cartao, int $userId, string $fileId): array
    {
        $acc = (int) $cartao['board']['account_id'];
        $cli = (int) ($cartao['board']['cliente_id'] ?? 0);
        if (!$cli) throw new \InvalidArgumentException('O quadro não tem cliente; ligue o quadro a um cliente para usar a pasta dele.');
        $item = Pasta::itemDoCliente($acc, $cli, $fileId);
        if (!$item || $item['pasta']) throw new \InvalidArgumentException('Escolha um arquivo da pasta do cliente.');
        Database::getConnection()->prepare(
            'INSERT IGNORE INTO task_drive_arquivos (account_id, task_id, cliente_id, file_id, nome, mime, link, vinculado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$acc, (int) $cartao['task']['id'], $cli, $item['id'], $item['nome'], $item['mime'], $item['link'], $userId ?: null]);
        Task::history((int) $cartao['task']['id'], $userId, 'drive_vinculado', null, ['arquivo' => $item['nome']]);
        return self::doCartao((int) $cartao['task']['id']);
    }

    public static function remover(array $cartao, int $userId, int $id): array
    {
        $st = Database::getConnection()->prepare('SELECT nome FROM task_drive_arquivos WHERE id = ? AND task_id = ? AND account_id = ?');
        $st->execute([$id, (int) $cartao['task']['id'], (int) $cartao['board']['account_id']]);
        $nome = $st->fetchColumn();
        if ($nome === false) throw new \InvalidArgumentException('Vínculo não encontrado.');
        Database::getConnection()->prepare('DELETE FROM task_drive_arquivos WHERE id = ?')->execute([$id]);
        Task::history((int) $cartao['task']['id'], $userId, 'drive_desvinculado', ['arquivo' => $nome], null);
        return self::doCartao((int) $cartao['task']['id']);
    }
}
