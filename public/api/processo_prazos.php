<?php
require_once __DIR__ . '/../../app/Models/Database.php';
require_once __DIR__ . '/../../app/Services/WebhookDispatcher.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

use App\Models\Database;
use App\Services\WebhookDispatcher;

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

// Criar tabela processo_prazos se não existir
$pdo->exec("CREATE TABLE IF NOT EXISTS processo_prazos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    data_limite DATE,
    responsavel VARCHAR(150),
    status ENUM('pendente','concluido','vencido') DEFAULT 'pendente',
    prioridade ENUM('baixa','media','alta') DEFAULT 'media',
    observacao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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

        // Auto-atualiza status para 'vencido' quando data_limite < CURDATE()
        $pdo->prepare(
            "UPDATE processo_prazos SET status = 'vencido'
             WHERE processo_id = :pid AND status = 'pendente' AND data_limite < CURDATE()"
        )->execute(['pid' => $processoId]);

        $stmt = $pdo->prepare("SELECT * FROM processo_prazos WHERE processo_id = :pid ORDER BY data_limite ASC, created_at ASC");
        $stmt->execute(['pid' => $processoId]);
        $prazos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['data' => $prazos]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $processoId = (int)($input['processo_id'] ?? 0);
        $descricao = trim($input['descricao'] ?? '');

        if (!$processoId || !$descricao) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO processo_prazos (processo_id, descricao, data_limite, responsavel, prioridade, observacao)
             VALUES (:processo_id, :descricao, :data_limite, :responsavel, :prioridade, :observacao)"
        );
        $stmt->execute([
            'processo_id' => $processoId,
            'descricao'   => $descricao,
            'data_limite' => $input['data_limite'] ?? null,
            'responsavel' => $input['responsavel'] ?? null,
            'prioridade'  => $input['prioridade'] ?? 'media',
            'observacao'  => $input['observacao'] ?? null,
        ]);
        $newId = $pdo->lastInsertId();

        // Registra em processo_history
        $pdo->prepare(
            "INSERT INTO processo_history (processo_id, user_email, acao, descricao)
             VALUES (:pid, :email, 'Prazo criado', :desc)"
        )->execute([
            'pid'   => $processoId,
            'email' => $userEmail,
            'desc'  => "Prazo criado: {$descricao}" . (isset($input['data_limite']) ? " (vence em {$input['data_limite']})" : ''),
        ]);

        WebhookDispatcher::fire('processo.prazo_created', WebhookDispatcher::buildPayload('processo.prazo_created', [
            'entity' => 'prazo', 'entity_id' => $newId, 'processo_id' => $processoId,
            'data' => ['id' => $newId, 'descricao' => $descricao, 'data_limite' => $input['data_limite'] ?? null, 'prioridade' => $input['prioridade'] ?? 'media'],
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

        $prevPrazo = $pdo->prepare("SELECT * FROM processo_prazos WHERE id = :id LIMIT 1");
        $prevPrazo->execute(['id' => $id]);
        $prevPrazo = $prevPrazo->fetch(PDO::FETCH_ASSOC);

        $allowed = ['status', 'descricao', 'data_limite'];
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

        $sql = "UPDATE processo_prazos SET " . implode(', ', $fields) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);

        $eventKey = (isset($input['status']) && $input['status'] === 'concluido')
            ? 'processo.prazo_completed' : 'processo.prazo_updated';
        WebhookDispatcher::fire($eventKey, WebhookDispatcher::buildPayload($eventKey, [
            'entity' => 'prazo', 'entity_id' => $id, 'processo_id' => $prevPrazo['processo_id'] ?? null,
            'data' => array_merge($prevPrazo ?? [], $input), 'previous_data' => $prevPrazo,
        ]));

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

        $pdo->prepare("DELETE FROM processo_prazos WHERE id = :id")->execute(['id' => $id]);
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
