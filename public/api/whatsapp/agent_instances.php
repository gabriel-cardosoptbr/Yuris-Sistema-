<?php
/**
 * GET /api/whatsapp/agent_instances.php
 *
 * Lista, SOMENTE LEITURA e SANITIZADA, as instâncias WhatsApp que a sessão pode
 * vincular ao Agente de IA. É a fonte única da verdade do canal para a tela do
 * agente: ela NÃO cria, não conecta e não configura instância (isso continua em
 * Comunicação -> Chat WhatsApp).
 *
 * Escopo (decisão de negócio matriz/filial):
 *   - Matriz → instâncias próprias + das filiais vinculadas (getAccessibleAccountIds)
 *   - Filial → instâncias próprias + da matriz (getPipelineAccountId)
 *
 * NUNCA devolve credenciais: evolution_api_key/token, webhook_url, base_url, qr.
 * Só campos de exibição: id, nome interno, número, status, conta dona, tipo, última sync.
 *
 * Gate: owner/admin (configurar o canal do agente é ação de gestão).
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';

use App\Core\AccountContext;
use App\Core\Database;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();

if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner/admin pode configurar o canal do agente']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Escopo de contas cujas instâncias podem ser vinculadas ───────────────────
// Matriz: próprias + filiais. Filial: próprias + matriz (pipeline).
$scope = $ctx->getAccessibleAccountIds();
$matrizId = $ctx->getPipelineAccountId();      // matriz da filial (ou o próprio id)
if ($matrizId > 0 && !in_array($matrizId, $scope, true)) {
    $scope[] = $matrizId;
}
$scope = array_values(array_unique(array_filter(array_map('intval', $scope), fn($v) => $v > 0)));

if (empty($scope)) {
    echo json_encode(['ok' => true, 'instances' => [], 'count' => 0, 'current_account_id' => $accountId]);
    exit;
}

$ph = [];
$params = [];
foreach ($scope as $i => $aid) {
    $k = "acc_{$i}";
    $ph[] = ":{$k}";
    $params[$k] = $aid;
}

try {
    $pdo = Database::getConnection();
    // SELECT explícito de campos NÃO sensíveis (jamais evolution_token/webhook_url/qr).
    $sql =
        "SELECT i.id, i.account_id, i.instance_name, i.display_name,
                i.phone, i.status, i.updated_at,
                a.nome AS account_nome, a.tipo AS account_tipo
           FROM whatsapp_instances i
           INNER JOIN accounts a ON a.id = i.account_id
          WHERE i.account_id IN (" . implode(',', $ph) . ")
          ORDER BY CASE WHEN i.account_id = :own THEN 0 ELSE 1 END,
                   a.nome ASC, i.instance_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params + ['own' => $accountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $instances = array_map(function (array $r) use ($accountId) {
        return [
            'id'            => (int)$r['id'],
            'instance_name' => $r['instance_name'],          // nome interno do canal
            'display_name'  => $r['display_name'] ?: $r['instance_name'],
            'phone'         => $r['phone'],                  // número conectado (pode ser null)
            'status'        => $r['status'] ?: 'close',
            'connected'     => ($r['status'] === 'open'),
            'account_id'    => (int)$r['account_id'],
            'account_nome'  => $r['account_nome'],
            'account_tipo'  => $r['account_tipo'],           // matriz | filial | advogado
            'is_own'        => ((int)$r['account_id'] === $accountId),
            'updated_at'    => $r['updated_at'],             // última sincronização
        ];
    }, $rows);

    echo json_encode([
        'ok'                 => true,
        'instances'          => $instances,
        'count'              => count($instances),
        'current_account_id' => $accountId,
    ]);
} catch (\Throwable $e) {
    error_log('[agent_instances] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao listar canais']);
}
