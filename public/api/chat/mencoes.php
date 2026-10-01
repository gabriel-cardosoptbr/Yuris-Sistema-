<?php
/**
 * /api/chat/mencoes.php
 * GET ?q=termo  — busca sugestões para o sistema de menções
 * Retorna: usuarios, processos, cards, clientes
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

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

// ── Edição CRM (conta sem módulo jurídico): o @ sugere o que existe NELA ─────
// Pessoas, leads, clientes, tarefas e conversas de WhatsApp; processo não existe
// nessa edição e não aparece. Cada resultado traz o contexto que ajuda a escolher
// (etapa do lead, setor do cliente, quadro e prazo da tarefa, telefone da
// conversa). Prefixo digitado escolhe o tipo e sai da busca: "@lead fiat" busca
// "fiat" nos leads. Em "Todos", no máximo 4 por tipo, para todos aparecerem.
// A edição jurídica nunca entra aqui: o caminho dela, abaixo, não mudou.
if (!$ctx->moduloJuridicoDisponivel()) {
    $tipos = ['usuario', 'card', 'cliente', 'tarefa', 'conversa'];
    if (!in_array($type, array_merge(['auto'], $tipos), true)) $type = 'auto';
    if ($type === 'auto') {
        $prefixos = [
            'card'     => '/^(lead|leads|card|cards)\b\s*/u',
            'cliente'  => '/^(cliente|clientes|cli)\b\s*/u',
            'tarefa'   => '/^(tarefa|tarefas|tar)\b\s*/u',
            'conversa' => '/^(conversa|conversas|zap|whats|whatsapp|wpp)\b\s*/u',
            'usuario'  => '/^(pessoa|pessoas|usuario|usuário|equipe)\b\s*/u',
        ];
        foreach ($prefixos as $t => $re) {
            if (preg_match($re, mb_strtolower($q))) {
                $type = $t;
                $q = trim((string)preg_replace($re, '', mb_strtolower($q)));
                $like = '%' . $q . '%';
                break;
            }
        }
    }
    $mostra = fn(string $t) => $type === 'auto' || $type === $t;
    $porTipo = $type === 'auto' ? 4 : $limit;
    $res = [];
    $dataBr = static function (?string $d): string {
        $ts = $d ? strtotime($d) : 0;
        return $ts ? date('d/m', $ts) : '';
    };

    if ($mostra('usuario') && $usersIn) {
        $s = $pdo->prepare(
            "SELECT u.id, u.nome, u.role, u.account_id, a.nome AS account_nome, a.tipo AS account_tipo
               FROM users u LEFT JOIN accounts a ON a.id = u.account_id
              WHERE u.deleted_at IS NULL AND u.status = 'active'
                AND u.account_id IN $usersIn AND u.nome LIKE ?
              ORDER BY (u.id = ?) ASC, u.nome LIMIT " . $porTipo
        );
        $s->execute(array_merge($usersParams, [$like, $uid]));
        $papel = ['owner' => 'Dono da conta', 'admin' => 'Administrador', 'manager' => 'Gestor', 'user' => 'Equipe'];
        foreach ($s->fetchAll() as $r) {
            $res[] = [
                'tipo' => 'usuario', 'id' => (int)$r['id'], 'display' => $r['nome'],
                'sub' => ((int)$r['id'] === (int)$uid ? 'Você · ' : '') . ($papel[$r['role'] ?? ''] ?? 'Equipe'),
                'token' => '@[user|' . $r['id'] . '|' . $r['nome'] . ']', 'url' => '/usuarios.php',
                'account_id' => (int)$r['account_id'], 'account_nome' => $r['account_nome'] ?? '', 'account_tipo' => $r['account_tipo'] ?? 'matriz',
            ];
        }
    }

    if ($mostra('card') && $cardIn) {
        $s = $pdo->prepare(
            "SELECT c.id, c.cliente_nome, c.empresa_nome, c.telefone_whatsapp, pc.nome AS etapa, u.nome AS resp
               FROM cards c
               LEFT JOIN pipeline_columns pc ON pc.id = c.coluna_id
               LEFT JOIN users u ON u.id = c.responsavel_user_id
              WHERE c.deleted_at IS NULL AND c.account_id IN $cardIn
                AND (c.cliente_nome LIKE ? OR c.empresa_nome LIKE ? OR c.telefone_whatsapp LIKE ?)
              ORDER BY c.updated_at DESC LIMIT " . $porTipo
        );
        $s->execute(array_merge($cardParams, [$like, $like, $like]));
        foreach ($s->fetchAll() as $r) {
            $display = $r['cliente_nome'] ?: ($r['empresa_nome'] ?: 'Lead sem nome');
            $sub = array_filter([$r['etapa'] ?? '', $r['resp'] ? 'com ' . $r['resp'] : 'sem consultor']);
            $res[] = [
                'tipo' => 'card', 'id' => (int)$r['id'], 'display' => $display, 'sub' => implode(' · ', $sub),
                'token' => '@[card|' . $r['id'] . '|' . $display . ']', 'url' => '/prospeccao.php?open=' . $r['id'],
            ];
        }
    }

    if ($mostra('cliente') && $cliIn) {
        $s = $pdo->prepare(
            "SELECT cl.id, cl.nome, cl.whatsapp, cl.telefone, cs.nome AS setor
               FROM clientes cl LEFT JOIN clientes_setores cs ON cs.id = cl.setor_id
              WHERE cl.deleted_at IS NULL AND cl.account_id IN $cliIn
                AND (cl.nome LIKE ? OR cl.cpf_cnpj LIKE ? OR cl.whatsapp LIKE ?)
              ORDER BY cl.updated_at DESC LIMIT " . $porTipo
        );
        $s->execute(array_merge($cliParams, [$like, $like, $like]));
        foreach ($s->fetchAll() as $r) {
            $display = $r['nome'] ?: 'Cliente sem nome';
            $res[] = [
                'tipo' => 'cliente', 'id' => (int)$r['id'], 'display' => $display,
                'sub' => implode(' · ', array_filter([$r['setor'] ?? '', $r['whatsapp'] ?: ($r['telefone'] ?? '')])),
                'token' => '@[cli|' . $r['id'] . '|' . $display . ']', 'url' => '/clientes.php?open=' . $r['id'],
            ];
        }
    }

    // Tarefas: só de quadro que a pessoa enxerga (a mesma regra de
    // TaskBoard::acesso: dono, membro, admin da conta ou quadro compartilhado).
    [$tarIn, $tarParams] = $buildIn($ctx->getAccessibleAccountIds('tarefas'));
    if ($mostra('tarefa') && $tarIn) {
        $admin = $ctx->isOwnerOrAdmin() ? 1 : 0;
        $s = $pdo->prepare(
            "SELECT t.id, t.titulo, t.prazo, t.status, b.nome AS quadro, u.nome AS resp
               FROM tasks t
               JOIN task_boards b ON b.id = t.board_id
               LEFT JOIN users u ON u.id = t.responsavel_id
              WHERE b.account_id IN $tarIn AND b.ativo = 1
                AND (b.owner_id = ? OR ? = 1 OR b.tipo = 'compartilhado'
                     OR EXISTS (SELECT 1 FROM task_board_members m WHERE m.board_id = b.id AND m.user_id = ?))
                AND t.titulo LIKE ?
              ORDER BY (t.status = 'concluida') ASC, (t.prazo IS NULL) ASC, t.prazo ASC, t.id DESC
              LIMIT " . $porTipo
        );
        $s->execute(array_merge($tarParams, [$uid, $admin, $uid, $like]));
        foreach ($s->fetchAll() as $r) {
            $display = $r['titulo'] ?: 'Tarefa sem título';
            $sub = [$r['quadro'] ?? ''];
            if (($r['status'] ?? '') === 'concluida') $sub[] = 'concluída';
            elseif ($r['prazo']) $sub[] = 'prazo ' . $dataBr($r['prazo']);
            if ($r['resp']) $sub[] = $r['resp'];
            $res[] = [
                'tipo' => 'tarefa', 'id' => (int)$r['id'], 'display' => $display, 'sub' => implode(' · ', array_filter($sub)),
                'token' => '@[tar|' . $r['id'] . '|' . $display . ']', 'url' => '/tarefas.php?tarefa=' . $r['id'],
            ];
        }
    }

    // Conversas: as do canal de WhatsApp que a pessoa pode ver (a mesma
    // resolução do Chat, deny-by-default). Sem canal, sem conversas.
    if ($mostra('conversa')) {
        try {
            // resolveRequestedChannel + check, e NAO resolveForRequest: aquele encerra a
            // requisição com 403 quando nega e pode criar canal; aqui negar é só não listar.
            $W = \App\WhatsAppAgente\WhatsAppChannelAccessService::class;
            $cid = $W::resolveRequestedChannel($pdo, (int)$ctx->getAccountId(), null);
            $ch = $cid ? $W::check($pdo, (int)$ctx->getAccountId(), $cid, 'view') : null;
            $inst = (int)($ch['channel_id'] ?? 0);
        } catch (\Throwable $e) { $inst = 0; }
        if ($inst > 0) {
            $s = $pdo->prepare(
                "SELECT w.id, w.contact_name, w.phone, w.remote_jid, w.last_message_at, c.cliente_nome AS lead
                   FROM whatsapp_chats w LEFT JOIN cards c ON c.id = w.linked_card_id AND c.deleted_at IS NULL
                  WHERE w.instance_id = ? AND w.is_group = 0
                    AND (w.contact_name LIKE ? OR w.phone LIKE ? OR c.cliente_nome LIKE ?)
                  ORDER BY w.last_message_at DESC LIMIT " . $porTipo
            );
            $s->execute([$inst, $like, $like, $like]);
            foreach ($s->fetchAll() as $r) {
                $fone = $r['phone'] ?: explode('@', (string)$r['remote_jid'])[0];
                $display = $r['contact_name'] ?: ($r['lead'] ?: $fone);
                $sub = array_filter([$fone !== $display ? $fone : '', $r['lead'] && $r['lead'] !== $display ? 'lead ' . $r['lead'] : '',
                                     $r['last_message_at'] ? 'última ' . $dataBr($r['last_message_at']) : '']);
                $res[] = [
                    'tipo' => 'conversa', 'id' => (int)$r['id'], 'display' => $display, 'sub' => implode(' · ', $sub),
                    'token' => '@[zap|' . $r['id'] . '|' . $display . ']', 'url' => '/chat.php?conversa=' . $r['id'],
                ];
            }
        }
    }

    echo json_encode(['ok' => true, 'crm' => true, 'data' => array_slice($res, 0, $type === 'auto' ? 20 : $limit)]);
    exit;
}

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
