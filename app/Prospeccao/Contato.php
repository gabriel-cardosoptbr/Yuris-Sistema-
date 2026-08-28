<?php
namespace App\Prospeccao;

use App\Core\Database;

class Contato
{
    /**
     * Normaliza telefone para somente dígitos com DDI 55.
     * Retorna null se inválido (menos de 10 dígitos).
     */
    public static function normalizePhone(string $tel): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $tel);
        if (strlen($digits) < 10) return null;
        if (strlen($digits) <= 11) $digits = '55' . $digits;
        return $digits;
    }

    /**
     * Localiza contato pelo telefone normalizado DENTRO DA CONTA.
     * Se não existir naquela conta, cria. Nunca duplica dentro da conta.
     * Retorna o id do contato, ou null se telefone inválido.
     *
     * O `$accountId` é obrigatório desde a migration 111. Até então o contato
     * era único por telefone GLOBALMENTE, então dois escritórios com um cliente
     * de mesmo número acabavam na MESMA linha, e o nome exibido era o de quem
     * cadastrou primeiro (bug B1). A chave única hoje é (account_id, telefone).
     */
    public static function findOrCreateByPhone(string $nome, string $telefone, int $accountId): ?int
    {
        $phone = self::normalizePhone($telefone);
        if (!$phone || $accountId <= 0) return null;

        $pdo = Database::getConnection();

        // Tenta inserir; se já existe o telefone NA CONTA, apenas atualiza o
        // nome se o armazenado estiver vazio
        $stmt = $pdo->prepare(
            'INSERT INTO contatos (account_id, nome, telefone)
             VALUES (:acc, :nome, :telefone)
             ON DUPLICATE KEY UPDATE
               nome     = IF(nome IS NULL OR nome = \'\', VALUES(nome), nome),
               id       = LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'acc'      => $accountId,
            'nome'     => trim($nome) ?: 'Contato',
            'telefone' => $phone,
        ]);

        return (int)$pdo->lastInsertId() ?: self::findIdByPhone($phone, $accountId);
    }

    /**
     * Busca id pelo telefone normalizado dentro da conta (fallback para o
     * ON DUPLICATE que não devolve id).
     */
    public static function findIdByPhone(string $telefone, int $accountId): ?int
    {
        $phone = self::normalizePhone($telefone);
        if (!$phone || $accountId <= 0) return null;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id FROM contatos WHERE telefone = ? AND account_id = ? LIMIT 1');
        $stmt->execute([$phone, $accountId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Localiza ou cria contato por JID DENTRO DA CONTA (para contatos @lid sem
     * telefone real). Chave única hoje é (account_id, remote_jid).
     */
    public static function findOrCreateByJid(string $nome, string $jid, int $accountId): ?int
    {
        if (!$jid || $accountId <= 0) return null;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO contatos (account_id, nome, remote_jid)
             VALUES (:acc, :nome, :jid)
             ON DUPLICATE KEY UPDATE
               nome = IF(nome IS NULL OR nome = \'\', VALUES(nome), nome),
               id   = LAST_INSERT_ID(id)'
        );
        $stmt->execute(['acc' => $accountId, 'nome' => trim($nome) ?: 'Contato', 'jid' => $jid]);

        $id = (int)$pdo->lastInsertId();
        if ($id) return $id;

        $stmt = $pdo->prepare(
            'SELECT id FROM contatos WHERE remote_jid = ? AND account_id = ? LIMIT 1');
        $stmt->execute([$jid, $accountId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /** Verifica se um JID é LID (número interno sem telefone real). */
    public static function isLid(string $jid): bool
    {
        return str_ends_with($jid, '@lid');
    }

    /**
     * Busca por id, restrita à conta quando `$accountId` é informado.
     *
     * Passar a conta é o correto em qualquer caminho vindo de uma sessão. O
     * parâmetro é opcional só para não quebrar chamadores internos que já
     * resolveram a posse antes (ex.: histórico do processo, que só recebe ids
     * de processos da própria conta).
     */
    public static function find(int $id, ?int $accountId = null): ?array
    {
        $pdo = Database::getConnection();
        if ($accountId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM contatos WHERE id = ? AND account_id = ? LIMIT 1');
            $stmt->execute([$id, $accountId]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM contatos WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
