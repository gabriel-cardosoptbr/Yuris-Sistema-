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

require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Task.php';
require_once __DIR__ . '/../../app/Models/TaskColumn.php';
require_once __DIR__ . '/../../app/Models/TaskRecurrence.php';
require_once __DIR__ . '/../../app/Models/TaskReminder.php';

$configFile = __DIR__ . '/../../config/app.php';
$config     = file_exists($configFile) ? (require $configFile) : [];
$cronToken  = $config['cron_token'] ?? getenv('CRON_TOKEN') ?: 'yuris_cron_token_change_me';

header('Content-Type: application/json; charset=utf-8');

if (($_GET['token'] ?? '') !== $cronToken) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\TaskRecurrence;
use App\Models\TaskReminder;

$pdo = \App\Models\Database::getConnection();
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
    $log[] = "criada instância #{$newId} para recorrencia #{$recData['id']} (prazo {$proximaData})";
}

// ── 2. Lembretes pendentes ────────────────────────────────────────────────────
$pendentes = TaskReminder::pending();
foreach ($pendentes as $r) {
    $enviado = false;

    if ($r['canal'] === 'whatsapp') {
        // tenta enviar via Evolution API se configurado
        try {
            require_once __DIR__ . '/../../app/Services/EvolutionApiService.php';
            $cfgStmt = $pdo->query("SELECT config_key, config_value FROM whatsapp_settings");
            $cfg = [];
            foreach ($cfgStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $cfg[$row['config_key']] = $row['config_value'];
            }
            if (!empty($cfg['evolution_base_url']) && !empty($cfg['evolution_api_key']) && !empty($cfg['evolution_instance'])) {
                $svc = new \App\Services\EvolutionApiService(
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
