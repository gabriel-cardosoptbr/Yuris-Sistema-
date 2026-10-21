<?php
namespace App\Drive;

use App\Core\Database;

/**
 * Seções do Drive na ficha do cliente (21/10/2026, migration 163): cada uma tem
 * um título e uma pasta da árvore do cliente ("Mídias prontas", "Mídias
 * editadas"), na ordem escolhida. A pasta pode ser uma que já existe (escolhida
 * navegando na pasta do cliente) ou uma nova, criada com o título dentro da
 * pasta do cliente. Remover a seção não apaga nada no Drive.
 */
final class Secao
{
    public static function listar(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, titulo, folder_id, folder_nome, ordem FROM drive_secoes_cliente WHERE account_id = ? AND cliente_id = ? ORDER BY ordem, id');
        $st->execute([$accountId, $clienteId]);
        return array_map(fn($s) => ['id' => (int) $s['id'], 'titulo' => $s['titulo'], 'folder_id' => $s['folder_id'], 'folder_nome' => $s['folder_nome'], 'ordem' => (int) $s['ordem']],
            $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    private static function titulo(string $t): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if ($t === '') throw new \InvalidArgumentException('Dê um título à seção.');
        if (mb_strlen($t) > 120) throw new \InvalidArgumentException('Título longo demais (até 120 letras).');
        return $t;
    }

    /**
     * Seção nova. Com $folderId, a pasta precisa ser da árvore do cliente; sem
     * ele, cria uma pasta com o título dentro da pasta do cliente.
     */
    public static function criar(int $accountId, int $userId, int $clienteId, string $titulo, ?string $folderId): array
    {
        $titulo = self::titulo($titulo);
        if ($folderId) {
            if (!Pasta::pastaDoCliente($accountId, $clienteId, $folderId)) throw new \InvalidArgumentException('Escolha uma pasta dentro da pasta do cliente.');
            $st = Database::getConnection()->prepare('SELECT nome FROM drive_itens WHERE account_id = ? AND cliente_id = ? AND file_id = ?');
            $st->execute([$accountId, $clienteId, $folderId]);
            $nome = $st->fetchColumn() ?: ((Pasta::daCliente($accountId, $clienteId)['folder_nome'] ?? null));
        } else {
            $p = Pasta::novaPasta($accountId, $clienteId, null, $titulo);
            $folderId = $p['id']; $nome = $p['nome'];
        }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM drive_secoes_cliente WHERE account_id = ? AND cliente_id = ?');
        $st->execute([$accountId, $clienteId]);
        $pdo->prepare('INSERT INTO drive_secoes_cliente (account_id, cliente_id, titulo, folder_id, folder_nome, ordem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $clienteId, $titulo, $folderId, $nome ?: null, (int) $st->fetchColumn(), $userId ?: null]);
        return self::listar($accountId, $clienteId);
    }

    public static function renomear(int $accountId, int $clienteId, int $id, string $titulo): array
    {
        $st = Database::getConnection()->prepare('UPDATE drive_secoes_cliente SET titulo = ? WHERE id = ? AND account_id = ? AND cliente_id = ?');
        $st->execute([self::titulo($titulo), $id, $accountId, $clienteId]);
        return self::listar($accountId, $clienteId);
    }

    public static function remover(int $accountId, int $clienteId, int $id): array
    {
        Database::getConnection()->prepare('DELETE FROM drive_secoes_cliente WHERE id = ? AND account_id = ? AND cliente_id = ?')->execute([$id, $accountId, $clienteId]);
        return self::listar($accountId, $clienteId);
    }

    /** Nova ordem: os ids na sequência da tela (os de outro cliente são ignorados). */
    public static function ordenar(int $accountId, int $clienteId, array $ids): array
    {
        $st = Database::getConnection()->prepare('UPDATE drive_secoes_cliente SET ordem = ? WHERE id = ? AND account_id = ? AND cliente_id = ?');
        foreach (array_values(array_map('intval', $ids)) as $i => $id) $st->execute([$i + 1, $id, $accountId, $clienteId]);
        return self::listar($accountId, $clienteId);
    }
}
