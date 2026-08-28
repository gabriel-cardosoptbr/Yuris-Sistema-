<?php
/**
 * seed_webhook_events.php — popula webhook_events a partir do catalogo
 * hardcoded em WebhookDispatcher::catalog(). Idempotente (UPSERT por codigo).
 *
 * Uso: php database/seed_webhook_events.php
 *
 * Categorias mapeadas: cada grupo do catalog vira uma categoria;
 * o label do grupo vira prefixo na descricao quando aplicavel.
 *
 * Nao adiciona eventos novos (advogados, lgpd, security, etc.) — esses
 * entram nas etapas 10/11 do plano, junto com seus call sites.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Webhooks\WebhookDispatcher;

$pdo = Database::getConnection();

$catalog = WebhookDispatcher::catalog();

$insert = $pdo->prepare(
    "INSERT INTO webhook_events (codigo, nome, descricao, categoria, status, payload_exemplo, created_at, updated_at)
     VALUES (:codigo, :nome, :descricao, :categoria, 'active', :payload_exemplo, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
       nome            = VALUES(nome),
       descricao       = VALUES(descricao),
       categoria       = VALUES(categoria),
       payload_exemplo = VALUES(payload_exemplo),
       updated_at      = NOW()"
);

$inserted = 0;
$updated  = 0;

foreach ($catalog as $categoryKey => $group) {
    $catLabel = $group['label'] ?? $categoryKey;
    foreach ($group['events'] as $codigo => $nome) {
        $exemplo = WebhookDispatcher::buildPayload($codigo, [
            'entity'    => explode('.', $codigo)[0] ?? null,
            'entity_id' => 1,
            'data'      => ['exemplo' => true],
        ]);
        $insert->execute([
            'codigo'          => $codigo,
            'nome'            => $nome,
            'descricao'       => $catLabel . ' — ' . $nome,
            'categoria'       => $categoryKey,
            'payload_exemplo' => json_encode($exemplo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if ($insert->rowCount() === 1) {
            $inserted++;
        } else {
            $updated++;
        }
    }
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM webhook_events")->fetchColumn();

echo "Seed webhook_events concluido.\n";
echo "  inseridos: {$inserted}\n";
echo "  atualizados: {$updated}\n";
echo "  total na tabela: {$total}\n";
