<?php
/**
 * aasp/test.php — Testa uma chave AASP SEM persistir nem encriptar.
 *
 * Uso (no fluxo de cadastro): admin digita chave + tipo, clica "Testar conexão".
 *
 * POST {
 *   _csrf,
 *   chave,                        // chave plain (NÃO logada — mascarada em qualquer log)
 *   tipo                = 'associado' | 'empresa',
 *   codigos_associados? = [12345, ...]   // só modo empresa
 *   data?               = 'YYYY-MM-DD'   // default = hoje
 *   diferencial?        = bool          // default false
 * }
 *
 * Responde:
 *   { ok, status_http, envelope_status, total, sample_raw, normalized_sample,
 *     duracao_ms, request_url_masked, mode }
 *
 * Segurança:
 *   • Exige owner|admin do tenant (assertOwnerOrAdmin)
 *   • CSRF obrigatório
 *   • Chave nunca aparece em log/response (apenas mascarada)
 *   • Não grava nada no banco (só audit do teste)
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\EnvLoader;
use App\Processos\AaspIntegration;
use App\Processos\Monitor\AaspProvider;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';
$userId      = (int)($_SESSION['user_id'] ?? 0);

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($userId === 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($payload['_csrf']) || !hash_equals($csrfSession, (string)$payload['_csrf'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
    exit;
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    if (!$ctx->isOwnerOrAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Apenas owner/admin pode testar integração AASP']);
        exit;
    }

    $chave = trim((string)($payload['chave'] ?? ''));
    if ($chave === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Informe a chave AASP']);
        exit;
    }
    $tipo = ($payload['tipo'] ?? 'associado') === 'empresa' ? 'empresa' : 'associado';
    $codigos = isset($payload['codigos_associados']) && is_array($payload['codigos_associados'])
        ? array_values(array_unique(array_map('intval', array_filter($payload['codigos_associados']))))
        : [];
    if ($tipo === 'empresa' && empty($codigos)) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => 'Modo empresa exige pelo menos 1 código de associado (a API AASP rejeita sem).',
        ]);
        exit;
    }

    $data = (string)($payload['data'] ?? '');
    if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        $data = date('Y-m-d');
    }
    $diferencial = !empty($payload['diferencial']);

    EnvLoader::load();
    $baseUrl     = EnvLoader::get('AASP_BASE_URL', 'https://intimacaoapi.aasp.org.br');
    $rateLimitMs = (int)(EnvLoader::get('AASP_RATE_LIMIT_MS', '1000'));

    $provider = new AaspProvider($baseUrl, 25, $rateLimitMs);
    $started  = microtime(true);

    try {
        $result = $provider->fetchPublications(
            ['data' => $data],
            [
                'chave'              => $chave,
                'tipo'               => $tipo,
                'codigos_associados' => $codigos,
                'diferencial'        => $diferencial,
                'timeout_seconds'    => 25,
            ]
        );
        $duracao = (int)((microtime(true) - $started) * 1000);

        // Audit: tested (sem credencial em si)
        AaspIntegration::audit(
            $accountId,
            null,
            'tested',
            $userId,
            "tipo={$tipo} data={$data} total={$result['total']} duracao_ms={$duracao}"
        );

        $sample = $result['items'][0] ?? null;
        $sampleRaw = $sample['payload_original'] ?? null;
        // Não devolver payload_original completo no normalized_sample (pra não poluir UI)
        $normalizedSample = $sample;
        if ($normalizedSample) {
            unset($normalizedSample['payload_original']);
        }

        echo json_encode([
            'ok'                 => true,
            'status_http'        => $result['raw_meta']['status_http'] ?? 200,
            'envelope_status'    => 'Ok',
            'total'              => $result['total'],
            'request_count'      => $result['raw_meta']['request_count'] ?? 1,
            'sample_raw'         => $sampleRaw,
            'normalized_sample'  => $normalizedSample,
            'mode'               => $tipo,
            'codigos_consultados'=> $tipo === 'empresa' ? $codigos : [],
            'data_consultada'    => $data,
            'diferencial'        => $diferencial,
            'duracao_ms'         => $duracao,
            'calls'              => $result['raw_meta']['calls'] ?? [],
        ]);
    } catch (\RuntimeException $e) {
        $duracao = (int)((microtime(true) - $started) * 1000);
        // Audit: error (mensagem segura, sem chave)
        AaspIntegration::audit(
            $accountId,
            null,
            'error',
            $userId,
            "test falhou: " . mb_substr($e->getMessage(), 0, 300)
        );
        http_response_code(502);
        echo json_encode([
            'ok'         => false,
            'error'      => $e->getMessage(),
            'mode'       => $tipo,
            'data_consultada' => $data,
            'duracao_ms' => $duracao,
        ]);
    }
} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);
}
