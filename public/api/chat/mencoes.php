<?php
/**
 * /api/chat/mencoes.php
 * GET ?q=termo  — busca sugestões para o sistema de menções
 * Retorna: usuarios, processos, cards
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
use App\Models\Database;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) { http_response_code(401); echo json_encode(['error' => 'Não autenticado']); exit; }

$pdo   = Database::getConnection();
$q     = trim($_GET['q'] ?? '');
$type  = strtolower(trim($_GET['type'] ?? 'auto'));
$like  = '%' . $q . '%';
$limit = min((int)($_GET['limit'] ?? 20), 50);

$result = [];

// ── Detecta tipo pelo prefixo digitado ────────────────────────────────────
// @pro... → processos | @card... → cards | @cli... → cards(clientes) | resto → usuários
$qLower = strtolower($q);

$showUsers     = ($type === 'auto' || $type === 'usuario');
$showProcessos = ($type === 'auto' || $type === 'processo');
$showCards     = ($type === 'auto' || $type === 'card');

if ($type === 'auto') {
    if (str_starts_with($qLower, 'pro'))  { $showUsers = false; $showCards = false; }
    elseif (str_starts_with($qLower, 'card') || str_starts_with($qLower, 'cli')) {
        $showUsers = false; $showProcessos = false;
    }
}

// ── Usuários ──────────────────────────────────────────────────────────────
if ($showUsers) {
    $s = $pdo->prepare(
        'SELECT id, nome, perfil FROM users
         WHERE deleted_at IS NULL AND status = \'active\' AND nome LIKE ?
         ORDER BY nome LIMIT ' . $limit
    );
    $s->execute([$like]);
    foreach ($s->fetchAll() as $row) {
        $result[] = [
            'tipo'          => 'usuario',
            'id'            => (int)$row['id'],
            'display'       => $row['nome'],
            'sub'           => ucfirst($row['perfil'] ?? ''),
            'token'         => '@[user|' . $row['id'] . '|' . $row['nome'] . ']',
            'url'           => '/sistema_vendas/public/usuarios.php',
        ];
    }
}

// ── Processos ────────────────────────────────────────────────────────────
if ($showProcessos) {
    $s = $pdo->prepare(
        'SELECT id, numero, cliente_nome FROM processos
         WHERE (numero LIKE ? OR cliente_nome LIKE ?)
         ORDER BY id DESC LIMIT ' . $limit
    );
    $s->execute([$like, $like]);
    foreach ($s->fetchAll() as $row) {
        // Sempre mostra valor humano. ID interno só fica na URL (técnico, invisível).
        $display = $row['numero'] ?: ($row['cliente_nome'] ?: 'Processo sem número');
        $result[] = [
            'tipo'    => 'processo',
            'id'      => (int)$row['id'],
            'display' => $display,
            'sub'     => $row['cliente_nome'] ?? '',
            'token'   => '@[proc|' . $row['id'] . '|' . $display . ']',
            'url'     => '/sistema_vendas/public/processos.php?open=' . $row['id'],
        ];
    }
}

// ── Cards ────────────────────────────────────────────────────────────────
if ($showCards) {
    $s = $pdo->prepare(
        'SELECT id, cliente_nome, empresa_nome FROM cards
         WHERE (cliente_nome LIKE ? OR empresa_nome LIKE ?)
         ORDER BY id DESC LIMIT ' . $limit
    );
    $s->execute([$like, $like]);
    foreach ($s->fetchAll() as $row) {
        // Display sempre humano: cliente OU empresa, sem ID interno.
        $display = $row['cliente_nome'] ?: ($row['empresa_nome'] ?: 'Lead sem nome');
        $result[] = [
            'tipo'    => 'card',
            'id'      => (int)$row['id'],
            'display' => $display,
            'sub'     => $row['empresa_nome'] ?? '',
            'token'   => '@[card|' . $row['id'] . '|' . $display . ']',
            'url'     => '/sistema_vendas/public/prospeccao.php?open=' . $row['id'],
        ];
    }
}

echo json_encode(['ok' => true, 'data' => array_slice($result, 0, 12)]);
