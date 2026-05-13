<?php
namespace App\Models;

use App\Models\Database;

class Processo
{
    /**
     * Lista processos do tenant.
     * SEGURANÇA: account_id é obrigatório — sem ele retorna vazio.
     * Inclui processos compartilhados via resource_shares.
     */
    public static function list($filters = [])
    {
        $pdo = Database::getConnection();

        $accountId = $filters['account_id'] ?? null;
        if (!$accountId) return [];

        // tenta com account_id; se coluna não existir (single-tenant) retira o filtro
        $hasAccountCol = true;
        try {
            $pdo->query('SELECT account_id FROM processos LIMIT 0');
        } catch (\Throwable $e) {
            $hasAccountCol = false;
        }

        if ($hasAccountCol) {
            $sql = 'SELECT p.*, t.nome AS setor_nome,
                    (SELECT COUNT(*) FROM processo_tarefas pt WHERE pt.processo_id = p.id) AS tarefas_total,
                    (SELECT COUNT(*) FROM processo_tarefas pt WHERE pt.processo_id = p.id AND pt.concluido = 1) AS tarefas_feitas
                    FROM processos p
                    LEFT JOIN teams t ON t.id = p.setor_id AND t.deleted_at IS NULL
                    WHERE p.deleted_at IS NULL
                      AND (
                        p.account_id = :account_id
                        OR EXISTS (
                          SELECT 1 FROM resource_shares rs
                          WHERE rs.resource_type = "processo"
                            AND rs.resource_id   = p.id
                            AND rs.status        = "active"
                            AND (rs.to_account_id = :account_id2 OR rs.to_account_id IS NULL)
                        )
                      )';
            $params = ['account_id' => $accountId, 'account_id2' => $accountId];
        } else {
            $sql    = 'SELECT p.*, t.nome AS setor_nome,
                       (SELECT COUNT(*) FROM processo_tarefas pt WHERE pt.processo_id = p.id) AS tarefas_total,
                       (SELECT COUNT(*) FROM processo_tarefas pt WHERE pt.processo_id = p.id AND pt.concluido = 1) AS tarefas_feitas
                       FROM processos p
                       LEFT JOIN teams t ON t.id = p.setor_id AND t.deleted_at IS NULL
                       WHERE p.deleted_at IS NULL';
            $params = [];
        }

        if (!empty($filters['setor_id'])) {
            $sql .= ' AND p.setor_id = :setor_id';
            $params['setor_id'] = $filters['setor_id'];
        }
        if (!empty($filters['responsavel_user_id'])) {
            $sql .= ' AND responsavel_user_id = :responsavel_user_id';
            $params['responsavel_user_id'] = $filters['responsavel_user_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from']) && !empty($filters['to'])) {
            $sql .= ' AND (p.proximo_prazo BETWEEN :from AND :to OR p.data_inicio BETWEEN :from AND :to)';
            $params['from'] = $filters['from'];
            $params['to'] = $filters['to'];
        }
        if (isset($filters['card_id'])) {
            $sql .= ' AND p.card_id = :card_id';
            $params['card_id'] = $filters['card_id'];
        }
        $sql .= ' ORDER BY p.proximo_prazo IS NULL, p.proximo_prazo ASC, p.updated_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT p.*, t.nome AS setor_nome
             FROM processos p
             LEFT JOIN teams t ON t.id = p.setor_id AND t.deleted_at IS NULL
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($data)
    {
        $pdo = Database::getConnection();

        // Resolve contato_id: herda do card se houver, ou cria/localiza pelo telefone
        $contatoId = $data['contato_id'] ?? null;
        if (!$contatoId && !empty($data['card_id'])) {
            $cardRow = $pdo->prepare('SELECT contato_id FROM cards WHERE id = ? LIMIT 1');
            $cardRow->execute([$data['card_id']]);
            $contatoId = $cardRow->fetchColumn() ?: null;
        }

        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar um processo');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO processos
             (account_id, numero, cliente_nome, parte_contraria, cpf_cnpj_parte_contraria, setor_id, vara_comarca,
              responsavel_user_id, status, data_inicio, proximo_prazo,
              ultima_movimentacao, observacoes, anexos, alerts, card_id, contato_id,
              created_at, updated_at)
             VALUES
             (:account_id, :numero, :cliente_nome, :parte_contraria, :cpf_cnpj_parte_contraria, :setor_id, :vara_comarca,
              :responsavel_user_id, :status, :data_inicio, :proximo_prazo,
              :ultima_movimentacao, :observacoes, :anexos, :alerts, :card_id, :contato_id,
              NOW(), NOW())'
        );
        $stmt->execute([
            'account_id'           => $data['account_id'],
            'numero'               => $data['numero']               ?? null,
            'cliente_nome'         => $data['cliente_nome']         ?? null,
            'parte_contraria'           => $data['parte_contraria']           ?? null,
            'cpf_cnpj_parte_contraria'  => $data['cpf_cnpj_parte_contraria']  ?? null,
            'setor_id'             => isset($data['setor_id']) && $data['setor_id'] !== '' ? (int)$data['setor_id'] : null,
            'vara_comarca'         => $data['vara_comarca']         ?? null,
            'responsavel_user_id'  => $data['responsavel_user_id']  ?? null,
            'status'               => $data['status']               ?? 'ativo',
            'data_inicio'          => $data['data_inicio']          ?? null,
            'proximo_prazo'        => $data['proximo_prazo']        ?? null,
            'ultima_movimentacao'  => $data['ultima_movimentacao']  ?? null,
            'observacoes'          => $data['observacoes']          ?? null,
            'anexos'               => isset($data['anexos'])  ? json_encode($data['anexos'],  JSON_UNESCAPED_UNICODE) : null,
            'alerts'               => isset($data['alerts'])  ? json_encode($data['alerts'],  JSON_UNESCAPED_UNICODE) : null,
            'card_id'              => $data['card_id']              ?? null,
            'contato_id'           => $contatoId,
        ]);
        return $pdo->lastInsertId();
    }

    public static function update($id, $data)
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = ['id' => $id];
        $allowed = ['numero','cliente_nome','parte_contraria','cpf_cnpj_parte_contraria','setor_id','vara_comarca','responsavel_user_id','status','data_inicio','proximo_prazo','ultima_movimentacao','observacoes','anexos','alerts','card_id','contato_id'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "$k = :$k";
                if (in_array($k, ['anexos','alerts'])) {
                    $params[$k] = $data[$k] !== null ? json_encode($data[$k], JSON_UNESCAPED_UNICODE) : null;
                } else {
                    $params[$k] = $data[$k];
                }
            }
        }
        if (empty($fields)) return false;

        // Se card_id foi atualizado e contato_id não foi informado explicitamente,
        // herda contato_id do card vinculado
        if (array_key_exists('card_id', $data) && !array_key_exists('contato_id', $data) && !empty($data['card_id'])) {
            $cardRow = $pdo->prepare('SELECT contato_id FROM cards WHERE id = ? LIMIT 1');
            $cardRow->execute([$data['card_id']]);
            $inherited = $cardRow->fetchColumn();
            if ($inherited) {
                $fields[]              = 'contato_id = :contato_id';
                $params['contato_id']  = (int)$inherited;
            }
        }

        $sql = 'UPDATE processos SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public static function softDelete($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE processos SET deleted_at = NOW() WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
