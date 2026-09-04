<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * WhatsAppWebhookEntitySync — persistencia de ENTIDADES do webhook da Evolution.
 *
 * Strangler do webhook.php (Onda 4 / 4D, Pass 2 = I4). Os blocos inline do switch que
 * gravavam contatos, chats e grupos foram movidos VERBATIM para ca (mesma SQL, mesma
 * ordem, mesmos guards). Diferenca em relacao ao original e SO qualificacao de namespace
 * (\PDO, \App\WhatsAppAgente\WhatsAppMessage, Database via use, self::upsertGroupParticipants) — comportamento
 * neutro, provado por: (a) diff normalizado vs HEAD (corpos byte-identicos), (b) harness de
 * equivalencia legado-vs-novo em nivel de banco rodado localmente (14 cenarios em transacoes
 * com rollback -> linhas identicas nas 3 tabelas) e (c) a guarda estrutural em
 * scripts/tests/wa_invariants.php (classe + metodos + delegacao do webhook).
 *
 * Cada metodo pega o proprio $pdo (igual aos blocos originais). O guard `if (!is_array($data))
 * return` no topo espelha o HEAD (foreach sobre nao-iteravel = noop 200) para payload
 * malformado com `data` escalar. Grupos mantem o try/catch defensivo interno (payload de
 * grupo malformado NAO derruba o webhook).
 */
class WhatsAppWebhookEntitySync
{
    /**
     * contacts.update / contacts.upsert.
     * Persiste o nome do contato em DOIS lugares: whatsapp_chats.contact_name (topo da
     * conversa 1:1) e whatsapp_contacts.push_name (resolve nome em grupos/1:1). Respeita
     * is_manual_name (rename manual do usuario nao e sobrescrito).
     */
    public static function syncContacts(int $accountId, int $instanceId, $data): void
    {
        if (!is_array($data)) return; // data escalar (payload malformado): noop 200, igual ao HEAD (foreach sobre nao-iteravel)
        $contacts = $data;
        if (isset($data['id'])) $contacts = [$data];
        $pdo = Database::getConnection();
        foreach ($contacts as $c) {
            if (!is_array($c)) continue;
            $jid  = $c['id'] ?? null;
            $name = $c['pushName'] ?? ($c['name'] ?? ($c['verifiedName'] ?? null));
            if (!$jid || !$name) continue;
            // Ignora "nome" que é só número (não é nome de verdade)
            if (preg_match('/^\d{6,}$/', (string)$name)) continue;
            // Ignora auto-nome ("Voce"/"you"/"eu"): rotularia o chat errado.
            if (in_array(mb_strtolower(trim((string)$name)), ['voce','você','you','eu'], true)) continue;
            $isGroup = str_ends_with((string)$jid, '@g.us') ? 1 : 0;
            // @lid (id de privacidade do WhatsApp) NAO e telefone discavel: nao derivar
            // phone dos seus digitos. Antes gravava o numero interno do @lid como
            // "telefone real" em whatsapp_contacts.phone, poluindo a coluna e enganando
            // resolvePhoneJid (que passava a "resolver" o @lid pra um numero invalido).
            // So @s.whatsapp.net vira phone. Mesmo padrao do sync.php.
            $phone   = ($isGroup || str_ends_with((string)$jid, '@lid'))
                ? null
                : preg_replace('/[^0-9]/', '', explode('@', (string)$jid)[0]);

            // a) nome de exibição no chat (não sobrescreve rename manual)
            $pdo->prepare(
                'UPDATE whatsapp_chats SET contact_name = ?
                 WHERE instance_id = ? AND remote_jid = ? AND COALESCE(is_manual_name,0) = 0'
            )->execute([$name, $instanceId, $jid]);

            // b) tabela de contatos (alimenta a resolução de nome em grupos/1:1)
            $pdo->prepare(
                'INSERT INTO whatsapp_contacts (account_id, instance_id, remote_jid, push_name, phone, is_group)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   push_name = IF(COALESCE(is_manual_name,0) = 0, VALUES(push_name), push_name),
                   phone     = IF(VALUES(phone) IS NOT NULL AND VALUES(phone) <> "", VALUES(phone), phone)'
            )->execute([$accountId, $instanceId, $jid, $name, $phone, $isGroup]);
        }
    }

    /**
     * chats.upsert / chats.update (sincronizacao inicial de conversas).
     */
    public static function syncChats(int $accountId, int $instanceId, $data): void
    {
        if (!is_array($data)) return; // data escalar (payload malformado): noop 200, igual ao HEAD (foreach sobre nao-iteravel)
        $chats = $data;
        if (isset($data['id'])) $chats = [$data];
        $pdo = Database::getConnection();
        foreach ($chats as $c) {
            $jid      = $c['id']       ?? null;
            $name     = $c['name']     ?? null;
            $unread   = (int)($c['unreadCount'] ?? 0);
            if (!$jid) continue;
            // Ignora status/broadcast e newsletter: nao sao conversas (nao viram chat "0").
            if (str_ends_with((string)$jid, '@broadcast') || str_contains((string)$jid, '@newsletter')) continue;
            $jid      = \App\WhatsAppAgente\WhatsAppMessage::resolvePhoneJid($pdo, (int)$instanceId, $jid); // @lid -> telefone quando conhecido
            $isGroup  = str_ends_with((string)$jid, '@g.us') ? 1 : 0;
            // Nao pre-cria shell @lid 1:1 vazio (fantasma): so se ja houver mensagem sob o jid.
            if (!$isGroup && str_ends_with((string)$jid, '@lid')) {
                $ex = $pdo->prepare('SELECT 1 FROM whatsapp_messages WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
                $ex->execute([(int)$instanceId, $jid]);
                if (!$ex->fetchColumn()) continue;
            }
            // Nao rotula com auto-nome ("Voce" etc.)
            $cn = trim((string)$name);
            if ($cn !== '' && in_array(mb_strtolower($cn), ['voce','você','you','eu'], true)) $cn = '';
            $name = $cn !== '' ? $cn : null;
            $s = $pdo->prepare(
                'INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, unread_count, is_group)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   account_id   = IF(account_id IS NULL, VALUES(account_id), account_id),
                   contact_name = IF(COALESCE(is_manual_name,0)=0 AND VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) <> "", VALUES(contact_name), contact_name),
                   unread_count = VALUES(unread_count)'
            );
            $s->execute([$accountId, $instanceId, $jid, $name, $unread, $isGroup]);
        }
    }

    /**
     * groups.upsert / groups.update (nome do grupo + foto + participantes).
     * Defensivo: shape varia por versao da Evolution, entao um payload inesperado NAO
     * derruba o webhook (try/catch local, igual ao original).
     */
    public static function syncGroups(int $accountId, int $instanceId, $data): void
    {
        if (!is_array($data)) return; // data escalar (payload malformado): noop 200, igual ao HEAD (foreach sobre nao-iteravel)
        try {
            $pdo    = Database::getConnection();
            $groups = $data;
            if (isset($data['id'])) $groups = [$data];
            foreach ($groups as $g) {
                if (!is_array($g)) continue;
                $gjid = $g['id'] ?? null;
                if (!$gjid) continue;
                $subj = $g['subject'] ?? ($g['subjectName'] ?? null);
                // Foto do grupo: grava a pictureUrl quando vier no evento (antes só o
                // nome era persistido → grupo novo ficava sem foto até "Sincronizar").
                $gpic = $g['pictureUrl'] ?? ($g['profilePictureUrl'] ?? ($g['profilePicUrl'] ?? null));
                if ($subj || $gpic) {
                    $pdo->prepare(
                        'INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, profile_pic_url, is_group)
                         VALUES (?,?,?,?,?,1)
                         ON DUPLICATE KEY UPDATE
                           account_id      = IF(account_id IS NULL, VALUES(account_id), account_id),
                           contact_name    = IF(COALESCE(is_manual_name,0)=0 AND VALUES(contact_name) IS NOT NULL AND VALUES(contact_name) <> "", VALUES(contact_name), contact_name),
                           profile_pic_url = IF(VALUES(profile_pic_url) IS NOT NULL AND VALUES(profile_pic_url) <> "", VALUES(profile_pic_url), profile_pic_url)'
                    )->execute([$accountId, $instanceId, $gjid, $subj, $gpic]);
                }
                if (!empty($g['participants']) && is_array($g['participants'])) {
                    self::upsertGroupParticipants($pdo, $accountId, $instanceId, $gjid, $g['participants']);
                }
            }
        } catch (\Throwable $_) { /* não derruba o webhook */ }
    }

    /**
     * group-participants.update / groups.participants.update (add/remove/promote membros).
     */
    public static function syncGroupParticipants(int $accountId, int $instanceId, $data): void
    {
        if (!is_array($data)) return; // data escalar (payload malformado): noop 200, igual ao HEAD (foreach sobre nao-iteravel)
        try {
            $pdo    = Database::getConnection();
            $gjid   = $data['id'] ?? ($data['groupJid'] ?? null);
            $action = strtolower((string)($data['action'] ?? 'add'));
            $parts  = $data['participants'] ?? [];
            if ($gjid && is_array($parts)) {
                if (in_array($action, ['remove','leave'], true)) {
                    $del = $pdo->prepare('DELETE FROM whatsapp_group_members WHERE instance_id = ? AND group_jid = ? AND participant_jid = ?');
                    foreach ($parts as $pj) { if (is_string($pj) && $pj !== '') $del->execute([$instanceId, $gjid, $pj]); }
                } else {
                    $defaultRole = $action === 'promote' ? 'admin' : 'member';
                    self::upsertGroupParticipants($pdo, $accountId, $instanceId, $gjid, $parts, $defaultRole);
                }
            }
        } catch (\Throwable $_) { /* não derruba o webhook */ }
    }

    /**
     * UPSERT de participantes de grupo em whatsapp_group_members.
     * Aceita itens como string (JID puro) ou objeto {id, admin, phoneNumber, pushName}.
     * Nunca grava número como "nome"; telefone vai na coluna própria.
     */
    public static function upsertGroupParticipants(\PDO $pdo, ?int $accountId, int $instanceId, string $groupJid, array $participants, string $defaultRole = 'member'): void
    {
        $ins = $pdo->prepare(
            'INSERT INTO whatsapp_group_members
               (account_id, instance_id, group_jid, participant_jid, push_name, phone, role)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               push_name = IF(VALUES(push_name) IS NOT NULL, VALUES(push_name), push_name),
               phone     = IF(VALUES(phone)     IS NOT NULL, VALUES(phone),     phone),
               role      = VALUES(role)'
        );
        foreach ($participants as $p) {
            if (is_string($p)) {
                $pj = $p; $admin = null; $phoneJid = null; $pn = null;
            } elseif (is_array($p)) {
                $pj       = $p['id'] ?? ($p['jid'] ?? null);
                $admin    = $p['admin'] ?? null;
                $phoneJid = $p['phoneNumber'] ?? null;
                $pn       = $p['pushName'] ?? ($p['name'] ?? null);
            } else {
                continue;
            }
            if (!$pj) continue;
            $src    = $phoneJid ?: $pj;
            $digits = preg_replace('/[^0-9]/', '', explode('@', (string)$src)[0]);
            $phone  = ($digits && strlen($digits) >= 10 && strlen($digits) < 14) ? $digits : null;
            if ($pn !== null && preg_match('/^\d{6,}$/', (string)$pn)) $pn = null; // número não é nome
            $role   = match ($admin) {
                'superadmin' => 'superadmin',
                'admin'      => 'admin',
                default      => $defaultRole,
            };
            $ins->execute([(int)($accountId ?? 0), $instanceId, $groupJid, $pj, $pn, $phone, $role]);
        }
    }
}
