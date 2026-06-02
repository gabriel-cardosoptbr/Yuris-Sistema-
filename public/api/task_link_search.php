<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx = AccountContext::fromSession();

$type  = $_GET['type']  ?? '';
$q     = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 15), 50);
$like  = '%' . $q . '%';
$pdo   = \App\Models\Database::getConnection();
$items = [];

// Monta uma cláusula IN escopada por módulo. O escopo de tarefas (BAIXA #19)
// respeita sync_tarefas das filiais; o de clientes (MEDIA #21) respeita o
// módulo Clientes — cada fonte vinculável usa o módulo da sua própria tela.
$buildTenantIn = function (string $modulo, string $prefix) use ($ctx): array {
    $ids = $ctx->getAccessibleAccountIds($modulo);
    $ph = []; $params = [];
    foreach ($ids as $i => $aid) {
        $k = "{$prefix}_{$i}";
        $ph[] = ":{$k}";
        $params[$k] = (int)$aid;
    }
    // Se por algum motivo a lista vier vazia, força IN (0) pra não vazar tudo.
    if (!$ph) { $ph[] = ':' . $prefix . '_none'; $params[$prefix . '_none'] = 0; }
    return ['(' . implode(',', $ph) . ')', $params];
};

try {
    switch ($type) {
        case 'contato':
            // BAIXA #19: escopa com o módulo 'tarefas' (antes era sem módulo).
            [$tenantIn, $tenantParams] = $buildTenantIn('tarefas', 'tls');
            $stmt = $pdo->prepare("
                SELECT id,
                       nome AS label,
                       COALESCE(telefone, email, '') AS sub
                FROM contatos
                WHERE (nome LIKE :q1 OR telefone LIKE :q2 OR email LIKE :q3)
                  AND account_id IN $tenantIn
                ORDER BY nome
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like] + $tenantParams);
            break;

        case 'cliente':
            // MEDIA #21: clientes da aba Clientes como fonte vinculável a tarefas.
            // Escopo = módulo 'clientes' (não 'tarefas'), espelhando api/clientes.php.
            [$tenantIn, $tenantParams] = $buildTenantIn('clientes', 'tls');
            $stmt = $pdo->prepare("
                SELECT id,
                       nome AS label,
                       COALESCE(NULLIF(cpf_cnpj,''), NULLIF(telefone,''), NULLIF(whatsapp,''), NULLIF(email,''), '') AS sub
                FROM clientes
                WHERE deleted_at IS NULL
                  AND (nome LIKE :q1 OR cpf_cnpj LIKE :q2 OR telefone LIKE :q3 OR whatsapp LIKE :q4 OR email LIKE :q5)
                  AND account_id IN $tenantIn
                ORDER BY nome
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like] + $tenantParams);
            break;

        case 'processo':
            // Label: número do processo (humano). Se faltar, usa cliente — nunca expõe ID interno.
            [$tenantIn, $tenantParams] = $buildTenantIn('tarefas', 'tls');
            $stmt = $pdo->prepare("
                SELECT id,
                       COALESCE(NULLIF(numero,''), NULLIF(cliente_nome,''), 'Processo sem número') AS label,
                       COALESCE(cliente_nome, '') AS sub
                FROM processos
                WHERE deleted_at IS NULL
                  AND (numero LIKE :q1 OR cliente_nome LIKE :q2 OR tipo_acao LIKE :q3)
                  AND account_id IN $tenantIn
                ORDER BY updated_at DESC
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like] + $tenantParams);
            break;

        case 'card':
            // Label: nome do cliente/lead. Se faltar, tenta empresa — nunca expõe ID interno.
            [$tenantIn, $tenantParams] = $buildTenantIn('tarefas', 'tls');
            $stmt = $pdo->prepare("
                SELECT id,
                       COALESCE(NULLIF(cliente_nome,''), NULLIF(empresa_nome,''), NULLIF(titulo,''), 'Lead sem nome') AS label,
                       COALESCE(empresa_nome, '') AS sub
                FROM cards
                WHERE deleted_at IS NULL
                  AND (cliente_nome LIKE :q1 OR empresa_nome LIKE :q2)
                  AND account_id IN $tenantIn
                ORDER BY updated_at DESC
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like] + $tenantParams);
            break;

        case 'dre_account':
            [$tenantIn, $tenantParams] = $buildTenantIn('tarefas', 'tls');
            $stmt = $pdo->prepare("
                SELECT id,
                       nome AS label,
                       tipo AS sub
                FROM dre_accounts
                WHERE (nome LIKE :q1 OR codigo LIKE :q2)
                  AND account_id IN $tenantIn
                ORDER BY nome
                LIMIT $limit
            ");
            $stmt->execute(['q1' => $like, 'q2' => $like] + $tenantParams);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Tipo inválido']);
            exit;
    }

    $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $items]);

} catch (\Throwable $e) {
    // NAO vaza a mensagem da exceção em prod (regra canônica 4): loga server-side
    // e devolve mensagem genérica.
    error_log('[task_link_search] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro ao buscar vínculos']);
}
