<?php
/**
 * whatsapp_dedupe_lid.php — corrige 3 problemas de dados do Chat WhatsApp:
 *   (A) account_id NULL em whatsapp_chats  -> backfill pela instancia.
 *   (B) contact_name = "Voce/voce/you/eu"  -> zera (a leitura cai no nome/telefone real).
 *   (C) duplicacao @lid x telefone          -> funde a conversa @lid na do telefone
 *       (quando o telefone real e conhecido via grupos/contatos), repontando
 *       whatsapp_messages / whatsapp_contacts / whatsapp_group_members /
 *       whatsapp_reactions / ai_intake_sessions e consolidando flags/vinculos.
 *
 * SEGURANCA: por padrao roda em DRY-RUN (so conta, nao altera). Para aplicar:
 *   php scripts/whatsapp_dedupe_lid.php --apply
 * Tudo escopado por instance_id e em transacao. @lid sem telefone resolvivel NAO e tocado.
 *
 * Uso local: C:\xampp\php\php.exe scripts/whatsapp_dedupe_lid.php [--apply]
 * Uso prod:  docker exec -i yuris_app php /var/www/html/scripts/whatsapp_dedupe_lid.php [--apply]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$APPLY = in_array('--apply', $argv, true);
$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== whatsapp_dedupe_lid (" . ($APPLY ? 'APPLY' : 'DRY-RUN') . ") ==\n";
echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n\n";

/* ── (A) account_id NULL ─────────────────────────────────────────── */
$nullAcc = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_chats WHERE account_id IS NULL")->fetchColumn();
echo "[A] chats com account_id NULL: {$nullAcc}\n";
if ($APPLY && $nullAcc > 0) {
    $n = $pdo->exec("UPDATE whatsapp_chats c JOIN whatsapp_instances i ON c.instance_id = i.id
                        SET c.account_id = i.account_id
                      WHERE c.account_id IS NULL AND i.account_id IS NOT NULL");
    echo "    -> account_id preenchido em {$n} chats\n";
}

/* ── (B) contact_name = "Voce" ───────────────────────────────────── */
$voce = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_chats
                           WHERE is_group=0 AND LOWER(TRIM(contact_name)) IN ('voce','você','you','eu')")->fetchColumn();
echo "[B] chats nomeados 'Voce/voce/you/eu': {$voce}\n";
if ($APPLY && $voce > 0) {
    $n = $pdo->exec("UPDATE whatsapp_chats SET contact_name = NULL
                      WHERE is_group=0 AND LOWER(TRIM(contact_name)) IN ('voce','você','you','eu')");
    echo "    -> contact_name zerado em {$n} chats (leitura usa nome/telefone real)\n";
}

/* ── (C) duplicacao @lid x telefone ──────────────────────────────── */
echo "\n[C] duplicacao @lid x telefone\n";

// helper: resolve telefone real de um @lid (grupos/contatos), igual ao runtime
function resolvePhone(PDO $pdo, int $inst, string $lidJid): ?string {
    foreach (['whatsapp_group_members' => 'participant_jid', 'whatsapp_contacts' => 'remote_jid'] as $tbl => $col) {
        $st = $pdo->prepare("SELECT phone FROM {$tbl} WHERE instance_id=? AND {$col}=? AND phone REGEXP '^[0-9]{10,15}$' LIMIT 1");
        $st->execute([$inst, $lidJid]);
        $p = $st->fetchColumn();
        if ($p) return (string)$p;
    }
    return null;
}

$lidChats = $pdo->query("SELECT id, instance_id, remote_jid, contact_name
                           FROM whatsapp_chats
                          WHERE is_group=0 AND remote_jid LIKE '%@lid'
                          ORDER BY instance_id, id")->fetchAll(PDO::FETCH_ASSOC);

$tot = count($lidChats); $merge = 0; $convert = 0; $unres = 0;
$plan = [];
foreach ($lidChats as $c) {
    $inst = (int)$c['instance_id'];
    $phone = resolvePhone($pdo, $inst, $c['remote_jid']);
    if (!$phone) { $unres++; $plan[] = ['lid'=>$c, 'action'=>'skip', 'target'=>null]; continue; }
    $target = $phone . '@s.whatsapp.net';
    $tw = $pdo->prepare("SELECT id FROM whatsapp_chats WHERE instance_id=? AND remote_jid=? LIMIT 1");
    $tw->execute([$inst, $target]);
    $twinId = $tw->fetchColumn();
    if ($twinId) { $merge++;   $plan[] = ['lid'=>$c, 'action'=>'merge',   'target'=>$target, 'twin'=>(int)$twinId]; }
    else         { $convert++; $plan[] = ['lid'=>$c, 'action'=>'convert', 'target'=>$target]; }
}
echo "    @lid (1:1) total: {$tot}\n";
echo "      -> fundir na conversa de telefone existente (merge): {$merge}\n";
echo "      -> reescrever pro JID de telefone (convert, sem gemea): {$convert}\n";
echo "      -> sem telefone conhecido (NAO mexe): {$unres}\n";

echo "\n    detalhe:\n";
foreach ($plan as $p) {
    $c = $p['lid'];
    $tgt = $p['target'] ? (explode('@', $p['target'])[0]) : '-';
    echo "      [{$p['action']}] chat#{$c['id']} inst={$c['instance_id']} nome=" . ($c['contact_name'] ?? '-') .
         " {$c['remote_jid']} -> tel={$tgt}" . (isset($p['twin']) ? " (gemea chat#{$p['twin']})" : '') . "\n";
}

if (!$APPLY) { echo "\n(DRY-RUN: nada foi alterado. Rode com --apply para aplicar.)\n"; exit(0); }

/* ── APPLY do (C) ────────────────────────────────────────────────── */
echo "\n== APPLY (C) merge/convert ==\n";
$done = 0;
foreach ($plan as $p) {
    if ($p['action'] === 'skip') continue;
    $c = $p['lid']; $inst = (int)$c['instance_id']; $lid = $c['remote_jid']; $target = $p['target'];
    try {
        $pdo->beginTransaction();
        // 1) Mensagens, contatos, grupo (autor), reactions, sessoes do agente -> repontam pro telefone.
        $pdo->prepare("UPDATE whatsapp_messages SET remote_jid=? WHERE instance_id=? AND remote_jid=?")->execute([$target,$inst,$lid]);
        $pdo->prepare("UPDATE whatsapp_messages SET participant_jid=? WHERE instance_id=? AND participant_jid=?")->execute([$target,$inst,$lid]);
        // ai_intake_sessions usa channel_id == instance_id
        try { $pdo->prepare("UPDATE ai_intake_sessions SET remote_jid=? WHERE channel_id=? AND remote_jid=?")->execute([$target,$inst,$lid]); } catch (\Throwable $_) {}
        // whatsapp_reactions: reactor_jid (UNIQUE por target_wamid+reactor) — apaga colisao, depois reapnta
        try {
            $pdo->prepare("DELETE r1 FROM whatsapp_reactions r1 JOIN whatsapp_reactions r2
                             ON r1.instance_id=r2.instance_id AND r1.target_wamid=r2.target_wamid
                            AND r2.reactor_jid=? AND r1.reactor_jid=? WHERE r1.instance_id=?")->execute([$target,$lid,$inst]);
            $pdo->prepare("UPDATE whatsapp_reactions SET reactor_jid=? WHERE instance_id=? AND reactor_jid=?")->execute([$target,$inst,$lid]);
        } catch (\Throwable $_) {}
        // whatsapp_group_members.participant_jid (se o lid for membro) — apaga colisao, reapnta
        try {
            $pdo->prepare("DELETE m1 FROM whatsapp_group_members m1 JOIN whatsapp_group_members m2
                             ON m1.instance_id=m2.instance_id AND m1.group_jid=m2.group_jid
                            AND m2.participant_jid=? AND m1.participant_jid=? WHERE m1.instance_id=?")->execute([$target,$lid,$inst]);
            $pdo->prepare("UPDATE whatsapp_group_members SET participant_jid=? WHERE instance_id=? AND participant_jid=?")->execute([$target,$inst,$lid]);
        } catch (\Throwable $_) {}
        // whatsapp_contacts: se ja existe a do telefone, apaga a do lid; senao reapnta
        $cx = $pdo->prepare("SELECT id FROM whatsapp_contacts WHERE instance_id=? AND remote_jid=? LIMIT 1");
        $cx->execute([$inst,$target]); $cTwin = $cx->fetchColumn();
        if ($cTwin) { $pdo->prepare("DELETE FROM whatsapp_contacts WHERE instance_id=? AND remote_jid=?")->execute([$inst,$lid]); }
        else        { try { $pdo->prepare("UPDATE whatsapp_contacts SET remote_jid=? WHERE instance_id=? AND remote_jid=?")->execute([$target,$inst,$lid]); } catch (\Throwable $_) {} }

        if ($p['action'] === 'convert') {
            // sem gemea: so reescreve o remote_jid do proprio chat + corrige phone/account
            $pdo->prepare("UPDATE whatsapp_chats SET remote_jid=?, phone=?, account_id=COALESCE(account_id,(SELECT account_id FROM whatsapp_instances WHERE id=?))
                            WHERE id=?")->execute([$target, explode('@',$target)[0], $inst, (int)$c['id']]);
        } else {
            // merge: consolida flags/vinculos na gemea e remove o chat @lid
            $twin = (int)$p['twin'];
            $pdo->prepare(
                "UPDATE whatsapp_chats t JOIN whatsapp_chats l ON l.id=:lid SET
                   t.unread_count       = t.unread_count + l.unread_count,
                   t.is_pinned          = GREATEST(t.is_pinned, l.is_pinned),
                   t.linked_card_id     = COALESCE(t.linked_card_id, l.linked_card_id),
                   t.linked_processo_id = COALESCE(t.linked_processo_id, l.linked_processo_id),
                   t.linked_user_id     = COALESCE(t.linked_user_id, l.linked_user_id),
                   t.contato_id         = COALESCE(t.contato_id, l.contato_id),
                   t.team_id            = COALESCE(t.team_id, l.team_id),
                   t.agent_paused       = GREATEST(t.agent_paused, l.agent_paused),
                   t.contact_name       = IF(t.contact_name IS NULL OR t.contact_name='' OR t.contact_name REGEXP '^[0-9]+$', COALESCE(l.contact_name, t.contact_name), t.contact_name),
                   t.last_message_at      = GREATEST(COALESCE(t.last_message_at,0), COALESCE(l.last_message_at,0)),
                   t.account_id         = COALESCE(t.account_id, l.account_id)
                 WHERE t.id=:twin"
            )->execute([':lid'=>(int)$c['id'], ':twin'=>$twin]);
            $pdo->prepare("DELETE FROM whatsapp_chats WHERE id=?")->execute([(int)$c['id']]);
        }
        // Preserva o mapa @lid -> telefone em whatsapp_contacts para o resolvePhoneJid
        // continuar normalizando mensagens FUTURAS desse contato. Sem isso, repontar o
        // participant_jid do grupo (@lid -> telefone) destruia a fonte de resolucao e a
        // duplicacao @lid voltava no proximo sync/mensagem.
        $aphone = explode('@', $target)[0];
        $pdo->prepare(
            "INSERT INTO whatsapp_contacts (account_id, instance_id, remote_jid, phone, is_group)
             VALUES ((SELECT account_id FROM whatsapp_instances WHERE id=?), ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE phone = VALUES(phone)"
        )->execute([$inst, $inst, $lid, $aphone]);
        $pdo->commit();
        $done++;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo "    [ERRO] chat#{$c['id']} ({$lid}): " . $e->getMessage() . "\n";
    }
}
echo "    -> {$done} conversas dedupadas\n";
echo "== concluido ==\n";
