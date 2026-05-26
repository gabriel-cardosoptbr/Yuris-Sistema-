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
                    $sets[]   = "contact_name = IF(contact_name IS NULL OR contact_name REGEXP '^[0-9]{14,}$', ?, contact_name)";
                    $params[] = $newName;
                }
                // Atualiza raw_payload para mensagens de mídia que foram salvas sem payload
                if (!empty($data['raw_payload'])) {
                    $sets[]   = 'raw_payload = IF(raw_payload IS NULL, ?, raw_payload)';
                    $params[] = $data['raw_payload'];
                }
                // Atualiza media_base64 (thumbnail) se ainda não tiver
                if (!empty($data['media_base64'])) {
                    $sets[]   = 'media_base64 = IF(media_base64 IS NULL, ?, media_base64)';
                    $params[] = $data['media_base64'];
                }
                // Atualiza mimetype se não tiver
                if (!empty($data['media_mimetype'])) {
                    $sets[]   = 'media_mimetype = IF(media_mimetype IS NULL, ?, media_mimetype)';
                    $params[] = $data['media_mimetype'];
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
             (account_id, instance_id, wamid, remote_jid, contact_name, phone,
              message_type, message_content, caption, media_url,
              media_mimetype, media_filename, media_base64,
              direction, status, quoted_wamid, raw_payload, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $accountIdResolved,
            $data['instance_id'],
            $data['wamid']          ?? null,
            $data['remote_jid'],
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
        ]);

        $newId = (int)$this->db->lastInsertId();

        // Atualiza resumo do chat
        $this->upsertChat($data);

        return $newId;
    }

    /** Buscar mensagens paginadas para um chat. */
    public function findByJid(int $instanceId, string $remoteJid, int $limit = 50, int $beforeId = 0): array
    {
        $sql = 'SELECT m.id, m.wamid, m.remote_jid,
                       COALESCE(
                           CASE WHEN m.contact_name REGEXP \'^[0-9]{14,}$\' THEN NULL ELSE m.contact_name END,
                           c.push_name
                       ) AS contact_name,
                       m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                       m.media_filename, m.direction, m.status, m.quoted_wamid, m.created_at
                FROM whatsapp_messages m
                LEFT JOIN whatsapp_contacts c
                       ON c.instance_id = m.instance_id
                      AND m.contact_name REGEXP \'^[0-9]{12,}$\'
                      AND (c.remote_jid LIKE CONCAT(m.contact_name, \'%@lid\')
                           OR c.remote_jid LIKE CONCAT(m.contact_name, \'%\'))
                WHERE m.instance_id = ? AND m.remote_jid = ?';
        $params = [$instanceId, $remoteJid];

        if ($beforeId > 0) {
            $sql .= ' AND m.id < ?';
            $params[] = $beforeId;
        }

        $sql .= ' ORDER BY m.id DESC LIMIT ' . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_reverse($rows);
    }

    /** Buscar apenas novas mensagens após um ID. */
    public function findAfter(int $instanceId, string $remoteJid, int $afterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.wamid, m.remote_jid,
                    COALESCE(
                        CASE WHEN m.contact_name REGEXP \'^[0-9]{14,}$\' THEN NULL ELSE m.contact_name END,
                        c.push_name
                    ) AS contact_name,
                    m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                    m.media_filename, m.direction, m.status, m.quoted_wamid, m.created_at
             FROM whatsapp_messages m
             LEFT JOIN whatsapp_contacts c
                    ON c.instance_id = m.instance_id
                   AND m.contact_name REGEXP \'^[0-9]{12,}$\'
                   AND (c.remote_jid LIKE CONCAT(m.contact_name, \'%@lid\')
                        OR c.remote_jid LIKE CONCAT(m.contact_name, \'%\'))
             WHERE m.instance_id = ? AND m.remote_jid = ? AND m.id > ?
             ORDER BY m.id ASC
             LIMIT 100'
        );
        $stmt->execute([$instanceId, $remoteJid, $afterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lista chats com última mensagem por JID. */
    /**
     * Lista conversas do WhatsApp com filtros opcionais.
     *
     * @param int         $instanceId  ID da instância WhatsApp
     * @param string      $search      Busca por nome/telefone
     * @param int|null    $teamId      Filtra pelo setor (NULL = todos; 0 = sem setor)
     */
    public function getChatList(int $instanceId, string $search = '', ?int $teamId = null, ?int $userId = null): array
    {
        // JOIN com teams para trazer nome e cor do setor junto com cada chat
        $sql = 'SELECT c.*,
                       t.nome AS team_nome,
                       t.cor  AS team_cor
                FROM whatsapp_chats c
                LEFT JOIN teams t ON t.id = c.team_id AND t.deleted_at IS NULL
                WHERE c.instance_id = ? AND c.is_archived = 0';
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
