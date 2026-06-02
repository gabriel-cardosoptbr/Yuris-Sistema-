<?php
/**
 * API: /api/teams.php
 * CRUD de times dentro de uma conta (tenant-isolated).
 *
 * GET    /api/teams.php          → lista todos os times da conta
 * GET    /api/teams.php?id=5     → retorna time específico com membros
 * POST   /api/teams.php          → cria novo time
 * PUT    /api/teams.php          → atualiza time (nome, cor, descricao, members)
 * DELETE /api/teams.php?id=5     → soft-delete
 */

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Team.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';

use App\Models\Team;
use App\Helpers\AccountContext;

session_start();
header('Content-Type: application/json; charset=utf-8');

$ctx       = AccountContext::fromSession();
$accountId = $ctx->getAccountId();
$method    = $_SERVER['REQUEST_METHOD'];
$input     = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF para operações de escrita
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    // ?action=for_chat_filter → times visíveis para o usuário logado (dropdown do filtro de chats)
    // owner/admin vê todos; demais usuários veem apenas os times dos quais fazem parte
    if (($_GET['action'] ?? '') === 'for_chat_filter') {
        $teams = Team::getTeamsForUser($ctx->getUserId(), $accountId, $ctx->isOwnerOrAdmin());
        echo json_encode(['data' => $teams]);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if ($id) {
        $team = Team::findById($id, $accountId);
        if (!$team) { http_response_code(404); echo json_encode(['error' => 'Time não encontrado']); exit; }
        echo json_encode(['data' => $team]);
    } else {
        echo json_encode(['data' => Team::list($accountId)]);
    }
    exit;
}

// Apenas owner/admin pode criar/editar/excluir times
if (!$ctx->isOwnerOrAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas owner/admin pode gerenciar times']);
    exit;
}

/**
 * FIX (audit #25): valida que os user_ids enviados como membros pertencem ao
 * tenant antes de gravar. Antes, teams.php repassava o array cru pro
 * Team::setMembers — um payload forjado podia inserir user_id de OUTRA conta
 * em team_members. Agora cruzamos com getAccessibleUsers() (matriz + filiais
 * vinculadas), descartando qualquer id fora do escopo acessível.
 *
 * @param array $members  ids enviados pelo cliente
 * @return array<int>     subconjunto válido (apenas ids acessíveis), únicos
 */
function _sanitizeTeamMembers(array $members, \App\Helpers\AccountContext $ctx): array {
    // getAccessibleUsers(false): inclui inativos também — o seletor da UI pode
    // ter membros que ficaram inativos; a regra aqui é só de TENANT, não de status.
    $allowed = [];
    foreach ($ctx->getAccessibleUsers(false) as $u) {
        $allowed[(int)$u['id']] = true;
    }
    $valid = [];
    foreach ($members as $uid) {
        $uid = (int)$uid;
        if ($uid > 0 && isset($allowed[$uid])) $valid[$uid] = true;
    }
    return array_keys($valid);
}

// ── POST — criar ──────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $nome = trim($input['nome'] ?? '');
    if (!$nome) { http_response_code(400); echo json_encode(['error' => 'Nome é obrigatório']); exit; }

    $cor      = preg_match('/^#[0-9A-Fa-f]{6}$/', $input['cor'] ?? '') ? $input['cor'] : '#3B82F6';
    $descricao = trim($input['descricao'] ?? '') ?: null;

    $id = Team::create(['account_id' => $accountId, 'nome' => $nome, 'cor' => $cor, 'descricao' => $descricao]);

    // FIX (audit #25): só grava membros que pertencem ao tenant.
    $members = _sanitizeTeamMembers(is_array($input['members'] ?? null) ? $input['members'] : [], $ctx);
    Team::setMembers($id, $members);

    \App\Models\Account::audit($accountId, 'team.created', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'team',
        'entidade_id' => (int)$id,
        'detalhes'    => [
            'nome'      => $nome,
            'cor'       => $cor,
            'descricao' => $descricao,
            'members'   => $members,
        ],
    ]);

    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

// ── PUT — atualizar ───────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }

    $team = Team::findById($id, $accountId);
    if (!$team) { http_response_code(404); echo json_encode(['error' => 'Time não encontrado']); exit; }

    $data = [];
    if (isset($input['nome']))     $data['nome']     = trim($input['nome']);
    if (isset($input['cor']))      $data['cor']      = preg_match('/^#[0-9A-Fa-f]{6}$/', $input['cor']) ? $input['cor'] : $team['cor'];
    if (array_key_exists('descricao', $input)) $data['descricao'] = trim($input['descricao']) ?: null;

    Team::update($id, $data);

    // FIX (audit #25): valida os membros contra o tenant antes de gravar/auditar.
    $membersChanged = isset($input['members']) && is_array($input['members']);
    $validMembers   = $membersChanged ? _sanitizeTeamMembers($input['members'], $ctx) : [];
    if ($membersChanged) {
        Team::setMembers($id, $validMembers);
    }

    \App\Models\Account::audit($accountId, 'team.updated', [
        'user_id'     => $ctx->getUserId(),
        'entidade'    => 'team',
        'entidade_id' => $id,
        'dados_antes' => $team,
        'detalhes'    => $data + ($membersChanged ? ['members' => $validMembers] : []),
    ]);

    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE — excluir ──────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? ($input['id'] ?? 0));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }

    $team = Team::findById($id, $accountId);
    if (!$team) { http_response_code(404); echo json_encode(['error' => 'Time não encontrado']); exit; }

    $ok = Team::delete($id, $accountId);
    if ($ok) {
        \App\Models\Account::audit($accountId, 'team.deleted', [
            'user_id'     => $ctx->getUserId(),
            'entidade'    => 'team',
            'entidade_id' => $id,
            'dados_antes' => $team,
        ]);
    }
    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
