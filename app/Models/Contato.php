<?php
namespace App\Models;

use App\Models\Database;

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
     * Localiza contato pelo telefone normalizado.
     * Se não existir, cria. Nunca duplica.
     * Retorna o id do contato, ou null se telefone inválido.
     */
    public static function findOrCreateByPhone(string $nome, string $telefone): ?int
    {
        $phone = self::normalizePhone($telefone);
        if (!$phone) return null;

        $pdo = Database::getConnection();

        // Tenta inserir; se já existe o telefone, apenas atualiza o nome
        // se o nome armazenado estiver vazio
        $stmt = $pdo->prepare(
            'INSERT INTO contatos (nome, telefone)
             VALUES (:nome, :telefone)
             ON DUPLICATE KEY UPDATE
               nome     = IF(nome IS NULL OR nome = \'\', VALUES(nome), nome),
               id       = LAST_INSERT_ID(id)'
        );
        $stmt->execute(['nome' => trim($nome) ?: 'Contato', 'telefone' => $phone]);

        return (int)$pdo->lastInsertId() ?: self::findIdByPhone($phone);
    }

    /**
     * Busca id pelo telefone normalizado (fallback para ON DUPLICATE que não retorna id).
     */
    public static function findIdByPhone(string $telefone): ?int
    {
        $phone = self::normalizePhone($telefone);
        if (!$phone) return null;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM contatos WHERE telefone = ? LIMIT 1');
        $stmt->execute([$phone]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Localiza ou cria contato por JID (para contatos @lid sem telefone real).
     * Nunca duplica — chave única é remote_jid.
     */
    public static function findOrCreateByJid(string $nome, string $jid): ?int
    {
        if (!$jid) return null;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO contatos (nome, remote_jid)
             VALUES (:nome, :jid)
             ON DUPLICATE KEY UPDATE
               nome = IF(nome IS NULL OR nome = \'\', VALUES(nome), nome),
               id   = LAST_INSERT_ID(id)'
        );
        $stmt->execute(['nome' => trim($nome) ?: 'Contato', 'jid' => $jid]);

        $id = (int)$pdo->lastInsertId();
        if ($id) return $id;

        $stmt = $pdo->prepare('SELECT id FROM contatos WHERE remote_jid = ? LIMIT 1');
        $stmt->execute([$jid]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /** Verifica se um JID é LID (número interno sem telefone real). */
    public static function isLid(string $jid): bool
    {
        return str_ends_with($jid, '@lid');
    }

    public static function find(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM contatos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
