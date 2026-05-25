<?php
namespace App\Services\Push;

require_once __DIR__ . '/../../Models/Database.php';
require_once __DIR__ . '/../../Models/AaspIntegration.php';
require_once __DIR__ . '/../../Models/PushTodayCache.php';
require_once __DIR__ . '/../../Models/PushQueryLog.php';
require_once __DIR__ . '/../../Models/AccountNotification.php';
require_once __DIR__ . '/../../Helpers/EnvLoader.php';
require_once __DIR__ . '/PublicationHasher.php';
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/AaspProvider.php';

use App\Models\AaspIntegration;
use App\Models\PushTodayCache;
use App\Models\PushQueryLog;
use App\Models\AccountNotification;
use App\Helpers\EnvLoader;

/**
 * AaspSyncRunner — processa integrações AASP vencidas.
 *
 * Para cada integração com status='active' e proxima_sync_em <= NOW():
 *   1. Decifra chave com Crypto (uso interno via AaspIntegration::getChavePlain)
 *   2. Chama AaspProvider com diferencial=true (só intimações que essa chave
 *      ainda não consultou — comportamento incremental nativo da AASP)
 *   3. Persiste novidades em push_today_cache (UNIQUE garante idempotência)
 *   4. markSyncSuccess (zera erros, agenda próxima sync)
 *   5. Notificação interna se houver intimações novas
 *
 * Em caso de erro:
 *   - markSyncError (backoff exponencial até 24h)
 *   - audit('sync_fail')
 *   - Próximas integrações continuam (1 erro não interrompe o batch)
 *
 * Multi-tenant: roda sem AccountContext (cron cross-conta). Cada gravação
 * respeita account_id da própria integração — sem vazamento entre tenants.
 *
 * Espelha o padrão de PushMonitorRunner (DJEN) — separação por provider
 * permite cron diferenciado por fonte.
 */
final class AaspSyncRunner
{
    private AaspProvider $provider;
    private int          $rateLimitMs;

    public function __construct(?AaspProvider $provider = null, int $rateLimitMs = 1000)
    {
        if (!$provider) {
            EnvLoader::load();
            $base = EnvLoader::get('AASP_BASE_URL', 'https://intimacaoapi.aasp.org.br');
            $rl   = (int)EnvLoader::get('AASP_RATE_LIMIT_MS', '1000');
            $provider = new AaspProvider($base, 25, $rl);
        }
        $this->provider    = $provider;
        $this->rateLimitMs = max(0, $rateLimitMs);
    }

    /**
     * Processa até $batchSize integrações vencidas.
     * Retorna ['processed','succeeded','failed','new_items','notified','details'].
     */
    public function runDue(int $batchSize = 20): array
    {
        $integrations = AaspIntegration::dueNow($batchSize);

        $summary = [
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'new_items' => 0,
            'notified'  => 0,
            'details'   => [],
        ];

        foreach ($integrations as $integ) {
            $summary['processed']++;
            try {
                $r = $this->runOne($integ);
                $summary['new_items'] += $r['new_items'];
                $summary['succeeded']++;
                if ($r['notified']) $summary['notified']++;
                $summary['details'][] = "[OK] aasp#{$integ['id']} ({$integ['nome']}): "
                    . "{$r['total']} encontrados, {$r['new_items']} novos"
                    . ($r['notified'] ? ' [notificado]' : '');
            } catch (\Throwable $e) {
                $summary['failed']++;
                AaspIntegration::markSyncError((int)$integ['id'], $e->getMessage());
                AaspIntegration::audit(
                    (int)$integ['account_id'],
                    (int)$integ['id'],
                    'sync_fail',
                    null,  // cron — sem actor user
                    'cron: ' . mb_substr($e->getMessage(), 0, 300)
                );
                $summary['details'][] = "[ERR] aasp#{$integ['id']}: " . $e->getMessage();
                error_log('[AaspSyncRunner] aasp#' . $integ['id'] . ': ' . $e->getMessage());
            }

            // Rate limit entre integrações (anti-flood da AASP)
            if ($this->rateLimitMs > 0 && $summary['processed'] < count($integrations)) {
                usleep($this->rateLimitMs * 1000);
            }
        }

        return $summary;
    }

    /**
     * Roda 1 integração. Retorna ['total','new_items','notified'].
     *
     * @throws \RuntimeException em erro de rede/auth/decryption
     */
    public function runOne(array $integ): array
    {
        $accountId     = (int)$integ['account_id'];
        $integrationId = (int)$integ['id'];
        $nome          = (string)$integ['nome'];
        $tipo          = (string)$integ['tipo'];
        $codigos       = is_array($integ['codigos_associados'] ?? null)
            ? $integ['codigos_associados'] : [];
        $hoje          = date('Y-m-d');

        // ── Log inicial ────────────────────────────────────────────────────
        $logId = PushQueryLog::start([
            'account_id'         => $accountId,
            'source_id'          => 'aasp',
            'origem_busca'       => 'automatica',
            'data_inicio_filtro' => $hoje,
            'data_fim_filtro'    => $hoje,
            'request_url'        => 'cron://aasp_sync_runner?int=' . $integrationId,
            'filtros_hash'       => PublicationHasher::filtersHash([
                'integration_id' => $integrationId,
                'data'           => $hoje,
                'diferencial'    => true,
            ]),
        ]);

        $started = microtime(true);

        // ── Decifra chave (em RAM apenas durante a chamada) ───────────────
        $chave = AaspIntegration::getChavePlain($integrationId, $accountId);

        // ── Provider call com diferencial=true (incremental) ──────────────
        // diferencial=true → AASP devolve só o que essa chave ainda não consultou
        // (sync rotineiro). Manual usa false (idempotente, traz tudo do dia).
        $result = $this->provider->fetchPublications(
            ['data' => $hoje],
            [
                'chave'              => $chave,
                'tipo'               => $tipo,
                'codigos_associados' => $codigos,
                'diferencial'        => true,
                'timeout_seconds'    => 25,
            ]
        );
        unset($chave);  // libera referência à credencial

        $items     = $result['items'] ?? [];
        $expiresAt = date('Y-m-d') . ' 23:59:59';
        $newCount  = 0;

        // ── Persistir em push_today_cache (idempotente via UNIQUE hash) ───
        foreach ($items as $it) {
            $inserted = PushTodayCache::upsert([
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
                'expires_at'              => $expiresAt,
            ]);
            if ($inserted) $newCount++;
        }

        // ── Atualizar estado da integração (avança proxima_sync_em) ───────
        AaspIntegration::markSyncSuccess($integrationId, $newCount, true);
        AaspIntegration::audit(
            $accountId, $integrationId, 'sync_ok', null,
            "cron: data={$hoje} total=" . count($items) . " novos={$newCount} diferencial=1"
        );

        // ── Notificar internamente se houver novidade ─────────────────────
        $notified = false;
        if ($newCount > 0) {
            $this->notifyNewPublications(
                $accountId,
                !empty($integ['created_by']) ? (int)$integ['created_by'] : null,
                $integrationId,
                $nome,
                $newCount,
                $items
            );
            $notified = true;
        }

        // ── Log final ──────────────────────────────────────────────────────
        PushQueryLog::finish($logId, [
            'response_status'  => $result['raw_meta']['status_http'] ?? 200,
            'response_hash'    => hash('sha256', json_encode(array_column($items, 'hash_conteudo'))),
            'total_resultados' => count($items),
            'status'           => 'ok',
            'duracao_ms'       => (int)((microtime(true) - $started) * 1000),
        ]);

        return [
            'total'     => count($items),
            'new_items' => $newCount,
            'notified'  => $notified,
        ];
    }

    /**
     * Cria notificação interna sobre novas publicações encontradas no cron.
     * Se created_by definido → notificação pessoal; senão → notificação da conta.
     */
    private function notifyNewPublications(
        int $accountId,
        ?int $userId,
        int $integrationId,
        string $nome,
        int $count,
        array $items
    ): void {
        // Pega até 3 amostras pra mensagem
        $samples = array_slice($items, 0, 3);
        $sampleTxt = [];
        foreach ($samples as $s) {
            $proc  = $s['numero_processo_mascara'] ?: ($s['numero_processo'] ?: '—');
            $orgao = mb_substr($s['orgao'] ?? '', 0, 60);
            $sampleTxt[] = "• {$s['tribunal']} — {$proc} ({$orgao})";
        }
        $titulo = "{$count} nova(s) publicação(ões) AASP — " . $nome;
        $msg    = "Sincronização automática AASP encontrou:\n" . implode("\n", $sampleTxt);
        if ($count > 3) $msg .= "\n…e mais " . ($count - 3) . ".";

        try {
            AccountNotification::criar([
                'account_id' => $accountId,
                'user_id'    => $userId,
                'tipo'       => 'intimacao',
                'titulo'     => $titulo,
                'mensagem'   => $msg,
                'payload'    => [
                    'integration_id' => $integrationId,
                    'source_id'      => 'aasp',
                    'count'          => $count,
                    'url'            => '/sistema_vendas/public/intimacoes.php',
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[AaspSyncRunner] notify falhou: ' . $e->getMessage());
        }
    }
}
