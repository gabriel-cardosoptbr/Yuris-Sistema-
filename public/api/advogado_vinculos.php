<?php
/**
 * API: /api/advogado_vinculos.php
 * Gerencia vínculos de CONTA entre matriz/filial (host) e advogado associado.
 *
 * Espelha account_vinculos mas pra advogado:
 *   - host = matriz OU filial
 *   - advogado = conta tipo='advogado'
 *   - sync_enabled + sync_cards/processos/tarefas (mesma estrutura)
 *
 * GET    /api/advogado_vinculos.php
 *          → lista vínculos da conta atual (como host OU como advogado)
 * POST   /api/advogado_vinculos.php
 *          Body: { codigo_advogado: "ADV-XXXXXX", csrf_token: "..." }
 *          → host solicita o vínculo (cria como 'pending'; advogado precisa
 *            aprovar antes de qualquer dado ser compartilhado)
 * PATCH  /api/advogado_vinculos.php
 *          Body: { id: 5, action: "aprovar"|"rejeitar"|"suspender"|"reativar"|"update_sync"
 *                  motivo?: "...", sync_enabled?, sync_cards?, sync_processos?, sync_tarefas? }
 * DELETE /api/advogado_vinculos.php?id=5
 *          → cancela vínculo (qualquer lado pode)
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Master\Account;
use App\Usuarios\AdvogadoVinculo;
use App\Master\AccountNotification;
use App\Core\AccountContext;
use App\Billing\PlanFeature;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx    = AccountContext::fromSession();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET — lista vínculos da conta atual
//   Se conta é matriz/filial → lista advogados vinculados (host view)
//   Se conta é advogado      → lista matrizes/filiais que a vincularam
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $tipo = $ctx->getAccountTipo();
    $vinculos = $tipo === 'advogado'
        ? AdvogadoVinculo::listByAdvogado($ctx->getAccountId())
        : AdvogadoVinculo::listByHost($ctx->getAccountId());
    echo json_encode(['data' => $vinculos]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — host vincula advogado via código ADV-XXXXXX
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    // Módulo por plano: vincular advogado associado não entra no plano Solo.
    // Quem paga pelo recurso é o HOST (quem cria o vínculo), por isso a checagem
    // fica só aqui no POST e não no GET/PATCH (o advogado do outro lado precisa
    // conseguir aprovar/recusar independente do plano dele).
    PlanFeature::assertEnabled($ctx->getAccountId(), PlanFeature::F_ADVOGADOS);

    $codigoAdvogado = trim($input['codigo_advogado'] ?? '');

    if ($codigoAdvogado === '') {
        http_response_code(400);
        echo json_encode(['error' => 'codigo_advogado é obrigatório']);
        exit;
    }

    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode vincular advogados']);
        exit;
    }

    // Conta atual NÃO pode ser advogado (advogado não convida advogado)
    if ($ctx->getAccountTipo() === 'advogado') {
        http_response_code(400);
        echo json_encode(['error' => 'Contas do tipo advogado não podem vincular outros advogados aqui']);
        exit;
    }

    // Busca em 3 lugares (advogado solo costuma compartilhar QUALQUER um deles):
    //   1. users.codigo_advogado (ADV-XXXXXX) — id universal de advogado
    //   2. users.codigo_vinculo  (xxxx-xxxx-xxxx-xxxx) — código pessoal
    //   3. accounts.codigo_vinculo — código de vínculo da conta tipo='advogado'
    // Em todos os casos, resolve para a conta tipo='advogado' do advogado.
    $pdo = \App\Core\Database::getConnection();

    // Tentativa 1+2: por código em users
    $stmt = $pdo->prepare(
        "SELECT a.id, a.nome, a.tipo, a.status, u.oab
           FROM users u
           INNER JOIN accounts a ON a.id = u.account_id
          WHERE (u.codigo_advogado = :cod OR u.codigo_vinculo = :cod)
            AND u.deleted_at IS NULL
            AND a.tipo = 'advogado'
            AND a.deleted_at IS NULL
            AND a.status IN ('active','trial','overdue')
          LIMIT 1"
    );
    $stmt->execute(['cod' => $codigoAdvogado]);
    $advogado = $stmt->fetch(\PDO::FETCH_ASSOC);

    // Tentativa 3: por accounts.codigo_vinculo direto
    if (!$advogado) {
        $stmt2 = $pdo->prepare(
            "SELECT a.id, a.nome, a.tipo, a.status,
                    (SELECT u.oab FROM users u
                       WHERE u.account_id = a.id AND u.oab IS NOT NULL AND u.oab <> ''
                         AND u.deleted_at IS NULL LIMIT 1) AS oab
               FROM accounts a
              WHERE a.codigo_vinculo = :cod
                AND a.tipo = 'advogado'
                AND a.deleted_at IS NULL
                AND a.status IN ('active','trial','overdue')
              LIMIT 1"
        );
        $stmt2->execute(['cod' => $codigoAdvogado]);
        $advogado = $stmt2->fetch(\PDO::FETCH_ASSOC);
    }

    if (!$advogado) {
        http_response_code(404);
        echo json_encode(['error' => 'Código inválido. Aceita: ADV-XXXXXX (código do advogado), código pessoal do user, ou código de vínculo da conta advogado.']);
        exit;
    }

    if ((int)$advogado['id'] === $ctx->getAccountId()) {
        http_response_code(400);
        echo json_encode(['error' => 'Uma conta não pode se vincular a si mesma']);
        exit;
    }

    // Cria vínculo como 'pending' (consentimento do advogado é obrigatório).
    // SEGURANÇA: não ativar automaticamente — enquanto pending, sync_* ficam 0
    // e getAccessibleAccountIds (que exige status='active' AND sync_enabled=1)
    // NÃO expõe cards/processos/tarefas do advogado à host. O advogado precisa
    // aprovar (PATCH action=aprovar) para o vínculo virar 'active'.
    // Idempotente: se já existe pending/active, retorna o ID existente.
    $vinculoId = AdvogadoVinculo::solicitar(
        $ctx->getAccountId(),
        (int)$advogado['id'],
        $ctx->getUserId(),
        false // não auto-ativar: aguarda consentimento do advogado
    );

    // Notifica o advogado
    $advAdmins = $pdo->prepare(
        "SELECT id FROM users WHERE account_id = :acc AND role IN ('owner','admin') AND deleted_at IS NULL"
    );
    $advAdmins->execute(['acc' => $advogado['id']]);
    $admins = $advAdmins->fetchAll(\PDO::FETCH_COLUMN);

    $hostAccount = Account::findById($ctx->getAccountId());
    foreach ($admins as $adminId) {
        AccountNotification::criar([
            'account_id' => (int)$advogado['id'],
            'user_id'    => $adminId,
            'tipo'       => 'advogado_vinculo.criado',
            'titulo'     => "Solicitação de vínculo de {$hostAccount['nome']}",
            'mensagem'   => "A conta \"{$hostAccount['nome']}\" solicitou um vínculo com você. Enquanto você não aprovar, ela NÃO terá acesso aos seus cards, processos ou tarefas. Acesse Configurações → Vínculos para aprovar ou recusar.",
            'payload'    => ['vinculo_id' => $vinculoId, 'host_account_id' => $ctx->getAccountId()],
        ]);
    }

    Account::audit($ctx->getAccountId(), 'advogado_vinculo.criado', [
        'user_id'  => $ctx->getUserId(),
        'detalhes' => ['vinculo_id' => $vinculoId, 'advogado_id' => $advogado['id']],
    ]);

    // Reporta o status real do vínculo (normalmente 'pending'; mas se já existia
    // um vínculo 'active' a chamada é idempotente e devolve o estado corrente).
    $vinculoAtual = AdvogadoVinculo::findById($vinculoId);
    $statusAtual  = $vinculoAtual['status'] ?? 'pending';

    echo json_encode([
        'success'      => true,
        'vinculo_id'   => $vinculoId,
        'status'       => $statusAtual,
        'pending'      => $statusAtual === 'pending',
        'message'      => $statusAtual === 'pending'
            ? 'Solicitação enviada. O advogado precisa aprovar antes de qualquer dado ser compartilhado.'
            : 'Vínculo já existente.',
        'advogado'     => [
            'id'   => (int)$advogado['id'],
            'nome' => $advogado['nome'],
            'oab'  => $advogado['oab'] ?? null,
        ],
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PATCH — aprovar / rejeitar / suspender / reativar / update_sync
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $vinculoId = (int) ($input['id'] ?? 0);
    $action    = $input['action'] ?? '';
    $motivo    = $input['motivo'] ?? '';

    if (!$vinculoId || !$action) {
        http_response_code(400);
        echo json_encode(['error' => 'id e action são obrigatórios']);
        exit;
    }

    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode alterar vínculos']);
        exit;
    }

    $vinculo = AdvogadoVinculo::findById($vinculoId);
    if (!$vinculo) {
        http_response_code(404);
        echo json_encode(['error' => 'Vínculo não encontrado']);
        exit;
    }

    // Permissão: apenas a HOST (matriz/filial) pode gerenciar suspender/sync.
    // O ADVOGADO aprova/rejeita o vínculo enquanto pending (consentimento
    // obrigatório antes de qualquer sincronização de dados).
    $hostId = (int)$vinculo['host_account_id'];
    $advId  = (int)$vinculo['advogado_account_id'];
    $isHost = $hostId === $ctx->getAccountId();
    $isAdv  = $advId  === $ctx->getAccountId();

    if (!$isHost && !$isAdv) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão sobre este vínculo']);
        exit;
    }

    $ok = false;
    switch ($action) {
        case 'aprovar':
            // Só faz sentido pro advogado aprovar quando pending
            if (!$isAdv) { http_response_code(403); echo json_encode(['error' => 'Apenas o advogado pode aprovar']); exit; }
            $ok = AdvogadoVinculo::aprovar($vinculoId, $ctx->getUserId());
            if ($ok) {
                AccountNotification::criar([
                    'account_id' => $hostId,
                    'user_id'    => null,
                    'tipo'       => 'advogado_vinculo.aprovado',
                    'titulo'     => 'Advogado aceitou o vínculo',
                    'mensagem'   => 'O advogado aceitou o vínculo. A sincronização começa desligada — habilite os módulos (cards/processos/tarefas) em Sincronização.',
                    'payload'    => ['vinculo_id' => $vinculoId],
                ]);
            }
            break;

        case 'rejeitar':
            if (!$isAdv) { http_response_code(403); echo json_encode(['error' => 'Apenas o advogado pode rejeitar']); exit; }
            $ok = AdvogadoVinculo::rejeitar($vinculoId, $ctx->getUserId());
            break;

        case 'suspender':
            if (!$isHost) { http_response_code(403); echo json_encode(['error' => 'Apenas a host pode suspender']); exit; }
            $ok = AdvogadoVinculo::suspender($vinculoId, $ctx->getUserId(), $motivo);
            if ($ok) {
                AccountNotification::criar([
                    'account_id' => $advId,
                    'user_id'    => null,
                    'tipo'       => 'advogado_vinculo.suspenso',
                    'titulo'     => 'Vínculo suspenso',
                    'mensagem'   => $motivo ?: 'Seu vínculo foi suspenso.',
                    'payload'    => ['vinculo_id' => $vinculoId],
                ]);
            }
            break;

        case 'reativar':
            if (!$isHost) { http_response_code(403); echo json_encode(['error' => 'Apenas a host pode reativar']); exit; }
            $ok = AdvogadoVinculo::reativar($vinculoId);
            break;

        case 'update_sync':
            // Sync flags só pela host. Vínculo precisa estar 'active'.
            if (!$isHost) { http_response_code(403); echo json_encode(['error' => 'Apenas a host configura sincronização']); exit; }
            if (($vinculo['status'] ?? '') !== 'active') {
                http_response_code(409);
                echo json_encode(['error' => 'Vínculo precisa estar ativo para configurar sincronização']);
                exit;
            }
            $ok = AdvogadoVinculo::updateSync($vinculoId, [
                'sync_enabled'   => !empty($input['sync_enabled']),
                'sync_cards'     => !empty($input['sync_cards']),
                'sync_processos' => !empty($input['sync_processos']),
                'sync_tarefas'   => !empty($input['sync_tarefas']),
            ]);
            if ($ok) {
                Account::audit($ctx->getAccountId(), 'advogado_vinculo.sync_atualizada', [
                    'user_id'  => $ctx->getUserId(),
                    'detalhes' => [
                        'vinculo_id'     => $vinculoId,
                        'sync_enabled'   => (int)!empty($input['sync_enabled']),
                        'sync_cards'     => (int)!empty($input['sync_cards']),
                        'sync_processos' => (int)!empty($input['sync_processos']),
                        'sync_tarefas'   => (int)!empty($input['sync_tarefas']),
                    ],
                ]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'action inválido: ' . $action]);
            exit;
    }

    Account::audit($ctx->getAccountId(), 'advogado_vinculo.' . $action, [
        'user_id'  => $ctx->getUserId(),
        'detalhes' => ['vinculo_id' => $vinculoId, 'motivo' => $motivo],
    ]);

    echo json_encode(['success' => $ok]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE — cancela vínculo (qualquer lado)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }

    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Apenas owner/admin pode cancelar vínculos']);
        exit;
    }

    $vinculo = AdvogadoVinculo::findById($id);
    if (!$vinculo) { http_response_code(404); echo json_encode(['error' => 'Vínculo não encontrado']); exit; }

    $isRelated = in_array($ctx->getAccountId(), [(int)$vinculo['host_account_id'], (int)$vinculo['advogado_account_id']]);
    if (!$isRelated) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão para cancelar este vínculo']);
        exit;
    }

    $pdo  = \App\Core\Database::getConnection();
    $stmt = $pdo->prepare("UPDATE advogado_vinculos SET status = 'rejected', updated_at = NOW() WHERE id = :id");
    $ok   = $stmt->execute(['id' => $id]);

    Account::audit($ctx->getAccountId(), 'advogado_vinculo.cancelado', [
        'user_id'  => $ctx->getUserId(),
        'detalhes' => ['vinculo_id' => $id],
    ]);

    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
