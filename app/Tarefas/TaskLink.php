<?php
namespace App\Tarefas;

class TaskLink
{
    public static function findByTask(int $taskId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM task_links WHERE task_id = ? ORDER BY id');
        $stmt->execute([$taskId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            try {
                [$row['nome_display'], $row['sub_display']] = self::resolveNome($row['link_type'], (int)$row['link_id'], $pdo);
            } catch (\Throwable $e) {
                $row['nome_display'] = '#' . $row['link_id'];
                $row['sub_display']  = '';
            }
        }
        return $rows;
    }

    private static function resolveNome(string $type, int $id, \PDO $pdo): array
    {
        switch ($type) {
            case 'processo':
                $s = $pdo->prepare('SELECT COALESCE(numero_cnj, numero, CONCAT("#", id)) AS n, COALESCE(cliente_nome,"") AS c FROM processos WHERE id = ? LIMIT 1');
                $s->execute([$id]);
                $r = $s->fetch(\PDO::FETCH_ASSOC);
                return $r ? [(string)$r['n'], (string)$r['c']] : ["#{$id}", ''];
            case 'card':
                $s = $pdo->prepare('SELECT COALESCE(titulo, cliente_nome, CONCAT("#",id)) AS n, COALESCE(empresa_nome,"") AS c FROM cards WHERE id = ? LIMIT 1');
                $s->execute([$id]);
                $r = $s->fetch(\PDO::FETCH_ASSOC);
                return $r ? [(string)$r['n'], (string)$r['c']] : ["#{$id}", ''];
            case 'contato':
                $s = $pdo->prepare('SELECT nome FROM contatos WHERE id = ? LIMIT 1');
                $s->execute([$id]);
                return [(string)($s->fetchColumn() ?: "#{$id}"), ''];
            case 'cliente':
                // MEDIA #21: cliente cadastrado na aba Clientes (tabela clientes).
                // Espelha task_link_search (label = nome), sub = CPF/CNPJ quando houver.
                $s = $pdo->prepare('SELECT nome, COALESCE(cpf_cnpj, "") AS c FROM clientes WHERE id = ? LIMIT 1');
                $s->execute([$id]);
                $r = $s->fetch(\PDO::FETCH_ASSOC);
                return $r ? [(string)$r['nome'], (string)$r['c']] : ["#{$id}", ''];
            case 'dre_account':
                // FIX (auditoria 2026-06-01 / ALTA #10): lia `descricao`, que o modulo
                // DRE nunca grava (sempre NULL) -> exibia '#ID'. task_link_search ja usa
                // `nome AS label`; aqui alinhamos pra `nome` (NOT NULL) e ficar coerente.
                $s = $pdo->prepare('SELECT nome FROM dre_accounts WHERE id = ? LIMIT 1');
                $s->execute([$id]);
                return [(string)($s->fetchColumn() ?: "#{$id}"), ''];
            default:
                return ["#{$id}", ''];
        }
    }

    public static function add(int $taskId, string $type, int $linkId): int
    {
        $pdo = Database::getConnection();
        // idempotente
        $check = $pdo->prepare('SELECT id FROM task_links WHERE task_id=? AND link_type=? AND link_id=?');
        $check->execute([$taskId, $type, $linkId]);
        if ($existing = $check->fetchColumn()) return (int)$existing;

        $pdo->prepare('INSERT INTO task_links (task_id, link_type, link_id) VALUES (?,?,?)')
            ->execute([$taskId, $type, $linkId]);
        return (int)$pdo->lastInsertId();
    }

    public static function remove(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM task_links WHERE id = ?')->execute([$id]);
    }
}
