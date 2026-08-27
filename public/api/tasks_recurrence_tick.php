<?php
/**
 * Cron interno — rodar diariamente à meia-noite.
 * Protegido por token: GET /api/tasks_recurrence_tick.php?token=SEU_TOKEN
 *
 * Tarefas:
 *  1. Varre task_recurrences ativas; cria instâncias atrasadas que não existem.
 *  2. Dispara lembretes pendentes (sistema/whatsapp).
 */
ob_start();

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Tarefas/Task.php';
require_once __DIR__ . '/../../app/Tarefas/TaskColumn.php';
require_once __DIR__ . '/../../app/Tarefas/TaskRecurrence.php';
require_once __DIR__ . '/../../app/Tarefas/TaskReminder.php';
require_once __DIR__ . '/../../app/Tarefas/TaskLink.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Core/EnvLoader.php';
require_once __DIR__ . '/../../app/Processos/ProcessoAudit.php';
require_once __DIR__ . '/../../app/Tarefas/TaskAudit.php';

// P1 LGPD (2A.4): carrega .env explicitamente — getenv() não funciona sem isso
\App\Core\EnvLoader::load();

// ─── LGPD P1 (2A.4): CRON_TOKEN obrigatório, sem fallback hardcoded ──────────
// Antes desta correção, o fallback era 'yuris_cron_token_change_me' (token
// público no código). Se .env esquecido em prod, qualquer um disparava o cron
// e podia criar tarefas/enviar WhatsApp em massa (DoS de notificações + spam).
// Agora: exige CRON_TOKEN definido (config/app.php OU env var), aborta se não.
$configFile = __DIR__ . '/../../config/app.php';
$config     = file_exists($configFile) ? (require $configFile) : [];
$envToken   = \App\Core\EnvLoader::get('CRON_TOKEN', '');
$cronToken  = $config['cron_token'] ?? ($envToken !== '' ? $envToken : null);

// ─── CLI bypass (Fix 2026-05-26 — auditoria pré-deploy AWS) ──────────────────
// Cron pode rodar via duas rotas:
//   (a) HTTP   — Windows Task Scheduler + curl, auth por ?token=<CRON_TOKEN>
//   (b) CLI    — Linux crontab: php /var/www/yuris/public/api/tasks_recurrence_tick.php
//                CLI é trusted (quem disparou tem shell access). Em produção
//                Linux recomendamos rota (b) — menos superfície de ataque.
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    // Bloqueia se token não configurado OU se é o legado inseguro
    if (!$cronToken || $cronToken === '' || $cronToken === 'yuris_cron_token_change_me') {
        http_response_code(503);
        error_log('[tasks_recurrence_tick] CRON_TOKEN não configurado ou usando default inseguro');
        echo json_encode([
            'ok' => false,
            'error' => 'Cron endpoint não configurado. Defina CRON_TOKEN no .env (gere com: openssl rand -hex 24).'
        ]);
        exit;
    }

    if (($_GET['token'] ?? '') !== $cronToken) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}
// ────────────────────────────────────────────────────────────────────────────

use App\Tarefas\Task;
use App\Tarefas\TaskColumn;
use App\Tarefas\TaskRecurrence;
use App\Tarefas\TaskReminder;

$pdo = \App\Core\Database::getConnection();
$log = [];

// ── 1. Recorrências atrasadas ─────────────────────────────────────────────────
$recs = TaskRecurrence::allActive();
foreach ($recs as $recData) {
    // última instância desta recorrência
    $stmt = $pdo->prepare(
        'SELECT * FROM tasks WHERE recorrencia_id = ? AND status != ? ORDER BY prazo DESC LIMIT 1'
    );
    $stmt->execute([$recData['id'], 'arquivada']);
    $ultima = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$ultima) continue;

    $rec = TaskRecurrence::loadById($recData['id']);
    if (!$rec) continue;

    $proximaData = $rec->calcularProximaData($ultima['prazo'] ?? date('Y-m-d'));
    if (!$proximaData) {
        TaskRecurrence::deactivate($recData['id']);
        $log[] = "recorrencia #{$recData['id']} encerrada (data_fim atingida)";
        continue;
    }

    // verifica se já existe instância para essa data (tolerância de ±1 dia)
    $check = $pdo->prepare(
        "SELECT id FROM tasks WHERE recorrencia_id = ? AND DATE(prazo) = DATE(?) AND status != 'arquivada' LIMIT 1"
    );
    $check->execute([$recData['id'], $proximaData]);
    if ($check->fetchColumn()) continue; // já existe

    // só cria se a data prevista já passou (instância atrasada)
    if (strtotime($proximaData) > time()) continue;

    $col = TaskColumn::initialColumn((int)$ultima['board_id']);
    if (!$col) continue;

    $newId = Task::create([
        'board_id'       => $ultima['board_id'],
        'column_id'      => $col['id'],
        'titulo'         => $ultima['titulo'],
        'descricao'      => $ultima['descricao'],
        'prioridade'     => $ultima['prioridade'],
        'prazo'          => $proximaData,
        'prazo_tipo'     => $ultima['prazo_tipo'],
        'responsavel_id' => $ultima['responsavel_id'],
        'criado_por_id'  => $ultima['criado_por_id'],
        'recorrencia_id' => $recData['id'],
        'origem_task_id' => $ultima['id'],
    ]);

    // Replica os vínculos da tarefa origem (processo, card, etc.) para a nova instância
    try {
        $origLinks = \App\Tarefas\TaskLink::findByTask((int)$ultima['id']);
        foreach ($origLinks as $lnk) {
            \App\Tarefas\TaskLink::add((int)$newId, (string)$lnk['link_type'], (int)$lnk['link_id']);
        }
        // Propaga ao histórico processual (se vinculada a processo)
        \App\Tarefas\TaskAudit::onRecurringInstance((int)$newId);
    } catch (\Throwable $_e) { /* não bloqueia o cron */ }

    $log[] = "criada instância #{$newId} para recorrencia #{$recData['id']} (prazo {$proximaData})";
}

// ── 2. Lembretes pendentes ────────────────────────────────────────────────────
$pendentes = TaskReminder::pending();
foreach ($pendentes as $r) {
    $enviado = false;

    if ($r['canal'] === 'whatsapp') {
        // tenta enviar via Evolution API se configurado
        try {
            require_once __DIR__ . '/../../app/WhatsAppAgente/EvolutionApiService.php';
            $cfgStmt = $pdo->query("SELECT config_key, config_value FROM whatsapp_settings");
            $cfg = [];
            foreach ($cfgStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $cfg[$row['config_key']] = $row['config_value'];
            }
            if (!empty($cfg['evolution_base_url']) && !empty($cfg['evolution_api_key']) && !empty($cfg['evolution_instance'])) {
                $svc = new \EvolutionApiService(
                    $cfg['evolution_base_url'], $cfg['evolution_api_key'], $cfg['evolution_instance']
                );
                // busca telefone do usuário
                $uStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $uStmt->execute([$r['user_id']]);
                $user = $uStmt->fetch(\PDO::FETCH_ASSOC);
                $telefone = $user['telefone'] ?? $user['whatsapp'] ?? null;
                if ($telefone) {
                    $msg = "⏰ Lembrete YURIS\n\nTarefa: {$r['task_titulo']}\nVencimento próximo.\n\nAcesse o sistema para mais detalhes.";
                    $svc->sendText($telefone . '@s.whatsapp.net', $msg);
                    $enviado = true;
                }
            }
        } catch (\Throwable $e) {
            $log[] = "whatsapp lembrete #{$r['id']} falhou: " . $e->getMessage();
        }
    } else {
        // canal 'sistema' — apenas marca como enviado (frontend vai ler via polling)
        $enviado = true;
    }

    if ($enviado) {
        TaskReminder::markSent((int)$r['id']);
        $log[] = "lembrete #{$r['id']} enviado via {$r['canal']}";
    }
}

ob_end_clean();
echo json_encode(['ok' => true, 'log' => $log, 'ts' => date('Y-m-d H:i:s')]);
