<?php
/**
 * GET /api/whatsapp/sdr_automacao.php — o n8n pergunta se pode mandar.
 *
 * O robô de disparo e a cadência de follow-up da Fleetiflow chamam isto antes de
 * cada mensagem; os botões "Disparo" e "Follow-up" do Chat (automacao_toggle.php)
 * decidem a resposta. Ver App\WhatsAppAgente\AutomacaoSdr.
 *
 *   200 { ok:true, disparo:bool, followup:bool }   401 token   404 conta
 *
 * Autentica pelo cabeçalho X-Fleetiflow-Token (FLEETIFLOW_SDR_WEBHOOK_TOKEN), como
 * sdr_etapa.php, e responde só sobre a conta dona desse token. Qualquer erro aqui
 * faz o fluxo NÃO mandar (ele trata ausência de `true` como desligado).
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\EnvLoader;
use App\WhatsAppAgente\AutomacaoSdr;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$esperado = trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_TOKEN', ''));
$recebido = (string)($_SERVER['HTTP_X_FLEETIFLOW_TOKEN'] ?? '');
if ($esperado === '' || !hash_equals($esperado, $recebido)) { http_response_code(401); echo json_encode(['error' => 'Token inválido']); exit; }

try {
    $conta = AutomacaoSdr::contaDoRobo();
    if ($conta === null) { http_response_code(404); echo json_encode(['error' => 'Conta da prospecção não encontrada']); exit; }
    echo json_encode(['ok' => true] + AutomacaoSdr::estado($conta));
} catch (\Throwable $e) {
    error_log('[sdr_automacao] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
