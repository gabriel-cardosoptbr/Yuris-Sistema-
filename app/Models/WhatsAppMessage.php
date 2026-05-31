<?php
require_once __DIR__ . '/Database.php';

use App\Models\Database;

class WhatsAppMessage
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Salvar ou atualizar mensagem pelo wamid.
     * Retorna o ID inserido/existente.
     */
    public function save(array $data): int
    {
        // Normaliza wamid vazio para NULL. Com o UNIQUE (instance_id, wamid),
        // duas strings vazias colidiriam (NULLs não colidem); manter null evita erro.
        if (isset($data['wamid']) && $data['wamid'] === '') {
            $data['wamid'] = null;
        }
        // Se tiver wamid, verificar duplicata
        if (!empty($data['wamid'])) {
            $stmt = $this->db->prepare(
                'SELECT id FROM whatsapp_messages WHERE instance_id = ? AND wamid = ? LIMIT 1'
            );
            $stmt->execute([$data['instance_id'], $data['wamid']]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                $sets   = [];
                $params = [];
                if (!empty($data['status'])) {
                    $sets[]   = 'status = ?';
                    $params[] = $data['status'];
                }
                // Atualiza contact_name se o armazenado é LID (≥14 dígitos) e agora temos um valor real
                $newName = (string)($data['contact_name'] ?? '');
                if ($newName !== '' && !preg_match('/^\d{14,}$/', $newName)) {
                    $sets[]   = "contact_name = IF(contact_name IS NULL OR contact_name REGEXP '^[0-9]{10,}$', ?, contact_name)";
                    $params[] = $newName;
                }
                // Atualiza raw_payload para mensagens de mídia que foram salvas sem payload
                if (!empty($data['raw_payload'])) {
                    $sets[]   = 'raw_payload = IF(raw_payload IS NULL, ?, raw_payload)';
                    $params[] = $data['raw_payload'];
                }
                // Atualiza media_base64. Se for o BINÁRIO COMPLETO (media_is_full=1),
                // sobrescreve um thumbnail antigo; se for só thumbnail, preenche quando
                // estiver vazio (nunca rebaixa um binário completo já salvo).
                if (!empty($data['media_base64'])) {
                    $isFull   = !empty($data['media_is_full']) ? 1 : 0;
                    $sets[]   = 'media_base64 = IF(media_base64 IS NULL OR ? = 1, ?, media_base64)';
                    $params[] = $isFull;
                    $params[] = $data['media_base64'];
                }
                // Atualiza mimetype se não tiver
                if (!empty($data['media_mimetype'])) {
                    $sets[]   = 'media_mimetype = IF(media_mimetype IS NULL, ?, media_mimetype)';
                    $params[] = $data['media_mimetype'];
                }
                // Preenche participant_jid (autor real em grupos) quando a linha foi
                // criada antes pelo polling sem esse dado. Só quando está vazio — assim
                // o webhook conserta o que o discover/refresh inseriram sem o autor.
                if (!empty($data['participant_jid'])) {
                    $sets[]   = 'participant_jid = IF(participant_jid IS NULL OR participant_jid = "", ?, participant_jid)';
                    $params[] = $data['participant_jid'];
                }
                // Sempre corrige o created_at com o timestamp real da Evolution API
                if (!empty($data['created_at'])) {
                    $sets[]   = 'created_at = ?';
                    $params[] = $data['created_at'];
                }
                if ($sets) {
                    $params[] = $existing;
                    $this->db->prepare(
                        'UPDATE whatsapp_messages SET ' . implode(', ', $sets) . ' WHERE id = ?'
                    )->execute($params);
                }
                return (int)$existing;
            }
        }

        // P0 LGPD (1.8) — resolve account_id da instância pra escrever junto
        // (antes ficava NULL no INSERT — JOIN tardio era frágil; agora gravamos
        // direto pra defesa em profundidade e consultas mais simples)
        $accountIdResolved = null;
        if (!empty($data['account_id']) && (int)$data['account_id'] > 0) {
            $accountIdResolved = (int)$data['account_id'];
        } else {
            $resolveStmt = $this->db->prepare('SELECT account_id FROM whatsapp_instances WHERE id = ? LIMIT 1');
            $resolveStmt->execute([(int)$data['instance_id']]);
            $accId = $resolveStmt->fetchColumn();
            $accountIdResolved = $accId !== false && $accId !== null ? (int)$accId : null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_messages
             (account_id, instance_id, wamid, remote_jid, participant_jid, contact_name, phone,
              message_type, message_content, caption, media_url,
              media_mimetype, media_filename, media_base64,
              direction, status, quoted_wamid, raw_payload, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insertParams = [
            $accountIdResolved,
            $data['instance_id'],
            $data['wamid']          ?? null,
            $data['remote_jid'],
            $data['participant_jid'] ?? null,
            $data['contact_name']   ?? null,
            $data['phone']          ?? null,
            $data['message_type']   ?? 'text',
            $data['message_content'] ?? null,
            $data['caption']        ?? null,
            $data['media_url']      ?? null,
            $data['media_mimetype'] ?? null,
            $data['media_filename'] ?? null,
            $data['media_base64']   ?? null,
            $data['direction']      ?? 'inbound',
            $data['status']         ?? 'sent',
            $data['quoted_wamid']   ?? null,
            $data['raw_payload']    ?? null,
            $data['created_at']     ?? date('Y-m-d H:i:s'),
        ];
        // Idempotência (contingência): com o UNIQUE (instance_id, wamid), uma corrida
        // entre sync e webhook podia tentar inserir a MESMA mensagem 2x. Em vez de
        // duplicar (ou estourar 500), recupera o id já existente e segue.
        try {
            $stmt->execute($insertParams);
        } catch (\PDOException $e) {
            if (!empty($data['wamid'])) {
                $dup = $this->db->prepare('SELECT id FROM whatsapp_messages WHERE instance_id = ? AND wamid = ? LIMIT 1');
                $dup->execute([$data['instance_id'], $data['wamid']]);
                $dupId = $dup->fetchColumn();
                if ($dupId) return (int)$dupId;
            }
            throw $e;
        }

        $newId = (int)$this->db->lastInsertId();

        // Atualiza resumo do chat
        $this->upsertChat($data);

        return $newId;
    }

    /** Buscar mensagens paginadas para um chat. */
    public function findByJid(int $instanceId, string $remoteJid, int $limit = 50, int $beforeId = 0, ?string $beforeAt = null): array
    {
        // Resolve o nome do remetente em 4 camadas (prioridade decrescente):
        //   1) group_members.push_name pelo participant_jid (autor real em grupos)
        //   2) contacts.push_name pelo participant_jid (fallback grupos)
        //   3) contact_name original do registro (filtra JIDs raw 14+ dígitos)
        //   4) contacts.push_name por matching de phone (último fallback 1:1)
        // Tambem retorna participant_jid separado pro JS resolver nome se quiser.
        // P2 (wire-up reply/reactions/delete 2026-05-25):
        //   - LEFT JOIN com whatsapp_messages q (alias) pra preview da quoted
        //   - m.is_deleted retornado pra render "Mensagem apagada"
        $sql = 'SELECT m.id, m.wamid, m.remote_jid, m.participant_jid,
                       COALESCE(
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN gm.push_name END,
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN cp.push_name END,
                           CASE WHEN m.contact_name REGEXP \'^[0-9]{14,}$\' THEN NULL ELSE m.contact_name END,
                           c.push_name
                       ) AS contact_name,
                       m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                       m.media_filename, m.direction, m.status, m.quoted_wamid, m.is_deleted, m.created_at,
                       q.message_content AS quoted_content,
                       q.caption         AS quoted_caption,
                       q.message_type    AS quoted_type,
                       q.direction       AS quoted_direction,
                       q.contact_name    AS quoted_sender_raw,
                       qgm.push_name     AS quoted_sender_member,
                       qcp.push_name     AS quoted_sender_contact
                FROM whatsapp_messages m
                LEFT JOIN whatsapp_group_members gm
                       ON gm.instance_id = m.instance_id
                      AND gm.group_jid       COLLATE utf8mb4_unicode_ci = m.remote_jid
                      AND gm.participant_jid COLLATE utf8mb4_unicode_ci = m.participant_jid
                LEFT JOIN whatsapp_contacts cp
                       ON cp.instance_id = m.instance_id
                      AND cp.remote_jid  = m.participant_jid
                LEFT JOIN whatsapp_contacts c
                       ON c.instance_id = m.instance_id
                      AND m.contact_name REGEXP \'^[0-9]{12,}$\'
                      AND (c.remote_jid LIKE CONCAT(m.contact_name, \'%@lid\')
                           OR c.remote_jid LIKE CONCAT(m.contact_name, \'%\'))
                LEFT JOIN whatsapp_messages q
                       ON q.instance_id = m.instance_id
                      AND q.wamid       = m.quoted_wamid
                LEFT JOIN whatsapp_group_members qgm
                       ON qgm.instance_id = q.instance_id
                      AND qgm.group_jid       COLLATE utf8mb4_unicode_ci = q.remote_jid
                      AND qgm.participant_jid COLLATE utf8mb4_unicode_ci = q.participant_jid
                LEFT JOIN whatsapp_contacts qcp
                       ON qcp.instance_id = q.instance_id
                      AND qcp.remote_jid  = q.participant_jid
                WHERE m.instance_id = ? AND m.remote_jid = ?';
        $params = [$instanceId, $remoteJid];

        // Paginação por keyset CRONOLÓGICO (created_at, id). O created_at guarda o
        // messageTimestamp REAL do WhatsApp; ordenar por id misturava mensagens
        // antigas sincronizadas (id alto, mas data passada) no meio das novas.
        // Cursor composto (data + id de desempate) evita pular/repetir nas bordas.
        if ($beforeAt !== null && $beforeAt !== '') {
            $sql .= ' AND (m.created_at < ? OR (m.created_at = ? AND m.id < ?))';
            $params[] = $beforeAt;
            $params[] = $beforeAt;
            $params[] = $beforeId;
        } elseif ($beforeId > 0) {
            // Compat: cursor antigo só por id (caso o front não mande before_at)
            $sql .= ' AND m.id < ?';
            $params[] = $beforeId;
        }

        $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pós-processamento: agrega reactions e normaliza quoted_sender
        $rows = array_reverse($rows);
        $this->hydrateReactions($instanceId, $rows);
        $this->hydrateQuotedSender($rows);
        return $rows;
    }

    /**
     * Busca por texto dentro de uma conversa (LIKE em message_content + caption).
     * Retorna até $limit matches mais recentes, com os mesmos JOINs do findByJid
     * (resolução de nome do autor + quoted preview + reactions).
     */
    public function searchInChat(int $instanceId, string $remoteJid, string $search, int $limit = 50): array
    {
        $like = '%' . $search . '%';
        $sql = 'SELECT m.id, m.wamid, m.remote_jid, m.participant_jid,
                       COALESCE(
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN gm.push_name END,
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN cp.push_name END,
                           CASE WHEN m.contact_name REGEXP \'^[0-9]{14,}$\' THEN NULL ELSE m.contact_name END,
                           c.push_name
                       ) AS contact_name,
                       m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                       m.media_filename, m.direction, m.status, m.quoted_wamid, m.is_deleted, m.created_at,
                       q.message_content AS quoted_content,
                       q.caption         AS quoted_caption,
                       q.message_type    AS quoted_type,
                       q.direction       AS quoted_direction,
                       q.contact_name    AS quoted_sender_raw,
                       qgm.push_name     AS quoted_sender_member,
                       qcp.push_name     AS quoted_sender_contact
                FROM whatsapp_messages m
                LEFT JOIN whatsapp_group_members gm
                       ON gm.instance_id = m.instance_id
                      AND gm.group_jid       COLLATE utf8mb4_unicode_ci = m.remote_jid
                      AND gm.participant_jid COLLATE utf8mb4_unicode_ci = m.participant_jid
                LEFT JOIN whatsapp_contacts cp
                       ON cp.instance_id = m.instance_id
                      AND cp.remote_jid  = m.participant_jid
                LEFT JOIN whatsapp_contacts c
                       ON c.instance_id = m.instance_id
                      AND m.contact_name REGEXP \'^[0-9]{12,}$\'
                      AND (c.remote_jid LIKE CONCAT(m.contact_name, \'%@lid\')
                           OR c.remote_jid LIKE CONCAT(m.contact_name, \'%\'))
                LEFT JOIN whatsapp_messages q
                       ON q.instance_id = m.instance_id
                      AND q.wamid       = m.quoted_wamid
                LEFT JOIN whatsapp_group_members qgm
                       ON qgm.instance_id = q.instance_id
                      AND qgm.group_jid       COLLATE utf8mb4_unicode_ci = q.remote_jid
                      AND qgm.participant_jid COLLATE utf8mb4_unicode_ci = q.participant_jid
                LEFT JOIN whatsapp_contacts qcp
                       ON qcp.instance_id = q.instance_id
                      AND qcp.remote_jid  = q.participant_jid
                WHERE m.instance_id = ?
                  AND m.remote_jid  = ?
                  AND m.is_deleted  = 0
                  AND (m.message_content LIKE ? OR m.caption LIKE ?)
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT ' . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$instanceId, $remoteJid, $like, $like]);
        $rows = array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));
        $this->hydrateReactions($instanceId, $rows);
        $this->hydrateQuotedSender($rows);
        return $rows;
    }

    /** Buscar apenas novas mensagens após um ID.
     *  Mesma resolucao em 4 camadas do findByJid pra coerencia (grupos). */
    public function findAfter(int $instanceId, string $remoteJid, int $afterId, ?string $afterAt = null): array
    {
        // Keyset cronológico: traz o que é MAIS NOVO que o cursor (created_at, id).
        // Mensagem antiga sincronizada (data passada) NÃO entra aqui — ela aparece
        // no lugar cronológico certo ao reabrir/rolar, não "pulando" no rodapé.
        if ($afterAt !== null && $afterAt !== '') {
            $cursorCond   = 'AND (m.created_at > ? OR (m.created_at = ? AND m.id > ?))';
            $cursorParams = [$afterAt, $afterAt, $afterId];
        } else {
            $cursorCond   = 'AND m.id > ?';
            $cursorParams = [$afterId];
        }
        $stmt = $this->db->prepare(
            'SELECT m.id, m.wamid, m.remote_jid, m.participant_jid,
                    COALESCE(
                        CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN gm.push_name END,
                        CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN cp.push_name END,
                        CASE WHEN m.contact_name REGEXP \'^[0-9]{14,}$\' THEN NULL ELSE m.contact_name END,
                        c.push_name
                    ) AS contact_name,
                    m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                    m.media_filename, m.direction, m.status, m.quoted_wamid, m.is_deleted, m.created_at,
                    q.message_content AS quoted_content,
                    q.caption         AS quoted_caption,
                    q.message_type    AS quoted_type,
                    q.direction       AS quoted_direction,
                    q.contact_name    AS quoted_sender_raw,
                    qgm.push_name     AS quoted_sender_member,
                    qcp.push_name     AS quoted_sender_contact
             FROM whatsapp_messages m
             LEFT JOIN whatsapp_group_members gm
                    ON gm.instance_id     = m.instance_id
                   AND gm.group_jid       COLLATE utf8mb4_unicode_ci = m.remote_jid
                   AND gm.participant_jid COLLATE utf8mb4_unicode_ci = m.participant_jid
             LEFT JOIN whatsapp_contacts cp
                    ON cp.instance_id = m.instance_id
                   AND cp.remote_jid  = m.participant_jid
             LEFT JOIN whatsapp_contacts c
                    ON c.instance_id = m.instance_id
                   AND m.contact_name REGEXP \'^[0-9]{12,}$\'
                   AND (c.remote_jid LIKE CONCAT(m.contact_name, \'%@lid\')
                        OR c.remote_jid LIKE CONCAT(m.contact_name, \'%\'))
             LEFT JOIN whatsapp_messages q
                    ON q.instance_id = m.instance_id
                   AND q.wamid       = m.quoted_wamid
             LEFT JOIN whatsapp_group_members qgm
                    ON qgm.instance_id = q.instance_id
                   AND qgm.group_jid       COLLATE utf8mb4_unicode_ci = q.remote_jid
                   AND qgm.participant_jid COLLATE utf8mb4_unicode_ci = q.participant_jid
             LEFT JOIN whatsapp_contacts qcp
                    ON qcp.instance_id = q.instance_id
                   AND qcp.remote_jid  = q.participant_jid
             WHERE m.instance_id = ? AND m.remote_jid = ? ' . $cursorCond . '
             ORDER BY m.created_at ASC, m.id ASC
             LIMIT 100'
        );
        $stmt->execute(array_merge([$instanceId, $remoteJid], $cursorParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->hydrateReactions($instanceId, $rows);
        $this->hydrateQuotedSender($rows);
        return $rows;
    }

    // ─── Helpers de hidratação (preview de quoted + reactions agregadas) ────────
    // Roda em PHP pra evitar GROUP BY + JOIN complicado em SQL (2 grupos diferentes:
    // mensagem real e reactions). Custo é uma query extra com WHERE IN (...) — barato.

    /**
     * Resolve quoted_sender pelo fallback: group_members → contacts → raw (filtra LID 14+).
     * Anexa $row['quoted_sender'] sem campos auxiliares.
     */
    private function hydrateQuotedSender(array &$rows): void
    {
        foreach ($rows as &$r) {
            if (empty($r['quoted_wamid'])) {
                $r['quoted_sender'] = null;
                continue;
            }
            $sender = $r['quoted_sender_member']
                   ?: $r['quoted_sender_contact']
                   ?: (preg_match('/^\d{14,}$/', (string)($r['quoted_sender_raw'] ?? '')) ? null : ($r['quoted_sender_raw'] ?? null));
            // Pra mensagens próprias citadas, usa rótulo amigável
            if (($r['quoted_direction'] ?? '') === 'outbound' && !$sender) {
                $sender = 'Você';
            }
            $r['quoted_sender'] = $sender;
            // Limpa campos auxiliares
            unset($r['quoted_sender_raw'], $r['quoted_sender_member'], $r['quoted_sender_contact']);
        }
    }

    /**
     * Busca todas as reactions das mensagens listadas em uma query só e anexa
     * agregadas por emoji em $row['reactions'] = [{emoji, count, mine}].
     */
    private function hydrateReactions(int $instanceId, array &$rows): void
    {
        if (empty($rows)) return;
        // Sempre garante o campo, mesmo sem wamid — evita undefined no frontend
        foreach ($rows as &$r0) { $r0['reactions'] = []; }
        unset($r0);

        $wamids = [];
        foreach ($rows as $r) {
            if (!empty($r['wamid'])) $wamids[] = $r['wamid'];
        }
        if (empty($wamids)) return;

        $placeholders = implode(',', array_fill(0, count($wamids), '?'));
        $sql = "SELECT target_wamid, emoji, reactor_jid, is_from_me
                FROM whatsapp_reactions
                WHERE instance_id = ?
                  AND target_wamid IN ($placeholders)
                  AND emoji <> ''";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$instanceId], $wamids));
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agrupa por target_wamid → emoji → {count, mine}
        $byWamid = [];
        foreach ($raw as $r) {
            $tw    = $r['target_wamid'];
            $emoji = $r['emoji'];
            if (!isset($byWamid[$tw][$emoji])) {
                $byWamid[$tw][$emoji] = ['emoji' => $emoji, 'count' => 0, 'mine' => false];
            }
            $byWamid[$tw][$emoji]['count']++;
            if ($r['is_from_me']) $byWamid[$tw][$emoji]['mine'] = true;
        }

        // Anexa em cada row (mantém ordem dos emojis pela primeira aparição)
        foreach ($rows as &$row) {
            $row['reactions'] = isset($byWamid[$row['wamid']]) ? array_values($byWamid[$row['wamid']]) : [];
        }
    }

    /**
     * UPSERT de reaction: unique por (instance_id, target_wamid, reactor_jid).
     * Se $data['emoji'] === '' → remove (DELETE).
     */
    public function upsertReaction(array $data): void
    {
        $instanceId  = (int)$data['instance_id'];
        $targetWamid = (string)$data['target_wamid'];
        $reactorJid  = (string)$data['reactor_jid'];
        $emoji       = (string)$data['emoji'];

        // Emoji vazio = remover reaction existente
        if ($emoji === '') {
            $this->db->prepare(
                'DELETE FROM whatsapp_reactions
                 WHERE instance_id = ? AND target_wamid = ? AND reactor_jid = ?'
            )->execute([$instanceId, $targetWamid, $reactorJid]);
            return;
        }

        // INSERT ON DUPLICATE KEY UPDATE — sobrescreve emoji anterior do mesmo reactor
        $this->db->prepare(
            'INSERT INTO whatsapp_reactions
             (account_id, instance_id, target_wamid, reactor_jid, reactor_name, emoji, is_from_me)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               emoji        = VALUES(emoji),
               reactor_name = VALUES(reactor_name),
               is_from_me   = VALUES(is_from_me),
               created_at   = CURRENT_TIMESTAMP'
        )->execute([
            (int)($data['account_id'] ?? 0),
            $instanceId,
            $targetWamid,
            $reactorJid,
            $data['reactor_name'] ?? null,
            $emoji,
            !empty($data['is_from_me']) ? 1 : 0,
        ]);
    }

    /**
     * Soft delete: marca is_deleted=1 numa mensagem.
     * Filtra por account_ids pra impedir cross-tenant.
     * Retorna true se afetou alguma linha.
     */
    public function markDeleted(int $messageId, array $accountIds): bool
    {
        $accountIds = array_filter(array_map('intval', $accountIds));
        if (empty($accountIds)) return false;

        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $this->db->prepare(
            "UPDATE whatsapp_messages
                SET is_deleted = 1
              WHERE id = ?
                AND account_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$messageId], $accountIds));
        return $stmt->rowCount() > 0;
    }

    /** Lista chats com última mensagem por JID. */
    /**
     * Lista conversas do WhatsApp com filtros opcionais.
     *
     * @param int         $instanceId  ID da instância WhatsApp
     * @param string      $search      Busca por nome/telefone
     * @param int|null    $teamId      Filtra pelo setor (NULL = todos; 0 = sem setor)
     */
    public function getChatList(int $instanceId, string $search = '', ?int $teamId = null, ?int $userId = null, bool $archived = false): array
    {
        // JOIN com teams para trazer nome e cor do setor junto com cada chat
        // archived=true → lista apenas arquivadas; false (default) → só não-arquivadas
        $sql = 'SELECT c.*,
                       t.nome AS team_nome,
                       t.cor  AS team_cor
                FROM whatsapp_chats c
                LEFT JOIN teams t ON t.id = c.team_id AND t.deleted_at IS NULL
                WHERE c.instance_id = ? AND c.is_archived = ' . ($archived ? '1' : '0');
        $params = [$instanceId];

        // Filtro de busca por nome ou telefone
        if ($search !== '') {
            $sql .= ' AND (c.contact_name LIKE ? OR c.phone LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Filtro por setor:
        //   team_id = N  → somente conversas desse setor
        //   team_id = 0  → somente conversas SEM setor
        //   null         → todos (sem filtro)
        if ($teamId !== null) {
            if ($teamId === 0) {
                $sql .= ' AND c.team_id IS NULL';
            } else {
                $sql .= ' AND c.team_id = ?';
                $params[] = $teamId;
            }
        }

        // Filtro por responsável (usuário vinculado):
        //   user_id = N  → somente conversas atribuídas a esse user
        //   user_id = 0  → somente conversas SEM responsável
        //   null         → todos (sem filtro)
        if ($userId !== null) {
            if ($userId === 0) {
                $sql .= ' AND c.linked_user_id IS NULL';
            } else {
                $sql .= ' AND c.linked_user_id = ?';
                $params[] = $userId;
            }
        }

        $sql .= ' ORDER BY c.is_pinned DESC, c.last_message_at DESC LIMIT 200';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atribui ou remove o setor (team) de uma conversa.
     * $teamId = null remove o setor.
     */
    public function setTeam(int $instanceId, string $remoteJid, ?int $teamId): bool
    {
        return $this->db->prepare(
            'UPDATE whatsapp_chats SET team_id = ?
             WHERE instance_id = ? AND remote_jid = ?'
        )->execute([$teamId, $instanceId, $remoteJid]);
    }

    /** Zera contador de não lidas de um chat. */
    public function markChatRead(int $instanceId, string $remoteJid): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_chats SET unread_count = 0
             WHERE instance_id = ? AND remote_jid = ?'
        );
        return $stmt->execute([$instanceId, $remoteJid]);
    }

    /** Marca chat como nao lido (volta unread_count = 1 se estava 0). */
    public function markChatUnread(int $instanceId, string $remoteJid): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_chats SET unread_count = GREATEST(unread_count, 1)
             WHERE instance_id = ? AND remote_jid = ?'
        );
        return $stmt->execute([$instanceId, $remoteJid]);
    }

    /** Alterna arquivado/desarquivado. */
    public function toggleArchive(int $instanceId, string $remoteJid): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_chats SET is_archived = IF(is_archived = 1, 0, 1)
             WHERE instance_id = ? AND remote_jid = ?'
        );
        return $stmt->execute([$instanceId, $remoteJid]);
    }

    /** Criar/atualizar registro de chat. */
    public function upsertChat(array $data): void
    {
        $isInbound = ($data['direction'] ?? 'inbound') === 'inbound';
        $content   = $data['message_content'] ?? ($data['caption'] ?? '');
        if (!$content) {
            $content = match ($data['message_type'] ?? 'text') {
                'image'    => '[Imagem]',
                'audio'    => '[Áudio]',
                'video'    => '[Vídeo]',
                'document' => '[Documento]',
                'sticker'  => '[Sticker]',
                default    => '...',
            };
        }

        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_chats
             (instance_id, remote_jid, contact_name, phone,
              last_message_content, last_message_type, last_message_at,
              last_message_from_me, unread_count, is_group)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               contact_name         = IF(is_group = 0 AND VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) != "", VALUES(contact_name), contact_name),
               phone                = IF(VALUES(phone) IS NOT NULL AND VALUES(phone) != "", VALUES(phone), phone),
               last_message_content = VALUES(last_message_content),
               last_message_type    = VALUES(last_message_type),
               last_message_at      = VALUES(last_message_at),
               last_message_from_me = VALUES(last_message_from_me),
               unread_count         = IF(VALUES(last_message_from_me) = 0, unread_count + 1, unread_count)'
        );

        $isGroup = str_ends_with($data['remote_jid'] ?? '', '@g.us');

        $stmt->execute([
            $data['instance_id'],
            $data['remote_jid'],
            $data['contact_name'] ?? null,
            $data['phone']        ?? null,
            $content,
            $data['message_type'] ?? 'text',
            $data['created_at']   ?? date('Y-m-d H:i:s'),
            $isInbound ? 0 : 1,
            $isInbound ? 1 : 0,
            $isGroup ? 1 : 0,
        ]);
    }

    /** Contar mensagens novas (para badge). */
    public function getTotalUnread(int $instanceId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(unread_count), 0)
             FROM whatsapp_chats
             WHERE instance_id = ? AND is_archived = 0'
        );
        $stmt->execute([$instanceId]);
        return (int)$stmt->fetchColumn();
    }

    /** Atualiza status de mensagem outbound. */
    public function updateStatus(string $wamid, string $status): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_messages SET status = ? WHERE wamid = ?'
        );
        return $stmt->execute([$status, $wamid]);
    }

    /** Pinnar/desafixar chat. */
    public function togglePin(int $instanceId, string $remoteJid): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_chats SET is_pinned = IF(is_pinned=1,0,1)
             WHERE instance_id = ? AND remote_jid = ?'
        );
        return $stmt->execute([$instanceId, $remoteJid]);
    }

    /**
     * Exclui uma conversa do banco local: remove todas as mensagens
     * e o registro do chat. A conversa retorna automaticamente se
     * novas mensagens chegarem via webhook.
     */
    public function deleteChat(int $instanceId, string $remoteJid): bool
    {
        // 1. Remove mensagens
        $this->db->prepare(
            'DELETE FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ?'
        )->execute([$instanceId, $remoteJid]);

        // 2. Remove o chat
        return $this->db->prepare(
            'DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?'
        )->execute([$instanceId, $remoteJid]);
    }

    /** Vincular chat a cliente/processo/usuário. */
    public function linkChat(int $instanceId, string $remoteJid, array $links): bool
    {
        $fields = [];
        $values = [];
        foreach (['linked_card_id', 'linked_user_id'] as $col) {
            if (array_key_exists($col, $links)) {
                $fields[] = "$col = ?";
                $values[] = $links[$col] ?: null;
            }
        }

        // Suporte a team_id: atribui setor junto ao vínculo do card/usuário
        if (array_key_exists('team_id', $links)) {
            $fields[] = 'team_id = ?';
            $values[] = $links['team_id'] ? (int)$links['team_id'] : null;
        }

        // Quando um card é vinculado, propaga o contato_id do card para o chat
        $contatoId = null;
        if (!empty($links['linked_card_id'])) {
            $cardRow = $this->db->prepare('SELECT contato_id, cliente_nome FROM cards WHERE id = ? LIMIT 1');
            $cardRow->execute([$links['linked_card_id']]);
            $cardData  = $cardRow->fetch(\PDO::FETCH_ASSOC) ?: [];
            $contatoId = $cardData['contato_id'] ?? null;

            // Se card ainda não tem contato_id, tenta criar via JID (LID) ou fica null
            if (!$contatoId) {
                require_once __DIR__ . '/Contato.php';
                $isLid = str_ends_with($remoteJid, '@lid');
                if ($isLid) {
                    $contatoId = \App\Models\Contato::findOrCreateByJid(
                        $cardData['cliente_nome'] ?? 'Contato',
                        $remoteJid
                    );
                    // Atualiza card com o novo contato_id
                    if ($contatoId) {
                        $this->db->prepare('UPDATE cards SET contato_id = ? WHERE id = ?')
                            ->execute([$contatoId, $links['linked_card_id']]);
                    }
                }
            }

            if ($contatoId) {
                $fields[] = 'contato_id = ?';
                $values[] = $contatoId;
            }
        }

        if ($fields) {
            $values[] = $instanceId;
            $values[] = $remoteJid;
            $this->db->prepare(
                'UPDATE whatsapp_chats SET ' . implode(', ', $fields) .
                ' WHERE instance_id = ? AND remote_jid = ?'
            )->execute($values);
        }

        // Grava vínculo permanente chat → contato_vinculos
        if ($contatoId) {
            // Busca o id do registro em whatsapp_chats para usar como referencia_id
            $chatRow = $this->db->prepare(
                'SELECT id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1'
            );
            $chatRow->execute([$instanceId, $remoteJid]);
            $chatId = $chatRow->fetchColumn();

            if ($chatId) {
                $this->db->prepare(
                    'INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
                     VALUES (?, \'chat\', ?, \'manual\')'
                )->execute([$contatoId, $chatId]);
            }

            // Grava ou atualiza vínculo card → contato_vinculos
            $this->db->prepare(
                'INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
                 VALUES (?, \'card\', ?, \'manual\')'
            )->execute([$contatoId, $links['linked_card_id']]);
        }

        // Processos: substitui lista completa na tabela de junção
        if (array_key_exists('processo_ids', $links)) {
            $this->setLinkedProcessos($instanceId, $remoteJid, $links['processo_ids'] ?? []);

            // Grava vínculos de processo em contato_vinculos
            if ($contatoId) {
                $insVinc = $this->db->prepare(
                    'INSERT IGNORE INTO contato_vinculos (contato_id, tipo_vinculo, referencia_id, origem)
                     VALUES (?, \'processo\', ?, \'manual\')'
                );
                foreach (($links['processo_ids'] ?? []) as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0) $insVinc->execute([$contatoId, $pid]);
                }
            }
        }

        return true;
    }

    /** Retorna IDs dos processos vinculados a um chat. */
    public function getLinkedProcessos(int $instanceId, string $remoteJid): array
    {
        $stmt = $this->db->prepare(
            'SELECT processo_id FROM whatsapp_chat_processos
             WHERE instance_id = ? AND remote_jid = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$instanceId, $remoteJid]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'processo_id');
    }

    /** Substitui todos os processos vinculados a um chat. */
    public function setLinkedProcessos(int $instanceId, string $remoteJid, array $processoIds): void
    {
        // Remove os antigos
        $this->db->prepare(
            'DELETE FROM whatsapp_chat_processos WHERE instance_id = ? AND remote_jid = ?'
        )->execute([$instanceId, $remoteJid]);

        // Insere os novos
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO whatsapp_chat_processos (instance_id, remote_jid, processo_id)
             VALUES (?, ?, ?)'
        );
        foreach ($processoIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) $stmt->execute([$instanceId, $remoteJid, $pid]);
        }
    }
}
