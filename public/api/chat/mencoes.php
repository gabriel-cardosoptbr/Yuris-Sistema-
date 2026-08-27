<?php
/**
 * /api/chat/mencoes.php
 * GET ?q=termo  — busca sugestões para o sistema de menções
 * Retorna: usuarios, processos, cards, clientes
 */
require_once __DIR__ . '/../../../app/Core/Database.php';
require_once __DIR__ . '/../../../app/Master/Account.php';
require_once __DIR__ . '/../../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../../app/Core/AccountContext.php';

use App\Core\Database;
use App\Core\AccountContext;

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

// ─── ALTA #2 (auditoria 2026-06-01): escopo POR MODULO ───────────────────────
// Antes usávamos um único getAccessibleAccountIds() (sem módulo) pra TODOS os
// blocos. Isso vazava cards/processos/clientes de uma filial cujo sync daquele
// módulo está DESLIGADO (a busca de @menção contornava a granularidade de
// sync_cards/sync_processos/sync_clientes). Agora cada bloco usa o escopo do
// SEU módulo, exatamente como api/cards.php (prospeccao), api/processes.php
// (processos) e api/clientes.php (clientes) fazem. Usuários mantêm o escopo
// base (matriz + filiais/advogados ativos).
$ctxIds = $ctx->getAccessibleAccountIds();                  // base (usuários)
if (empty($ctxIds)) {
    echo json_encode(['ok' => true, 'data' => []]);
    exit;
}

// Constrói placeholders posicionais (?) pra uma lista de account_ids.
// Retorna [$inSql, $params] ou [null, []] se a lista estiver vazia.
$buildIn = static function (array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($ids)) return [null, []];
    return ['(' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
};

[$usersIn, $usersParams]   = $buildIn($ctxIds);
[$procIn,  $procParams]    = $buildIn($ctx->getAccessibleAccountIds('processos'));
[$cardIn,  $cardParams]    = $buildIn($ctx->getAccessibleAccountIds('prospeccao'));
[$cliIn,   $cliParams]     = $buildIn($ctx->getAccessibleAccountIds('clientes'));
// ────────────────────────────────────────────────────────────────────────────

$pdo   = Database::getConnection();
$q     = trim($_GET['q'] ?? '');
$type  = strtolower(trim($_GET['type'] ?? 'auto'));
$like  = '%' . $q . '%';
$limit = min((int)($_GET['limit'] ?? 20), 50);

$result = [];

// ── Detecta tipo pelo prefixo digitado ────────────────────────────────────
// @pro... → processos | @card... → cards | @cli...|@cliente... → clientes | resto → usuários
$qLower = strtolower($q);

$showUsers     = ($type === 'auto' || $type === 'usuario');
$showProcessos = ($type === 'auto' || $type === 'processo');
$showCards     = ($type === 'auto' || $type === 'card');
$showClientes  = ($type === 'auto' || $type === 'cliente');

if ($type === 'auto') {
    // 'cliente'/'cli' tem prioridade sobre 'card' pra não cair no funil errado
    // (antes 'cli' ligava cards e o usuário nunca achava o cliente real).
    if (str_starts_with($qLower, 'cli')) {
        $showUsers = false; $showProcessos = false; $showCards = false;
    } elseif (str_starts_with($qLower, 'pro')) {
        $showUsers = false; $showCards = false; $showClientes = false;
    } elseif (str_starts_with($qLower, 'card')) {
        $showUsers = false; $showProcessos = false; $showClientes = false;
    }
}

// ── Usuários (somente do tenant) ──────────────────────────────────────────
// JOIN com accounts pra retornar info da conta dona do user — UI agrupa por
// matriz/filial/advogado pra deixar claro de qual organizacao a pessoa eh.
if ($showUsers && $usersIn) {
    $s = $pdo->prepare(
        "SELECT u.id, u.nome, u.perfil, u.account_id,
                a.nome AS account_nome, a.tipo AS account_tipo
           FROM users u
           LEFT JOIN accounts a ON a.id = u.account_id
          WHERE u.deleted_at IS NULL AND u.status = 'active'
            AND u.account_id IN $usersIn
            AND u.nome LIKE ?
          ORDER BY a.tipo, a.nome, u.nome LIMIT " . $limit
    );
    $s->execute(array_merge($usersParams, [$like]));
    foreach ($s->fetchAll() as $row) {
        $result[] = [
            'tipo'         => 'usuario',
            'id'           => (int)$row['id'],
            'display'      => $row['nome'],
            'sub'          => ucfirst($row['perfil'] ?? ''),
            'token'        => '@[user|' . $row['id'] . '|' . $row['nome'] . ']',
            'url'          => '/usuarios.php',
            // Info de organização: usado pelo frontend pra agrupar por conta
            'account_id'   => (int)$row['account_id'],
            'account_nome' => $row['account_nome'] ?? '',
            'account_tipo' => $row['account_tipo'] ?? 'matriz', // matriz | filial | advogado
        ];
    }
}

// ── Processos (escopo do módulo 'processos') ─────────────────────────────
if ($showProcessos && $procIn) {
    $s = $pdo->prepare(
        "SELECT id, numero, cliente_nome FROM processos
         WHERE deleted_at IS NULL
           AND account_id IN $procIn
           AND (numero LIKE ? OR cliente_nome LIKE ?)
         ORDER BY id DESC LIMIT " . $limit
    );
    $s->execute(array_merge($procParams, [$like, $like]));
    foreach ($s->fetchAll() as $row) {
        // Sempre mostra valor humano. ID interno só fica na URL (técnico, invisível).
        $display = $row['numero'] ?: ($row['cliente_nome'] ?: 'Processo sem número');
        $result[] = [
            'tipo'    => 'processo',
            'id'      => (int)$row['id'],
            'display' => $display,
            'sub'     => $row['cliente_nome'] ?? '',
            'token'   => '@[proc|' . $row['id'] . '|' . $display . ']',
            'url'     => '/processos.php?open=' . $row['id'],
        ];
    }
}

// ── Cards (escopo do módulo 'prospeccao') ────────────────────────────────
if ($showCards && $cardIn) {
    $s = $pdo->prepare(
        "SELECT id, cliente_nome, empresa_nome FROM cards
         WHERE deleted_at IS NULL
           AND account_id IN $cardIn
           AND (cliente_nome LIKE ? OR empresa_nome LIKE ?)
         ORDER BY id DESC LIMIT " . $limit
    );
    $s->execute(array_merge($cardParams, [$like, $like]));
    foreach ($s->fetchAll() as $row) {
        // Display sempre humano: cliente OU empresa, sem ID interno.
        $display = $row['cliente_nome'] ?: ($row['empresa_nome'] ?: 'Lead sem nome');
        $result[] = [
            'tipo'    => 'card',
            'id'      => (int)$row['id'],
            'display' => $display,
            'sub'     => $row['empresa_nome'] ?? '',
            'token'   => '@[card|' . $row['id'] . '|' . $display . ']',
            'url'     => '/prospeccao.php?open=' . $row['id'],
        ];
    }
}

// ── Clientes (escopo do módulo 'clientes') ───────────────────────────────
// ALTA #1: a tabela `clientes` (base real de clientes do escritório) NUNCA era
// consultada — '@cliente Fulano' caía numa busca de cards. Agora é uma fonte
// de menção de primeira classe, com token @[cli|...] e url /clientes.php?open=<id>
// (clientes.php passou a tratar ?open= via auto-open).
if ($showClientes && $cliIn) {
    // A tabela `clientes` não tem coluna de empresa/razão social — o nome do
    // cliente é o campo humano. Busca por nome ou CPF/CNPJ (mesmo espírito do
    // Cliente::list). O CPF/CNPJ vira o "sub" pra desambiguar homônimos.
    $s = $pdo->prepare(
        "SELECT id, nome, cpf_cnpj FROM clientes
         WHERE deleted_at IS NULL
           AND account_id IN $cliIn
           AND (nome LIKE ? OR cpf_cnpj LIKE ?)
         ORDER BY id DESC LIMIT " . $limit
    );
    $s->execute(array_merge($cliParams, [$like, $like]));
    foreach ($s->fetchAll() as $row) {
        $display = $row['nome'] ?: 'Cliente sem nome';
        $result[] = [
            'tipo'    => 'cliente',
            'id'      => (int)$row['id'],
            'display' => $display,
            'sub'     => $row['cpf_cnpj'] ?? '',
            'token'   => '@[cli|' . $row['id'] . '|' . $display . ']',
            'url'     => '/clientes.php?open=' . $row['id'],
        ];
    }
}

echo json_encode(['ok' => true, 'data' => array_slice($result, 0, 12)]);
