<?php
// Runner standalone da migration 089 (whatsapp_quoted_snapshot) para produção.
// Uso: docker exec -i yuris_app php /var/www/html/database/migrations/run_089.php
//
// 1) Adiciona 2 colunas em whatsapp_messages: quoted_sender_name + quoted_text
//    (snapshot da citação embutido no payload do WhatsApp).
// 2) BACKFILL idempotente: para mensagens de resposta JÁ existentes em que o
//    webhook antigo não capturou a citação (quoted_wamid NULL mas o raw_payload
//    tem contextInfo.stanzaId), preenche quoted_wamid + snapshot a partir do
//    raw_payload. Só toca linhas ainda não preenchidas — seguro reexecutar.

require_once __DIR__ . '/../../app/Models/Database.php';

use App\Models\Database;

$pdo = Database::getConnection();
echo "== Migration 089: whatsapp_quoted_snapshot ==\n";
echo "Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n----\n";

/** Adiciona uma coluna se ainda não existir (idempotente). */
$addCol = function (string $col, string $ddl) use ($pdo) {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'whatsapp_messages'
            AND COLUMN_NAME = ?"
    );
    $st->execute([$col]);
    if ((int)$st->fetchColumn() > 0) { echo "  [skip] coluna $col já existe\n"; return; }
    $pdo->exec($ddl);
    echo "  [ok] coluna $col adicionada\n";
};

$addCol('quoted_sender_name',
    "ALTER TABLE whatsapp_messages ADD COLUMN quoted_sender_name VARCHAR(255) NULL AFTER quoted_wamid");
$addCol('quoted_text',
    "ALTER TABLE whatsapp_messages ADD COLUMN quoted_text VARCHAR(500) NULL AFTER quoted_sender_name");

// ── Backfill das citações já existentes ────────────────────────────────────
// Mesma lógica do extractQuotedSnapshot() do webhook, em PHP (parse do JSON).
echo "----\nBackfill de citações antigas...\n";

$snapshotFrom = function (array $p): array {
    // Procura contextInfo: topo do payload → message.extendedTextMessage → subs de mídia
    $msgObj = $p['message'] ?? [];
    $ci = $p['contextInfo'] ?? ($msgObj['extendedTextMessage']['contextInfo'] ?? null);
    if (!$ci && is_array($msgObj)) {
        foreach (['imageMessage','videoMessage','audioMessage','stickerMessage','documentMessage'] as $sub) {
            if (!empty($msgObj[$sub]['contextInfo'])) { $ci = $msgObj[$sub]['contextInfo']; break; }
        }
    }
    if (!is_array($ci)) return [null, null, null];

    $stanza = $ci['stanzaId'] ?? null;

    $participant = $ci['participant'] ?? null;
    $sender = null;
    if ($participant && !str_contains((string)$participant, '@lid')) {
        $d = preg_replace('/\D/', '', explode('@', (string)$participant)[0]);
        if ($d !== '') $sender = $d;
    }

    $qm = $ci['quotedMessage'] ?? [];
    $text = null;
    if (is_array($qm)) {
        $text = $qm['conversation']
            ?? ($qm['extendedTextMessage']['text'] ?? null)
            ?? ($qm['imageMessage']['caption'] ?? null)
            ?? ($qm['videoMessage']['caption'] ?? null)
            ?? ($qm['documentMessage']['caption'] ?? null)
            ?? null;
        if ($text === null) {
            if (isset($qm['imageMessage']))        $text = '📷 Imagem';
            elseif (isset($qm['videoMessage']))    $text = '🎥 Vídeo';
            elseif (isset($qm['audioMessage']))    $text = '🎵 Áudio';
            elseif (isset($qm['documentMessage'])) $text = '📄 Documento';
            elseif (isset($qm['stickerMessage']))  $text = '✨ Sticker';
        }
    }
    if (is_string($text) && mb_strlen($text) > 480) $text = mb_substr($text, 0, 477) . '…';

    return [$stanza, $sender, $text];
};

// Candidatos: resposta sem quoted_wamid mas com stanzaId no raw_payload.
$rows = $pdo->query(
    "SELECT id, raw_payload FROM whatsapp_messages
      WHERE quoted_wamid IS NULL
        AND raw_payload IS NOT NULL
        AND raw_payload LIKE '%\"stanzaId\"%'"
)->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare(
    "UPDATE whatsapp_messages
        SET quoted_wamid = ?, quoted_sender_name = ?, quoted_text = ?
      WHERE id = ?"
);

$n = 0;
foreach ($rows as $r) {
    $p = json_decode((string)$r['raw_payload'], true);
    if (!is_array($p)) continue;
    [$stanza, $sender, $text] = $snapshotFrom($p);
    if (!$stanza) continue;
    $upd->execute([$stanza, $sender, $text, (int)$r['id']]);
    $n++;
}
echo "  [ok] $n citações antigas preenchidas (de " . count($rows) . " candidatas)\n";
echo "== Migration 089 concluída ==\n";
