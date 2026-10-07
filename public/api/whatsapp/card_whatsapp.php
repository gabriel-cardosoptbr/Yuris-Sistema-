<?php
/**
 * GET /api/whatsapp/card_whatsapp.php?card_id=ID
 *
 * Tudo do WhatsApp de um lead, lido do que o Yuris já guarda, para a ficha do card na
 * edição CRM (assets/ff-conversa.js). Funciona com o número desconectado: nada aqui
 * chama a Evolution, só lê whatsapp_chats, whatsapp_messages e as tabelas do card.
 * É o que deixa o card servir de base quando um WhatsApp cai (pedido de 07/10/2026).
 *
 *   { ok, card:{id, cliente, empresa, tipo, telefone, cidade, criado_em},
 *     etapa:{nome, cor} | null, setor:{nome, cor} | null,
 *     atendimento:{responsavel, agente:{nome, ligado, pausado, pausado_por} | null, quem},
 *     numeros:[{id, nome, telefone, status, dono, jid, ultima_mensagem}],
 *     mensagens:[{n, quando, de, tipo, texto}], total_mensagens }
 *
 * O card tem de ser de uma conta que o usuário acessa (prospecção). Só lê; sem CSRF.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$cardId = (int)($_GET['card_id'] ?? 0);
if ($cardId <= 0) { http_response_code(400); echo json_encode(['error' => 'card_id obrigatório']); exit; }

try {
    $ctx = AccountContext::fromSession();
    $acessiveis = array_map('intval', $ctx->getAccessibleAccountIds('prospeccao') ?: [(int)$ctx->getAccountId()]);
    $pdo = Database::getConnection();

    $st = $pdo->prepare('SELECT * FROM cards WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$cardId]);
    $card = $st->fetch(PDO::FETCH_ASSOC);
    if (!$card || !in_array((int)$card['account_id'], $acessiveis, true)) {
        http_response_code(404); echo json_encode(['error' => 'Card não encontrado']); exit;
    }
    $conta = (int)$card['account_id'];

    $um = function (string $sql, array $par) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($par);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $etapa = $card['coluna_id'] ? $um('SELECT nome, cor FROM pipeline_columns WHERE id = ? AND account_id = ?', [(int)$card['coluna_id'], $conta]) : null;
    $setor = !empty($card['team_id']) ? $um('SELECT nome, cor FROM teams WHERE id = ? AND account_id = ? AND deleted_at IS NULL', [(int)$card['team_id'], $conta]) : null;
    $resp  = $card['responsavel_user_id'] ? $um('SELECT nome FROM users WHERE id = ? AND account_id = ?', [(int)$card['responsavel_user_id'], $conta]) : null;

    // As conversas ligadas a este card (uma por número; o mesmo lead pode ter falado por dois).
    $st = $pdo->prepare(
        "SELECT w.instance_id, w.remote_jid, w.agent_paused, w.agent_paused_by, w.last_message_at,
                i.instance_name, i.display_name, i.phone, i.status, i.responsavel_user_id AS dono_id
           FROM whatsapp_chats w
           JOIN whatsapp_instances i ON i.id = w.instance_id AND i.account_id = ?
          WHERE w.linked_card_id = ?
       ORDER BY w.last_message_at DESC, w.id DESC"
    );
    $st->execute([$conta, $cardId]);
    $chats = $st->fetchAll(PDO::FETCH_ASSOC);

    $nomeUsuario = function (?int $id) use ($um, $conta): ?string {
        if (!$id) return null;
        $u = $um('SELECT nome FROM users WHERE id = ? AND account_id = ?', [$id, $conta]);
        return $u ? (string)$u['nome'] : null;
    };

    $numeros = []; $agente = null; $filtros = []; $vistos = [];
    foreach ($chats as $c) {
        $iid = (int)$c['instance_id'];
        $numeros[] = [
            'id' => $iid,
            'nome' => trim((string)($c['display_name'] ?? '')) !== '' ? (string)$c['display_name'] : (string)$c['instance_name'],
            'telefone' => preg_replace('/\D/', '', (string)($c['phone'] ?? '')),
            'status' => (string)$c['status'],
            'dono' => $nomeUsuario($c['dono_id'] ? (int)$c['dono_id'] : null),
            'jid' => (string)$c['remote_jid'],
            'ultima_mensagem' => $c['last_message_at'],
        ];
        if ($agente === null) {
            $ag = $um('SELECT name, enabled FROM agent_configs WHERE whatsapp_instance_id = ? AND account_id = ? LIMIT 1', [$iid, $conta]);
            $agente = [
                'nome' => $ag ? (string)$ag['name'] : null,
                'ligado' => $ag ? (bool)$ag['enabled'] : false,
                'pausado' => (bool)(int)$c['agent_paused'],
                'pausado_por' => $nomeUsuario($c['agent_paused_by'] ? (int)$c['agent_paused_by'] : null),
            ];
        }
        $k = $iid . '|' . $c['remote_jid'];
        if (!isset($vistos[$k])) { $vistos[$k] = true; $filtros[] = [$iid, (string)$c['remote_jid']]; }
    }

    // Quem está com o lead agora.
    $quem = 'Sem ninguém';
    if ($agente && $agente['ligado'] && !$agente['pausado']) $quem = 'Agente de IA' . ($agente['nome'] ? ' (' . $agente['nome'] . ')' : '');
    elseif ($agente && $agente['pausado']) $quem = $agente['pausado_por'] ? 'Pessoa (' . $agente['pausado_por'] . ')' : 'Pessoa do time (IA pausada)';
    elseif ($resp) $quem = $resp['nome'] . ' (responsável do card)';

    // A conversa: as mensagens de todas as conversas ligadas, as 300 mais recentes, em ordem.
    $mensagens = []; $total = 0;
    if ($filtros) {
        $or = []; $par = [];
        foreach ($filtros as [$iid, $jid]) { $or[] = '(instance_id = ? AND remote_jid = ?)'; $par[] = $iid; $par[] = $jid; }
        $where = '(' . implode(' OR ', $or) . ') AND deleted_at IS NULL AND is_deleted = 0';
        $cs = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE $where");
        $cs->execute($par);
        $total = (int)$cs->fetchColumn();
        $ms = $pdo->prepare(
            "SELECT id, direction, message_type, message_content, caption, media_filename, created_at
               FROM whatsapp_messages WHERE $where ORDER BY created_at DESC, id DESC LIMIT 300"
        );
        $ms->execute($par);
        foreach (array_reverse($ms->fetchAll(PDO::FETCH_ASSOC)) as $m) {
            $texto = trim((string)($m['message_content'] ?? ''));
            if ($texto === '') $texto = trim((string)($m['caption'] ?? ''));
            $mensagens[] = [
                'n' => (int)$m['id'],
                'quando' => $m['created_at'],           // UTC, como o resto do sistema
                'de' => $m['direction'] === 'outbound' ? 'nos' : 'lead',
                'tipo' => (string)$m['message_type'],
                'texto' => mb_substr($texto, 0, 4000),
                'arquivo' => $m['media_filename'] ?: null,
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'card' => [
            'id' => (int)$card['id'],
            'cliente' => (string)$card['cliente_nome'],
            'empresa' => $card['empresa_nome'],
            'tipo' => $card['tipo_lead'] ?? null,
            'telefone' => preg_replace('/\D/', '', (string)($card['telefone_whatsapp'] ?? '')),
            'cidade' => trim(((string)($card['cidade'] ?? '')) . (!empty($card['uf']) ? '/' . $card['uf'] : '')) ?: null,
            'criado_em' => $card['created_at'],
        ],
        'etapa' => $etapa,
        'setor' => $setor,
        'atendimento' => ['responsavel' => $resp ? (string)$resp['nome'] : null, 'agente' => $agente, 'quem' => $quem],
        'numeros' => $numeros,
        'mensagens' => $mensagens,
        'total_mensagens' => $total,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[card_whatsapp] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
