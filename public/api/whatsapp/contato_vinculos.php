<?php
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';

session_start(['read_and_close' => true]);
$_uid = $_SESSION['user_id'] ?? null;

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!$_uid) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

try {
    $pdo = \App\Models\Database::getConnection();
    $jid = trim($_GET['jid'] ?? '');

    if (!$jid) {
        echo json_encode(['ok' => false, 'error' => 'jid obrigatório']);
        exit;
    }

    $contato = null;

    // ── Estratégia 1: telefone extraído do JID (@s.whatsapp.net) ─────────────
    $phone = preg_replace('/@.*$/', '', $jid);
    $phone = preg_replace('/[^0-9]/', '', $phone);

    if (strlen($phone) >= 10 && !str_ends_with($jid, '@lid')) {
        $stmt = $pdo->prepare(
            'SELECT * FROM contatos
             WHERE telefone = ?
                OR telefone = CONCAT(\'55\', ?)
             LIMIT 1'
        );
        $stmt->execute([$phone, $phone]);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Estratégia 2: remote_jid direto em contatos (para @lid) ──────────────
    if (!$contato) {
        $stmt = $pdo->prepare('SELECT * FROM contatos WHERE remote_jid = ? LIMIT 1');
        $stmt->execute([$jid]);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Estratégia 3: whatsapp_chats.contato_id (fallback geral) ─────────────
    if (!$contato) {
        $stmt = $pdo->prepare(
            'SELECT ct.* FROM whatsapp_chats wc
             JOIN contatos ct ON ct.id = wc.contato_id
             WHERE wc.remote_jid = ? LIMIT 1'
        );
        $stmt->execute([$jid]);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Estratégia 4: whatsapp_chats → linked_card_id → card.contato_id ──────
    if (!$contato) {
        $stmt = $pdo->prepare(
            'SELECT ct.* FROM whatsapp_chats wc
             JOIN cards c  ON c.id  = wc.linked_card_id
             JOIN contatos ct ON ct.id = c.contato_id
             WHERE wc.remote_jid = ? LIMIT 1'
        );
        $stmt->execute([$jid]);
        $contato = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    if (!$contato) {
        echo json_encode(['ok' => true, 'contato' => null, 'vinculos' => []]);
        exit;
    }

    // Busca vínculos do contato
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
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
