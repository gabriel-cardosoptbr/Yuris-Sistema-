<?php
/**
 * /api/chat/mencoes.php
 * GET ?q=termo  — busca sugestões para o sistema de menções
 * Retorna: usuarios, processos, cards
 */
require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';

use App\Models\Database;
use App\Helpers\AccountContext;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');

// ─── LGPD P1 (2B.1): tenant enforcement ─────────────────────────────────────
// Antes desta correção, GET /api/chat/mencoes.php?q=silva retornava NOMES,
// EMAILS, NÚMEROS CNJ, CLIENTE_NOME, EMPRESA_NOME de TODOS os tenants.
// Qualquer usuário autenticado podia enumerar o catálogo da plataforma.
// Agora filtramos por accounts acessíveis pela sessão (matriz + filiais
// com sync ativo).
$ctx = AccountContext::fromSession();
$uid = $ctx->getUserId();

$accessibleIds = $ctx->getAccessibleAccountIds(); // sem módulo: só matriz+filiais ativas
if (empty($accessibleIds)) {
    echo json_encode(['ok' => true, 'data' => []]);
    exit;
}
// Constrói placeholders nomeados pra IN(...)
$accPlaceholders = [];
$accParams       = [];
foreach ($accessibleIds as $i => $aid) {
    $k = "acc{$i}";
    $accPlaceholders[] = ":{$k}";
    $accParams[$k]     = (int)$aid;
}
$accIn = implode(',', $accPlaceholders);
// ────────────────────────────────────────────────────────────────────────────

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

// ── Usuários (somente do tenant) ──────────────────────────────────────────
if ($showUsers) {
    $s = $pdo->prepare(
        "SELECT id, nome, perfil FROM users
         WHERE deleted_at IS NULL AND status = 'active'
           AND account_id IN ($accIn)
           AND nome LIKE :like
         ORDER BY nome LIMIT " . $limit
    );
    $s->execute(['like' => $like] + $accParams);
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

// ── Processos (somente do tenant) ────────────────────────────────────────
if ($showProcessos) {
    $s = $pdo->prepare(
        "SELECT id, numero, cliente_nome FROM processos
         WHERE deleted_at IS NULL
           AND account_id IN ($accIn)
           AND (numero LIKE :like1 OR cliente_nome LIKE :like2)
         ORDER BY id DESC LIMIT " . $limit
    );
    $s->execute(['like1' => $like, 'like2' => $like] + $accParams);
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

// ── Cards (somente do tenant) ────────────────────────────────────────────
if ($showCards) {
    $s = $pdo->prepare(
        "SELECT id, cliente_nome, empresa_nome FROM cards
         WHERE deleted_at IS NULL
           AND account_id IN ($accIn)
           AND (cliente_nome LIKE :like1 OR empresa_nome LIKE :like2)
         ORDER BY id DESC LIMIT " . $limit
    );
    $s->execute(['like1' => $like, 'like2' => $like] + $accParams);
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
