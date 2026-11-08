<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Processos\ProcessoAudit;
use App\Webhooks\WebhookDispatcher;

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// CSRF defense (2026-05-26 — auditoria de seg pré-deploy): aceita request se
// Origin/Referer = same-origin OU token CSRF presente. Bloqueia cross-site.
TenantGuard::requireSameOriginOrCsrf();

$ctx = AccountContext::fromSession();
$ctx->assertModuloJuridicoDisponivel();

$pdo = Database::getConnection();

// Criar tabela processo_history se não existir.
// O DDL inline espelha o schema real (migrations 040 + 052): ProcessoAudit::log
// grava author_account_*/ip/user_agent/request_id. Omitir as colunas quebraria
// a auditoria num banco fresco rodado antes dessas migrations.
$pdo->exec("CREATE TABLE IF NOT EXISTS processo_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    user_email VARCHAR(150),
    acao VARCHAR(100),
    descricao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    author_account_id INT NULL,
    author_account_tipo VARCHAR(20) NULL,
    author_account_nome VARCHAR(150) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    request_id CHAR(12) NULL,
    INDEX(processo_id),
    INDEX idx_ph_author_account (author_account_id),
    INDEX idx_request_id (request_id)
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

        // Registra em processo_history via ProcessoAudit (trilha forense completa)
        ProcessoAudit::log($processoId, 'Tarefa criada', "Tarefa criada: {$titulo}", 'tarefa', (int) $newId);

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
        foreach (['data_tarefa', 'responsavel'] as $k) {
            if (array_key_exists($k, $input) && is_string($input[$k]) && trim($input[$k]) === '') $input[$k] = null;
        }
        if (array_key_exists('prioridade', $input) && !in_array($input['prioridade'], ['baixa', 'media', 'alta'], true)) unset($input['prioridade']);
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

        // Registra em processo_history via ProcessoAudit, ligado à tarefa (a ficha dela mostra o
        // próprio log). Concluir e reabrir são uma entrada; o que mudou na mesma edição (nome,
        // data, responsável, prioridade) vira UMA entrada "Tarefa editada" com cada de → para.
        // Antes, salvar sem mexer no nome gravava "Tarefa renomeada" do mesmo jeito.
        $pidT = (int) $prevTarefa['processo_id'];
        if (array_key_exists('concluido', $input) && (int) (bool) $input['concluido'] !== (int) $prevTarefa['concluido']) {
            $acao = $input['concluido'] ? 'Tarefa concluída' : 'Tarefa reaberta';
            ProcessoAudit::log($pidT, $acao, "{$acao}: {$prevTarefa['titulo']}", 'tarefa', $id);
        }
        $mudancas = [];
        if (array_key_exists('titulo', $input) && trim((string) $input['titulo']) !== (string) $prevTarefa['titulo']) {
            $mudancas[] = "nome '{$prevTarefa['titulo']}' → '" . trim((string) $input['titulo']) . "'";
        }
        if (array_key_exists('data_tarefa', $input) && (string) $input['data_tarefa'] !== (string) $prevTarefa['data_tarefa']) {
            $fd = function ($d) { return $d ? date('d/m/Y', strtotime((string) $d)) : 'vazio'; };
            $mudancas[] = 'data ' . $fd($prevTarefa['data_tarefa']) . ' → ' . $fd($input['data_tarefa']);
        }
        if (array_key_exists('responsavel', $input) && (string) $input['responsavel'] !== (string) $prevTarefa['responsavel']) {
            $mudancas[] = 'responsável ' . ($prevTarefa['responsavel'] ?: 'vazio') . ' → ' . ($input['responsavel'] ?: 'vazio');
        }
        if (array_key_exists('prioridade', $input) && (string) $input['prioridade'] !== (string) $prevTarefa['prioridade']) {
            $nomes = ['baixa' => 'baixa', 'media' => 'média', 'alta' => 'alta'];
            $mudancas[] = 'prioridade ' . ($nomes[$prevTarefa['prioridade']] ?? 'vazio') . ' → ' . ($nomes[$input['prioridade']] ?? 'vazio');
        }
        if ($mudancas) {
            ProcessoAudit::log($pidT, 'Tarefa editada', "Tarefa '{$prevTarefa['titulo']}': " . implode('; ', $mudancas), 'tarefa', $id);
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
        // Auditoria server-side: registra exclusão via helper (trilha forense)
        if ($procId) {
            ProcessoAudit::log($procId, 'Tarefa excluída', "Tarefa removida: {$tarefaTitulo}", 'tarefa', $id);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;

} catch (\Throwable $e) {
    \App\Core\ErrorReporter::handle($e);  // P1 LGPD (2D.1)
    exit;
}
