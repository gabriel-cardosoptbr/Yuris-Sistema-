<?php
/**
 * POST /api/plataforma_eventos.php?c=<account_id>
 *
 * Recebe os eventos da plataforma Fleetiflow (pedido da conta, recarga,
 * troca de plano, módulo...). Máquina a máquina: sem sessão, autenticado pela
 * assinatura HMAC com o segredo que esta conta recebeu ao se conectar. Toda a
 * regra mora em App\Plataforma\Eventos; aqui só autentica e responde.
 *
 *   200 {"ok":true}        recebido (ou já recebido antes: idempotente)
 *   400 corpo fora do contrato   401 assinatura   405 método
 *   410 conta sem conexão (a plataforma para de insistir só quando revogada)
 *   500 erro interno (a plataforma tenta de novo)
 *
 * O `c` não é segredo: diz só QUAL segredo conferir. Sem a assinatura certa,
 * nada é gravado. O segredo nunca é logado.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Plataforma\Eventos;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pevResponder(int $status, array $corpo): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pevResponder(405, ['ok' => false, 'error' => 'Método não permitido']);

$accountId = ctype_digit((string) ($_GET['c'] ?? '')) ? (int) $_GET['c'] : 0;
if ($accountId <= 0) pevResponder(400, ['ok' => false, 'error' => 'Requisição inválida']);

// Um evento cabe com folga em 256 KB.
$corpo = (string) file_get_contents('php://input', false, null, 0, 262145);
if ($corpo === '' || strlen($corpo) > 262144) pevResponder(400, ['ok' => false, 'error' => 'Requisição inválida']);

try {
    $aut = Eventos::autenticar(
        $accountId,
        $corpo,
        (string) ($_SERVER['HTTP_X_FLEETIFLOW_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_X_FLEETIFLOW_ASSINATURA'] ?? '')
    );
    if ($aut === 410) pevResponder(410, ['ok' => false, 'error' => 'Conta sem conexão com a plataforma']);
    if ($aut !== 0) pevResponder(401, ['ok' => false, 'error' => 'Não autorizado']);

    $evento = json_decode($corpo, true);
    if (!is_array($evento)) pevResponder(400, ['ok' => false, 'error' => 'Requisição inválida']);

    $r = Eventos::receber($accountId, $evento);
    pevResponder(200, ['ok' => true, 'novo' => $r['novo']]);
} catch (\InvalidArgumentException $e) {
    pevResponder(400, ['ok' => false, 'error' => 'Requisição inválida']);
} catch (\Throwable $e) {
    error_log('[plataforma_eventos] ' . get_class($e) . ': ' . $e->getMessage());
    pevResponder(500, ['ok' => false, 'error' => 'Erro interno']);
}
