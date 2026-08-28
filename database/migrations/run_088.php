<?php
/**
 * run_088.php — Executor IDEMPOTENTE da migration 088 (correções WhatsApp).
 *
 * Engine-agnóstico: funciona em MySQL 8.0 (produção, container yuris_db) e em
 * MariaDB 10.4 (dev local XAMPP). NÃO usa sintaxe MariaDB-only
 * ("ADD INDEX IF NOT EXISTS") — checa o information_schema antes de cada DDL.
 *
 * Produção (dentro do container do app):
 *     docker exec yuris_app php database/migrations/run_088.php
 *
 * Seguro reexecutar: cada passo é idempotente.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$db  = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Banco:  {$db}\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "----\n";

$indexExists = function (string $table, string $index) use ($pdo, $db): bool {
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $s->execute([$db, $table, $index]);
    return (int)$s->fetchColumn() > 0;
};

// A) Índice de ordenação cronológica (instance_id, remote_jid, created_at, id)
if ($indexExists('whatsapp_messages', 'idx_jid_created')) {
    echo "A) idx_jid_created: ja existe — skip\n";
} else {
    $pdo->exec('ALTER TABLE whatsapp_messages
                ADD INDEX idx_jid_created (instance_id, remote_jid, created_at, id)');
    echo "A) idx_jid_created: CRIADO\n";
}

// C) Backfill participant_jid a partir do raw_payload (só onde vazio) — idempotente
$sqlC = <<<'SQL'
UPDATE whatsapp_messages
   SET participant_jid = JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.key.participant'))
 WHERE (participant_jid IS NULL OR participant_jid = '')
   AND remote_jid LIKE '%@g.us'
   AND raw_payload IS NOT NULL
   AND JSON_VALID(raw_payload)
   AND JSON_EXTRACT(raw_payload, '$.key.participant') IS NOT NULL
SQL;
echo 'C) participant_jid backfill: ' . (int)$pdo->exec($sqlC) . " linhas\n";

// G1) wamid '' -> NULL — idempotente (NULLs nao colidem no UNIQUE)
echo "G1) wamid vazio -> NULL: "
   . (int)$pdo->exec("UPDATE whatsapp_messages SET wamid = NULL WHERE wamid = ''")
   . " linhas\n";

// G2) dedup wamid (mantem o de menor id) — idempotente
$sqlG2 = <<<'SQL'
DELETE m1 FROM whatsapp_messages m1
  INNER JOIN whatsapp_messages m2
     ON m1.instance_id = m2.instance_id
    AND m1.wamid       = m2.wamid
    AND m1.id          > m2.id
 WHERE m1.wamid IS NOT NULL AND m1.wamid <> ''
SQL;
echo 'G2) duplicatas removidas: ' . (int)$pdo->exec($sqlG2) . " linhas\n";

// G3) UNIQUE (instance_id, wamid)
if ($indexExists('whatsapp_messages', 'uq_inst_wamid')) {
    echo "G3) uq_inst_wamid: ja existe — skip\n";
} else {
    $pdo->exec('ALTER TABLE whatsapp_messages
                ADD UNIQUE INDEX uq_inst_wamid (instance_id, wamid)');
    echo "G3) uq_inst_wamid: CRIADO\n";
}

echo "----\nOK — migration 088 aplicada com sucesso.\n";
