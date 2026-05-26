<?php
/**
 * push/search.php — Busca publicações em tempo real no provider (DJEN).
 *
 * POST { _csrf, mode: 'cache_hoje'|'manual', filters: {...} }
 *
 * MODO 'cache_hoje' (default):
 *   - Buscar publicações de HOJE no DJEN
 *   - Popular push_today_cache (TTL 24h)
 *   - Retornar items normalizados
 *
 * MODO 'manual':
 *   - Buscar com filtros customizados (data passada, OAB, tribunal, processo)
 *   - NÃO persiste nada — só devolve em memória
 *   - Frontend mostra aviso "salvo apenas se interagir"
 *
 * Sempre loga em push_query_logs (LGPD-aware).
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/PushTodayCache.php';
require_once __DIR__ . '/../../../app/Models/PushQueryLog.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/EnvLoader.php';
require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
require_once __DIR__ . '/../../../app/Services/Push/PublicationHasher.php';
require_once __DIR__ . '/../../../app/Services/Push/ProviderInterface.php';
require_once __DIR__ . '/../../../app/Services/Push/DjenProvider.php';

use App\Helpers\AccountContext;
use App\Helpers\EnvLoader;
use App\Models\PushTodayCache;
use App\Models\PushQueryLog;
use App\Services\Push\PublicationHasher;
use App\Services\Push\DjenProvider;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($payload['_csrf']) || $payload['_csrf'] !== $csrfSession) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF inválido']);
    exit;
}

try {
    $ctx        = AccountContext::fromSession();
    $accountId  = $ctx->getAccountId();
    $mode       = ($payload['mode'] ?? 'cache_hoje') === 'manual' ? 'manual' : 'cache_hoje';
    $rawFilters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

    // Sanitização e allowlist de filtros
    $filters = [
        'numero_oab'      => isset($rawFilters['numero_oab'])      ? trim((string)$rawFilters['numero_oab'])      : '',
        'sigla_tribunal'  => isset($rawFilters['sigla_tribunal'])  ? strtoupper(preg_replace('/[^A-Z0-9-]/i', '', (string)$rawFilters['sigla_tribunal'])) : '',
        'numero_processo' => isset($rawFilters['numero_processo']) ? preg_replace('/[^0-9\-.]/', '', (string)$rawFilters['numero_processo']) : '',
        'data_inicio'     => isset($rawFilters['data_inicio'])     && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFilters['data_inicio']) ? $rawFilters['data_inicio'] : '',
        'data_fim'        => isset($rawFilters['data_fim'])        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFilters['data_fim'])    ? $rawFilters['data_fim']    : '',
        'nome_advogado'   => isset($rawFilters['nome_advogado'])   ? trim((string)$rawFilters['nome_advogado'])   : '',
    ];

    // Modo cache_hoje: forçar janela = hoje
    if ($mode === 'cache_hoje') {
        $hoje = date('Y-m-d');
        $filters['data_inicio'] = $hoje;
        $filters['data_fim']    = $hoje;
    }

    // ── Validação pré-voo: datas futuras quebram a DJEN com 422 ─────────
    // A DJEN só tem publicações até o dia útil corrente. Se o relógio do
    // servidor está no futuro (ex: ambiente de teste em 2026), a chamada
    // retorna 422 sem mensagem útil. Devolve erro local amigável.
    $hojeReal = date('Y-m-d');
    $dInicio  = $filters['data_inicio'] ?: null;
    $dFim     = $filters['data_fim']    ?: null;
    if ($dInicio && $dInicio > $hojeReal) {
        http_response_code(400);
        echo json_encode([
            'error' => "Data início ({$dInicio}) é futura — a DJEN só tem dados até {$hojeReal}.",
        ]);
        exit;
    }
    if ($dFim && $dFim > $hojeReal) {
        http_response_code(400);
        echo json_encode([
            'error' => "Data fim ({$dFim}) é futura — a DJEN só tem dados até {$hojeReal}.",
        ]);
        exit;
    }
    // Janela máxima da DJEN: ~90 dias por requisição
    if ($dInicio && $dFim) {
        $diff = (strtotime($dFim) - strtotime($dInicio)) / 86400;
        if ($diff > 90) {
            http_response_code(400);
            echo json_encode([
                'error' => "Período muito longo (" . (int)$diff . " dias). Máximo aceito pela DJEN: 90 dias por busca.",
            ]);
            exit;
        }
    }

    // Provider DJEN
    EnvLoader::load();
    $baseUrl = EnvLoader::get('DJEN_BASE_URL', 'https://comunicaapi.pje.jus.br/api/v1');
    $provider = new DjenProvider($baseUrl);

    // Log inicial
    $logId = PushQueryLog::start([
        'account_id'         => $accountId,
        'source_id'          => 'djen',
        'origem_busca'       => $mode === 'cache_hoje' ? 'cache_hoje' : 'manual',
        'data_inicio_filtro' => $filters['data_inicio'] ?: null,
        'data_fim_filtro'    => $filters['data_fim']    ?: null,
        'request_url'        => '/api/push/search.php',
        'filtros_hash'       => PublicationHasher::filtersHash($filters),
    ]);

    $started = microtime(true);
    // Limit 500 cobre períodos longos (~3 meses de busca pesada). DJEN aceita
    // até 1000, mas 500 é o melhor custo/benefício pra timeout e payload.
    $result  = $provider->fetchPublications($filters, ['limit' => 500, 'timeout_seconds' => 25]);
    $items   = $result['items'] ?? [];

    // Modo cache_hoje: persistir em push_today_cache (TTL fim do dia)
    $cachedCount = 0;
    if ($mode === 'cache_hoje') {
        $expiresAt = date('Y-m-d') . ' 23:59:59';
        foreach ($items as $it) {
            $ok = PushTodayCache::upsert([
                'account_id'              => $accountId,
                'source_id'               => $it['source_id'],
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
                'expires_at'              => $expiresAt,
            ]);
            if ($ok) $cachedCount++;
        }
    }

    // Log final
    PushQueryLog::finish($logId, [
        'response_status'  => $result['raw_meta']['status_http'] ?? 200,
        'response_hash'    => hash('sha256', json_encode(array_column($items, 'hash_conteudo'))),
        'total_resultados' => count($items),
        'status'           => 'ok',
        'duracao_ms'       => (int)((microtime(true) - $started) * 1000),
    ]);

    // Resposta — payload_original NÃO vai pro frontend (LGPD: menos PII no wire)
    $publicItems = array_map(static function ($it) {
        unset($it['payload_original']);
        return $it;
    }, $items);

    echo json_encode([
        'ok'             => true,
        'mode'           => $mode,
        'total'          => count($publicItems),
        'cached'         => $cachedCount,
        'items'          => $publicItems,
        'persistido'     => $mode === 'cache_hoje',
        'aviso_lgpd'     => $mode === 'manual'
            ? 'Esta busca consulta fontes oficiais em tempo real. Os resultados serão salvos apenas se você marcar como lida, favoritar, comentar ou vincular ao processo.'
            : null,
    ]);

} catch (\RuntimeException $e) {
    // Provider error — log + 502
    if (!empty($logId)) {
        PushQueryLog::finish($logId, [
            'status' => 'erro',
            'erro'   => $e->getMessage(),
        ]);
    }
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    if (!empty($logId)) {
        PushQueryLog::finish($logId, [
            'status' => 'erro',
            'erro'   => $e->getMessage(),
        ]);
    }
    \App\Helpers\ErrorReporter::handle($e);
}
