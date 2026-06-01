<?php
namespace App\Models;

use App\Models\Database;

/**
 * Cliente — base real de clientes do escritório (módulo Operações > Clientes).
 *
 * Separado da Prospecção: aqui ficam clientes ativos/antigos, não leads.
 * Multi-tenant via account_id. Cada conta tem seus próprios setores
 * (clientes_setores). Sem herança matriz→filial (decisão deliberada da Fase 2 —
 * leve, fácil de evoluir depois se preciso).
 *
 * Audit: toda mutação grava em clientes_history (antes/depois em JSON).
 */
class Cliente
{
    /**
     * Lista clientes do tenant.
     * SEGURANÇA: account_ids obrigatório — sem isso retorna [] (jamais cross-tenant).
     *
     * Filtros aceitos:
     *   account_ids  array<int>     — matriz pode passar [matriz_id, filial_ids...]
     *   account_id   int            — legado, embrulhado em [account_id]
     *   setor_id     int            — kanban-by-coluna
     *   responsavel_id int
     *   status       string
     *   search       string         — nome / telefone / whatsapp / cpf_cnpj / email
     *   include_archived bool       — default false (esconde deleted_at IS NOT NULL)
     */
    public static function list(array $filters = []): array
    {
        $pdo = Database::getConnection();
        $accountIds = self::_normalizeAccountIds($filters);
        if (empty($accountIds)) return [];

        $in   = self::_buildInClause($accountIds, 'cliacc');
        $sql  = "SELECT c.*,
                        s.nome AS setor_nome,
                        s.cor  AS setor_cor,
                        u.nome AS responsavel_nome,
                        a.nome AS origin_account_nome,
                        a.tipo AS origin_account_tipo
                   FROM clientes c
              LEFT JOIN clientes_setores s ON s.id = c.setor_id
              LEFT JOIN users  u           ON u.id = c.responsavel_id
              LEFT JOIN accounts a         ON a.id = c.account_id
                  WHERE c.account_id IN ({$in['placeholders']})";
        $params = $in['params'];

        if (empty($filters['include_archived'])) {
            $sql .= ' AND c.deleted_at IS NULL';
        }
        if (!empty($filters['setor_id'])) {
            $sql .= ' AND c.setor_id = :setor_id';
            $params['setor_id'] = (int)$filters['setor_id'];
        }
        if (!empty($filters['responsavel_id'])) {
            $sql .= ' AND c.responsavel_id = :resp_id';
            $params['resp_id'] = (int)$filters['responsavel_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (c.nome LIKE :s
                        OR c.telefone LIKE :s
                        OR c.whatsapp LIKE :s
                        OR c.cpf_cnpj LIKE :s
                        OR c.email LIKE :s)';
            $params['s'] = '%' . trim($filters['search']) . '%';
        }

        $sql .= ' ORDER BY c.setor_id, c.ordem_no_setor, c.updated_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Busca cliente por id. Sem filtro de tenant aqui — caller (endpoint) é
     * responsável por validar acesso via AccountContext.
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT c.*,
                    s.nome AS setor_nome,
                    s.cor  AS setor_cor,
                    u.nome AS responsavel_nome,
                    a.nome AS origin_account_nome,
                    a.tipo AS origin_account_tipo
               FROM clientes c
          LEFT JOIN clientes_setores s ON s.id = c.setor_id
          LEFT JOIN users  u           ON u.id = c.responsavel_id
          LEFT JOIN accounts a         ON a.id = c.account_id
              WHERE c.id = :id
              LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria cliente. account_id obrigatório (vem do AccountContext, NUNCA do body).
     * Dedupe automático: se telefone fornecido, vincula a contato existente ou cria.
     * Audit: grava clientes_history acao='created'.
     */
    public static function create(array $data, ?int $userId = null): int
    {
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório');
        }
        if (empty($data['nome']) || !trim((string)$data['nome'])) {
            throw new \InvalidArgumentException('nome é obrigatório');
        }
        if (empty($data['setor_id'])) {
            throw new \InvalidArgumentException('setor_id é obrigatório');
        }

        $pdo = Database::getConnection();
        $accountId = (int)$data['account_id'];

        // ── Dedupe: tenta achar/criar contato pelo telefone preferencial ─────
        $contatoId = null;
        $phoneForDedupe = self::_pickDedupePhone($data);
        if ($phoneForDedupe) {
            require_once __DIR__ . '/Contato.php';
            $contatoId = \App\Models\Contato::findOrCreateByPhone(
                (string)$data['nome'],
                $phoneForDedupe
            );
        }

        $payload = [
            'account_id'     => $accountId,
            'contato_id'     => $contatoId,
            'setor_id'       => (int)$data['setor_id'],
            'responsavel_id' => !empty($data['responsavel_id']) ? (int)$data['responsavel_id'] : null,
            'nome'           => trim((string)$data['nome']),
            'tipo_cliente'   => in_array(($data['tipo_cliente'] ?? 'PF'), ['PF','PJ'], true) ? ($data['tipo_cliente'] ?? 'PF') : 'PF',
            'cpf_cnpj'       => self::_cleanDigits($data['cpf_cnpj'] ?? null),
            'rg'             => self::_trimOrNull($data['rg'] ?? null),
            'nome_mae'       => self::_trimOrNull($data['nome_mae'] ?? null),
            'telefone'       => self::_trimOrNull($data['telefone'] ?? null),
            'whatsapp'       => self::_trimOrNull($data['whatsapp'] ?? null),
            'email'          => self::_trimOrNull($data['email'] ?? null),
            'cep'            => self::_cleanDigits($data['cep'] ?? null),
            'logradouro'     => self::_trimOrNull($data['logradouro'] ?? null),
            'numero'         => self::_trimOrNull($data['numero'] ?? null),
            'complemento'    => self::_trimOrNull($data['complemento'] ?? null),
            'bairro'         => self::_trimOrNull($data['bairro'] ?? null),
            'cidade'         => self::_trimOrNull($data['cidade'] ?? null),
            'uf'             => self::_normalizeUf($data['uf'] ?? null),
            // origem: slug livre (validado contra clientes_origens no endpoint).
            // Default 'cadastro_manual' pra manter backward-compat com leads antigos.
            'origem'         => self::_trimOrNull($data['origem'] ?? null) ?? 'cadastro_manual',
            'status'         => self::_trimOrNull($data['status'] ?? 'ativo') ?? 'ativo',
            'ordem_no_setor' => isset($data['ordem_no_setor']) ? (int)$data['ordem_no_setor'] : 0,
            'observacoes'    => self::_trimOrNull($data['observacoes'] ?? null),
            'created_by'     => $userId,
            'updated_by'     => $userId,
        ];

        $sql = "INSERT INTO clientes
                  (account_id, contato_id, setor_id, responsavel_id, nome,
                   tipo_cliente, cpf_cnpj, rg, nome_mae, telefone, whatsapp, email,
                   cep, logradouro, numero, complemento, bairro, cidade, uf,
                   origem, status, ordem_no_setor, observacoes,
                   created_by, updated_by, created_at, updated_at)
                VALUES
                  (:account_id, :contato_id, :setor_id, :responsavel_id, :nome,
                   :tipo_cliente, :cpf_cnpj, :rg, :nome_mae, :telefone, :whatsapp, :email,
                   :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf,
                   :origem, :status, :ordem_no_setor, :observacoes,
                   :created_by, :updated_by, NOW(), NOW())";
        $pdo->prepare($sql)->execute($payload);
        $id = (int)$pdo->lastInsertId();

        self::_logHistory($id, $accountId, $userId, 'created', null, $payload);
        return $id;
    }

    /**
     * Atualiza cliente. Campos não whitelisted são ignorados.
     * NÃO atualiza account_id (jamais cross-tenant).
     */
    public static function update(int $id, array $data, ?int $userId = null): bool
    {
        $pdo = Database::getConnection();
        $before = self::find($id);
        if (!$before) return false;

        $allowed = [
            'setor_id','responsavel_id','nome','tipo_cliente','cpf_cnpj',
            'rg','nome_mae',
            'telefone','whatsapp','email',
            'cep','logradouro','numero','complemento','bairro','cidade','uf',
            'origem','status',
            'ordem_no_setor','observacoes'
        ];
        $fields = [];
        $params = ['id' => $id];

        foreach ($allowed as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if     ($k === 'cpf_cnpj' || $k === 'cep') $v = self::_cleanDigits($v);
            elseif ($k === 'tipo_cliente') $v = in_array($v, ['PF','PJ'], true) ? $v : 'PF';
            elseif ($k === 'uf')        $v = self::_normalizeUf($v);
            // origem agora é slug livre — validação (existe em clientes_origens?)
            // fica no endpoint pra permitir backward-compat com slugs legados.
            elseif ($k === 'setor_id' || $k === 'responsavel_id' || $k === 'ordem_no_setor') $v = $v === null || $v === '' ? null : (int)$v;
            else                        $v = self::_trimOrNull(is_scalar($v) ? (string)$v : null);
            $fields[] = "$k = :$k";
            $params[$k] = $v;
        }

        if (empty($fields)) return false;

        // Re-dedupe contato se telefone/whatsapp mudou
        if (array_key_exists('telefone', $data) || array_key_exists('whatsapp', $data)) {
            $phoneNew = self::_pickDedupePhone(array_merge((array)$before, $data));
            if ($phoneNew) {
                require_once __DIR__ . '/Contato.php';
                $cid = \App\Models\Contato::findOrCreateByPhone(
                    (string)($data['nome'] ?? $before['nome'] ?? ''),
                    $phoneNew
                );
                if ($cid) { $fields[] = 'contato_id = :contato_id'; $params['contato_id'] = $cid; }
            }
        }

        if ($userId !== null) { $fields[] = 'updated_by = :uby'; $params['uby'] = $userId; }

        $sql = 'UPDATE clientes SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $ok = $pdo->prepare($sql)->execute($params);

        if ($ok) {
            $after = self::find($id);
            self::_logHistory($id, (int)$before['account_id'], $userId, 'updated',
                self::_diff($before, $after), self::_diff($after, $before));
        }
        return $ok;
    }

    /** Move cliente para outro setor + ordem nova. Audit acao='moved'. */
    public static function move(int $id, int $setorId, int $ordem, ?int $userId = null): bool
    {
        $pdo = Database::getConnection();
        $before = self::find($id);
        if (!$before) return false;
        $de = (int)$before['setor_id'];
        $ok = $pdo->prepare('UPDATE clientes SET setor_id = :s, ordem_no_setor = :o, updated_by = :u, updated_at = NOW() WHERE id = :id')
                  ->execute(['s' => $setorId, 'o' => $ordem, 'u' => $userId, 'id' => $id]);
        if ($ok) {
            self::_logHistory($id, (int)$before['account_id'], $userId, 'moved',
                ['setor_id' => $de, 'ordem_no_setor' => (int)$before['ordem_no_setor']],
                ['setor_id' => $setorId, 'ordem_no_setor' => $ordem]);
        }
        return $ok;
    }

    /**
     * Reordena em lote (drag-and-drop). Valida que cada id pertence aos
     * account_ids do tenant antes de mexer.
     * @return int número de linhas atualizadas
     */
    public static function bulkUpdateOrders(array $updates, array $accountIds, ?int $userId = null): int
    {
        if (empty($updates) || empty($accountIds)) return 0;
        $pdo = Database::getConnection();
        $in  = self::_buildInClause($accountIds, 'cliboacc');

        try {
            $pdo->beginTransaction();
            $sel = $pdo->prepare(
                "SELECT id, setor_id, ordem_no_setor, account_id FROM clientes
                  WHERE id = :id AND account_id IN ({$in['placeholders']}) LIMIT 1"
            );
            $upd = $pdo->prepare('UPDATE clientes SET setor_id = :s, ordem_no_setor = :o, updated_by = :u, updated_at = NOW() WHERE id = :id');
            $n = 0;
            foreach ($updates as $up) {
                $cid = (int)($up['id'] ?? 0);
                if (!$cid) continue;
                $sel->execute(['id' => $cid] + $in['params']);
                $before = $sel->fetch(\PDO::FETCH_ASSOC);
                if (!$before) continue;  // não pertence ao tenant
                $newSetor = isset($up['setor_id']) ? (int)$up['setor_id'] : (int)$before['setor_id'];
                $newOrdem = isset($up['ordem_no_setor']) ? (int)$up['ordem_no_setor'] : 0;
                $upd->execute(['s' => $newSetor, 'o' => $newOrdem, 'u' => $userId, 'id' => $cid]);
                self::_logHistory($cid, (int)$before['account_id'], $userId, 'moved',
                    ['setor_id' => (int)$before['setor_id'], 'ordem_no_setor' => (int)$before['ordem_no_setor']],
                    ['setor_id' => $newSetor, 'ordem_no_setor' => $newOrdem]);
                $n++;
            }
            $pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return 0;
        }
    }

    /** Soft-delete (arquivamento permanente). */
    public static function archive(int $id, ?int $userId = null): bool
    {
        $pdo = Database::getConnection();
        $before = self::find($id);
        if (!$before || !empty($before['deleted_at'])) return false;
        $ok = $pdo->prepare('UPDATE clientes SET deleted_at = NOW(), deleted_by = :u WHERE id = :id')
                  ->execute(['u' => $userId, 'id' => $id]);
        if ($ok) self::_logHistory($id, (int)$before['account_id'], $userId, 'archived', null, null);
        return $ok;
    }

    /** Restaura cliente arquivado. */
    public static function restore(int $id, ?int $userId = null): bool
    {
        $pdo = Database::getConnection();
        $before = self::find($id);
        if (!$before || empty($before['deleted_at'])) return false;
        $ok = $pdo->prepare('UPDATE clientes SET deleted_at = NULL, deleted_by = NULL, updated_by = :u, updated_at = NOW() WHERE id = :id')
                  ->execute(['u' => $userId, 'id' => $id]);
        if ($ok) self::_logHistory($id, (int)$before['account_id'], $userId, 'restored', null, null);
        return $ok;
    }

    /** Histórico cronológico decrescente do cliente. */
    public static function getHistory(int $id): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT ch.*, u.nome AS user_nome, u.login AS user_login
               FROM clientes_history ch
          LEFT JOIN users u ON u.id = ch.user_id
              WHERE ch.cliente_id = :id
           ORDER BY ch.created_at DESC, ch.id DESC"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ───────── helpers ──────────────────────────────────────────────

    /** Telefone preferencial pra dedupe: WhatsApp > telefone. */
    private static function _pickDedupePhone(array $data): ?string
    {
        $w = trim((string)($data['whatsapp'] ?? ''));
        if ($w !== '') return $w;
        $t = trim((string)($data['telefone'] ?? ''));
        return $t !== '' ? $t : null;
    }

    private static function _trimOrNull($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function _cleanDigits($v): ?string
    {
        if ($v === null) return null;
        $d = preg_replace('/\D/', '', (string)$v);
        return $d === '' ? null : $d;
    }

    /** UF: upper-case + 2 chars + ISO 3166-2 BR allowlist. NULL fora da lista. */
    private static function _normalizeUf($v): ?string
    {
        if ($v === null) return null;
        $uf = strtoupper(trim((string)$v));
        if ($uf === '') return null;
        static $allowed = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
        return in_array($uf, $allowed, true) ? $uf : null;
    }

    /** Retorna apenas as chaves de $a cujos valores diferem entre $a e $b. */
    private static function _diff(?array $a, ?array $b): ?array
    {
        if (!$a) return null;
        $out = [];
        foreach ($a as $k => $v) {
            if (!is_scalar($v) && $v !== null) continue;  // só campos primitivos
            if (!isset($b[$k]) || (string)$b[$k] !== (string)$v) $out[$k] = $v;
        }
        return $out ?: null;
    }

    /** Grava entrada em clientes_history. Falha silenciosa (não bloqueia mutação). */
    private static function _logHistory(int $clienteId, int $accountId, ?int $userId, string $acao, ?array $antes, ?array $depois): void
    {
        try {
            $pdo = Database::getConnection();
            $pdo->prepare(
                'INSERT INTO clientes_history (cliente_id, account_id, user_id, acao, antes_json, depois_json, created_at)
                 VALUES (:cid, :aid, :uid, :ac, :ant, :dep, NOW())'
            )->execute([
                'cid' => $clienteId,
                'aid' => $accountId,
                'uid' => $userId,
                'ac'  => $acao,
                'ant' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
                'dep' => $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) { /* silent */ }
    }

    // ───────── multi-tenant helpers (espelham Card/PipelineColumn) ───

    private static function _normalizeAccountIds(array $filters): array
    {
        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            return array_values(array_filter(array_map('intval', $filters['account_ids']), fn($v) => $v > 0));
        }
        if (!empty($filters['account_id'])) return [(int)$filters['account_id']];
        return [];
    }

    private static function _buildInClause(array $ids, string $prefix): array
    {
        $ph = []; $params = [];
        foreach ($ids as $i => $id) {
            $k = "{$prefix}_{$i}";
            $ph[] = ":{$k}";
            $params[$k] = (int)$id;
        }
        return ['placeholders' => implode(',', $ph), 'params' => $params];
    }
}
