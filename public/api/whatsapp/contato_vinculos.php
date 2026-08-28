<?php
use App\WhatsAppAgente\WhatsAppChannelAccessService;

ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;

session_start(['read_and_close' => true]);

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ctx       = AccountContext::fromSession();
// Auditoria 2026-06-01 BAIXA #24: usa o módulo canônico 'whatsapp' (coerente com
// media.php). Funcionalmente idêntico a 'chat' (mesmo conjunto matriz+filiais
// ativas), mas mantém os endpoints do pacote WhatsApp consistentes no escopo
// cross-conta. Fallback p/ conta atual evita IN () vazio caso o set venha vazio.
$tenantIds = $ctx->getAccessibleAccountIds('whatsapp');
if (empty($tenantIds)) $tenantIds = [$ctx->getAccountId()];

try {
    $pdo = \App\Core\Database::getConnection();

    // Gate de canal (Fase 2): exige 'view' em algum canal (próprio ou compartilhado
    // com a flag ligada). A RESOLUÇÃO do contato abaixo continua escopada pelas
    // contas acessíveis (CRM próprio do tenant) — a filial não enxerga o CRM da
    // matriz mesmo vendo o chat compartilhado; aqui só se garante o acesso ao canal.
    WhatsAppChannelAccessService::resolveForRequest($pdo, $ctx->getAccountId(), ($_GET['channel_id'] ?? null), 'view');

    $jid = trim($_GET['jid'] ?? '');

    if (!$jid) {
        echo json_encode(['ok' => false, 'error' => 'jid obrigatório']);
        exit;
    }

    // Cláusula IN para filtrar TUDO por tenant
    $ph = []; $tenantParams = [];
    foreach ($tenantIds as $i => $aid) {
        $k = "cvacc_{$i}";
        $ph[] = ":{$k}";
        $tenantParams[$k] = (int)$aid;
    }
    $tenantIn = '(' . implode(',', $ph) . ')';

    $contato = null;

    // ── Estratégia 1: telefone extraído do JID (@s.whatsapp.net) ─────────────
    $phone = preg_replace('/@.*$/', '', $jid);
    $phone = preg_replace('/[^0-9]/', '', $phone);

    if (strlen($phone) >= 10 && !str_ends_with($jid, '@lid')) {
        $stmt = $pdo->prepare(
            "SELECT * FROM contatos
             WHERE (telefone = :p1 OR telefone = CONCAT('55', :p2))
               AND account_id IN $tenantIn
             LIMIT 1"
        );
        $stmt->execute(['p1' => $phone, 'p2' => $phone] + $tenantParams);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Estratégia 2: remote_jid direto em contatos ──────────────────────────
    if (!$contato) {
        $stmt = $pdo->prepare(
            "SELECT * FROM contatos
             WHERE remote_jid = :jid AND account_id IN $tenantIn
             LIMIT 1"
        );
        $stmt->execute(['jid' => $jid] + $tenantParams);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Estratégia 3: whatsapp_chats.contato_id (com JOIN no instance pra tenant) ──
    if (!$contato) {
        $stmt = $pdo->prepare(
            "SELECT ct.* FROM whatsapp_chats wc
             INNER JOIN contatos ct           ON ct.id = wc.contato_id
             INNER JOIN whatsapp_instances wi ON wi.id = wc.instance_id
             WHERE wc.remote_jid = :jid
               AND wi.account_id IN $tenantIn
               AND ct.account_id IN $tenantIn
             LIMIT 1"
        );
        $stmt->execute(['jid' => $jid] + $tenantParams);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Estratégia 4: via card vinculado ──────────────────────────────────────
    if (!$contato) {
        $stmt = $pdo->prepare(
            "SELECT ct.* FROM whatsapp_chats wc
             INNER JOIN cards c               ON c.id  = wc.linked_card_id
             INNER JOIN contatos ct           ON ct.id = c.contato_id
             INNER JOIN whatsapp_instances wi ON wi.id = wc.instance_id
             WHERE wc.remote_jid = :jid
               AND wi.account_id IN $tenantIn
               AND c.account_id  IN $tenantIn
             LIMIT 1"
        );
        $stmt->execute(['jid' => $jid] + $tenantParams);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    if (!$contato) {
        echo json_encode(['ok' => true, 'contato' => null, 'vinculos' => []]);
        exit;
    }

    // Busca vínculos — o contato já foi validado como sendo do tenant
    $stmt = $pdo->prepare(
        'SELECT cv.tipo_vinculo, cv.referencia_id, cv.origem, cv.ativo
         FROM contato_vinculos cv
         WHERE cv.contato_id = ? AND cv.ativo = 1
         ORDER BY cv.tipo_vinculo, cv.referencia_id'
    );
    $stmt->execute([$contato['id']]);
    $vinculos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'       => true,
        'contato'  => $contato,
        'vinculos' => $vinculos,
    ]);

} catch (\Throwable $e) {
    // Auditoria 2026-06-01 MEDIA #32: nao vaza $e->getMessage() em prod
    // (antes devolvia a mensagem real no JSON). Loga server-side e devolve generico.
    require_once __DIR__ . '/../../../app/Core/ErrorReporter.php';
    \App\Core\ErrorReporter::handle($e);
}
