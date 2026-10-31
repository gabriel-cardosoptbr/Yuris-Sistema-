<?php
/**
 * POST /api/whatsapp/sdr_roleta.php — o n8n pergunta quem assina a abertura.
 *
 * O robô da planilha e o disparo das listas chamam isto ANTES de montar a
 * mensagem de abertura: a Roleta sorteia o atendente da vez (ou devolve o já
 * sorteado para esse lead) e diz como ele aparece no texto. Ver
 * App\WhatsAppAgente\Roleta::reservar.
 *
 *   Body JSON: { telefone, card_id?, tipo?, origem? }
 *     tipo:   'abertura' (padrão) sorteia; qualquer outro ('followup') não sorteia
 *     origem: 'planilha' | 'listas' (vai para o histórico da roleta)
 *   200 { ok:true, atendente: { user_id, atendente, vendedor, novo } | null, motivo? }
 *        vendedor = o primeiro nome do cadastro ("Kaue"), que entra no lugar de
 *        {vendedor} nos textos; atendente = o nome completo do cadastro
 *        atendente null = roleta desligada (o fluxo usa o texto de antes)
 *   Lead novo vai para o vendedor da vez (Roleta::daVez), e o seguinte já é sorteado.
 *   401 token   404 conta   422 dados
 *
 * Autentica pelo cabeçalho X-Fleetiflow-Token (FLEETIFLOW_SDR_WEBHOOK_TOKEN), como
 * sdr_etapa.php, e age só na conta dona desse token.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\EnvLoader;
use App\WhatsAppAgente\AutomacaoSdr;
use App\WhatsAppAgente\Roleta;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$esperado = trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_TOKEN', ''));
$recebido = (string)($_SERVER['HTTP_X_FLEETIFLOW_TOKEN'] ?? '');
if ($esperado === '' || !hash_equals($esperado, $recebido)) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$in   = json_decode((string)file_get_contents('php://input'), true) ?? [];
$fone = preg_replace('/\D/', '', (string)($in['telefone'] ?? ''));
$tipo = trim((string)($in['tipo'] ?? 'abertura'));
if (strlen($fone) < 10 || strlen($fone) > 13) { http_response_code(422); echo json_encode(['error' => 'Informe o telefone do lead']); exit; }

try {
    $conta = AutomacaoSdr::contaDoRobo();
    if ($conta === null) { http_response_code(404); echo json_encode(['error' => 'Conta da prospecção não encontrada']); exit; }
    if ($tipo !== 'abertura') { echo json_encode(['ok' => true, 'atendente' => null, 'motivo' => 'só a abertura é sorteada']); exit; }

    $cardId = (int)($in['card_id'] ?? 0) ?: null;
    $r = Roleta::reservar($conta, $fone, $cardId, (string)($in['origem'] ?? 'disparo'));
    echo json_encode(isset($r['pulou'])
        ? ['ok' => true, 'atendente' => null, 'motivo' => $r['pulou']]
        : ['ok' => true, 'atendente' => $r], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[sdr_roleta] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
