<?php
namespace App\Processos\Monitor;

require_once __DIR__ . '/../../Core/Database.php';
require_once __DIR__ . '/../PushMonitor.php';
require_once __DIR__ . '/../PushTodayCache.php';
require_once __DIR__ . '/../PushQueryLog.php';
require_once __DIR__ . '/../../Master/AccountNotification.php';
require_once __DIR__ . '/../../Core/EnvLoader.php';

use App\Processos\PushMonitor;
use App\Processos\PushTodayCache;
use App\Processos\PushQueryLog;
use App\Master\AccountNotification;
use App\Core\EnvLoader;

/**
 * PushMonitorRunner — processa monitores vencidos.
 *
 * Para cada monitor due:
 *   1. Mapeia tipo + valor + uf + tribunal pra filtros DJEN
 *   2. Calcula janela de busca (overlap c/ última consulta pra não perder)
 *   3. Chama DjenProvider
 *   4. Hash do conjunto retornado vs ultimo_hash_resultado
 *   5. Persiste novidades em push_today_cache
 *   6. Cria notificação interna se houver novidade
 *   7. markSuccess (avança proxima_consulta_em) ou markError (backoff)
 *
 * Cross-tenant: roda sem AccountContext (é cron), mas cada gravação respeita
 * o account_id do próprio monitor — sem vazamento.
 */
final class PushMonitorRunner
{
    private DjenProvider $djen;
    private int          $rateLimitMs;

    public function __construct(?DjenProvider $djen = null, int $rateLimitMs = 1000)
    {
        if (!$djen) {
            EnvLoader::load();
            $base = EnvLoader::get('DJEN_BASE_URL', 'https://comunicaapi.pje.jus.br/api/v1');
            $djen = new DjenProvider($base);
        }
        $this->djen        = $djen;
        $this->rateLimitMs = max(0, $rateLimitMs);
    }

    /**
     * Processa até $batchSize monitores vencidos.
     * Retorna resumo: ['processed','succeeded','failed','new_items','notified'].
     */
    public function runDue(int $batchSize = 20): array
    {
        $monitors = PushMonitor::dueNow($batchSize);

        $summary = [
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'new_items' => 0,
            'notified'  => 0,
            'details'   => [],
        ];

        foreach ($monitors as $m) {
            $summary['processed']++;
            try {
                $r = $this->runOne($m);
                $summary['new_items'] += $r['new_items'];
                $summary['succeeded']++;
                if ($r['notified']) $summary['notified']++;
                $summary['details'][] = "[OK] mon#{$m['id']} ({$m['tipo_monitoramento']}={$m['valor_monitorado']}): {$r['total']} encontrados, {$r['new_items']} novos";
            } catch (\Throwable $e) {
                $summary['failed']++;
                PushMonitor::markError((int)$m['id'], $e->getMessage());
                $summary['details'][] = "[ERR] mon#{$m['id']}: " . $e->getMessage();
                error_log('[PushMonitorRunner] mon#' . $m['id'] . ': ' . $e->getMessage());
            }

            // Rate limit entre monitores (anti-flood do CNJ)
            if ($this->rateLimitMs > 0 && $summary['processed'] < count($monitors)) {
                usleep($this->rateLimitMs * 1000);
            }
        }

        return $summary;
    }

    /**
     * Roda 1 monitor. Retorna ['total','new_items','notified'].
     */
    public function runOne(array $m): array
    {
        $accountId  = (int)$m['account_id'];
        $monitorId  = (int)$m['id'];
        $tipo       = (string)$m['tipo_monitoramento'];
        $valor      = (string)$m['valor_monitorado'];
        $nomeComp   = (string)($m['nome_complementar'] ?? '');
        $uf         = (string)($m['uf'] ?? '');
        $tribunal   = (string)($m['tribunal'] ?? '');
        $createdBy  = !empty($m['created_by']) ? (int)$m['created_by'] : null;

        // ── Mapear tipo → filtros DJEN ──────────────────────────────────────────
        $filters = [];
        switch ($tipo) {
            case 'oab':
                if ($uf !== '' && !preg_match('/^[A-Z]{2}/', $valor)) {
                    $filters['numero_oab'] = strtoupper($uf) . $valor;
                } else {
                    $filters['numero_oab'] = $valor;
                }
                break;
            case 'processo':
                $filters['numero_processo'] = preg_replace('/[^0-9]/', '', $valor);
                break;
            case 'nome':
                $filters['nome_advogado'] = $valor;
                break;
            case 'customizado':
            default:
                throw new \RuntimeException("Tipo de monitoramento '{$tipo}' não suportado nesta versão");
        }

        // Filtro AND adicional: nome_complementar — usado pra combinar OAB+Nome
        // numa busca única (precisão 100% — evita homônimos com mesma OAB)
        if ($nomeComp !== '' && empty($filters['nome_advogado'])) {
            $filters['nome_advogado'] = $nomeComp;
        }

        if ($tribunal !== '') $filters['sigla_tribunal'] = $tribunal;

        // ── Janela de busca: overlap de 1 dia com última consulta ────────────────
        $hoje = date('Y-m-d');
        if (!empty($m['ultima_consulta_em'])) {
            $filters['data_inicio'] = date('Y-m-d', max(strtotime($m['ultima_consulta_em']) - 86400, strtotime('-7 days')));
        } else {
            $filters['data_inicio'] = date('Y-m-d', strtotime('-7 days'));
        }
        $filters['data_fim'] = $hoje;

        // ── Log inicial ─────────────────────────────────────────────────────────
        $logId = PushQueryLog::start([
            'account_id'         => $accountId,
            'monitor_id'         => $monitorId,
            'source_id'          => 'djen',
            'origem_busca'       => 'automatica',
            'data_inicio_filtro' => $filters['data_inicio'],
            'data_fim_filtro'    => $filters['data_fim'],
            'request_url'        => 'cron://push_monitor_runner',
            'filtros_hash'       => PublicationHasher::filtersHash($filters),
        ]);

        // ── Provider call ───────────────────────────────────────────────────────
        // limit=500 cobre semana inteira mesmo pra advogados ativos.
        // Cron roda a cada 2h, então cobertura > volume real esperado.
        $started = microtime(true);
        $result  = $this->djen->fetchPublications($filters, ['limit' => 500, 'timeout_seconds' => 25]);
        $items   = $result['items'] ?? [];

        // ── Hash do conjunto retornado ──────────────────────────────────────────
        $hashes = array_map(static fn($it) => $it['hash_conteudo'], $items);
        sort($hashes);
        $resultHash = hash('sha256', implode('|', $hashes));

        // ── Persistir em push_today_cache (idempotente) — conta novos de fato ──
        $expiresAt = date('Y-m-d') . ' 23:59:59';
        $newCount  = 0;
        foreach ($items as $it) {
            $inserted = PushTodayCache::upsert([
                'account_id'              => $accountId,
                'monitor_id'              => $monitorId,
                'source_id'               => $it['source_id'],
                'tribunal'                => $it['tribunal'],
                'data_disponibilizacao'   => $it['data_disponibilizacao'],
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

        // ── Decidir se notifica: hash novo E pelo menos 1 item novo ─────────────
        $hashChanged = ($resultHash !== ($m['ultimo_hash_resultado'] ?? ''));
        $notified    = false;
        if ($hashChanged && $newCount > 0) {
            $this->notifyNewPublications($accountId, $createdBy, $monitorId, $tipo, $valor, $newCount, $items);
            $notified = true;
        }

        // ── Atualiza estado do monitor (avança proxima_consulta_em) ─────────────
        PushMonitor::markSuccess($monitorId, $resultHash);

        // ── Log final ───────────────────────────────────────────────────────────
        PushQueryLog::finish($logId, [
            'response_status'  => $result['raw_meta']['status_http'] ?? 200,
            'response_hash'    => $resultHash,
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
     * Cria notificação interna sobre novas publicações encontradas.
     * Se created_by definido → notificação pessoal; senão → notificação da conta (user_id NULL).
     */
    private function notifyNewPublications(int $accountId, ?int $userId, int $monitorId, string $tipo, string $valor, int $count, array $items): void
    {
        // Pega até 3 amostras pra mensagem
        $samples = array_slice($items, 0, 3);
        $sampleTxt = [];
        foreach ($samples as $s) {
            $proc = $s['numero_processo_mascara'] ?: ($s['numero_processo'] ?: '—');
            $orgao = mb_substr($s['orgao'] ?? '', 0, 60);
            $sampleTxt[] = "• {$s['tribunal']} — {$proc} ({$orgao})";
        }
        $titulo  = "{$count} nova(s) publicação(ões) para " . strtoupper($tipo) . ' ' . $valor;
        $msg     = "Encontradas em monitoramento automático:\n" . implode("\n", $sampleTxt);
        if ($count > 3) $msg .= "\n…e mais " . ($count - 3) . ".";

        try {
            AccountNotification::criar([
                'account_id' => $accountId,
                'user_id'    => $userId,
                'tipo'       => 'intimacao',
                'titulo'     => $titulo,
                'mensagem'   => $msg,
                'payload'    => [
                    'monitor_id' => $monitorId,
                    'tipo'       => $tipo,
                    'valor'      => $valor,
                    'count'      => $count,
                    'url'        => '/intimacoes.php',
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[PushMonitorRunner] notify falhou: ' . $e->getMessage());
        }
    }
}
