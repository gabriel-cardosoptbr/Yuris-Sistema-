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
require_once __DIR__ . '/../../../app/Helpers/EnvLoader.php';
require_once __DIR__ . '/../../../app/Helpers/MasterAudit.php';
require_once __DIR__ . '/../../../app/Services/Push/PublicationHasher.php';
require_once __DIR__ . '/../../../app/Services/Push/ProviderInterface.php';
require_once __DIR__ . '/../../../app/Services/Push/DjenProvider.php';
require_once __DIR__ . '/../../../app/Services/Push/PushMonitorRunner.php';

use App\Models\Database;
use App\Models\PushTodayCache;
use App\Models\PushMonitor;
use App\Helpers\EnvLoader;
use App\Services\Push\PushMonitorRunner;

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

    // ── STEP 2: Processar monitores vencidos via runner ───────────────────────
    $runnerSummary = ['processed'=>0,'succeeded'=>0,'failed'=>0,'new_items'=>0,'notified'=>0,'details'=>[]];
    if ($dry || !empty($_GET['skip_monitors'])) {
        $due = PushMonitor::dueNow(20);
        $log[] = "[DRY/SKIP] Monitores vencidos: " . count($due) . " — runner não executado";
    } else {
        try {
            $runner = new PushMonitorRunner();
            $runnerSummary = $runner->runDue(20);
            $log[] = "[OK] Runner: {$runnerSummary['processed']} processados, "
                  . "{$runnerSummary['succeeded']} ok, {$runnerSummary['failed']} erro, "
                  . "{$runnerSummary['new_items']} novos itens, {$runnerSummary['notified']} notificações";
            $log = array_merge($log, $runnerSummary['details']);
        } catch (\Throwable $re) {
            $log[] = "[ERR] Runner: " . $re->getMessage();
            error_log('[push/tick runner] ' . $re->getMessage());
        }
    }

    // ── Log no master_audit ───────────────────────────────────────────────────
    try {
        \App\Helpers\MasterAudit::log(
            'push.tick',
            'push_today_cache',
            0,
            'Cron de intimações executado' . ($dry ? ' (DRY RUN)' : ''),
            ['log' => $log, 'expired' => $expired, 'runner' => $runnerSummary]
        );
    } catch (\Throwable $_) {}

    @unlink($lockFile);
    ob_end_clean();
    echo json_encode([
        'ok'         => true,
        'dry_run'    => $dry,
        'ts'         => date('c'),
        'duracao_ms' => (int)((microtime(true) - $started) * 1000),
        'expired'    => $expired,
        'runner'     => $runnerSummary,
        'log'        => $log,
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
