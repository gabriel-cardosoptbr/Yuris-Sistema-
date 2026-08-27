<?php
require_once __DIR__ . '/../Core/Database.php';

use App\Core\Database;

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
              direction, status, quoted_wamid, quoted_sender_name, quoted_text, raw_payload, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
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
            $data['quoted_sender_name'] ?? null,
            $data['quoted_text']        ?? null,
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

        // Atualiza resumo do chat (propaga o account_id resolvido pra nao gravar NULL)
        $data['account_id'] = $accountIdResolved;
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
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL AND gm.push_name REGEXP \'[A-Za-z]\' THEN gm.push_name END,
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL AND cp.push_name REGEXP \'[A-Za-z]\' THEN cp.push_name END,
                           CASE WHEN m.contact_name REGEXP \'^[+0-9 ()-]{8,}$\' THEN NULL ELSE m.contact_name END,
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN gm.push_name END,
                           c.push_name
                       ) AS contact_name,
                       m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                       m.media_filename, m.direction, m.status, m.quoted_wamid, m.is_deleted, m.created_at,
                       m.quoted_sender_name, m.quoted_text,
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
                      AND gm.group_jid       = m.remote_jid
                      AND gm.participant_jid = m.participant_jid
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
                      AND qgm.group_jid       = q.remote_jid
                      AND qgm.participant_jid = q.participant_jid
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
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL AND gm.push_name REGEXP \'[A-Za-z]\' THEN gm.push_name END,
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL AND cp.push_name REGEXP \'[A-Za-z]\' THEN cp.push_name END,
                           CASE WHEN m.contact_name REGEXP \'^[+0-9 ()-]{8,}$\' THEN NULL ELSE m.contact_name END,
                           CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN gm.push_name END,
                           c.push_name
                       ) AS contact_name,
                       m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                       m.media_filename, m.direction, m.status, m.quoted_wamid, m.is_deleted, m.created_at,
                       m.quoted_sender_name, m.quoted_text,
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
                      AND gm.group_jid       = m.remote_jid
                      AND gm.participant_jid = m.participant_jid
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
                      AND qgm.group_jid       = q.remote_jid
                      AND qgm.participant_jid = q.participant_jid
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
                        CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL AND gm.push_name REGEXP \'[A-Za-z]\' THEN gm.push_name END,
                        CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL AND cp.push_name REGEXP \'[A-Za-z]\' THEN cp.push_name END,
                        CASE WHEN m.contact_name REGEXP \'^[+0-9 ()-]{8,}$\' THEN NULL ELSE m.contact_name END,
                        CASE WHEN m.direction = \'inbound\' AND m.participant_jid IS NOT NULL THEN gm.push_name END,
                        c.push_name
                    ) AS contact_name,
                    m.message_type, m.message_content, m.caption, m.media_url, m.media_mimetype,
                    m.media_filename, m.direction, m.status, m.quoted_wamid, m.is_deleted, m.created_at,
                    m.quoted_sender_name, m.quoted_text,
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
                   AND gm.group_jid       = m.remote_jid
                   AND gm.participant_jid = m.participant_jid
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
                   AND qgm.group_jid       = q.remote_jid
                   AND qgm.participant_jid = q.participant_jid
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
        // 1a passada: prioriza um candidato COM LETRAS (nome real). Telefone/LID
        // NAO viram "nome" — ficam só como fallback e marcam a linha pra
        // enriquecimento (buscar o nome real do autor citado nas mensagens).
        // BUG (2026-05-31): o fallback antigo só descartava LID puro de 14+ digitos,
        // entao um telefone normal (13 dig / formatado) virava o "nome" do autor
        // citado em grupos. Ex.: citar msg do "Marcelo" mostrava "+55 (11) ...".
        $hasLetters = static fn($s) => $s !== null && preg_match('/[A-Za-z]/', (string)$s) === 1;
        $needWamids = [];
        foreach ($rows as &$r) {
            if (empty($r['quoted_wamid'])) {
                $r['quoted_sender'] = null;
                $this->cleanQuotedAux($r);
                continue;
            }
            $letters = null;
            // Inclui o snapshot (quoted_sender_name vindo do contextInfo) na busca
            // por um nome COM LETRAS — cobre citação de msg que não está no banco.
            foreach ([$r['quoted_sender_member'] ?? null, $r['quoted_sender_contact'] ?? null, $r['quoted_sender_raw'] ?? null, $r['quoted_sender_name'] ?? null] as $c) {
                if ($hasLetters($c)) { $letters = $c; break; }
            }
            if ($letters !== null) {
                $r['quoted_sender'] = $letters;
                $needWamids[(string)$r['quoted_wamid']] = true; // ainda tenta achar nome real
            } else {
                // Telefone de fallback (descarta LID puro 14+ digitos, que nao e exibivel)
                $phone = $r['quoted_sender_member'] ?: ($r['quoted_sender_contact'] ?: ($r['quoted_sender_raw'] ?: ($r['quoted_sender_name'] ?: null)));
                if ($phone !== null && preg_match('/^\d{14,}$/', (string)$phone)) $phone = null;
                $r['quoted_sender'] = $phone; // por enquanto o telefone (ou null)
                $needWamids[(string)$r['quoted_wamid']] = true;
            }
            // Se a mensagem original NÃO está no banco (sem quoted_content), usa o
            // texto do snapshot embutido (quoted_text) pra preview não ficar vazio.
            if (empty($r['quoted_content']) && empty($r['quoted_caption']) && !empty($r['quoted_text'])) {
                $r['quoted_content'] = $r['quoted_text'];
            }
            // Mensagem propria citada sem nome resolvido → rotulo amigavel
            if (($r['quoted_direction'] ?? '') === 'outbound' && ($r['quoted_sender'] === null || $r['quoted_sender'] === '')) {
                $r['quoted_sender'] = 'Você';
                unset($needWamids[(string)$r['quoted_wamid']]);
            }
        }
        unset($r);

        // 2a passada: enriquece com o NOME REAL do autor citado. O push_name de
        // group_members costuma ser só o telefone; aqui buscamos um contact_name
        // COM LETRAS nas mensagens daquele participant_jid (mesma logica do
        // getGroupMembers). 1 query batelada por quoted_wamid — barata e indexada
        // (idx_wamid + idx_participant).
        if ($needWamids) {
            $wamids = array_keys($needWamids);
            $ph = implode(',', array_fill(0, count($wamids), '?'));
            try {
                $st = $this->db->prepare(
                    "SELECT q.wamid AS qw,
                            MAX(CASE WHEN mm.contact_name REGEXP '[A-Za-z]' THEN mm.contact_name END) AS real_name
                       FROM whatsapp_messages q
                       JOIN whatsapp_messages mm
                         ON mm.instance_id = q.instance_id
                        AND mm.participant_jid = q.participant_jid
                      WHERE q.wamid IN ($ph)
                      GROUP BY q.wamid"
                );
                $st->execute($wamids);
                $real = [];
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (!empty($row['real_name'])) $real[(string)$row['qw']] = $row['real_name'];
                }
                if ($real) {
                    foreach ($rows as &$r2) {
                        $w = (string)($r2['quoted_wamid'] ?? '');
                        if ($w !== '' && isset($real[$w])) $r2['quoted_sender'] = $real[$w];
                    }
                    unset($r2);
                }
            } catch (\Throwable $e) {
                error_log('[whatsapp hydrateQuotedSender] enrich falhou: ' . $e->getMessage());
            }
        }

        // Limpa campos auxiliares de todas as linhas
        foreach ($rows as &$r3) { $this->cleanQuotedAux($r3); }
        unset($r3);
    }

    /** Remove os campos auxiliares usados só pra resolver quoted_sender. */
    private function cleanQuotedAux(array &$r): void
    {
        unset($r['quoted_sender_raw'], $r['quoted_sender_member'], $r['quoted_sender_contact'],
              $r['quoted_sender_name'], $r['quoted_text']);
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
    public function getChatList(int $instanceId, string $search = '', ?int $teamId = null, ?int $userId = null, bool $archived = false, int $limit = 500): array
    {
        // JOIN com teams para trazer nome e cor do setor junto com cada chat
        // archived=true → lista apenas arquivadas; false (default) → só não-arquivadas
        //
        // Fix 2026-05-31 ("Você" na lista 1:1): o upsertChat grava no contact_name
        // o nome da ÚLTIMA mensagem — quando ela é sua (outbound), grava "Você",
        // rotulando a conversa inteira errado. Aqui resolvemos o nome de exibição
        // por uma subquery que pega o pushName REAL (com letra, sem ser "Você"/
        // telefone/LID) da mensagem inbound mais recente. Cai pro contact_name do
        // chat (respeitando rename manual) só quando não há nome real nas mensagens.
        $sql = 'SELECT c.*,
                       t.nome AS team_nome,
                       t.cor  AS team_cor,
                       COALESCE(
                           CASE WHEN COALESCE(c.is_manual_name,0)=1 THEN c.contact_name END,
                           (SELECT mi.contact_name FROM whatsapp_messages mi
                             WHERE mi.instance_id IN (SELECT wi.id FROM whatsapp_instances wi
                                     WHERE wi.account_id = (SELECT account_id FROM whatsapp_instances WHERE id = c.instance_id))
                               AND c.remote_jid NOT LIKE \'%@g.us\'
                               AND mi.remote_jid  = c.remote_jid
                               AND mi.direction   = \'inbound\'
                               AND mi.contact_name REGEXP \'[A-Za-z]\'
                               AND mi.contact_name NOT REGEXP \'^[+0-9 ()-]+$\'
                               AND LOWER(mi.contact_name) NOT IN (\'voce\',\'você\',\'you\')
                             ORDER BY mi.created_at DESC LIMIT 1),
                           -- Fallback (2026-05-31): chats 1:1 cujo remote_jid é um LID
                           -- (@lid). Não há inbound com nome real nessa conversa, mas
                           -- a MESMA pessoa escreve em GRUPOS com o pushName de verdade
                           -- (ex.: Milton/Gabriel). Cruza o LID do chat com o
                           -- participant_jid das mensagens e pega o nome mais frequente.
                           -- Coberto pelo índice idx_participant (instance_id,participant_jid).
                           -- Cross-instância: a conta pode ter várias conexões de WhatsApp
                           -- (reconexões geram novas instâncias) e o nome pode ter sido
                           -- sincronizado por uma e não por outra; buscamos em TODAS as
                           -- instâncias da MESMA conta (mesmo tenant, sem vazamento).
                           -- O filtro @g.us evita resolver nome de pessoa em grupos.
                           (SELECT mp.contact_name FROM whatsapp_messages mp
                             WHERE mp.instance_id IN (SELECT wi.id FROM whatsapp_instances wi
                                     WHERE wi.account_id = (SELECT account_id FROM whatsapp_instances WHERE id = c.instance_id))
                               AND c.remote_jid NOT LIKE \'%@g.us\'
                               AND mp.participant_jid = c.remote_jid
                               AND mp.contact_name REGEXP \'[A-Za-z]\'
                               AND mp.contact_name NOT REGEXP \'^[+0-9 ()-]+$\'
                               AND LOWER(mp.contact_name) NOT IN (\'voce\',\'você\',\'you\')
                             GROUP BY mp.contact_name ORDER BY COUNT(*) DESC LIMIT 1),
                           CASE WHEN c.contact_name REGEXP \'[A-Za-z]\'
                                     AND LOWER(c.contact_name) NOT IN (\'voce\',\'você\',\'you\')
                                THEN c.contact_name END
                       ) AS display_name,
                       -- Telefone REAL: o c.phone às vezes guarda o LID (id interno
                       -- longo, não-discável). Quando o remote_jid é @lid, busca o
                       -- telefone verdadeiro em group_members/contacts por aquele LID.
                       COALESCE(
                           CASE WHEN c.remote_jid NOT LIKE \'%@lid\'
                                     AND c.phone REGEXP \'^[0-9]{10,13}$\'
                                THEN c.phone END,
                           (SELECT gm.phone FROM whatsapp_group_members gm
                             WHERE gm.instance_id = c.instance_id
                               AND gm.participant_jid = c.remote_jid
                               AND gm.phone REGEXP \'^[0-9]{10,13}$\' LIMIT 1),
                           (SELECT wc.phone FROM whatsapp_contacts wc
                             WHERE wc.instance_id = c.instance_id
                               AND wc.remote_jid = c.remote_jid
                               AND wc.phone REGEXP \'^[0-9]{10,13}$\' LIMIT 1),
                           CASE WHEN c.phone REGEXP \'^[0-9]{10,13}$\' THEN c.phone END
                       ) AS real_phone
                FROM whatsapp_chats c
                LEFT JOIN teams t ON t.id = c.team_id AND t.deleted_at IS NULL
                WHERE c.instance_id = ? AND c.is_archived = ' . ($archived ? '1' : '0') . '
                  -- Esconde status/broadcast e newsletter (nao sao conversas).
                  AND c.remote_jid NOT LIKE \'%@broadcast\'
                  AND c.remote_jid NOT LIKE \'%@newsletter\'
                  -- Esconde shell 1:1 VAZIO (sem mensagem) e sem vinculo: fantasma de
                  -- discovery/foto (ex.: @lid duplicado do telefone). Grupos, fixados,
                  -- vinculados ou com pelo menos 1 mensagem sempre aparecem.
                  AND (
                        c.is_group = 1
                     OR c.is_pinned = 1
                     OR c.linked_user_id     IS NOT NULL
                     OR c.linked_card_id     IS NOT NULL
                     OR c.linked_processo_id IS NOT NULL
                     OR c.contato_id         IS NOT NULL
                     -- O2 (cross-instancia): apos reconexao a conta ganha uma instancia
                     -- nova e as msgs antigas ficam sob a instancia velha; olhar so a
                     -- instancia atual dava "shell vazio" e escondia a conversa. Agora o
                     -- EXISTS varre TODAS as instancias da MESMA conta (mesmo tenant).
                     OR EXISTS (SELECT 1 FROM whatsapp_messages mm
                                 WHERE mm.instance_id IN (SELECT wi.id FROM whatsapp_instances wi
                                         WHERE wi.account_id = (SELECT account_id FROM whatsapp_instances WHERE id = c.instance_id))
                                   AND mm.remote_jid  = c.remote_jid LIMIT 1)
                  )';
        $params = [$instanceId];

        // Filtro de busca por nome ou telefone. O2: o c.phone as vezes guarda o LID
        // (nao discavel) e o c.contact_name pode estar vazio/numero; entao a busca tambem
        // casa o nome/telefone REAIS da agenda (whatsapp_contacts) e o telefone do membro
        // de grupo (whatsapp_group_members) ligados a este remote_jid.
        if ($search !== '') {
            $sql .= ' AND (
                c.contact_name LIKE ? OR c.phone LIKE ?
                OR EXISTS (SELECT 1 FROM whatsapp_contacts wc
                            WHERE wc.instance_id = c.instance_id AND wc.remote_jid = c.remote_jid
                              AND (wc.push_name LIKE ? OR wc.phone LIKE ?))
                OR EXISTS (SELECT 1 FROM whatsapp_group_members gm
                            WHERE gm.instance_id = c.instance_id
                              AND gm.participant_jid = c.remote_jid
                              AND gm.phone LIKE ?)
            )';
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
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

        // O2: ordena por COALESCE(last_message_at, updated_at, created_at) — chats "shell"
        // criados sem last_message_at (NULL) afundavam (NULL DESC vai pro fim) e caiam fora
        // do limite, sumindo da lista. LIMIT parametrizado (default 500, "Carregar mais"
        // amplia) — antes era fixo em 200 e a 201a conversa ficava invisivel.
        $limit = max(50, min(2000, $limit));
        $sql .= ' ORDER BY c.is_pinned DESC, COALESCE(c.last_message_at, c.updated_at, c.created_at) DESC LIMIT ' . (int)$limit;
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

        $isGroup = str_ends_with($data['remote_jid'] ?? '', '@g.us');

        // account_id: usa o resolvido pelo save(); fallback resolve pela instancia (evita NULL).
        $accId = isset($data['account_id']) && (int)$data['account_id'] > 0 ? (int)$data['account_id'] : null;
        if ($accId === null) {
            $r = $this->db->prepare('SELECT account_id FROM whatsapp_instances WHERE id = ? LIMIT 1');
            $r->execute([(int)$data['instance_id']]);
            $a = $r->fetchColumn();
            $accId = ($a !== false && $a !== null) ? (int)$a : null;
        }

        // contact_name: SO usa o nome da mensagem quando ela e INBOUND. Em mensagem sua
        // (outbound), o pushName e o SEU nome (ex.: "Voce"), que rotularia o chat errado.
        $cname = $isInbound ? trim((string)($data['contact_name'] ?? '')) : '';
        if ($cname !== '' && in_array(mb_strtolower($cname), ['voce', 'você', 'you', 'eu'], true)) $cname = '';
        $cname = $cname !== '' ? $cname : null;

        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_chats
             (account_id, instance_id, remote_jid, contact_name, phone,
              last_message_content, last_message_type, last_message_at,
              last_message_from_me, unread_count, is_group)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               account_id           = IF(account_id IS NULL, VALUES(account_id), account_id),
               contact_name         = IF(is_group = 0 AND VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) != "", VALUES(contact_name), contact_name),
               phone                = IF(VALUES(phone) IS NOT NULL AND VALUES(phone) != "", VALUES(phone), phone),
               last_message_content = VALUES(last_message_content),
               last_message_type    = VALUES(last_message_type),
               last_message_at      = VALUES(last_message_at),
               last_message_from_me = VALUES(last_message_from_me),
               unread_count         = IF(VALUES(last_message_from_me) = 0, unread_count + 1, unread_count)'
        );

        $stmt->execute([
            $accId,
            $data['instance_id'],
            $data['remote_jid'],
            $cname,
            $data['phone']        ?? null,
            $content,
            $data['message_type'] ?? 'text',
            $data['created_at']   ?? date('Y-m-d H:i:s'),
            $isInbound ? 0 : 1,
            $isInbound ? 1 : 0,
            $isGroup ? 1 : 0,
        ]);
    }

    /**
     * Normaliza um remote_jid @lid (id de privacidade do WhatsApp) para o JID de telefone
     * (<numero>@s.whatsapp.net) QUANDO ja conhecemos o numero real do contato (de grupos em
     * comum / contatos). Senao, devolve o jid cru. Evita criar/duplicar a conversa sob o @lid
     * quando o telefone ja e conhecido. Prevencao da duplicacao @lid x telefone.
     */
    public static function resolvePhoneJid(\PDO $db, int $instanceId, ?string $jid): ?string
    {
        if (!$jid || !str_ends_with($jid, '@lid')) return $jid;
        // Fonte confiavel 1: participante de grupo (phoneNumber real).
        $st = $db->prepare("SELECT phone FROM whatsapp_group_members
                             WHERE instance_id = ? AND participant_jid = ? AND phone REGEXP '^[0-9]{10,15}$'
                             LIMIT 1");
        $st->execute([$instanceId, $jid]);
        $phone = $st->fetchColumn();
        if (!$phone) {
            // Fonte 2: contato ja com telefone resolvido.
            $st = $db->prepare("SELECT phone FROM whatsapp_contacts
                                 WHERE instance_id = ? AND remote_jid = ? AND phone REGEXP '^[0-9]{10,15}$'
                                 LIMIT 1");
            $st->execute([$instanceId, $jid]);
            $phone = $st->fetchColumn();
        }
        if ($phone && preg_match('/^[0-9]{10,15}$/', (string)$phone)) {
            return $phone . '@s.whatsapp.net';
        }
        return $jid; // sem telefone confiavel: mantem @lid (nao quebra o fluxo atual)
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
    public function updateStatus(int $instanceId, string $wamid, string $status): bool
    {
        // B5 (auditoria): escopa por instance_id — um wamid repetido entre tenants
        // (teste/replay/forja) nao altera o status de mensagem de outro tenant.
        $stmt = $this->db->prepare(
            'UPDATE whatsapp_messages SET status = ? WHERE instance_id = ? AND wamid = ?'
        );
        return $stmt->execute([$status, $instanceId, $wamid]);
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
                require_once __DIR__ . '/../Prospeccao/Contato.php';
                $isLid = str_ends_with($remoteJid, '@lid');
                if ($isLid) {
                    $contatoId = \App\Prospeccao\Contato::findOrCreateByJid(
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

    /**
     * Lista os membros de um grupo (pra header "N membros", modal e resolução de
     * @menções). O push_name em whatsapp_group_members frequentemente é só o
     * telefone (a Evolution não devolve o nome no fetchGroupInfo). Então AQUI
     * enriquecemos: se houver um pushName REAL (com letra) nas mensagens daquele
     * participant_jid, usamos ele no lugar do telefone.
     *
     * Bug fix 2026-05-31: este método era chamado em group_members.php mas NÃO
     * existia → erro fatal → lista vazia + menções sem nome. Criado + enriquecido.
     */
    public function getGroupMembers(int $instanceId, string $groupJid): array
    {
        // 1) Mapa participant_jid → nome real, tirado do pushName das mensagens
        //    do grupo (contact_name que tem letra, não é telefone/LID).
        $nameStmt = $this->db->prepare(
            "SELECT m.participant_jid,
                    MAX(CASE WHEN m.contact_name REGEXP '[A-Za-z]'
                             AND m.contact_name NOT REGEXP '^[+0-9 ()-]+$'
                        THEN m.contact_name END) AS real_name
               FROM whatsapp_messages m
              WHERE m.instance_id = ?
                AND m.remote_jid = ?
                AND m.participant_jid IS NOT NULL
              GROUP BY m.participant_jid"
        );
        $nameStmt->execute([$instanceId, $groupJid]);
        $realByJid = [];
        foreach ($nameStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['real_name'])) {
                $realByJid[$r['participant_jid']] = $r['real_name'];
            }
        }

        // 2) Lista de membros (de whatsapp_group_members)
        $stmt = $this->db->prepare(
            "SELECT participant_jid, push_name, phone, role
               FROM whatsapp_group_members
              WHERE instance_id = ? AND group_jid = ?
              ORDER BY (role = 'superadmin') DESC, (role = 'admin') DESC, push_name ASC"
        );
        $stmt->execute([$instanceId, $groupJid]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 3) Enriquece: prioriza nome real das mensagens sobre o telefone gravado
        foreach ($members as &$m) {
            $jid = $m['participant_jid'] ?? '';
            if (isset($realByJid[$jid])) {
                $m['push_name'] = $realByJid[$jid];
            }
        }
        unset($m);

        return $members;
    }
}
