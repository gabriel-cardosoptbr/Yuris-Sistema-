<?php
namespace App\Models;

use App\Models\Database;

class Processo
{
    /**
     * Lista processos do tenant.
     * SEGURANÇA: account_id é obrigatório — sem ele retorna vazio.
     * Inclui processos compartilhados via resource_shares.
     *
     * Aceita:
     *   - $filters['account_ids']  array de ints — sessões matriz passam matriz + filiais
     *   - $filters['account_id']   int legado — convertido em [account_id]
     */
    public static function list($filters = [])
    {
        $pdo = Database::getConnection();

        $accountIds = self::_normalizeAccountIds($filters);
        if (empty($accountIds)) return [];

        // tenta com account_id; se coluna não existir (single-tenant) retira o filtro
        $hasAccountCol = true;
        try {
            $pdo->query('SELECT account_id FROM processos LIMIT 0');
        } catch (\Throwable $e) {
            $hasAccountCol = false;
        }

        if ($hasAccountCol) {
            $inOwn   = self::_buildInClause($accountIds, 'pown');
            $inShare = self::_buildInClause($accountIds, 'pshr');
            $userId  = isset($filters['user_id']) ? (int)$filters['user_id'] : 0;
            $userClause = $userId > 0 ? ' OR rs.to_user_id = :puid' : '';
            // Inclui origem (account_nome/tipo) via LEFT JOIN com accounts
            // → permite renderizar selo "MATRIZ" / "FILIAL — Nome" no card sem fetch extra.
            $sql = "SELECT p.*, t.nome AS setor_nome,
                    a.nome AS origin_account_nome,
                    a.tipo AS origin_account_tipo,
                    p.account_id AS origin_account_id,
                    (SELECT COUNT(*) FROM processo_tarefas pt WHERE pt.processo_id = p.id) AS tarefas_total,
                    (SELECT COUNT(*) FROM processo_tarefas pt WHERE pt.processo_id = p.id AND pt.concluido = 1) AS tarefas_feitas
                    FROM processos p
                    LEFT JOIN teams t ON t.id = p.setor_id AND t.deleted_at IS NULL
                    LEFT JOIN accounts a ON a.id = p.account_id
                    WHERE p.deleted_at IS NULL
                      AND (
                        p.account_id IN ({$inOwn['placeholders']})
                        OR EXISTS (
                          SELECT 1 FROM resource_shares rs
                          WHERE rs.resource_type = 'processo'
                            AND rs.resource_id   = p.id
                            AND rs.status        = 'active'
                            AND (rs.to_account_id IN ({$inShare['placeholders']}) OR rs.to_account_id IS NULL{$userClause})
                        )
                      )";
            $params = $inOwn['params'] + $inShare['params'];
            if ($userId > 0) $params['puid'] = $userId;
        } else {
            $sql    = 'SELECT p.*, t.nome AS setor_nome,
                       NULL AS origin_account_nome,
                       NULL AS origin_account_tipo,
                       p.account_id AS origin_account_id,
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
        // Filtro por cliente (aba Clientes) — simetrico ao card_id. Sem isto o
        // vinculo cliente_id (migration 093) ficava write-only (auditoria 2026-06-01).
        if (isset($filters['cliente_id'])) {
            $sql .= ' AND p.cliente_id = :cliente_id';
            $params['cliente_id'] = (int)$filters['cliente_id'];
        }
        $sql .= ' ORDER BY p.proximo_prazo IS NULL, p.proximo_prazo ASC, p.updated_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find($id)
    {
        $pdo = Database::getConnection();
        // p.* já inclui account_seq automaticamente.
        // accounts JOIN devolve origem (matriz / filial — Nome) para selo visual.
        $stmt = $pdo->prepare(
            'SELECT p.*, t.nome AS setor_nome,
                    a.nome AS origin_account_nome,
                    a.tipo AS origin_account_tipo,
                    p.account_id AS origin_account_id
             FROM processos p
             LEFT JOIN teams t ON t.id = p.setor_id AND t.deleted_at IS NULL
             LEFT JOIN accounts a ON a.id = p.account_id
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($data)
    {
        $pdo = Database::getConnection();

        // Resolve contato_id: herda do card (Prospecção) ou do cliente (aba Clientes)
        // se houver. Card tem prioridade quando ambos vierem (não deveria, a UI
        // deixa escolher só uma fonte, mas é defensivo).
        $contatoId = $data['contato_id'] ?? null;
        if (!$contatoId && !empty($data['card_id'])) {
            $cardRow = $pdo->prepare('SELECT contato_id FROM cards WHERE id = ? LIMIT 1');
            $cardRow->execute([$data['card_id']]);
            $contatoId = $cardRow->fetchColumn() ?: null;
        }
        if (!$contatoId && !empty($data['cliente_id'])) {
            $cliRow = $pdo->prepare('SELECT contato_id FROM clientes WHERE id = ? LIMIT 1');
            $cliRow->execute([$data['cliente_id']]);
            $contatoId = $cliRow->fetchColumn() ?: null;
        }

        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar um processo');
        }

        // account_seq: número sequencial DENTRO da conta. Gerado uma vez e nunca reutilizado.
        // Usa MAX(account_seq)+1 para garantir continuidade mesmo se houver deleções.
        $seqStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(account_seq), 0) + 1 FROM processos WHERE account_id = :acc'
        );
        $seqStmt->execute(['acc' => (int)$data['account_id']]);
        $accountSeq = (int) $seqStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO processos
             (account_id, account_seq, numero, cliente_nome, parte_contraria, cpf_cnpj_parte_contraria, setor_id, vara_comarca,
              responsavel_user_id, status, data_inicio, proximo_prazo,
              ultima_movimentacao, observacoes, anexos, alerts, card_id, cliente_id, contato_id,
              created_at, updated_at)
             VALUES
             (:account_id, :account_seq, :numero, :cliente_nome, :parte_contraria, :cpf_cnpj_parte_contraria, :setor_id, :vara_comarca,
              :responsavel_user_id, :status, :data_inicio, :proximo_prazo,
              :ultima_movimentacao, :observacoes, :anexos, :alerts, :card_id, :cliente_id, :contato_id,
              NOW(), NOW())'
        );
        $stmt->execute([
            'account_id'           => $data['account_id'],
            'account_seq'          => $accountSeq,
            'numero'               => $data['numero']               ?? null,
            'cliente_nome'         => $data['cliente_nome']         ?? null,
            'parte_contraria'           => $data['parte_contraria']           ?? null,
            'cpf_cnpj_parte_contraria'  => $data['cpf_cnpj_parte_contraria']  ?? null,
            'setor_id'             => isset($data['setor_id']) && $data['setor_id'] !== '' ? (int)$data['setor_id'] : null,
            'vara_comarca'         => $data['vara_comarca']         ?? null,
            'responsavel_user_id'  => $data['responsavel_user_id']  ?? null,
            'status'               => $data['status']               ?? 'ativo',
            // Datas: normaliza "" e "0000-00-00" para NULL (evita lixo no banco).
            // data_inicio: se vier vazio, assume HOJE (data de abertura do processo no escritório).
            // proximo_prazo / ultima_movimentacao: permanecem NULL se não informados.
            'data_inicio'          => self::_normalizeDate($data['data_inicio'] ?? null) ?? date('Y-m-d'),
            'proximo_prazo'        => self::_normalizeDate($data['proximo_prazo']        ?? null),
            'ultima_movimentacao'  => self::_normalizeDate($data['ultima_movimentacao']  ?? null),
            'observacoes'          => $data['observacoes']          ?? null,
            'anexos'               => isset($data['anexos'])  ? json_encode($data['anexos'],  JSON_UNESCAPED_UNICODE) : null,
            'alerts'               => isset($data['alerts'])  ? json_encode($data['alerts'],  JSON_UNESCAPED_UNICODE) : null,
            'card_id'              => $data['card_id']              ?? null,
            'cliente_id'           => !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null,
            'contato_id'           => $contatoId,
        ]);
        return $pdo->lastInsertId();
    }

    public static function update($id, $data)
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = ['id' => $id];
        $allowed = ['numero','cliente_nome','parte_contraria','cpf_cnpj_parte_contraria','setor_id','vara_comarca','responsavel_user_id','status','data_inicio','proximo_prazo','ultima_movimentacao','observacoes','anexos','alerts','card_id','cliente_id','contato_id'];
        $dateCols = ['data_inicio','proximo_prazo','ultima_movimentacao'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "$k = :$k";
                if (in_array($k, ['anexos','alerts'])) {
                    $params[$k] = $data[$k] !== null ? json_encode($data[$k], JSON_UNESCAPED_UNICODE) : null;
                } elseif (in_array($k, $dateCols, true)) {
                    // Normaliza datas: "" e "0000-00-00" viram NULL
                    $params[$k] = self::_normalizeDate($data[$k]);
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
        // Idem para cliente_id (aba Clientes): herda contato_id do cliente vinculado
        // se contato_id não veio explícito e card_id não tratou acima.
        if (array_key_exists('cliente_id', $data) && !array_key_exists('contato_id', $data)
            && !array_key_exists('contato_id', $params) && !empty($data['cliente_id'])) {
            $cliRow = $pdo->prepare('SELECT contato_id FROM clientes WHERE id = ? LIMIT 1');
            $cliRow->execute([$data['cliente_id']]);
            $inherited = $cliRow->fetchColumn();
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

    // ───────── helpers ──────────────────────────────────────────────

    /**
     * Normaliza valores de data vindos do frontend.
     * Trata "", "0000-00-00", "0000-00-00 00:00:00" como NULL.
     * Mantém o valor original em qualquer outro caso (validação fica a cargo do banco).
     */
    private static function _normalizeDate($v)
    {
        if ($v === null) return null;
        if (!is_string($v)) return $v;
        $v = trim($v);
        if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return null;
        return $v;
    }

    // ───────── helpers de multi-tenant ──────────────────────────────
    private static function _normalizeAccountIds(array $filters): array
    {
        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            return array_values(array_filter(array_map('intval', $filters['account_ids']), fn($v) => $v > 0));
        }
        if (!empty($filters['account_id'])) {
            return [(int) $filters['account_id']];
        }
        return [];
    }

    private static function _buildInClause(array $ids, string $prefix): array
    {
        $ph     = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $k          = "{$prefix}_{$i}";
            $ph[]       = ":{$k}";
            $params[$k] = (int) $id;
        }
        return ['placeholders' => implode(',', $ph), 'params' => $params];
    }
}
