<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Models/Account.php';
require_once __DIR__ . '/../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../app/Helpers/TenantGuard.php';
require_once __DIR__ . '/../../app/Services/WebhookDispatcher.php';

use App\Models\Database;
use App\Helpers\AccountContext;
use App\Helpers\TenantGuard;
use App\Services\WebhookDispatcher;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$ctx = AccountContext::fromSession();

$pdo = Database::getConnection();

// Criar tabela processo_history se não existir
$pdo->exec("CREATE TABLE IF NOT EXISTS processo_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    user_email VARCHAR(150),
    acao VARCHAR(100),
    descricao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Criar tabela processo_tarefas se não existir
$pdo->exec("CREATE TABLE IF NOT EXISTS processo_tarefas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    concluido TINYINT DEFAULT 0,
    prioridade ENUM('baixa','media','alta') DEFAULT 'media',
    responsavel VARCHAR(150),
    data_tarefa DATE,
    ordem INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];
$userEmail = $_SESSION['user_nome'] ?? $_SESSION['user_email'] ?? 'sistema';

try {
    if ($method === 'GET') {
        $processoId = (int)($_GET['processo_id'] ?? 0);
        if (!$processoId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing processo_id']);
            exit;
        }
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

        $stmt = $pdo->prepare(
            "SELECT * FROM processo_tarefas WHERE processo_id = :pid ORDER BY concluido ASC, ordem ASC"
        );
        $stmt->execute(['pid' => $processoId]);
        $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['data' => $tarefas]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $processoId = (int)($input['processo_id'] ?? 0);
        $titulo = trim($input['titulo'] ?? '');

        if (!$processoId || !$titulo) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

        // Pegar próxima ordem
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordem), -1) + 1 as next_order FROM processo_tarefas WHERE processo_id = :pid");
        $stmt->execute(['pid' => $processoId]);
        $nextOrder = (int)($stmt->fetchColumn() ?? 0);

        $stmt = $pdo->prepare(
            "INSERT INTO processo_tarefas (processo_id, titulo, prioridade, responsavel, data_tarefa, ordem)
             VALUES (:processo_id, :titulo, :prioridade, :responsavel, :data_tarefa, :ordem)"
        );
        $stmt->execute([
            'processo_id' => $processoId,
            'titulo'      => $titulo,
            'prioridade'  => $input['prioridade'] ?? 'media',
            'responsavel' => $input['responsavel'] ?? null,
            'data_tarefa' => $input['data_tarefa'] ?? null,
            'ordem'       => $nextOrder,
        ]);
        $newId = $pdo->lastInsertId();

        // Registra em processo_history
        $pdo->prepare(
            "INSERT INTO processo_history (processo_id, user_email, acao, descricao)
             VALUES (:pid, :email, 'Tarefa criada', :desc)"
        )->execute([
            'pid'   => $processoId,
            'email' => $userEmail,
            'desc'  => "Tarefa criada: {$titulo}",
        ]);

        // P0 LGPD: tenant dono do processo
        $procAcc = $pdo->prepare("SELECT account_id FROM processos WHERE id = ? LIMIT 1");
        $procAcc->execute([$processoId]);
        $ownerAcc = (int)($procAcc->fetchColumn() ?: $ctx->getAccountId());
        WebhookDispatcher::fire($ownerAcc, 'processo.tarefa_created', WebhookDispatcher::buildPayload('processo.tarefa_created', [
            'entity' => 'tarefa', 'entity_id' => $newId, 'processo_id' => $processoId,
            'data' => ['id' => $newId, 'titulo' => $titulo, 'prioridade' => $input['prioridade'] ?? 'media'],
        ]));
        echo json_encode(['success' => true, 'id' => $newId]);
        exit;
    }

    if ($method === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id']);
            exit;
        }

        $prevTarefa = $pdo->prepare("SELECT * FROM processo_tarefas WHERE id = :id LIMIT 1");
        $prevTarefa->execute(['id' => $id]);
        $prevTarefa = $prevTarefa->fetch(PDO::FETCH_ASSOC);
        if (!$prevTarefa) { http_response_code(404); echo json_encode(['error' => 'Tarefa não encontrada']); exit; }
        TenantGuard::assertProcessoAcessivel($ctx, (int)$prevTarefa['processo_id']);

        $allowed = ['concluido', 'titulo', 'prioridade', 'responsavel', 'data_tarefa', 'ordem'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $input)) {
                $fields[] = "$k = :$k";
                $params[$k] = $input[$k];
            }
        }
        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit;
        }

        $sql = "UPDATE processo_tarefas SET " . implode(', ', $fields) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);

        // Registra em processo_history para qualquer mudança relevante
        if (array_key_exists('concluido', $input) || array_key_exists('titulo', $input)) {
            $stmt = $pdo->prepare("SELECT processo_id, titulo FROM processo_tarefas WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $tarefa = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($tarefa) {
                if (array_key_exists('concluido', $input)) {
                    $acao = $input['concluido'] ? 'Tarefa concluída' : 'Tarefa reaberta';
                    $desc = "{$acao}: {$tarefa['titulo']}";
                } else {
                    $acao = 'Tarefa editada';
                    $desc = "Tarefa renomeada para: {$input['titulo']}";
                }
                $pdo->prepare(
                    "INSERT INTO processo_history (processo_id, user_email, acao, descricao)
                     VALUES (:pid, :email, :acao, :desc)"
                )->execute([
                    'pid'   => $tarefa['processo_id'],
                    'email' => $userEmail,
                    'acao'  => $acao,
                    'desc'  => $desc,
                ]);
            }
        }

        $eventKey = (isset($input['concluido']) && $input['concluido']) ? 'processo.tarefa_completed' : 'processo.tarefa_updated';
        // Use tarefa_completed only when marking done; otherwise only fire if meaningful field changed
        if (!isset($input['concluido']) && !isset($input['titulo'])) {
            $eventKey = null; // silent for minor updates (order changes, etc.)
        }
        if ($eventKey) {
            // P0 LGPD: tenant dono do processo
            $procAcc = $pdo->prepare("SELECT account_id FROM processos WHERE id = ? LIMIT 1");
            $procAcc->execute([(int)$prevTarefa['processo_id']]);
            $ownerAcc = (int)($procAcc->fetchColumn() ?: $ctx->getAccountId());
            WebhookDispatcher::fire($ownerAcc, $eventKey, WebhookDispatcher::buildPayload($eventKey, [
                'entity' => 'tarefa', 'entity_id' => $id, 'processo_id' => $prevTarefa['processo_id'] ?? null,
                'data' => array_merge($prevTarefa ?? [], $input), 'previous_data' => $prevTarefa,
            ]));
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = (int)($input['id'] ?? 0);
        }
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id']);
            exit;
        }

        // Valida tenant antes de deletar
        $row = $pdo->prepare("SELECT processo_id, titulo FROM processo_tarefas WHERE id = :id LIMIT 1");
        $row->execute(['id' => $id]);
        $tarefaInfo = $row->fetch(PDO::FETCH_ASSOC);
        $procId   = (int)($tarefaInfo['processo_id'] ?? 0);
        $tarefaTitulo = (string)($tarefaInfo['titulo'] ?? '');
        if ($procId) TenantGuard::assertProcessoAcessivel($ctx, $procId);

        $pdo->prepare("DELETE FROM processo_tarefas WHERE id = :id")->execute(['id' => $id]);
        // Auditoria server-side: registra exclusão
        if ($procId) {
            $pdo->prepare(
                "INSERT INTO processo_history (processo_id, user_email, acao, descricao)
                 VALUES (:pid, :email, 'Tarefa excluída', :desc)"
            )->execute([
                'pid' => $procId, 'email' => $userEmail,
                'desc' => "Tarefa removida: {$tarefaTitulo}",
            ]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
