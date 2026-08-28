<?php
/**
 * aasp/sync.php — Sincroniza intimações AASP de UMA data (default: hoje).
 *
 * POST { _csrf, integration_id, data?, diferencial?, force? }
 *
 * Fluxo:
 *   1. Auth + CSRF (qualquer user do tenant pode disparar sync — não exige admin)
 *   2. Carrega aasp_integration (já filtrado por account_id)
 *   3. Descriptografa chave (em RAM apenas durante a chamada)
 *   4. AaspProvider->fetchPublications(data, chave, tipo, codigos, diferencial)
 *   5. Pra cada item retornado: PushTodayCache::upsert com source_id='aasp'
 *   6. markSyncSuccess + audit('sync_ok')   (ou markSyncError + audit('sync_fail'))
 *   7. Devolve resumo { total, cached_novos, items, duracao_ms, request_count }
 *
 * Idempotente: UNIQUE(account_id, hash_conteudo) garante que rodar 2x não duplica.
 *
 * Importante:
 *   • Modo `diferencial=true` faz a AASP devolver só o que ainda não foi consultado
 *     por essa chave. Use no cron automático. Por padrão (manual) é false pra
 *     evitar perda silenciosa de intimações que já viram lá mas o user reabriu.
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\EnvLoader;
use App\Processos\AaspIntegration;
use App\Processos\PushTodayCache;
use App\Processos\PushQueryLog;
use App\Processos\Monitor\AaspProvider;
use App\Processos\Monitor\PublicationHasher;

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

    $integrationId = (int)($payload['integration_id'] ?? 0);
    if ($integrationId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'integration_id obrigatório']);
        exit;
    }

    // Find — já filtra por account_id (multi-tenant)
    $integ = AaspIntegration::find($integrationId, $accountId);
    if (!$integ) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Integração não encontrada nesta conta']);
        exit;
    }
    if ($integ['status'] === 'inactive') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Integração marcada como inativa — reative antes de sincronizar']);
        exit;
    }

    // Data (default = hoje)
    $data = (string)($payload['data'] ?? '');
    if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        $data = date('Y-m-d');
    }
    $diferencial = !empty($payload['diferencial']);
    $codigos     = is_array($integ['codigos_associados']) ? $integ['codigos_associados'] : [];

    EnvLoader::load();
    $baseUrl     = EnvLoader::get('AASP_BASE_URL', 'https://intimacaoapi.aasp.org.br');
    $rateLimitMs = (int)(EnvLoader::get('AASP_RATE_LIMIT_MS', '1000'));
    $provider    = new AaspProvider($baseUrl, 25, $rateLimitMs);

    // ── Log inicial ────────────────────────────────────────────────────────
    $logId = PushQueryLog::start([
        'account_id'         => $accountId,
        'source_id'          => 'aasp',
        'origem_busca'       => 'manual',
        'data_inicio_filtro' => $data,
        'data_fim_filtro'    => $data,
        'request_url'        => '/api/aasp/sync.php?int=' . $integrationId,
        'filtros_hash'       => PublicationHasher::filtersHash([
            'integration_id' => $integrationId,
            'data'           => $data,
            'diferencial'    => $diferencial,
        ]),
    ]);

    $started = microtime(true);
    try {
        $chave  = AaspIntegration::getChavePlain($integrationId, $accountId);
        $result = $provider->fetchPublications(
            ['data' => $data],
            [
                'chave'              => $chave,
                'tipo'               => $integ['tipo'],
                'codigos_associados' => $codigos,
                'diferencial'        => $diferencial,
                'timeout_seconds'    => 25,
            ]
        );
        // Libera referência à chave em RAM (php zerafy não é garantido, mas reduz janela)
        unset($chave);

        $items   = $result['items'] ?? [];
        $cached  = 0;
        $expires = date('Y-m-d') . ' 23:59:59';
        foreach ($items as $it) {
            $ok = PushTodayCache::upsert([
                'account_id'              => $accountId,
                'source_id'               => 'aasp',
                'tribunal'                => $it['tribunal'],
                'data_disponibilizacao'   => $it['data_disponibilizacao'],
                'data_publicacao'         => $it['data_publicacao'],
                'numero_processo'         => $it['numero_processo'],
                'numero_processo_mascara' => $it['numero_processo_mascara'],
                'orgao'                   => $it['orgao'],
                'id_orgao'                => $it['id_orgao'],
                'tipo_comunicacao'        => $it['tipo_comunicacao'],
                'meio'                    => $it['meio'],
                'meio_completo'           => $it['meio_completo'],
                'classe_nome'             => $it['classe_nome'],
                'classe_codigo'           => $it['classe_codigo'],
                'titulo'                  => $it['titulo'],
                'resumo'                  => $it['resumo'],
                'conteudo'                => $it['conteudo'],
                'url_origem'              => $it['url_origem'],
                'numero_comunicacao'      => $it['numero_comunicacao'],
                'hash_externo'            => $it['hash_externo'],
                'hash_conteudo'           => $it['hash_conteudo'],
                'payload_original'        => $it['payload_original'],
                'expires_at'              => $expires,
            ]);
            if ($ok) $cached++;
        }

        // Atualiza integração
        AaspIntegration::markSyncSuccess($integrationId, $cached, $diferencial);
        AaspIntegration::audit(
            $accountId, $integrationId, 'sync_ok', $userId,
            "data={$data} total=" . count($items) . " novos={$cached} dif=" . ($diferencial ? '1' : '0')
        );

        // Log final
        PushQueryLog::finish($logId, [
            'response_status'  => $result['raw_meta']['status_http'] ?? 200,
            'response_hash'    => hash('sha256', json_encode(array_column($items, 'hash_conteudo'))),
            'total_resultados' => count($items),
            'status'           => 'ok',
            'duracao_ms'       => (int)((microtime(true) - $started) * 1000),
        ]);

        // Não devolver payload_original ao frontend (LGPD: menos PII no wire)
        $publicItems = array_map(static function ($it) {
            unset($it['payload_original']);
            return $it;
        }, $items);

        echo json_encode([
            'ok'              => true,
            'integration_id'  => $integrationId,
            'data_consultada' => $data,
            'diferencial'     => $diferencial,
            'total'           => count($items),
            'cached_novos'    => $cached,
            'request_count'   => $result['raw_meta']['request_count'] ?? 1,
            'duracao_ms'      => (int)((microtime(true) - $started) * 1000),
            'items'           => $publicItems,
            'calls'           => $result['raw_meta']['calls'] ?? [],
        ]);
    } catch (\RuntimeException $e) {
        // Erro de provider (rede/auth/validação) — mensagem propagada ao user,
        // chave NUNCA aparece (AaspProvider e log já mascaram)
        AaspIntegration::markSyncError($integrationId, $e->getMessage());
        AaspIntegration::audit(
            $accountId, $integrationId, 'sync_fail', $userId,
            "data={$data} erro=" . mb_substr($e->getMessage(), 0, 300)
        );
        PushQueryLog::finish($logId, [
            'status' => 'erro',
            'erro'   => mb_substr($e->getMessage(), 0, 500),
            'duracao_ms' => (int)((microtime(true) - $started) * 1000),
        ]);
        http_response_code(502);
        echo json_encode([
            'ok'              => false,
            'error'           => $e->getMessage(),
            'integration_id'  => $integrationId,
            'data_consultada' => $data,
            'duracao_ms'      => (int)((microtime(true) - $started) * 1000),
        ]);
    }
} catch (\Throwable $e) {
    if (!empty($logId)) {
        try { PushQueryLog::finish($logId, ['status' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 500)]); }
        catch (\Throwable $_) {}
    }
    \App\Core\ErrorReporter::handle($e);
}
