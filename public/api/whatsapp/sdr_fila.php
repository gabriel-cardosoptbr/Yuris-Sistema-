<?php
/**
 * POST /api/whatsapp/sdr_fila.php: o n8n manda o retrato da fila de aberturas.
 *
 * O robô da planilha e o disparo das listas mandam, a cada rodada, como está a fila deles
 * (quantos faltam, os próximos na ordem, quantos saíram por dia, os que foram pulados e por
 * quê). O CRM guarda o último de cada um e mostra no painel Operação › Fila. Os fluxos só
 * LEEM a fila para montar o retrato. Ver App\WhatsAppAgente\FilaDaProspeccao.
 *
 *   Body JSON: { fluxo: 'planilha'|'listas', retrato: { janela, restantes, por_grupo,
 *                enviados_total, enviados_por_dia, pulados, proximos, problemas } }
 *   200 { ok:true }   401 token   404 conta   422 fluxo desconhecido
 *
 * Autentica pelo cabeçalho X-Fleetiflow-Token (FLEETIFLOW_SDR_WEBHOOK_TOKEN), como
 * sdr_etapa.php, e age só na conta dona desse token.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\EnvLoader;
use App\WhatsAppAgente\AutomacaoSdr;
use App\WhatsAppAgente\FilaDaProspeccao;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$esperado = trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_TOKEN', ''));
$recebido = (string)($_SERVER['HTTP_X_FLEETIFLOW_TOKEN'] ?? '');
if ($esperado === '' || !hash_equals($esperado, $recebido)) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$bruto = (string)file_get_contents('php://input');
if (strlen($bruto) > 200000) { http_response_code(413); echo json_encode(['error' => 'Retrato grande demais']); exit; }
$in = json_decode($bruto, true) ?? [];

try {
    $conta = AutomacaoSdr::contaDoRobo();
    if ($conta === null) { http_response_code(404); echo json_encode(['error' => 'Conta da prospecção não encontrada']); exit; }
    if (!FilaDaProspeccao::guardar($conta, (string)($in['fluxo'] ?? ''), (array)($in['retrato'] ?? []))) {
        http_response_code(422); echo json_encode(['error' => 'Fluxo desconhecido']); exit;
    }
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    error_log('[sdr_fila] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
