<?php
/**
 * POST /api/whatsapp/sdr_etapa.php
 *
 * Chamado pelo n8n da Fleetiflow (robô de apresentação, Vitória e cadência) a
 * cada passo da prospecção: garante o card do lead, dá a ele o nome da empresa e
 * move a etapa. A etapa do Chat é a coluna do card, então as duas telas andam
 * juntas. Ver App\WhatsAppAgente\SdrFleetiflow (ETAPAS e as regras de AUTO_DE:
 * a automação não tira card de etapa que já é de uma pessoa).
 *
 * Autentica pelo cabeçalho X-Fleetiflow-Token, como sdr_transferencia.php.
 *
 *   Body JSON: { telefone, remote_jid?, empresa?, nome_contato?, etapa? }
 *     etapa: novo | qualificacao | followup | qualificado | especialista |
 *            bloqueado | fora_escopo | perdido   (vazio = só garante o card)
 *   200 { ok, card_id, etapa, moveu }   401 token   404 conta   422 dados
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Database;
use App\Core\EnvLoader;
use App\WhatsAppAgente\SdrFleetiflow;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

$esperado = trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_TOKEN', ''));
$recebido = (string)($_SERVER['HTTP_X_FLEETIFLOW_TOKEN'] ?? '');
if ($esperado === '' || !hash_equals($esperado, $recebido)) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}

$in    = json_decode(file_get_contents('php://input'), true) ?? [];
$jid   = trim((string)($in['remote_jid'] ?? ''));
$fone  = preg_replace('/[^0-9]/', '', (string)($in['telefone'] ?? ''));
$etapa = trim((string)($in['etapa'] ?? ''));
if ($fone === '' && str_ends_with($jid, '@s.whatsapp.net')) {
    $fone = explode('@', $jid)[0];
}
if (strlen($fone) < 10 || strlen($fone) > 13) {
    http_response_code(422); echo json_encode(['error' => 'Informe o telefone do lead']); exit;
}
if ($etapa !== '' && !isset(SdrFleetiflow::ETAPAS[$etapa])) {
    http_response_code(422); echo json_encode(['error' => 'Etapa desconhecida']); exit;
}

try {
    $pdo = Database::getConnection();

    // A conversa, pelo JID recebido ou pelo telefone; só de canal de conta Fleetiflow.
    $alvo = null;
    $busca = $pdo->prepare(
        'SELECT wc.instance_id, wc.remote_jid, wi.account_id
           FROM whatsapp_chats wc
           JOIN whatsapp_instances wi ON wi.id = wc.instance_id
          WHERE wc.remote_jid = ?
       ORDER BY wc.last_message_at DESC LIMIT 5'
    );
    foreach (array_unique(array_filter([$jid, $fone . '@s.whatsapp.net'])) as $j) {
        $busca->execute([$j]);
        foreach ($busca->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (SdrFleetiflow::contaUsa((int)$r['account_id'])) { $alvo = $r; break 2; }
        }
    }

    // O robô avisa logo depois de mandar, às vezes antes de o webhook criar a
    // conversa. Aí o card nasce pelo telefone, e o webhook liga a conversa nele.
    if (!$alvo) {
        $contas = [];
        foreach ($pdo->query("SELECT DISTINCT account_id FROM whatsapp_instances WHERE status = 'open'")->fetchAll(\PDO::FETCH_COLUMN) as $a) {
            if (SdrFleetiflow::contaUsa((int)$a)) $contas[] = (int)$a;
        }
        if (count($contas) !== 1) {
            http_response_code(404); echo json_encode(['error' => 'Conta Fleetiflow não encontrada']); exit;
        }
        $alvo = ['account_id' => $contas[0], 'instance_id' => null, 'remote_jid' => null];
    }

    $accountId = (int)$alvo['account_id'];
    $cardId = SdrFleetiflow::garantirCard(
        $accountId,
        $alvo['instance_id'] !== null ? (int)$alvo['instance_id'] : null,
        $alvo['remote_jid'],
        $fone,
        (string)($in['nome_contato'] ?? ''),
        (string)($in['empresa'] ?? '')
    );
    if (!$cardId) {
        echo json_encode(['ok' => true, 'card_id' => null, 'etapa' => null, 'moveu' => false]); exit;
    }

    $moveu = $etapa !== '' && SdrFleetiflow::moverEtapa($accountId, $cardId, $etapa);

    $st = $pdo->prepare('SELECT coluna_id FROM cards WHERE id = ?');
    $st->execute([$cardId]);
    $agora = array_search((int)$st->fetchColumn(), SdrFleetiflow::colunasDaConta($pdo, $accountId), true);
    $agora = $agora === false ? null : $agora;

    echo json_encode(['ok' => true, 'card_id' => $cardId, 'etapa' => $agora, 'moveu' => $moveu]);
} catch (\Throwable $e) {
    error_log('[sdr_etapa] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
