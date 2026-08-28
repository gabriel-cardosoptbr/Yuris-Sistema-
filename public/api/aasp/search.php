<?php
/**
 * aasp/search.php — Busca por período na AASP.
 *
 * Diferença do sync.php:
 *   • sync.php → 1 data fixa (hoje), pra cron/sincronização rotineira
 *   • search.php → range de datas (data_inicio..data_fim), pra busca ativa do usuário
 *
 * POST {
 *   _csrf,
 *   integration_id?,         // opcional — se omitido, usa 1ª integração active|pending
 *   data_inicio,             // YYYY-MM-DD (obrigatório)
 *   data_fim,                // YYYY-MM-DD (obrigatório)
 *   persistir?               // bool, default false — se true grava em push_today_cache (source_id='aasp')
 * }
 *
 * Estratégia (otimização anti-rate-limit):
 *   1. listDiasComIntimacoes(qtdeDias=range) → AASP devolve dias do range que TÊM intimação
 *   2. Filtra dias retornados pelos que caem em [data_inicio, data_fim]
 *   3. Pra cada dia válido → fetchPublications(data=dia)
 *   4. Acumula items, dedup por hash_conteudo (caso códigos diferentes retornem mesma pub)
 *   5. Retorna items SEM payload_original (LGPD)
 *
 * Cap defensivo: máximo 30 dias por busca (configurável via AASP_MAX_DAYS env).
 * Aviso LGPD: cliente deve mostrar banner "busca em tempo real, só salva se interagir".
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

    // ── Validar datas ──────────────────────────────────────────────────────
    $dataIni = (string)($payload['data_inicio'] ?? '');
    $dataFim = (string)($payload['data_fim']    ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'data_inicio e data_fim obrigatórios em formato YYYY-MM-DD']);
        exit;
    }
    if ($dataIni > $dataFim) {
        // Auto-swap se trocaram a ordem
        [$dataIni, $dataFim] = [$dataFim, $dataIni];
    }
    $tsIni  = strtotime($dataIni);
    $tsFim  = strtotime($dataFim);
    $diffDays = (int)floor(($tsFim - $tsIni) / 86400) + 1;

    EnvLoader::load();
    $maxDays = max(1, (int)EnvLoader::get('AASP_MAX_DAYS', '30'));
    if ($diffDays > $maxDays) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => "Período máximo permitido é {$maxDays} dias por busca. Você pediu {$diffDays} dias. Faça em pedaços ou ajuste AASP_MAX_DAYS no .env.",
        ]);
        exit;
    }
    if ($tsFim > time()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'data_fim não pode estar no futuro']);
        exit;
    }

    // ── Escolher integração ────────────────────────────────────────────────
    $integrationId = (int)($payload['integration_id'] ?? 0);
    if ($integrationId > 0) {
        $integ = AaspIntegration::find($integrationId, $accountId);
        if (!$integ) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Integração AASP não encontrada nesta conta']);
            exit;
        }
    } else {
        // Auto-escolha: primeira active/pending
        $list = AaspIntegration::listForAccount($accountId);
        $integ = null;
        foreach ($list as $i) {
            if (in_array($i['status'], ['active', 'pending'], true)) {
                $integ = $i;
                break;
            }
        }
        if (!$integ) {
            http_response_code(409);
            echo json_encode([
                'ok'    => false,
                'error' => 'Nenhuma integração AASP ativa/pendente neste tenant. Cadastre uma chave AASP primeiro.',
            ]);
            exit;
        }
        $integrationId = (int)$integ['id'];
    }
    if ($integ['status'] === 'inactive') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Integração marcada como inativa']);
        exit;
    }

    $persistir = !empty($payload['persistir']);
    $codigos   = is_array($integ['codigos_associados']) ? $integ['codigos_associados'] : [];

    $baseUrl     = EnvLoader::get('AASP_BASE_URL', 'https://intimacaoapi.aasp.org.br');
    $rateLimitMs = (int)EnvLoader::get('AASP_RATE_LIMIT_MS', '1000');
    $provider    = new AaspProvider($baseUrl, 25, $rateLimitMs);

    // ── Log inicial ────────────────────────────────────────────────────────
    $logId = PushQueryLog::start([
        'account_id'         => $accountId,
        'source_id'          => 'aasp',
        'origem_busca'       => 'manual',
        'data_inicio_filtro' => $dataIni,
        'data_fim_filtro'    => $dataFim,
        'request_url'        => '/api/aasp/search.php?int=' . $integrationId,
        'filtros_hash'       => PublicationHasher::filtersHash([
            'integration_id' => $integrationId,
            'data_inicio'    => $dataIni,
            'data_fim'       => $dataFim,
        ]),
    ]);

    $started = microtime(true);
    try {
        $chave = AaspIntegration::getChavePlain($integrationId, $accountId);

        // ── Passo 1: descobrir dias com intimação dentro do range ──────────
        // Otimização: evita chamar /intimacao em dias vazios. AASP devolve
        // os últimos N dias com intimação; filtramos pelos que caem no range.
        $qtdeDiasJornal = max(1, min(365, (int)floor((time() - $tsIni) / 86400) + 1));
        $diasDisponiveis = [];
        $jornaisStatus   = 'ok';        // 'ok' | 'erro' | 'vazio'
        $jornaisDatas    = [];          // datas brutas que a AASP devolveu (qualquer período)
        $usedFallback    = false;
        try {
            // Modo empresa: precisa de codigoPessoaAssociado em GetJornaisComIntimacoes.
            // Pegamos o primeiro código pra fazer a sondagem (cobre o caso comum).
            $codigoSonda = ($integ['tipo'] === 'empresa' && !empty($codigos)) ? (int)$codigos[0] : null;
            $jornais = $provider->listDiasComIntimacoes($chave, $integ['tipo'], $qtdeDiasJornal, $codigoSonda);
            $jornaisDatas = $jornais['datas'];
            foreach ($jornais['datas'] as $d) {
                if ($d >= $dataIni && $d <= $dataFim) $diasDisponiveis[] = $d;
            }
            if (empty($diasDisponiveis)) {
                $jornaisStatus = 'vazio';
            }
        } catch (\RuntimeException $jornEx) {
            $jornaisStatus = 'erro';
            error_log('[aasp/search] GetJornaisComIntimacoes falhou, fallback: ' . $jornEx->getMessage());
        }

        // Fallback dia-a-dia em 2 casos:
        //   1) GetJornaisComIntimacoes deu erro (chave não suporta endpoint)
        //   2) GetJornaisComIntimacoes retornou OK mas com 0 dias no range
        //      (pode ser real, mas pode ser falso negativo — confirma 1 por 1)
        if ($jornaisStatus === 'erro' || $jornaisStatus === 'vazio') {
            $usedFallback = true;
            for ($ts = $tsIni; $ts <= $tsFim; $ts += 86400) {
                $diasDisponiveis[] = date('Y-m-d', $ts);
            }
        }
        $diasDisponiveis = array_values(array_unique($diasDisponiveis));
        sort($diasDisponiveis);

        // ── Passo 2: pra cada dia, fetchPublications ───────────────────────
        $allItems  = [];
        $seenHash  = [];
        $callCount = 0;
        $erros     = [];
        foreach ($diasDisponiveis as $dia) {
            $callCount++;
            try {
                $result = $provider->fetchPublications(
                    ['data' => $dia],
                    [
                        'chave'              => $chave,
                        'tipo'               => $integ['tipo'],
                        'codigos_associados' => $codigos,
                        'diferencial'        => false,  // manual = sempre traz tudo
                        'timeout_seconds'    => 25,
                    ]
                );
                foreach ($result['items'] as $it) {
                    if (isset($seenHash[$it['hash_conteudo']])) continue;
                    $seenHash[$it['hash_conteudo']] = true;
                    $allItems[] = $it;
                }
            } catch (\RuntimeException $e) {
                $erros[] = "dia {$dia}: " . $e->getMessage();
                if (count($erros) >= 3) {
                    // Se 3 dias seguidos falharem, aborta — provavelmente chave expirou
                    throw new \RuntimeException(
                        'AASP retornou erro em múltiplos dias. Último: ' . $e->getMessage()
                    );
                }
            }
        }
        unset($chave);  // libera referência à credencial em RAM

        // ── Passo 3: persistir se solicitado ───────────────────────────────
        $cached  = 0;
        $expires = date('Y-m-d') . ' 23:59:59';
        if ($persistir) {
            foreach ($allItems as $it) {
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
        }

        // Log final
        PushQueryLog::finish($logId, [
            'response_status'  => 200,
            'response_hash'    => hash('sha256', json_encode(array_column($allItems, 'hash_conteudo'))),
            'total_resultados' => count($allItems),
            'status'           => 'ok',
            'duracao_ms'       => (int)((microtime(true) - $started) * 1000),
        ]);

        // Audit
        AaspIntegration::audit(
            $accountId, $integrationId, 'sync_ok', $userId,
            "search {$dataIni}..{$dataFim} dias=" . count($diasDisponiveis)
            . " total=" . count($allItems) . ($persistir ? " cached={$cached}" : ' (não-persistido)')
        );

        // Strip payload_original do response (LGPD)
        $publicItems = array_map(static function ($it) {
            unset($it['payload_original']);
            return $it;
        }, $allItems);

        // Diagnóstico: primeiro item RAW da AASP (pra UI mostrar quando cards ficam vazios
        // e ajustar o normalize). É dado da própria conta — não há vazamento.
        $firstItemRaw       = null;
        $firstItemRawKeys   = [];
        if (!empty($allItems)) {
            $firstItemRaw = $allItems[0]['payload_original'] ?? null;
            if (is_array($firstItemRaw)) {
                $firstItemRawKeys = array_keys($firstItemRaw);
            }
        }

        echo json_encode([
            'ok'                  => true,
            'integration_id'      => $integrationId,
            'integration_nome'    => $integ['nome'],
            'data_inicio'         => $dataIni,
            'data_fim'            => $dataFim,
            'dias_no_range'       => $diffDays,
            'dias_com_intimacao'  => $diasDisponiveis,
            'jornais_status'      => $jornaisStatus,         // 'ok' | 'erro' | 'vazio'
            'jornais_datas_raw'   => $jornaisDatas,          // o que a AASP listou (qualquer período)
            'used_fallback'       => $usedFallback,           // true se consultou dia-a-dia em vez de usar GetJornais
            'request_count'       => $callCount,
            'total'               => count($allItems),
            'persistido'          => $persistir,
            'cached_novos'        => $cached,
            'duracao_ms'          => (int)((microtime(true) - $started) * 1000),
            'erros_parciais'      => $erros,
            'items'               => $publicItems,
            'first_item_raw'      => $firstItemRaw,           // payload original do 1º item — diagnóstico
            'first_item_raw_keys' => $firstItemRawKeys,       // só os nomes dos campos — diagnóstico rápido
            'aviso_lgpd'          => $persistir
                ? null
                : 'Esta busca consulta a AASP em tempo real. Os resultados serão salvos apenas se você marcar como lida, favoritar, comentar ou vincular ao processo.',
        ]);
    } catch (\RuntimeException $e) {
        AaspIntegration::markSyncError($integrationId, $e->getMessage());
        AaspIntegration::audit(
            $accountId, $integrationId, 'sync_fail', $userId,
            "search {$dataIni}..{$dataFim} erro=" . mb_substr($e->getMessage(), 0, 300)
        );
        PushQueryLog::finish($logId, [
            'status'     => 'erro',
            'erro'       => mb_substr($e->getMessage(), 0, 500),
            'duracao_ms' => (int)((microtime(true) - $started) * 1000),
        ]);
        http_response_code(502);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
        ]);
    }
} catch (\Throwable $e) {
    if (!empty($logId)) {
        try { PushQueryLog::finish($logId, ['status' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 500)]); }
        catch (\Throwable $_) {}
    }
    \App\Core\ErrorReporter::handle($e);
}
