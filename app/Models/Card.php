<?php
namespace App\Models;

use App\Models\Database;

class Card
{
    /**
     * Lista cards do tenant.
     * SEGURANÇA: account_id é obrigatório — NUNCA listar sem filtro de tenant.
     * Inclui cards compartilhados com a conta via resource_shares.
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

        // detecta se coluna account_id existe (single-tenant fallback)
        $hasAccountCol = true;
        try {
            $pdo->query('SELECT account_id FROM cards LIMIT 0');
        } catch (\Throwable $e) {
            $hasAccountCol = false;
        }

        if ($hasAccountCol) {
            $inOwn   = self::_buildInClause($accountIds, 'cown');
            $inShare = self::_buildInClause($accountIds, 'cshr');
            $userId  = isset($filters['user_id']) ? (int)$filters['user_id'] : 0;
            $userClause = $userId > 0 ? ' OR rs.to_user_id = :cuid' : '';
            // Inclui origem (account_nome/tipo) via LEFT JOIN com accounts
            // → permite renderizar selo "MATRIZ" / "FILIAL — Nome" no card sem fetch extra.
            $sql = "SELECT c.*,
                           a.nome AS origin_account_nome,
                           a.tipo AS origin_account_tipo,
                           c.account_id AS origin_account_id,
                           (SELECT remote_jid FROM whatsapp_chats WHERE linked_card_id = c.id LIMIT 1) AS linked_chat_jid
                    FROM cards c
                    LEFT JOIN accounts a ON a.id = c.account_id
                    WHERE c.deleted_at IS NULL
                      AND (
                        c.account_id IN ({$inOwn['placeholders']})
                        OR EXISTS (
                          SELECT 1 FROM resource_shares rs
                          WHERE rs.resource_type = 'card'
                            AND rs.resource_id   = c.id
                            AND rs.status        = 'active'
                            AND (rs.to_account_id IN ({$inShare['placeholders']}) OR rs.to_account_id IS NULL{$userClause})
                        )
                      )";
            $params = $inOwn['params'] + $inShare['params'];
            if ($userId > 0) $params['cuid'] = $userId;
        } else {
            $sql    = 'SELECT c.*,
                              NULL AS origin_account_nome,
                              NULL AS origin_account_tipo,
                              c.account_id AS origin_account_id,
                              (SELECT remote_jid FROM whatsapp_chats WHERE linked_card_id = c.id LIMIT 1) AS linked_chat_jid
                       FROM cards c WHERE c.deleted_at IS NULL';
            $params = [];
        }

        if (!empty($filters['coluna_id'])) {
            $sql .= ' AND c.coluna_id = :coluna_id';
            $params['coluna_id'] = $filters['coluna_id'];
        }
        if (!empty($filters['responsavel_user_id'])) {
            $sql .= ' AND c.responsavel_user_id = :responsavel_user_id';
            $params['responsavel_user_id'] = $filters['responsavel_user_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $filters['status'];
        }
        $sql .= ' ORDER BY c.coluna_id, c.ordem_na_coluna, c.updated_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find($id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT c.*,
                    a.nome AS origin_account_nome,
                    a.tipo AS origin_account_tipo,
                    c.account_id AS origin_account_id,
                    (SELECT remote_jid FROM whatsapp_chats WHERE linked_card_id = c.id LIMIT 1) AS linked_chat_jid
             FROM cards c
             LEFT JOIN accounts a ON a.id = c.account_id
             WHERE c.id = :id AND c.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public static function create($data)
    {
        // account_id OBRIGATÓRIO ao criar
        if (empty($data['account_id'])) {
            throw new \InvalidArgumentException('account_id é obrigatório para criar um card');
        }
        $pdo = Database::getConnection();
        // titulo: usa o que veio; senão usa cliente_nome (preserva título visível em outros lugares)
        $titulo = trim($data['titulo'] ?? $data['cliente_nome'] ?? '');
        $stmt = $pdo->prepare('INSERT INTO cards
              (account_id, titulo, cliente_nome, empresa_nome, telefone_whatsapp, email,
               cpf_cnpj, rg, nome_mae,
               cep, logradouro, numero, complemento, bairro, cidade, uf,
               responsavel_user_id, coluna_id, ordem_na_coluna,
               valor_estimado, valor_proposta, valor_fechado_final,
               data_prevista_fechamento, data_fechamento, descricao, status,
               created_at, updated_at)
            VALUES
              (:account_id, :titulo, :cliente_nome, :empresa_nome, :telefone_whatsapp, :email,
               :cpf_cnpj, :rg, :nome_mae,
               :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf,
               :responsavel_user_id, :coluna_id, :ordem_na_coluna,
               :valor_estimado, :valor_proposta, :valor_fechado_final,
               :data_prevista_fechamento, :data_fechamento, :descricao, :status,
               NOW(), NOW())');
        $stmt->execute([
            'account_id'   => $data['account_id'],
            'titulo'       => $titulo ?: null,
            'cliente_nome' => $data['cliente_nome'] ?? '',
            'empresa_nome' => $data['empresa_nome'] ?? null,
            'telefone_whatsapp' => $data['telefone_whatsapp'] ?? null,
            'email' => $data['email'] ?? null,
            // Dados pessoais
            'cpf_cnpj'     => self::_cleanDigitsOrNull($data['cpf_cnpj'] ?? null),
            'rg'           => self::_trimOrNull($data['rg'] ?? null),
            'nome_mae'     => self::_trimOrNull($data['nome_mae'] ?? null),
            // Endereço estruturado
            'cep'          => self::_cleanDigitsOrNull($data['cep'] ?? null),
            'logradouro'   => self::_trimOrNull($data['logradouro'] ?? null),
            'numero'       => self::_trimOrNull($data['numero'] ?? null),
            'complemento'  => self::_trimOrNull($data['complemento'] ?? null),
            'bairro'       => self::_trimOrNull($data['bairro'] ?? null),
            'cidade'       => self::_trimOrNull($data['cidade'] ?? null),
            'uf'           => self::_normalizeUf($data['uf'] ?? null),
            'responsavel_user_id' => $data['responsavel_user_id'] ?? null,
            'coluna_id' => $data['coluna_id'] ?? null,
            'ordem_na_coluna' => $data['ordem_na_coluna'] ?? 0,
            'valor_estimado' => $data['valor_estimado'] ?? 0,
            'valor_proposta' => $data['valor_proposta'] ?? 0,
            'valor_fechado_final' => $data['valor_fechado_final'] ?? 0,
            // Datas: normaliza "" e "0000-00-00" para NULL (evita lixo no banco)
            'data_prevista_fechamento' => self::_normalizeDate($data['data_prevista_fechamento'] ?? null),
            'data_fechamento' => self::_normalizeDate($data['data_fechamento'] ?? null),
            'descricao' => $data['descricao'] ?? null,
            'status' => $data['status'] ?? 'aberto'
        ]);
        $id = (int)$pdo->lastInsertId();

        // Vincula ao contato pelo telefone (cria contato se ainda não existir)
        if ($id && !empty($data['telefone_whatsapp'])) {
            require_once __DIR__ . '/Contato.php';
            $contatoId = \App\Models\Contato::findOrCreateByPhone(
                $data['cliente_nome'] ?? '',
                $data['telefone_whatsapp']
            );
            if ($contatoId) {
                $pdo->prepare('UPDATE cards SET contato_id = ? WHERE id = ?')
                    ->execute([$contatoId, $id]);
            }
        }

        return $id;
    }

    public static function update($id, $data)
    {
        $pdo = Database::getConnection();
        $fields = [];
        $params = ['id' => $id];
        $allowed  = ['titulo','cliente_nome','empresa_nome','telefone_whatsapp','email',
                     'cpf_cnpj','rg','nome_mae',
                     'cep','logradouro','numero','complemento','bairro','cidade','uf',
                     'responsavel_user_id','coluna_id','ordem_na_coluna',
                     'valor_estimado','valor_proposta','valor_fechado_final',
                     'data_prevista_fechamento','data_fechamento','descricao','status'];
        $dateCols   = ['data_prevista_fechamento','data_fechamento'];
        $digitsOnly = ['cpf_cnpj','cep'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "$k = :$k";
                if      (in_array($k, $dateCols,   true)) $params[$k] = self::_normalizeDate($data[$k]);
                elseif  (in_array($k, $digitsOnly, true)) $params[$k] = self::_cleanDigitsOrNull($data[$k]);
                elseif  ($k === 'uf')                     $params[$k] = self::_normalizeUf($data[$k]);
                else                                       $params[$k] = $data[$k];
            }
        }
        if (empty($fields)) return false;

        // Resolve contato_id se telefone foi atualizado
        if (array_key_exists('telefone_whatsapp', $data) && !empty($data['telefone_whatsapp'])) {
            require_once __DIR__ . '/Contato.php';
            $nome = $data['cliente_nome'] ?? null;
            if (!$nome) {
                $existing = self::find($id);
                $nome = $existing['cliente_nome'] ?? '';
            }
            $contatoId = \App\Models\Contato::findOrCreateByPhone($nome, $data['telefone_whatsapp']);
            if ($contatoId) {
                $fields[]              = 'contato_id = :contato_id';
                $params['contato_id']  = $contatoId;
            }
        }

        $sql = 'UPDATE cards SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public static function move($id, $coluna_id, $ordem_na_coluna, $usuario_id = null)
    {
        $pdo = Database::getConnection();
        // get current coluna for history
        $cur = self::find($id);
        $de_coluna = $cur['coluna_id'] ?? null;
        $stmt = $pdo->prepare('UPDATE cards SET coluna_id = :coluna_id, ordem_na_coluna = :ordem_na_coluna, updated_at = NOW() WHERE id = :id');
        $ok = $stmt->execute(['coluna_id' => $coluna_id, 'ordem_na_coluna' => $ordem_na_coluna, 'id' => $id]);
        if ($ok) {
            // LGPD Etapa 4: ip + user_agent + request_id
            if (!class_exists('App\\Helpers\\RequestId')) {
                require_once dirname(__DIR__) . '/Helpers/RequestId.php';
            }
            $h = $pdo->prepare(
                'INSERT INTO card_history (card_id, usuario_id, acao, de_coluna_id, para_coluna_id,
                                           ip, user_agent, request_id, created_at)
                 VALUES (:card_id, :usuario_id, :acao, :de_coluna_id, :para_coluna_id,
                         :ip, :ua, :rid, NOW())'
            );
            $h->execute([
                'card_id'=>$id,'usuario_id'=>$usuario_id,'acao'=>'moved',
                'de_coluna_id'=>$de_coluna,'para_coluna_id'=>$coluna_id,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'rid' => \App\Helpers\RequestId::get(),
            ]);
        }
        return $ok;
    }

    public static function bulkUpdateOrders(array $updates, $usuario_id = null)
    {
        $pdo = Database::getConnection();
        try {
            $pdo->beginTransaction();
            $uStmt = $pdo->prepare('UPDATE cards SET coluna_id = :coluna_id, ordem_na_coluna = :ordem_na_coluna, updated_at = NOW() WHERE id = :id');
            // LGPD Etapa 4: ip + user_agent + request_id
            if (!class_exists('App\\Helpers\\RequestId')) {
                require_once dirname(__DIR__) . '/Helpers/RequestId.php';
            }
            $hStmt = $pdo->prepare(
                'INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado,
                                           valor_anterior, valor_novo, de_coluna_id, para_coluna_id,
                                           ip, user_agent, request_id, created_at)
                 VALUES (:card_id, :usuario_id, :acao, :campo_alterado,
                         :valor_anterior, :valor_novo, :de_coluna_id, :para_coluna_id,
                         :ip, :ua, :rid, NOW())'
            );
            foreach ($updates as $up) {
                $id = (int)($up['id'] ?? 0);
                if (!$id) continue;
                $new_col = isset($up['coluna_id']) ? (int)$up['coluna_id'] : null;
                $new_ord = isset($up['ordem_na_coluna']) ? (int)$up['ordem_na_coluna'] : 0;
                // fetch current
                $cur = self::find($id);
                $de_col = $cur['coluna_id'] ?? null;
                $prev_ord = $cur['ordem_na_coluna'] ?? null;
                $uStmt->execute(['coluna_id'=>$new_col,'ordem_na_coluna'=>$new_ord,'id'=>$id]);
                $hStmt->execute([
                    'card_id'=>$id,
                    'usuario_id'=>$usuario_id,
                    'acao'=>'reorder',
                    'campo_alterado'=>'coluna_id,ordem_na_coluna',
                    'valor_anterior'=>json_encode(['coluna_id'=>$de_col,'ordem_na_coluna'=>$prev_ord], JSON_UNESCAPED_UNICODE),
                    'valor_novo'=>json_encode(['coluna_id'=>$new_col,'ordem_na_coluna'=>$new_ord], JSON_UNESCAPED_UNICODE),
                    'de_coluna_id'=>$de_col,
                    'para_coluna_id'=>$new_col
                ]);
            }
            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }

    public static function getHistory($card_id)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT ch.*, u.login as usuario_login FROM card_history ch LEFT JOIN users u ON u.id = ch.usuario_id WHERE ch.card_id = :card_id ORDER BY ch.created_at DESC');
        $stmt->execute(['card_id' => $card_id]);
        return $stmt->fetchAll();
    }

    public static function getChecklist($card_id)
    {
        // lazy-load checklist model
        if (!class_exists('\App\\Models\\CardChecklist')) {
            require_once __DIR__ . '/CardChecklist.php';
        }
        return \App\Models\CardChecklist::listByCard($card_id);
    }

    public static function recalculateChecklistPercent($card_id)
    {
        $pdo = Database::getConnection();
        // count total and concluded
        $stmt = $pdo->prepare('SELECT COUNT(*) as total, SUM(concluido) as done FROM card_checklist_items WHERE card_id = :card_id');
        $stmt->execute(['card_id' => $card_id]);
        $row = $stmt->fetch();
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done'] ?? 0);
        $percent = 0.0;
        if ($total > 0) $percent = round(($done / $total) * 100, 2);

        // ensure column exists; try update, if fails create column then update
        try {
            $u = $pdo->prepare('UPDATE cards SET checklist_percentual = :pct, updated_at = NOW() WHERE id = :id');
            $u->execute(['pct' => $percent, 'id' => $card_id]);
        } catch (\PDOException $e) {
            // try to add column then update
            try {
                $pdo->exec('ALTER TABLE cards ADD COLUMN checklist_percentual DECIMAL(5,2) DEFAULT 0');
                $u = $pdo->prepare('UPDATE cards SET checklist_percentual = :pct, updated_at = NOW() WHERE id = :id');
                $u->execute(['pct' => $percent, 'id' => $card_id]);
            } catch (\Exception $inner) {
                return false;
            }
        }
        return $percent;
    }

    public static function softDelete($id, $deleted_by = null)
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE cards SET deleted_at = NOW(), deleted_by = :deleted_by WHERE id = :id');
        return $stmt->execute(['deleted_by' => $deleted_by, 'id' => $id]);
    }

    // ───────── helpers ──────────────────────────────────────────────

    /**
     * Normaliza valores de data vindos do frontend.
     * Trata "", "0000-00-00", "0000-00-00 00:00:00" como NULL.
     * Mantém o valor original em qualquer outro caso.
     */
    private static function _normalizeDate($v)
    {
        if ($v === null) return null;
        if (!is_string($v)) return $v;
        $v = trim($v);
        if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return null;
        return $v;
    }

    /** trim + null se vazio. Usado em campos cadastrais (RG, nome_mae, logradouro, etc.). */
    private static function _trimOrNull($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    /** Mantém só dígitos. NULL se ficou vazio. Usado em cpf_cnpj e cep. */
    private static function _cleanDigitsOrNull($v): ?string
    {
        if ($v === null) return null;
        $d = preg_replace('/\D/', '', (string)$v);
        return $d === '' ? null : $d;
    }

    /** UF: upper-case + allowlist ISO 3166-2 BR. NULL fora da lista. */
    private static function _normalizeUf($v): ?string
    {
        if ($v === null) return null;
        $uf = strtoupper(trim((string)$v));
        if ($uf === '') return null;
        static $allowed = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
        return in_array($uf, $allowed, true) ? $uf : null;
    }

    // ───────── helpers de multi-tenant ──────────────────────────────
    /**
     * Normaliza account_ids vindos de filters[]. Aceita:
     *   account_ids => [1,2,3]   (preferencial)
     *   account_id  => 1         (legado — embrulha em array)
     */
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

    /** Gera placeholders nomeados para uma cláusula IN(...) + array de params. */
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
