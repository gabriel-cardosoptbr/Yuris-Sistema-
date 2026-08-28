<?php
/**
 * /api/chat/mensagens.php
 * GET  ?conversa_id=X&limit=50&before_id=Y  — lista mensagens (paginado)
 * GET  ?conversa_id=X&after_id=Y            — novas mensagens (polling)
 * POST                                       — envia mensagem
 */
require_once __DIR__ . '/../../../app/bootstrap.php';
use App\Core\Database;
use App\Core\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

$uid  = (int)($_SESSION['user_id']    ?? 0);
$csrf = $_SESSION['csrf_token'] ?? '';
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Não autenticado']); exit; }

$ctx = AccountContext::fromSession();  // pra escopar validacao de mencoes por tenant

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ── Verifica participação ──────────────────────────────────────────────────
function checkParticipant(PDO $pdo, int $convId, int $uid): bool
{
    $s = $pdo->prepare('SELECT 1 FROM chat_participantes WHERE conversa_id = ? AND usuario_id = ? LIMIT 1');
    $s->execute([$convId, $uid]);
    return (bool)$s->fetchColumn();
}

// ── GET: listar mensagens ─────────────────────────────────────────────────
if ($method === 'GET') {
    $convId   = (int)($_GET['conversa_id'] ?? 0);
    $beforeId = (int)($_GET['before_id']   ?? 0);
    $afterId  = (int)($_GET['after_id']    ?? 0);
    $limit    = min((int)($_GET['limit'] ?? 50), 100);

    if (!$convId) { http_response_code(400); echo json_encode(['error' => 'conversa_id obrigatório']); exit; }
    if (!checkParticipant($pdo, $convId, $uid)) {
        http_response_code(403); echo json_encode(['error' => 'Acesso negado']); exit;
    }

    $sql    = 'SELECT m.id, m.conversa_id, m.usuario_id, m.mensagem, m.created_at, u.nome AS usuario_nome
               FROM chat_mensagens m
               JOIN users u ON u.id = m.usuario_id
               WHERE m.conversa_id = ?';
    $params = [$convId];

    if ($afterId > 0) {
        $sql   .= ' AND m.id > ?';
        $params[] = $afterId;
        $sql   .= ' ORDER BY m.id ASC LIMIT ' . $limit;
    } elseif ($beforeId > 0) {
        $sql   .= ' AND m.id < ?';
        $params[] = $beforeId;
        $sql   .= ' ORDER BY m.id DESC LIMIT ' . $limit;
    } else {
        $sql .= ' ORDER BY m.id DESC LIMIT ' . $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();

    // Para paginação "antes de", inverte para ordem cronológica
    if ($beforeId > 0 || (!$afterId && !$beforeId)) {
        $msgs = array_reverse($msgs);
    }

    echo json_encode(['ok' => true, 'data' => $msgs]);
    exit;
}

// ── POST: enviar mensagem ─────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($body['_csrf']) || $body['_csrf'] !== $csrf) {
        http_response_code(403); echo json_encode(['error' => 'CSRF inválido']); exit;
    }

    $convId  = (int)($body['conversa_id'] ?? 0);
    $texto   = trim($body['mensagem'] ?? '');
    $mencoes = $body['mencoes'] ?? [];

    if (!$convId) { http_response_code(400); echo json_encode(['error' => 'conversa_id obrigatório']); exit; }
    if ($texto === '') { http_response_code(400); echo json_encode(['error' => 'Mensagem vazia']); exit; }
    if (mb_strlen($texto) > 10000) { http_response_code(400); echo json_encode(['error' => 'Mensagem muito longa']); exit; }
    if (!checkParticipant($pdo, $convId, $uid)) {
        http_response_code(403); echo json_encode(['error' => 'Acesso negado']); exit;
    }

    // Salva mensagem
    $pdo->prepare('INSERT INTO chat_mensagens (conversa_id, usuario_id, mensagem) VALUES (?, ?, ?)')
        ->execute([$convId, $uid, $texto]);
    $msgId = (int)$pdo->lastInsertId();

    // Atualiza updated_at da conversa
    $pdo->prepare('UPDATE chat_conversas SET updated_at = NOW() WHERE id = ?')->execute([$convId]);

    // Salva menções validadas
    if (is_array($mencoes)) {
        // BAIXA #1 (auditoria 2026-06-01): grava account_id do contexto. Antes
        // ficava NULL (a coluna existe desde a migration 037 mas o INSERT nunca
        // a preenchia), quebrando filtros/relatórios escopados por tenant.
        $myAccountId = $ctx->getAccountId();
        $stmtM = $pdo->prepare(
            'INSERT INTO chat_mencoes (mensagem_id, tipo, referencia_id, texto_exibido, url_destino, account_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($mencoes as $m) {
            $tipo   = $m['tipo']          ?? '';
            $refId  = (int)($m['referencia_id'] ?? 0);
            $texto  = substr(trim($m['texto_exibido'] ?? ''), 0, 200);
            $url    = substr(trim($m['url_destino']   ?? ''), 0, 500);

            // ALTA #1: 'cliente' agora é um tipo de menção de primeira classe
            // (a tabela `clientes`). Enum estendido na migration 094.
            if (!in_array($tipo, ['usuario','processo','card','cliente'])) continue;
            if (!$refId || !$texto || !$url) continue;

            // SEGURANCA (auditoria 2026-06-01): valida a referencia ESCOPADA por
            // tenant. Antes, qualquer id existente era aceito (IDOR/enumeracao —
            // dava pra mencionar processo/card/usuario de outro tenant). Cada tipo
            // usa o escopo do SEU módulo (respeita sync_<modulo> por filial).
            $scopeMod = match($tipo) {
                'card'     => 'prospeccao',
                'processo' => 'processos',
                'cliente'  => 'clientes',
                default    => 'chat',
            };
            $accIds   = $ctx->getAccessibleAccountIds($scopeMod);
            if (empty($accIds)) continue;
            $phRef = implode(',', array_fill(0, count($accIds), '?'));
            $sqlRef = match($tipo) {
                'usuario'  => "SELECT id FROM users     WHERE id = ? AND deleted_at IS NULL AND account_id IN ($phRef) LIMIT 1",
                'processo' => "SELECT id FROM processos WHERE id = ? AND deleted_at IS NULL AND account_id IN ($phRef) LIMIT 1",
                'card'     => "SELECT id FROM cards      WHERE id = ? AND deleted_at IS NULL AND account_id IN ($phRef) LIMIT 1",
                'cliente'  => "SELECT id FROM clientes   WHERE id = ? AND deleted_at IS NULL AND account_id IN ($phRef) LIMIT 1",
                default    => null,
            };
            if (!$sqlRef) continue;
            $valid = $pdo->prepare($sqlRef);
            $valid->execute(array_merge([$refId], $accIds));
            if (!$valid->fetchColumn()) continue; // referencia fora do tenant — descarta mencao

            $stmtM->execute([$msgId, $tipo, $refId, $texto, $url, $myAccountId]);
        }
    }

    // Retorna mensagem salva para exibição imediata
    $saved = $pdo->prepare(
        'SELECT m.id, m.conversa_id, m.usuario_id, m.mensagem, m.created_at, u.nome AS usuario_nome
         FROM chat_mensagens m JOIN users u ON u.id = m.usuario_id WHERE m.id = ?'
    );
    $saved->execute([$msgId]);

    echo json_encode(['ok' => true, 'data' => $saved->fetch()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
