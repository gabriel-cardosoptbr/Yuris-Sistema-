<?php
/**
 * lgpd_retention_tick.php — Cron de retenção LGPD (Etapa 7).
 *
 * Executa políticas ativas em `retention_policies`:
 *   • purge_fisico — DELETE registros mais antigos que retencao_dias
 *   • anonimizar   — NULLifica campos PII (raw_payload, body, etc) preservando linha
 *   • arquivar     — UPDATE archived_at (se existir) — não implementado ainda
 *
 * Protegido por CRON_TOKEN (mesma técnica de tasks_recurrence_tick.php).
 * Recomendado rodar 1x/dia (00:30).
 *
 * Acesso: GET /api/lgpd_retention_tick.php?token=<CRON_TOKEN>
 */
ob_start();

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Helpers/EnvLoader.php';
require_once __DIR__ . '/../../app/Helpers/MasterAudit.php';

use App\Models\Database;
use App\Helpers\EnvLoader;

EnvLoader::load();

$configFile = __DIR__ . '/../../config/app.php';
$config     = file_exists($configFile) ? (require $configFile) : [];
$envToken   = EnvLoader::get('CRON_TOKEN', '');
$cronToken  = $config['cron_token'] ?? ($envToken !== '' ? $envToken : null);

// ─── CLI bypass (Fix 2026-05-26 — auditoria pré-deploy AWS) ──────────────────
// Cron pode rodar via duas rotas:
//   (a) HTTP   — Windows Task Scheduler + curl, auth por ?token=<CRON_TOKEN>
//   (b) CLI    — Linux crontab: php /var/www/yuris/public/api/lgpd_retention_tick.php
//                CLI é trusted (quem disparou tem shell access). Sem header de
//                token, sem Content-Type JSON (logs vão pro stdout do cron).
// Em produção Linux, recomendamos rota (b) — menos superfície de ataque.
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
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
}

$pdo  = Database::getConnection();
$log  = [];
$dry  = !empty($_GET['dry_run']);  // ?dry_run=1 — só conta, não executa

// ── Lock simples: bloqueia se outro tick rodou < 1h atrás ──────────────────
try {
    $st = $pdo->query("SELECT MAX(ultimo_run) AS last FROM retention_policies");
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row && $row['last'] && (strtotime($row['last']) > time() - 3600) && empty($_GET['force'])) {
        echo json_encode([
            'ok'    => true,
            'skipped' => true,
            'reason'  => 'Última execução há menos de 1h. Use ?force=1 para sobrescrever.',
            'last_run' => $row['last'],
        ]);
        exit;
    }
} catch (\Throwable $_) {}

// ── Carrega policies ativas ────────────────────────────────────────────────
$policies = $pdo->query(
    "SELECT * FROM retention_policies WHERE ativo = 1 ORDER BY entidade"
)->fetchAll(\PDO::FETCH_ASSOC);

foreach ($policies as $p) {
    $entidade = $p['entidade'];
    $dias     = (int)$p['retencao_dias'];
    $acao     = $p['acao_apos'];
    $campo    = $p['campo_referencia'];

    $count   = 0;
    $status  = 'success';
    $erro    = null;
    $sqlCount = null;
    $sqlDo    = null;

    try {
        // Allowlist de entidades — evita injection via valor do banco
        switch ($entidade) {
            case 'webhook_logs':
                $sqlCount = "SELECT COUNT(*) FROM webhook_logs WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)";
                $sqlDo    = "DELETE FROM webhook_logs WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)";
                break;
            case 'login_attempts':
                $sqlCount = "SELECT COUNT(*) FROM login_attempts WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)";
                $sqlDo    = "DELETE FROM login_attempts WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)";
                break;
            case 'gateway_events_received':
                $sqlCount = "SELECT COUNT(*) FROM gateway_events_received WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)";
                $sqlDo    = "DELETE FROM gateway_events_received WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)";
                break;
            case 'emails_outbox_body':
                $sqlCount = "SELECT COUNT(*) FROM emails_outbox
                             WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL :d DAY)
                               AND body_html IS NOT NULL AND body_html != ''";
                $sqlDo    = "UPDATE emails_outbox
                             SET body_html = NULL, body_text = NULL
                             WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL :d DAY)
                               AND body_html IS NOT NULL AND body_html != ''";
                break;
            case 'whatsapp_messages_payload':
                $sqlCount = "SELECT COUNT(*) FROM whatsapp_messages
                             WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)
                               AND raw_payload IS NOT NULL AND raw_payload != ''";
                $sqlDo    = "UPDATE whatsapp_messages
                             SET raw_payload = NULL
                             WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)
                               AND raw_payload IS NOT NULL AND raw_payload != ''";
                break;
            case 'whatsapp_messages_media':
                // Onda 4 / F1-F2 leve: minimiza o media_base64 (arquivo inline ate ~4MB na
                // tabela quente) apos N dias. NULL na coluna preserva a linha + metadata
                // (tipo, caption, media_url, mimetype); midia recente segue viavel via
                // re-fetch da Evolution no media.php (fallback por url/raw_payload).
                $sqlCount = "SELECT COUNT(*) FROM whatsapp_messages
                             WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)
                               AND media_base64 IS NOT NULL AND media_base64 != ''";
                $sqlDo    = "UPDATE whatsapp_messages
                             SET media_base64 = NULL
                             WHERE {$campo} < DATE_SUB(NOW(), INTERVAL :d DAY)
                               AND media_base64 IS NOT NULL AND media_base64 != ''";
                break;
            default:
                $log[] = "[SKIP] {$entidade}: entidade não mapeada (allowlist)";
                $status = 'error';
                $erro   = 'entidade não suportada';
        }

        if ($sqlCount && $sqlDo) {
            $st = $pdo->prepare($sqlCount);
            $st->execute(['d' => $dias]);
            $count = (int)$st->fetchColumn();

            if (!$dry && $count > 0) {
                $st = $pdo->prepare($sqlDo);
                $st->execute(['d' => $dias]);
                $log[] = "[OK] {$entidade}: {$acao} -> {$count} linhas afetadas (>{$dias}d)";
            } elseif ($dry) {
                $log[] = "[DRY] {$entidade}: {$acao} afetaria {$count} linhas (>{$dias}d)";
            } else {
                $log[] = "[--] {$entidade}: nenhum registro elegivel";
            }
        }
    } catch (\Throwable $e) {
        $status = 'error';
        $erro   = $e->getMessage();
        $log[]  = "[ERR] {$entidade}: " . $e->getMessage();
        error_log("[lgpd_retention_tick] {$entidade}: " . $e->getMessage());
    }

    // Atualiza estado da policy
    try {
        $pdo->prepare(
            "UPDATE retention_policies
             SET ultimo_run = NOW(), ultimo_purge_count = :n,
                 ultimo_status = :s, ultimo_erro = :e
             WHERE id = :id"
        )->execute([
            'n'  => $count,
            's'  => $status,
            'e'  => $erro,
            'id' => $p['id'],
        ]);
    } catch (\Throwable $_) {}
}

// Log no master_audit (informativo)
try {
    \App\Helpers\MasterAudit::log(
        'lgpd_retention.tick',
        'retention_policies',
        0,
        'Cron de retencao executado' . ($dry ? ' (DRY RUN)' : ''),
        ['log' => $log]
    );
} catch (\Throwable $_) {}

ob_end_clean();
echo json_encode([
    'ok'  => true,
    'dry_run' => $dry,
    'ts'  => date('c'),
    'log' => $log,
]);
