<?php
namespace App\Prospeccao;

use App\Core\Database;

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
        // Prospecção convertida sai do FUNIL ATIVO, mas não do banco: ela
        // continua consultável por quem pedir explicitamente (relatório, filtro,
        // auditoria) passando incluir_convertidas. Some da visão de trabalho
        // porque o funil é a fila do que ainda está em aberto, e um lead já
        // fechado ali só atrapalha a leitura do que falta fazer.
        //
        // A checagem de coluna existente é a mesma defesa que o resto do método
        // usa: em base sem a migration 126 a consulta não pode quebrar.
        if (empty($filters['incluir_convertidas'])) {
            static $temColunaCliente = null;
            if ($temColunaCliente === null) {
                try {
                    $pdo->query('SELECT cliente_id FROM cards LIMIT 0');
                    $temColunaCliente = true;
                } catch (\Throwable $e) {
                    $temColunaCliente = false;
                }
            }
            if ($temColunaCliente) {
                $sql .= ' AND c.cliente_id IS NULL';
            }
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

        // origem_id (canal de aquisição, migration 127) só entra no INSERT se a
        // coluna existir. Mesma defesa que list() usa para cliente_id: em base
        // sem a migration o cadastro não pode quebrar.
        $temOrigem = self::_temColunaOrigem();
        $colOrigem = $temOrigem ? ', origem_id' : '';
        $valOrigem = $temOrigem ? ', :origem_id' : '';

        // Aniversario (migration 128). Mesma sonda: base sem a migration nao pode
        // quebrar o cadastro de lead.
        $temNasc = self::_temColunaNascimento();
        if ($temNasc) { $colOrigem .= ', data_nascimento'; $valOrigem .= ', :data_nascimento'; }

        $stmt = $pdo->prepare('INSERT INTO cards
              (account_id, titulo, cliente_nome, empresa_nome, telefone_whatsapp, email,
               cpf_cnpj, rg, nome_mae,
               cep, logradouro, numero, complemento, bairro, cidade, uf,
               responsavel_user_id, coluna_id, ordem_na_coluna,
               valor_estimado, valor_proposta, valor_fechado_final,
               data_prevista_fechamento, data_fechamento, descricao, status' . $colOrigem . ',
               created_at, updated_at)
            VALUES
              (:account_id, :titulo, :cliente_nome, :empresa_nome, :telefone_whatsapp, :email,
               :cpf_cnpj, :rg, :nome_mae,
               :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf,
               :responsavel_user_id, :coluna_id, :ordem_na_coluna,
               :valor_estimado, :valor_proposta, :valor_fechado_final,
               :data_prevista_fechamento, :data_fechamento, :descricao, :status' . $valOrigem . ',
               NOW(), NOW())');
        $origemId = $temOrigem ? self::_intOrNull($data['origem_id'] ?? null) : null;
        if ($origemId !== null && !self::_origemDaConta($origemId, (int)$data['account_id'])) {
            $origemId = null; // canal de outra conta: ver o comentário em update()
        }

        $stmt->execute(($temOrigem ? ['origem_id' => $origemId] : [])
                     + ($temNasc ? ['data_nascimento' => self::_normalizeDate($data['data_nascimento'] ?? null)] : [])
                     + [
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
            $contatoId = \App\Prospeccao\Contato::findOrCreateByPhone(
                $data['cliente_nome'] ?? '',
                $data['telefone_whatsapp'],
                (int)($data['account_id'] ?? 0)
            );
            if ($contatoId) {
                $pdo->prepare('UPDATE cards SET contato_id = ? WHERE id = ?')
                    ->execute([$contatoId, $id]);
            }
        }

        // Primeiro evento da linha do tempo. Sem ele a timeline do lead começa
        // no meio da história, e depois da conversão o cliente não teria como
        // mostrar quando e por quem entrou no sistema.
        if ($id) {
            self::logEvento($id, $data['_usuario_id'] ?? null, 'created');
        }

        return $id;
    }

    /**
     * Uma linha em card_history. É o único ponto que escreve nessa tabela fora
     * de move()/bulkUpdateOrders(), para o formato não divergir entre quem
     * registra criação, alteração de campo e conversão.
     *
     * Falha em silêncio de propósito: histórico não pode derrubar a operação
     * que ele está descrevendo.
     */
    public static function logEvento(
        $cardId,
        $usuarioId = null,
        string $acao = 'updated',
        ?string $campo = null,
        $de = null,
        $para = null
    ): void {
        try {
            $pdo = Database::getConnection();
            if (!class_exists('App\\Core\\RequestId')) {
                require_once __DIR__ . '/../Core/RequestId.php';
            }
            $pdo->prepare(
                'INSERT INTO card_history
                   (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo,
                    ip, user_agent, request_id, created_at)
                 VALUES (:card_id, :usuario_id, :acao, :campo, :de, :para, :ip, :ua, :rid, NOW())'
            )->execute([
                'card_id'    => (int)$cardId,
                'usuario_id' => $usuarioId,
                'acao'       => $acao,
                'campo'      => $campo,
                'de'         => $de === null ? null : (string)$de,
                'para'       => $para === null ? null : (string)$para,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'rid'        => \App\Core\RequestId::get(),
            ]);
        } catch (\Throwable $e) {
            // silencioso
        }
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

        $dateCols = ['data_prevista_fechamento','data_fechamento'];

        // Canal de aquisição (migration 127). Entra na lista de campos
        // permitidos só quando a coluna existe, e daí em diante o histórico
        // campo a campo de _logCampos() cuida dele sem tratamento especial.
        if (self::_temColunaOrigem()) {
            $allowed[] = 'origem_id';
        }
        if (self::_temColunaNascimento()) {
            $allowed[]  = 'data_nascimento';
            $dateCols[] = 'data_nascimento';   // normaliza "" e "0000-00-00" para NULL
        }

        $digitsOnly = ['cpf_cnpj','cep'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "$k = :$k";
                if      (in_array($k, $dateCols,   true)) $params[$k] = self::_normalizeDate($data[$k]);
                elseif  (in_array($k, $digitsOnly, true)) $params[$k] = self::_cleanDigitsOrNull($data[$k]);
                elseif  ($k === 'uf')                     $params[$k] = self::_normalizeUf($data[$k]);
                elseif  ($k === 'origem_id')              $params[$k] = self::_intOrNull($data[$k]);
                else                                       $params[$k] = $data[$k];
            }
        }
        if (empty($fields)) return false;

        // Resolve contato_id se telefone foi atualizado
        if (array_key_exists('telefone_whatsapp', $data) && !empty($data['telefone_whatsapp'])) {
            // o card existente e quem diz de qual conta e o contato
            $existing = self::find($id);
            $nome = $data['cliente_nome'] ?? ($existing['cliente_nome'] ?? '');
            $contatoId = \App\Prospeccao\Contato::findOrCreateByPhone(
                $nome,
                $data['telefone_whatsapp'],
                (int)($existing['account_id'] ?? 0)
            );
            if ($contatoId) {
                $fields[]              = 'contato_id = :contato_id';
                $params['contato_id']  = $contatoId;
            }
        }

        // Estado ANTES da escrita, para o histórico dizer o que mudou.
        // Até 09/09/2026 este método não registrava nada: só move() e
        // bulkUpdateOrders() escreviam em card_history. Ou seja, alterar o
        // telefone ou o responsável de um lead não deixava rastro nenhum, e a
        // auditoria da prospecção tinha um buraco do tamanho do cadastro
        // inteiro.
        $antes = self::find($id);

        // Canal de aquisição de OUTRA conta não entra. O catálogo
        // (clientes_origens) é por conta, e uma sessão matriz editando card de
        // filial mandaria o id do catálogo da matriz. Gravar assim deixaria o
        // card com um canal que a conversão depois não consegue resolver, e o
        // dado sumiria em silêncio no meio do caminho.
        if (array_key_exists('origem_id', $params) && $params['origem_id'] !== null && $antes) {
            if (!self::_origemDaConta((int)$params['origem_id'], (int)($antes['account_id'] ?? 0))) {
                $params['origem_id'] = null;
            }
        }

        $sql = 'UPDATE cards SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $ok   = $stmt->execute($params);

        if ($ok && $antes) {
            self::_logCampos($id, $antes, $params, $data['_usuario_id'] ?? null);
        }
        return $ok;
    }

    /**
     * Uma linha de card_history POR CAMPO que mudou de valor, com o valor
     * anterior e o novo.
     *
     * Um único "registro atualizado" não serve para auditoria: seis meses
     * depois ninguém sabe se o que mudou foi uma vírgula na descrição ou o
     * telefone de contato do cliente.
     */
    private static function _logCampos($id, array $antes, array $params, $usuarioId = null): void
    {
        // Ruído não é histórico: campos de ordenação mudam a cada arrastar de
        // card e já são registrados por move()/bulkUpdateOrders().
        $ignorar = ['id', 'ordem_na_coluna', 'contato_id'];

        foreach ($params as $campo => $novo) {
            if (in_array($campo, $ignorar, true)) continue;
            if (!array_key_exists($campo, $antes)) continue;

            $de = $antes[$campo];
            // Comparação frouxa de propósito: o banco devolve '0.00' onde o
            // formulário manda '0', e isso não é uma alteração.
            if ((string)$de === (string)$novo) continue;
            if (($de === null || $de === '') && ($novo === null || $novo === '')) continue;

            self::logEvento(
                $id,
                $usuarioId,
                $campo === 'coluna_id' ? 'stage_changed' : 'updated',
                $campo,
                $de,
                $novo
            );
        }
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
            if (!class_exists('App\\Core\\RequestId')) {
                require_once __DIR__ . '/../Core/RequestId.php';
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
                'rid' => \App\Core\RequestId::get(),
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
            if (!class_exists('App\\Core\\RequestId')) {
                require_once __DIR__ . '/../Core/RequestId.php';
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
        if (!class_exists('\App\\Prospeccao\\CardChecklist')) {
            require_once __DIR__ . '/CardChecklist.php';
        }
        return \App\Prospeccao\CardChecklist::listByCard($card_id);
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
    /**
     * A coluna origem_id existe? (migration 127)
     *
     * Cacheado em static porque create() e update() perguntam a cada chamada, e
     * numa importação de leads isso seria um SELECT extra por card.
     */
    private static function _temColunaOrigem(): bool
    {
        static $tem = null;
        if ($tem === null) {
            try {
                Database::getConnection()->query('SELECT origem_id FROM cards LIMIT 0');
                $tem = true;
            } catch (\Throwable $e) {
                $tem = false;
            }
        }
        return $tem;
    }

    /** O canal pertence a esta conta? O catálogo clientes_origens é por conta. */
    private static function _origemDaConta(int $origemId, int $accountId): bool
    {
        if ($origemId <= 0 || $accountId <= 0) return false;
        try {
            $st = Database::getConnection()->prepare(
                'SELECT 1 FROM clientes_origens WHERE id = ? AND account_id = ? LIMIT 1'
            );
            $st->execute([$origemId, $accountId]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A coluna data_nascimento existe? (migration 128) */
    private static function _temColunaNascimento(): bool
    {
        static $tem = null;
        if ($tem === null) {
            try {
                Database::getConnection()->query('SELECT data_nascimento FROM cards LIMIT 0');
                $tem = true;
            } catch (\Throwable $e) {
                $tem = false;
            }
        }
        return $tem;
    }

    /** "" e "0" viram NULL: canal não escolhido é ausência, não canal zero. */
    private static function _intOrNull($v): ?int
    {
        if ($v === null || $v === '' || (int)$v <= 0) return null;
        return (int)$v;
    }

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
