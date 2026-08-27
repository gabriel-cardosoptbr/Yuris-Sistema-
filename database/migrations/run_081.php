<?php
// Runner standalone da migration 081 (agente -> instância WhatsApp).
//
// Uso local:  php database/migrations/run_081.php
// Uso prod:   docker exec -i yuris_app php /var/www/html/database/migrations/run_081.php
//
// Liga agent_configs a whatsapp_instances (FK), troca a unicidade per-user por
// unicidade per-canal (1 agente por instância), faz backfill pelo número antigo,
// dropa whatsapp_number e adiciona UNIQUE(account_id,phone) em whatsapp_instances.
//
// Idempotente: cada passo é precedido de check em information_schema.

require_once __DIR__ . '/../../app/Core/Database.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Migration 081: agent_configs -> instância WhatsApp ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "DB: "     . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n----\n";

$hasColumn = function (string $t, string $c) use ($pdo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$t, $c]);
    return (int)$s->fetchColumn() > 0;
};
$hasIndex = function (string $t, string $i) use ($pdo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $s->execute([$t, $i]);
    return (int)$s->fetchColumn() > 0;
};
$hasFk = function (string $t, string $fk) use ($pdo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                           AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $s->execute([$t, $fk]);
    return (int)$s->fetchColumn() > 0;
};

// ── 1) Colunas novas em agent_configs ───────────────────────────────────────
echo "Tabela: agent_configs\n";
if (!$hasColumn('agent_configs', 'branch_id')) {
    $pdo->exec("ALTER TABLE agent_configs ADD COLUMN branch_id INT NULL AFTER account_id");
    echo "  [ok] branch_id adicionada\n";
} else { echo "  [skip] branch_id já existe\n"; }

if (!$hasColumn('agent_configs', 'whatsapp_instance_id')) {
    $pdo->exec("ALTER TABLE agent_configs ADD COLUMN whatsapp_instance_id INT NULL AFTER user_id");
    echo "  [ok] whatsapp_instance_id adicionada\n";
} else { echo "  [skip] whatsapp_instance_id já existe\n"; }

if (!$hasColumn('agent_configs', 'updated_by')) {
    $pdo->exec("ALTER TABLE agent_configs ADD COLUMN updated_by INT NULL AFTER prompt");
    echo "  [ok] updated_by adicionada\n";
} else { echo "  [skip] updated_by já existe\n"; }

// ── 2) Backfill + dedup (só enquanto whatsapp_number ainda existe) ───────────
if ($hasColumn('agent_configs', 'whatsapp_number')) {
    $n = $pdo->exec(
        "UPDATE agent_configs ac
         JOIN whatsapp_instances wi
           ON wi.account_id = ac.account_id
          AND wi.phone IS NOT NULL
          AND REGEXP_REPLACE(wi.phone, '[^0-9]', '') = REGEXP_REPLACE(COALESCE(ac.whatsapp_number, ''), '[^0-9]', '')
          AND REGEXP_REPLACE(COALESCE(ac.whatsapp_number, ''), '[^0-9]', '') <> ''
         SET ac.whatsapp_instance_id = wi.id
         WHERE ac.whatsapp_instance_id IS NULL"
    );
    echo "  [ok] backfill: {$n} config(s) casada(s) por número\n";

    $d = $pdo->exec(
        "UPDATE agent_configs ac
         JOIN (SELECT whatsapp_instance_id, MAX(id) AS keep_id
                 FROM agent_configs
                WHERE whatsapp_instance_id IS NOT NULL
                GROUP BY whatsapp_instance_id) k
           ON k.whatsapp_instance_id = ac.whatsapp_instance_id
         SET ac.whatsapp_instance_id = NULL
         WHERE ac.id <> k.keep_id"
    );
    echo "  [ok] dedup: {$d} config(s) desvinculada(s) (1 agente por canal)\n";
} else {
    echo "  [skip] backfill (whatsapp_number já removido)\n";
}

// ── 3) Troca de UNIQUE (per-user -> per-canal) ──────────────────────────────
if ($hasIndex('agent_configs', 'uk_agent_account_user')) {
    $pdo->exec("ALTER TABLE agent_configs DROP INDEX uk_agent_account_user");
    echo "  [ok] DROP INDEX uk_agent_account_user\n";
} else { echo "  [skip] uk_agent_account_user já removido\n"; }

if (!$hasIndex('agent_configs', 'uk_agent_instance')) {
    $pdo->exec("ALTER TABLE agent_configs ADD UNIQUE KEY uk_agent_instance (whatsapp_instance_id)");
    echo "  [ok] ADD UNIQUE uk_agent_instance (whatsapp_instance_id)\n";
} else { echo "  [skip] uk_agent_instance já existe\n"; }

// ── 4) FK agent_configs.whatsapp_instance_id -> whatsapp_instances(id) ───────
if (!$hasFk('agent_configs', 'fk_agent_wpinstance')) {
    $pdo->exec("ALTER TABLE agent_configs
                ADD CONSTRAINT fk_agent_wpinstance FOREIGN KEY (whatsapp_instance_id)
                REFERENCES whatsapp_instances(id) ON DELETE SET NULL");
    echo "  [ok] ADD FK fk_agent_wpinstance (ON DELETE SET NULL)\n";
} else { echo "  [skip] FK fk_agent_wpinstance já existe\n"; }

// ── 5) Drop whatsapp_number (número vem por relacionamento) ──────────────────
if ($hasColumn('agent_configs', 'whatsapp_number')) {
    $pdo->exec("ALTER TABLE agent_configs DROP COLUMN whatsapp_number");
    echo "  [ok] DROP COLUMN whatsapp_number\n";
} else { echo "  [skip] whatsapp_number já removido\n"; }

// ── 6) whatsapp_instances UNIQUE(account_id, phone) com guarda de duplicata ──
echo "----\nTabela: whatsapp_instances\n";
if (!$hasIndex('whatsapp_instances', 'uk_instances_account_phone')) {
    $dup = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT account_id, phone FROM whatsapp_instances
             WHERE phone IS NOT NULL AND phone <> ''
             GROUP BY account_id, phone HAVING COUNT(*) > 1
         ) t"
    )->fetchColumn();
    if ($dup > 0) {
        echo "  [WARN] {$dup} número(s) duplicado(s); UNIQUE(account_id,phone) NÃO aplicada.\n";
        echo "         Resolva os duplicados em whatsapp_instances e rode de novo.\n";
    } else {
        $pdo->exec("ALTER TABLE whatsapp_instances ADD UNIQUE KEY uk_instances_account_phone (account_id, phone)");
        echo "  [ok] ADD UNIQUE uk_instances_account_phone (account_id, phone)\n";
    }
} else { echo "  [skip] uk_instances_account_phone já existe\n"; }

echo "== Migration 081 concluída ==\n";
