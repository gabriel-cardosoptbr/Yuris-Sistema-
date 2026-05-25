<?php
/**
 * push/tick.php — Cron do módulo Intimações.
 *
 * STEPS:
 *   1. Expira push_today_cache vencido (TTL 24h)
 *   2. Roda PushMonitorRunner.runDue() — processa até 20 monitores vencidos
 *      por execução, chama DJEN, popula cache, dispara notificações
 *
 * Protegido por CRON_TOKEN (mesma técnica de lgpd_retention_tick.php).
 * Recomendado: cron Windows roda a cada 10 minutos.
 *
 * Query params:
 *   token=<CRON_TOKEN>   obrigatório
 *   dry_run=1            só conta, não roda runner
 *   force=1              ignora lock
 *   skip_monitors=1      só expira cache, pula runner (útil em deploy/teste)
 *
 * Acesso: GET /sistema_vendas/public/api/push/tick.php?token=<CRON_TOKEN>
 */
ob_start();

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/PushTodayCache.php';
require_once __DIR__ . '/../../../app/Models/PushMonitor.php';
require_once __DIR__ . '/../../../app/Models/PushQueryLog.php';
require_once __DIR__ . '/../../../app/Models/AaspIntegration.php';
require_once __DIR__ . '/../../../app/Helpers/EnvLoader.php';
require_once __DIR__ . '/../../../app/Helpers/Crypto.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Services/Push/PublicationHasher.php';
require_once __DIR__ . '/../../../app/Services/Push/ProviderInterface.php';
require_once __DIR__ . '/../../../app/Services/Push/DjenProvider.php';
require_once __DIR__ . '/../../../app/Services/Push/AaspProvider.php';
require_once __DIR__ . '/../../../app/Services/Push/PushMonitorRunner.php';
require_once __DIR__ . '/../../../app/Services/Push/AaspSyncRunner.php';

use App\Models\Database;
use App\Models\PushTodayCache;
use App\Models\PushMonitor;
use App\Models\AaspIntegration;
use App\Helpers\EnvLoader;
use App\Services\Push\PushMonitorRunner;
use App\Services\Push\AaspSyncRunner;

EnvLoader::load();

$configFile = __DIR__ . '/../../../config/app.php';
$config     = file_exists($configFile) ? (require $configFile) : [];
$envToken   = EnvLoader::get('CRON_TOKEN', '');
$cronToken  = $config['cron_token'] ?? ($envToken !== '' ? $envToken : null);

header('Content-Type: application/json; charset=utf-8');

if (!$cronToken || $cronToken === 'yuris_cron_token_change_me') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'CRON_TOKEN não configurado']);
    exit;
}
if (($_GET['token'] ?? '') !== $cronToken) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$started = microtime(true);
$log     = [];
$dry     = !empty($_GET['dry_run']);
$force   = !empty($_GET['force']);

// ── Lock simples via arquivo (evita 2 ticks concorrentes) ──────────────────
$lockFile = __DIR__ . '/../../../storage/push_cron.lock';
$lockDir  = dirname($lockFile);
if (!is_dir($lockDir)) { @mkdir($lockDir, 0755, true); }

if (!$force && file_exists($lockFile)) {
    $lockAge = time() - @filemtime($lockFile);
    if ($lockAge < 300) { // 5 min
        echo json_encode([
            'ok'      => true,
            'skipped' => true,
            'reason'  => "Outro tick rodou há {$lockAge}s. Use ?force=1 para sobrescrever.",
        ]);
        exit;
    }
}
@touch($lockFile);

try {
    // ── STEP 1: Expira cache vencido ──────────────────────────────────────────
    $expired = 0;
    if ($dry) {
        $pdo = Database::getConnection();
        $st  = $pdo->query('SELECT COUNT(*) FROM push_today_cache WHERE expires_at <= NOW()');
        $expired = (int)$st->fetchColumn();
        $log[] = "[DRY] Cache expiraria {$expired} linhas";
    } else {
        $expired = PushTodayCache::expireOld();
        $log[]   = "[OK] Cache expirado: {$expired} linhas apagadas";
    }

    // ── STEP 2: Processar monitores DJEN vencidos via runner ─────────────────
    $runnerSummary = ['processed'=>0,'succeeded'=>0,'failed'=>0,'new_items'=>0,'notified'=>0,'details'=>[]];
    if ($dry || !empty($_GET['skip_monitors'])) {
        $due = PushMonitor::dueNow(20);
        $log[] = "[DRY/SKIP] Monitores DJEN vencidos: " . count($due) . " — runner não executado";
    } else {
        try {
            $runner = new PushMonitorRunner();
            $runnerSummary = $runner->runDue(20);
            $log[] = "[OK] DJEN: {$runnerSummary['processed']} processados, "
                  . "{$runnerSummary['succeeded']} ok, {$runnerSummary['failed']} erro, "
                  . "{$runnerSummary['new_items']} novos itens, {$runnerSummary['notified']} notificações";
            $log = array_merge($log, $runnerSummary['details']);
        } catch (\Throwable $re) {
            $log[] = "[ERR] DJEN runner: " . $re->getMessage();
            error_log('[push/tick djen-runner] ' . $re->getMessage());
        }
    }

    // ── STEP 3: Processar integrações AASP vencidas via AaspSyncRunner ────────
    $aaspSummary = ['processed'=>0,'succeeded'=>0,'failed'=>0,'new_items'=>0,'notified'=>0,'details'=>[]];
    if ($dry || !empty($_GET['skip_aasp'])) {
        $dueA = AaspIntegration::dueNow(20);
        $log[] = "[DRY/SKIP] Integrações AASP vencidas: " . count($dueA) . " — runner não executado";
    } else {
        try {
            $aaspRunner  = new AaspSyncRunner();
            $aaspSummary = $aaspRunner->runDue(20);
            $log[] = "[OK] AASP: {$aaspSummary['processed']} processados, "
                  . "{$aaspSummary['succeeded']} ok, {$aaspSummary['failed']} erro, "
                  . "{$aaspSummary['new_items']} novos itens, {$aaspSummary['notified']} notificações";
            $log = array_merge($log, $aaspSummary['details']);
        } catch (\Throwable $ae) {
            $log[] = "[ERR] AASP runner: " . $ae->getMessage();
            error_log('[push/tick aasp-runner] ' . $ae->getMessage());
        }
    }

    // ── STEP 4: Reset diário do contador "intimações de hoje" (uma vez/dia) ──
    // Roda só entre 00:00 e 00:14 pra evitar resetar a cada tick (cron a cada 10min).
    $dailyReset = 0;
    $hour = (int)date('G');
    $min  = (int)date('i');
    if (!$dry && $hour === 0 && $min < 15) {
        try {
            $dailyReset = AaspIntegration::resetDailyCounter();
            if ($dailyReset > 0) {
                $log[] = "[OK] Reset diário AASP: total_intimacoes_hoje zerado em {$dailyReset} integração(ões)";
            }
        } catch (\Throwable $re) {
            error_log('[push/tick daily-reset] ' . $re->getMessage());
        }
    }

    // ── Log no master_audit ───────────────────────────────────────────────────
    try {
        \App\Helpers\MasterAudit::log(
            'push.tick',
            'push_today_cache',
            0,
            'Cron de intimações executado' . ($dry ? ' (DRY RUN)' : ''),
            [
                'log'          => $log,
                'expired'      => $expired,
                'djen_runner'  => $runnerSummary,
                'aasp_runner'  => $aaspSummary,
                'daily_reset'  => $dailyReset,
            ]
        );
    } catch (\Throwable $_) {}

    @unlink($lockFile);
    ob_end_clean();
    echo json_encode([
        'ok'           => true,
        'dry_run'      => $dry,
        'ts'           => date('c'),
        'duracao_ms'   => (int)((microtime(true) - $started) * 1000),
        'expired'      => $expired,
        'djen_runner'  => $runnerSummary,
        'aasp_runner'  => $aaspSummary,
        'daily_reset'  => $dailyReset,
        'log'          => $log,
    ]);
} catch (\Throwable $e) {
    @unlink($lockFile);
    error_log('[push/tick] ' . $e->getMessage());
    http_response_code(500);
    ob_end_clean();
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'log'   => $log,
    ]);
}
