<?php
/**
 * /api/chat/conversas.php  — v2 (multi-tenant)
 *
 * GET  ?action=list               → lista conversas ativas do usuário
 * GET  ?action=list&arquivadas=1  → lista conversas arquivadas
 * GET  ?action=get&id=X           → detalhes de uma conversa
 * GET  ?action=modal_data         → dados para o modal de criação (usuários, setores, vínculos)
 *
 * POST action=create_direct       → conversa direta entre 2 usuários
 * POST action=create_group        → grupo com nome + múltiplos usuários
 * POST action=create_setor        → canal do setor (adiciona todos os membros do team)
 * POST action=create_vinculada    → conversa cross-account (filial ↔ matriz)
 * POST action=create_advogado     → conversa com advogado associado (convite aceito)
 * POST action=mark_read           → marca conversa como lida
 * POST action=update_participants → adiciona/remove participantes (grupos/setor)
 * POST action=archive             → arquiva conversa
 * POST action=unarchive           → desarquiva conversa
 * POST action=delete              → soft-delete da conversa
 *
 * SEGURANÇA:
 *   account_id NUNCA aceito do frontend — sempre lido da sessão via AccountContext
 *   CSRF validado em todos os POSTs
 *   Participação verificada antes de qualquer leitura/escrita
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Helpers\AccountContext;

header('Content-Type: application/json; charset=utf-8');

$ctx  = AccountContext::fromSession();
$uid  = $ctx->getUserId();
$aid  = $ctx->getAccountId();
$csrf = $_SESSION['csrf_token'] ?? '';
session_write_close(); // libera lock de sessão (evita bloqueio no polling)

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = [];

// ─── LGPD P1 (2B.2) — Validação de participantes cross-tenant ────────────────
// Antes desta correção, create_direct/create_group/update_participants
// validavam apenas existência do user_id (SELECT id FROM users WHERE id = ?).
// Atacante podia adicionar usuário de OUTRO tenant na conversa, que então
// receberia mensagens e poderia enviar como participante legítimo.
// Esta função restringe a usuários:
//   1. Da própria conta OU filiais sync ativas
//   2. OU advogados associados com convite aceito pela conta atual
// Retorna array dos IDs válidos (subset do input). IDs inválidos são DROPADOS.
function _validParticipantIds(\PDO $pdo, array $userIds, \App\Helpers\AccountContext $ctx): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
    if (empty($userIds)) return [];

    $accessibleAccountIds = $ctx->getAccessibleAccountIds();
    $myAccountId          = $ctx->getAccountId();

    if (empty($accessibleAccountIds)) return [];

    // Placeholders dinâmicos
    $accPh = []; $uPh = []; $params = [];
    foreach ($accessibleAccountIds as $i => $aid) { $k = "vacc{$i}"; $accPh[] = ":{$k}"; $params[$k] = (int)$aid; }
    foreach ($userIds as $i => $uid) { $k = "vu{$i}"; $uPh[] = ":{$k}"; $params[$k] = (int)$uid; }

    // (1) Usuários dos tenants acessíveis
    $sqlOwn = "SELECT id FROM users
               WHERE id IN (" . implode(',', $uPh) . ")
                 AND account_id IN (" . implode(',', $accPh) . ")
                 AND deleted_at IS NULL";
    $st = $pdo->prepare($sqlOwn);
    $st->execute($params);
    $valid = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));

    // (2) Advogados associados convidados pela conta atual (cobertura cross-tenant legítima)
    $remaining = array_diff($userIds, $valid);
    if (!empty($remaining)) {
        $rPh = []; $rParams = ['from_acc' => $myAccountId];
        foreach ($remaining as $i => $uid) { $k = "vra{$i}"; $rPh[] = ":{$k}"; $rParams[$k] = (int)$uid; }
        $sqlAdv = "SELECT DISTINCT convidado_user_id FROM advogado_convites
                   WHERE from_account_id = :from_acc
                     AND status = 'accepted'
                     AND convidado_user_id IN (" . implode(',', $rPh) . ")";
        $st2 = $pdo->prepare($sqlAdv);
        $st2->execute($rParams);
        $extra = array_map('intval', $st2->fetchAll(\PDO::FETCH_COLUMN));
        $valid = array_merge($valid, $extra);
    }

    return array_values(array_unique($valid));
}
// ────────────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $action;
    if (empty($body['_csrf']) || $body['_csrf'] !== $csrf) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF inválido']);
        exit;
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function checkParticipant(PDO $pdo, int $convId, int $uid): bool
{
    $s = $pdo->prepare('SELECT 1 FROM chat_participantes WHERE conversa_id = ? AND usuario_id = ? LIMIT 1');
    $s->execute([$convId, $uid]);
    return (bool) $s->fetchColumn();
}

function getConvRow(PDO $pdo, int $convId, int $aid): ?array
{
    $s = $pdo->prepare(
        'SELECT * FROM chat_conversas WHERE id = ? AND account_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $s->execute([$convId, $aid]);
    $r = $s->fetch();
    return $r ?: null;
}

// ── Garante canal Geral da conta ───────────────────────────────────────────
function ensureCanalGeral(PDO $pdo, int $uid, int $aid): void
{
    $exists = $pdo->prepare(
        "SELECT id FROM chat_conversas WHERE tipo = 'geral' AND account_id = ? AND deleted_at IS NULL LIMIT 1"
    );
    $exists->execute([$aid]);
    if ($exists->fetchColumn()) return;

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO chat_conversas (account_id, nome, tipo, canal_geral_key, criado_por)
         VALUES (?, 'Canal Geral', 'geral', ?, ?)"
    );
    $ins->execute([$aid, (string) $aid, $uid]);
    $convId = (int) $pdo->lastInsertId();
    if (!$convId) return;

    $users = $pdo->prepare(
        "SELECT id FROM users WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL"
    );
    $users->execute([$aid]);
    $stmt = $pdo->prepare('INSERT IGNORE INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)');
    foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $stmt->execute([$convId, $u]);
    }
}

ensureCanalGeral($pdo, $uid, $aid);

// ══════════════════════════════════════════════════════════════════════════════
// GET: list
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'list') {
    $arquivadas    = !empty($_GET['arquivadas']);
    $archiveFilter = $arquivadas ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL';

    $stmt = $pdo->prepare(
        "SELECT c.id, c.nome, c.tipo, c.team_id, c.ref_account_id, c.updated_at, c.archived_at,
                CASE
                  WHEN c.tipo = 'direto'
                  THEN (SELECT u2.nome FROM chat_participantes p2
                        JOIN users u2 ON u2.id = p2.usuario_id
                        WHERE p2.conversa_id = c.id AND p2.usuario_id <> ? LIMIT 1)
                  ELSE c.nome
                END AS display_nome,
                (SELECT m.mensagem FROM chat_mensagens m
                 WHERE m.conversa_id = c.id ORDER BY m.id DESC LIMIT 1) AS ultima_msg,
                (SELECT COUNT(*) FROM chat_mensagens m
                 WHERE m.conversa_id = c.id
                   AND m.usuario_id <> ?
                   AND m.id > COALESCE(
                       (SELECT p3.ultima_leitura_id FROM chat_participantes p3
                        WHERE p3.conversa_id = c.id AND p3.usuario_id = ? LIMIT 1), 0)
                ) AS nao_lidas
         FROM chat_conversas c
         JOIN chat_participantes p ON p.conversa_id = c.id AND p.usuario_id = ?
         WHERE c.deleted_at IS NULL AND {$archiveFilter}
         ORDER BY c.updated_at DESC
         LIMIT 150"
    );
    $stmt->execute([$uid, $uid, $uid, $uid]);
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// GET: get
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id obrigatório']); exit; }
    if (!checkParticipant($pdo, $id, $uid)) {
        http_response_code(403); echo json_encode(['error' => 'Acesso negado']); exit;
    }

    $conv = $pdo->prepare('SELECT * FROM chat_conversas WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $conv->execute([$id]);
    $conv = $conv->fetch();
    if (!$conv) { http_response_code(404); echo json_encode(['error' => 'Não encontrada']); exit; }

    $parts = $pdo->prepare(
        'SELECT u.id, u.nome, u.account_id,
                (SELECT a.nome FROM accounts a WHERE a.id = u.account_id LIMIT 1) AS account_nome
         FROM chat_participantes p
         JOIN users u ON u.id = p.usuario_id
         WHERE p.conversa_id = ?'
    );
    $parts->execute([$id]);
    $conv['participantes'] = $parts->fetchAll();

    if ($conv['team_id']) {
        $team = $pdo->prepare('SELECT id, nome, cor FROM teams WHERE id = ? LIMIT 1');
        $team->execute([$conv['team_id']]);
        $conv['team'] = $team->fetch() ?: null;
    }
    if ($conv['ref_account_id']) {
        $refAcc = $pdo->prepare('SELECT nome FROM accounts WHERE id = ? LIMIT 1');
        $refAcc->execute([$conv['ref_account_id']]);
        $conv['ref_account_nome'] = $refAcc->fetchColumn() ?: null;
    }

    echo json_encode(['ok' => true, 'data' => $conv]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// GET: modal_data — tudo que o frontend precisa para o modal de criação
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'modal_data') {
    $accountTipo = $ctx->getAccountTipo();

    // Usuários da mesma conta — JOIN com accounts pra trazer info da org
    // (necessário para sub-agrupar matriz/filial no UI quando o escritório tem
    // múltiplas contas vinculadas)
    $usersStmt = $pdo->prepare(
        "SELECT u.id, u.nome, u.account_id,
                a.nome AS account_nome, a.tipo AS account_tipo
           FROM users u
           LEFT JOIN accounts a ON a.id = u.account_id
          WHERE u.account_id = ? AND u.id <> ? AND u.status = 'active' AND u.deleted_at IS NULL
          ORDER BY u.nome"
    );
    $usersStmt->execute([$aid, $uid]);
    $users = $usersStmt->fetchAll();

    // Setores — busca times da conta
    $teamsStmt = $pdo->prepare(
        "SELECT t.id, t.nome, t.cor
         FROM teams t
         WHERE t.account_id = ? AND t.deleted_at IS NULL
         ORDER BY t.nome"
    );
    $teamsStmt->execute([$aid]);
    $teams = $teamsStmt->fetchAll();

    // Membros dos setores com nome completo (inclui o usuário logado)
    if ($teams) {
        $teamIds    = array_column($teams, 'id');
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $mStmt = $pdo->prepare(
            "SELECT tm.team_id, tm.user_id AS id, u.nome
             FROM team_members tm
             JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id IN ($placeholders)
               AND u.account_id = ?
               AND u.status = 'active'
               AND u.deleted_at IS NULL
             ORDER BY u.nome ASC"
        );
        $mStmt->execute(array_merge($teamIds, [$aid]));
        $allTmRows = $mStmt->fetchAll();

        // Indexa membros por team_id
        $membersByTeam = [];
        foreach ($allTmRows as $row) {
            $membersByTeam[(int)$row['team_id']][] = [
                'id'   => (int)$row['id'],
                'nome' => $row['nome'],
            ];
        }
        foreach ($teams as &$t) {
            $tid              = (int)$t['id'];
            $t['members']     = $membersByTeam[$tid] ?? [];
            $t['member_ids']  = array_column($t['members'], 'id'); // backward-compat
        }
        unset($t);
    }

    // Contas vinculadas ativas
    $linkedAccounts = [];
    if ($accountTipo === 'matriz') {
        $lnk = $pdo->prepare(
            "SELECT a.id, a.nome, a.tipo FROM account_vinculos v
             JOIN accounts a ON a.id = v.filial_account_id
             WHERE v.matriz_account_id = ? AND v.status = 'active' AND a.status = 'active'
             ORDER BY a.nome"
        );
        $lnk->execute([$aid]);
    } else {
        $lnk = $pdo->prepare(
            "SELECT a.id, a.nome, a.tipo FROM account_vinculos v
             JOIN accounts a ON a.id = v.matriz_account_id
             WHERE v.filial_account_id = ? AND v.status = 'active' AND a.status = 'active'
             ORDER BY a.nome"
        );
        $lnk->execute([$aid]);
    }
    $linkedAccounts = $lnk->fetchAll();

    // Usuários de cada conta vinculada (inclui account_tipo pra agrupamento no UI)
    $linkedUsers = [];
    foreach ($linkedAccounts as $la) {
        $lu = $pdo->prepare(
            "SELECT id, nome, ? AS account_id, ? AS account_nome, ? AS account_tipo
             FROM users WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY nome"
        );
        $lu->execute([$la['id'], $la['nome'], $la['tipo'], $la['id']]);
        $linkedUsers[(int) $la['id']] = $lu->fetchAll();
    }

    // Advogados com convite aceito
    $advStmt = $pdo->prepare(
        "SELECT ac.id, ac.convidado_nome, ac.convidado_email, ac.convidado_user_id,
                COALESCE(u.nome, ac.convidado_nome) AS user_nome
         FROM advogado_convites ac
         LEFT JOIN users u ON u.id = ac.convidado_user_id
         WHERE ac.from_account_id = ? AND ac.status = 'accepted' AND ac.convidado_user_id IS NOT NULL
         ORDER BY ac.convidado_nome"
    );
    $advStmt->execute([$aid]);
    $advogados = $advStmt->fetchAll();

    echo json_encode([
        'ok'              => true,
        'account_tipo'    => $accountTipo,
        'users'           => $users,
        'teams'           => $teams,
        'linked_accounts' => $linkedAccounts,
        'linked_users'    => $linkedUsers,
        'advogados'       => $advogados,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: create_direct
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'create_direct') {
    $otherId = (int) ($body['usuario_id'] ?? 0);
    if (!$otherId || $otherId === $uid) {
        http_response_code(400); echo json_encode(['error' => 'usuario_id inválido']); exit;
    }
    // P1 LGPD (2B.2): valida tenant — não basta o user existir
    $validIds = _validParticipantIds($pdo, [$otherId], $ctx);
    if (empty($validIds)) {
        http_response_code(403);
        echo json_encode(['error' => 'Usuário não pertence ao seu tenant ou não é advogado associado convidado.']);
        exit;
    }

    $existing = $pdo->prepare(
        "SELECT c.id FROM chat_conversas c
         JOIN chat_participantes p1 ON p1.conversa_id = c.id AND p1.usuario_id = ?
         JOIN chat_participantes p2 ON p2.conversa_id = c.id AND p2.usuario_id = ?
         WHERE c.tipo = 'direto' AND c.deleted_at IS NULL LIMIT 1"
    );
    $existing->execute([$uid, $otherId]);
    $convId = $existing->fetchColumn();

    if (!$convId) {
        $pdo->prepare(
            "INSERT INTO chat_conversas (account_id, nome, tipo, criado_por) VALUES (?, NULL, 'direto', ?)"
        )->execute([$aid, $uid]);
        $convId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)');
        $stmt->execute([$convId, $uid]);
        $stmt->execute([$convId, $otherId]);
    }

    echo json_encode(['ok' => true, 'conversa_id' => (int) $convId]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: create_group
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'create_group') {
    $nome    = trim($body['nome'] ?? '');
    $userIds = array_unique(array_filter(array_map('intval', $body['usuario_ids'] ?? [])));
    if (!$nome) { http_response_code(400); echo json_encode(['error' => 'Nome obrigatório']); exit; }
    if (!count($userIds)) { http_response_code(400); echo json_encode(['error' => 'Adicione ao menos 1 participante']); exit; }

    // P1 LGPD (2B.2): filtra participantes a usuários do tenant + advogados convidados
    $validIds = _validParticipantIds($pdo, $userIds, $ctx);
    if (empty($validIds)) {
        http_response_code(403);
        echo json_encode(['error' => 'Nenhum dos participantes é válido para esta conta.']);
        exit;
    }
    // Log de IDs descartados (cross-tenant) pra diagnóstico
    $dropped = array_diff($userIds, $validIds);
    if (!empty($dropped)) {
        error_log('[chat/conversas create_group] user_ids cross-tenant rejeitados: ' . implode(',', $dropped));
    }

    $pdo->prepare(
        "INSERT INTO chat_conversas (account_id, nome, tipo, criado_por) VALUES (?, ?, 'grupo', ?)"
    )->execute([$aid, $nome, $uid]);
    $convId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT IGNORE INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)');
    $stmt->execute([$convId, $uid]);
    foreach ($validIds as $uId) $stmt->execute([$convId, $uId]);

    echo json_encode(['ok' => true, 'conversa_id' => $convId, 'rejected_user_ids' => array_values($dropped)]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: create_setor
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'create_setor') {
    $teamId = (int) ($body['team_id'] ?? 0);
    if (!$teamId) { http_response_code(400); echo json_encode(['error' => 'team_id obrigatório']); exit; }

    $team = $pdo->prepare(
        'SELECT id, nome FROM teams WHERE id = ? AND account_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $team->execute([$teamId, $aid]);
    $team = $team->fetch();
    if (!$team) { http_response_code(404); echo json_encode(['error' => 'Setor não encontrado']); exit; }

    $existing = $pdo->prepare(
        "SELECT id FROM chat_conversas WHERE team_id = ? AND tipo = 'setor' AND account_id = ? AND deleted_at IS NULL LIMIT 1"
    );
    $existing->execute([$teamId, $aid]);
    $convId = $existing->fetchColumn();

    if (!$convId) {
        $pdo->prepare(
            "INSERT INTO chat_conversas (account_id, nome, tipo, team_id, criado_por) VALUES (?, ?, 'setor', ?, ?)"
        )->execute([$aid, $team['nome'], $teamId, $uid]);
        $convId = (int) $pdo->lastInsertId();

        $members = $pdo->prepare('SELECT user_id FROM team_members WHERE team_id = ?');
        $members->execute([$teamId]);
        $stmt = $pdo->prepare('INSERT IGNORE INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)');
        $stmt->execute([$convId, $uid]);
        foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $mId) $stmt->execute([$convId, $mId]);
    } else {
        $pdo->prepare('INSERT IGNORE INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)')
            ->execute([$convId, $uid]);
    }

    echo json_encode(['ok' => true, 'conversa_id' => (int) $convId]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: create_vinculada — cross-account (filial ou matriz)
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'create_vinculada') {
    $tipo         = $body['tipo'] ?? '';
    $refAccountId = (int) ($body['ref_account_id'] ?? 0);
    $nome         = trim($body['nome'] ?? '');
    $userIds      = array_unique(array_filter(array_map('intval', $body['usuario_ids'] ?? [])));

    if (!in_array($tipo, ['filial', 'matriz'])) {
        http_response_code(400); echo json_encode(['error' => 'tipo inválido: use filial ou matriz']); exit;
    }
    if (!$refAccountId) {
        http_response_code(400); echo json_encode(['error' => 'ref_account_id obrigatório']); exit;
    }
    if (!count($userIds)) {
        http_response_code(400); echo json_encode(['error' => 'Selecione ao menos 1 participante']); exit;
    }

    if ($tipo === 'filial') {
        $vnk = $pdo->prepare(
            "SELECT id FROM account_vinculos
             WHERE matriz_account_id = ? AND filial_account_id = ? AND status = 'active' LIMIT 1"
        );
        $vnk->execute([$aid, $refAccountId]);
    } else {
        $vnk = $pdo->prepare(
            "SELECT id FROM account_vinculos
             WHERE filial_account_id = ? AND matriz_account_id = ? AND status = 'active' LIMIT 1"
        );
        $vnk->execute([$aid, $refAccountId]);
    }
    if (!$vnk->fetchColumn()) {
        http_response_code(403); echo json_encode(['error' => 'Vínculo não encontrado ou inativo']); exit;
    }

    if (!$nome) {
        $refNome = $pdo->prepare('SELECT nome FROM accounts WHERE id = ? LIMIT 1');
        $refNome->execute([$refAccountId]);
        $nome = 'Chat com ' . ($refNome->fetchColumn() ?: 'Conta vinculada');
    }

    $pdo->prepare(
        "INSERT INTO chat_conversas (account_id, nome, tipo, ref_account_id, criado_por) VALUES (?, ?, ?, ?, ?)"
    )->execute([$aid, $nome, $tipo, $refAccountId, $uid]);
    $convId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT IGNORE INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)');
    $stmt->execute([$convId, $uid]);
    foreach ($userIds as $uId) $stmt->execute([$convId, $uId]);

    echo json_encode(['ok' => true, 'conversa_id' => $convId]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: create_advogado
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'create_advogado') {
    $advUserId = (int) ($body['advogado_user_id'] ?? 0);
    if (!$advUserId) { http_response_code(400); echo json_encode(['error' => 'advogado_user_id obrigatório']); exit; }

    $convite = $pdo->prepare(
        "SELECT convidado_nome FROM advogado_convites
         WHERE from_account_id = ? AND convidado_user_id = ? AND status = 'accepted' LIMIT 1"
    );
    $convite->execute([$aid, $advUserId]);
    $convite = $convite->fetch();
    if (!$convite) {
        http_response_code(403); echo json_encode(['error' => 'Convite de advogado não encontrado ou não aceito']); exit;
    }

    $existing = $pdo->prepare(
        "SELECT c.id FROM chat_conversas c
         JOIN chat_participantes p1 ON p1.conversa_id = c.id AND p1.usuario_id = ?
         JOIN chat_participantes p2 ON p2.conversa_id = c.id AND p2.usuario_id = ?
         WHERE c.tipo = 'advogado' AND c.account_id = ? AND c.deleted_at IS NULL LIMIT 1"
    );
    $existing->execute([$uid, $advUserId, $aid]);
    $convId = $existing->fetchColumn();

    if (!$convId) {
        $nome = 'Adv. ' . ($convite['convidado_nome'] ?? 'Externo');
        $pdo->prepare(
            "INSERT INTO chat_conversas (account_id, nome, tipo, criado_por) VALUES (?, ?, 'advogado', ?)"
        )->execute([$aid, $nome, $uid]);
        $convId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)');
        $stmt->execute([$convId, $uid]);
        $stmt->execute([$convId, $advUserId]);
    } else {
        $pdo->prepare('INSERT IGNORE INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)')
            ->execute([$convId, $uid]);
    }

    echo json_encode(['ok' => true, 'conversa_id' => (int) $convId]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: mark_read
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'mark_read') {
    $convId = (int) ($body['conversa_id'] ?? 0);
    if (!$convId || !checkParticipant($pdo, $convId, $uid)) {
        http_response_code(400); echo json_encode(['error' => 'Inválido ou sem acesso']); exit;
    }
    $lastId = $pdo->prepare('SELECT MAX(id) FROM chat_mensagens WHERE conversa_id = ?');
    $lastId->execute([$convId]);
    $lastId = (int) $lastId->fetchColumn();
    if ($lastId) {
        $pdo->prepare(
            'UPDATE chat_participantes SET ultima_leitura_id = ? WHERE conversa_id = ? AND usuario_id = ?'
        )->execute([$lastId, $convId, $uid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: update_participants
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'update_participants') {
    $convId    = (int) ($body['conversa_id'] ?? 0);
    $addIds    = array_unique(array_filter(array_map('intval', $body['add_ids']    ?? [])));
    $removeIds = array_unique(array_filter(array_map('intval', $body['remove_ids'] ?? [])));

    if (!$convId) { http_response_code(400); echo json_encode(['error' => 'conversa_id obrigatório']); exit; }

    $conv = getConvRow($pdo, $convId, $aid);
    if (!$conv) { http_response_code(404); echo json_encode(['error' => 'Não encontrada']); exit; }

    if (in_array($conv['tipo'], ['geral', 'direto'])) {
        http_response_code(400); echo json_encode(['error' => 'Tipo de conversa não permite edição de participantes']); exit;
    }

    $isCriador = (int) $conv['criado_por'] === $uid;
    if (!$isCriador && !$ctx->isOwnerOrAdmin()) {
        http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit;
    }

    if (count($addIds)) {
        // P1 LGPD (2B.2): filtra adds a participantes válidos no tenant
        $validAdds = _validParticipantIds($pdo, $addIds, $ctx);
        $rejected  = array_diff($addIds, $validAdds);
        if (!empty($rejected)) {
            error_log('[chat/conversas update_participants] add cross-tenant rejeitados: ' . implode(',', $rejected));
        }
        $stmtAdd = $pdo->prepare('INSERT IGNORE INTO chat_participantes (conversa_id, usuario_id) VALUES (?, ?)');
        foreach ($validAdds as $uId) $stmtAdd->execute([$convId, $uId]);
    }
    if (count($removeIds)) {
        $stmtDel = $pdo->prepare(
            'DELETE FROM chat_participantes WHERE conversa_id = ? AND usuario_id = ? AND usuario_id != ?'
        );
        foreach ($removeIds as $uId) $stmtDel->execute([$convId, $uId, (int) $conv['criado_por']]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: archive / unarchive
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && in_array($action, ['archive', 'unarchive'])) {
    $convId = (int) ($body['conversa_id'] ?? 0);
    if (!$convId || !checkParticipant($pdo, $convId, $uid)) {
        http_response_code(400); echo json_encode(['error' => 'Inválido ou sem acesso']); exit;
    }
    $val = $action === 'archive' ? 'NOW()' : 'NULL';
    $pdo->prepare("UPDATE chat_conversas SET archived_at = {$val} WHERE id = ? AND account_id = ?")
        ->execute([$convId, $aid]);
    echo json_encode(['ok' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: delete (soft)
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'delete') {
    $convId = (int) ($body['conversa_id'] ?? 0);
    if (!$convId) { http_response_code(400); echo json_encode(['error' => 'conversa_id obrigatório']); exit; }

    $conv = getConvRow($pdo, $convId, $aid);
    if (!$conv) { http_response_code(404); echo json_encode(['error' => 'Não encontrada']); exit; }
    if ($conv['tipo'] === 'geral') {
        http_response_code(400); echo json_encode(['error' => 'O canal geral não pode ser excluído']); exit;
    }

    $isCriador = (int) $conv['criado_por'] === $uid;
    if (!$isCriador && !$ctx->isOwnerOrAdmin()) {
        http_response_code(403); echo json_encode(['error' => 'Sem permissão para excluir']); exit;
    }

    $pdo->prepare('UPDATE chat_conversas SET deleted_at = NOW() WHERE id = ?')->execute([$convId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
http_response_code(404);
echo json_encode(['error' => 'Ação não encontrada']);
