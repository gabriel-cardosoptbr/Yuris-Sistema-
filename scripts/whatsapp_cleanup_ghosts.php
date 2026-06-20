<?php
/**
 * whatsapp_cleanup_ghosts.php — remove "fantasmas" do Chat WhatsApp:
 *   (G1) shell 1:1 VAZIO (0 mensagens) e sem vinculo/fixacao  -> DELETE
 *        (ex.: shell @lid duplicado recriado pelo discovery/foto)
 *   (G2) chats de status@broadcast / @newsletter (+ mensagens) -> DELETE (nao sao conversas)
 *   (G3) contact_name lixo em 1:1 (so digitos 10+, ou "Voce/voce/you/eu"),
 *        respeitando rename manual                            -> NULL (display cai no nome/telefone real)
 *
 * SEGURANCA: DRY-RUN por padrao (so conta/lista). Para aplicar:
 *   php scripts/whatsapp_cleanup_ghosts.php --apply
 * No --apply faz BACKUP das linhas afetadas em _bak_ghosts_<ts> antes de apagar. Transacional.
 *
 * Uso local: C:\xampp\php\php.exe scripts/whatsapp_cleanup_ghosts.php [--apply]
 * Uso prod:  docker exec -i yuris_app php /var/www/html/scripts/whatsapp_cleanup_ghosts.php [--apply]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../app/Models/Database.php';
use App\Models\Database;

$APPLY = in_array('--apply', $argv, true);
$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== whatsapp_cleanup_ghosts (" . ($APPLY ? 'APPLY' : 'DRY-RUN') . ") ==\n";
echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n\n";

/* Predicado do shell 1:1 vazio (sem msg) e sem vinculo. */
$emptyWhere = "
    c.is_group = 0
    AND c.is_pinned = 0
    AND c.linked_user_id     IS NULL
    AND c.linked_card_id     IS NULL
    AND c.linked_processo_id IS NULL
    AND c.contato_id         IS NULL
    AND c.remote_jid NOT LIKE '%@broadcast'
    AND c.remote_jid NOT LIKE '%@newsletter'
    AND NOT EXISTS (SELECT 1 FROM whatsapp_messages mm
                     WHERE mm.instance_id = c.instance_id AND mm.remote_jid = c.remote_jid)
";

$g1 = $pdo->query("SELECT c.id, c.instance_id, c.remote_jid, c.contact_name
                     FROM whatsapp_chats c WHERE {$emptyWhere} ORDER BY c.instance_id, c.id")
          ->fetchAll(PDO::FETCH_ASSOC);
echo "[G1] shells 1:1 VAZIOS sem vinculo: " . count($g1) . "\n";
foreach ($g1 as $r) echo "      chat#{$r['id']} inst={$r['instance_id']} nome=" . ($r['contact_name'] ?? '-') . " {$r['remote_jid']}\n";

$g2 = $pdo->query("SELECT id, instance_id, remote_jid, contact_name FROM whatsapp_chats
                    WHERE remote_jid LIKE '%@broadcast' OR remote_jid LIKE '%@newsletter'
                    ORDER BY instance_id, id")->fetchAll(PDO::FETCH_ASSOC);
echo "\n[G2] chats broadcast/newsletter: " . count($g2) . "\n";
foreach ($g2 as $r) echo "      chat#{$r['id']} inst={$r['instance_id']} nome=" . ($r['contact_name'] ?? '-') . " {$r['remote_jid']}\n";

$g3 = $pdo->query("SELECT id, instance_id, remote_jid, contact_name FROM whatsapp_chats
                    WHERE is_group = 0 AND COALESCE(is_manual_name,0) = 0 AND contact_name IS NOT NULL
                      AND ( contact_name REGEXP '^[0-9]{10,}$'
                            OR LOWER(TRIM(contact_name)) IN ('voce','você','you','eu') )
                    ORDER BY instance_id, id")->fetchAll(PDO::FETCH_ASSOC);
echo "\n[G3] nomes-lixo (digitos/'Voce') a zerar: " . count($g3) . "\n";
foreach ($g3 as $r) echo "      chat#{$r['id']} inst={$r['instance_id']} nome=\"{$r['contact_name']}\" {$r['remote_jid']}\n";

if (!$APPLY) { echo "\n(DRY-RUN: nada alterado. Rode com --apply para aplicar.)\n"; exit(0); }

/* ── APPLY ─────────────────────────────────────────────────────────── */
$ts  = date('Ymd_His');
$bak = "_bak_ghosts_{$ts}";
echo "\n== APPLY ==\nBackup das linhas afetadas em {$bak}_chats / {$bak}_messages\n";
$pdo->beginTransaction();
try {
    // Backup (estrutura + dados) das linhas que serao apagadas (G1 + G2).
    $pdo->exec("CREATE TABLE {$bak}_chats LIKE whatsapp_chats");
    $pdo->exec("INSERT INTO {$bak}_chats
                SELECT c.* FROM whatsapp_chats c WHERE {$emptyWhere}");
    $pdo->exec("INSERT INTO {$bak}_chats
                SELECT * FROM whatsapp_chats
                 WHERE remote_jid LIKE '%@broadcast' OR remote_jid LIKE '%@newsletter'");
    $pdo->exec("CREATE TABLE {$bak}_messages LIKE whatsapp_messages");
    $pdo->exec("INSERT INTO {$bak}_messages
                SELECT m.* FROM whatsapp_messages m
                  JOIN whatsapp_chats c ON c.instance_id = m.instance_id AND c.remote_jid = m.remote_jid
                 WHERE c.remote_jid LIKE '%@broadcast' OR c.remote_jid LIKE '%@newsletter'");

    // G2: apaga mensagens dos chats broadcast/newsletter, depois os chats.
    $n2m = $pdo->exec("DELETE m FROM whatsapp_messages m
                        JOIN whatsapp_chats c ON c.instance_id = m.instance_id AND c.remote_jid = m.remote_jid
                       WHERE c.remote_jid LIKE '%@broadcast' OR c.remote_jid LIKE '%@newsletter'");
    $n2 = $pdo->exec("DELETE FROM whatsapp_chats
                       WHERE remote_jid LIKE '%@broadcast' OR remote_jid LIKE '%@newsletter'");

    // G1: apaga os shells vazios (ja sem mensagens por definicao).
    $n1 = $pdo->exec("DELETE c FROM whatsapp_chats c WHERE {$emptyWhere}");

    // G3: zera nomes-lixo.
    $n3 = $pdo->exec("UPDATE whatsapp_chats SET contact_name = NULL
                       WHERE is_group = 0 AND COALESCE(is_manual_name,0) = 0 AND contact_name IS NOT NULL
                         AND ( contact_name REGEXP '^[0-9]{10,}$'
                               OR LOWER(TRIM(contact_name)) IN ('voce','você','you','eu') )");

    $pdo->commit();
    echo "    -> G1 shells apagados: {$n1}\n";
    echo "    -> G2 chats broadcast/newsletter apagados: {$n2} (mensagens: {$n2m})\n";
    echo "    -> G3 nomes-lixo zerados: {$n3}\n";
    echo "== concluido (backup em {$bak}_chats / {$bak}_messages) ==\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "    [ERRO] rollback: " . $e->getMessage() . "\n";
    exit(1);
}
