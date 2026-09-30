<?php
/**
 * POST /api/whatsapp/sdr_transferencia.php
 *
 * Chamado pela Vitória (IA de pré-qualificação da Fleetiflow, no n8n) quando o
 * lead está pronto para o especialista. O especialista atende no MESMO número,
 * pelo Chat do CRM: aqui a conversa é pausada para a IA, o resumo vai para o card
 * e a conta é notificada. Ver App\WhatsAppAgente\SdrFleetiflow::transferir.
 *
 * Sem sessão: autentica pelo cabeçalho X-Fleetiflow-Token, comparado com
 * FLEETIFLOW_SDR_WEBHOOK_TOKEN do .env. Token vazio no .env = endpoint desligado.
 * Só age em conversa de canal cuja conta dona é Fleetiflow.
 *
 *   Body JSON: { remote_jid, telefone?, empresa?, nome_contato?, resumo }
 *   200 { ok, card_id }   401 token   404 conversa não encontrada   422 dados
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

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$jid    = trim((string)($in['remote_jid'] ?? ''));
$fone   = preg_replace('/[^0-9]/', '', (string)($in['telefone'] ?? ''));
$resumo = trim((string)($in['resumo'] ?? ''));
if (($jid === '' && $fone === '') || $resumo === '') {
    http_response_code(422); echo json_encode(['error' => 'Informe remote_jid (ou telefone) e resumo']); exit;
}
$resumo = mb_substr($resumo, 0, 4000);

try {
    $pdo = Database::getConnection();

    // A conversa, pelo JID que a Vitória recebeu; na falta, pelo telefone.
    $candidatos = [];
    $busca = $pdo->prepare(
        'SELECT wc.instance_id, wc.remote_jid, wi.account_id
           FROM whatsapp_chats wc
           JOIN whatsapp_instances wi ON wi.id = wc.instance_id
          WHERE wc.remote_jid = ?
       ORDER BY wc.last_message_at DESC LIMIT 5'
    );
    foreach (array_filter([$jid, $fone !== '' ? $fone . '@s.whatsapp.net' : '']) as $j) {
        $busca->execute([$j]);
        foreach ($busca->fetchAll(\PDO::FETCH_ASSOC) as $r) { $candidatos[] = $r; }
        if ($candidatos) break;
    }
    $alvo = null;
    foreach ($candidatos as $r) {
        if (SdrFleetiflow::contaUsa((int)$r['account_id'])) { $alvo = $r; break; }
    }
    if (!$alvo) {
        http_response_code(404); echo json_encode(['error' => 'Conversa não encontrada']); exit;
    }

    $res = SdrFleetiflow::transferir((int)$alvo['account_id'], (int)$alvo['instance_id'], (string)$alvo['remote_jid'], [
        'resumo'       => $resumo,
        'telefone'     => $fone,
        'empresa'      => (string)($in['empresa'] ?? ''),
        'nome_contato' => (string)($in['nome_contato'] ?? ''),
    ]);
    echo json_encode(['ok' => true, 'card_id' => $res['card_id']]);
} catch (\Throwable $e) {
    error_log('[sdr_transferencia] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
