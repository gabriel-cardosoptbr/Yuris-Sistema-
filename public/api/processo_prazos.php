<?php
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Master/Account.php';
require_once __DIR__ . '/../../app/Master/ResourceShare.php';
require_once __DIR__ . '/../../app/Core/AccountContext.php';
require_once __DIR__ . '/../../app/Core/TenantGuard.php';
require_once __DIR__ . '/../../app/Webhooks/WebhookDispatcher.php';
require_once __DIR__ . '/../../app/Core/ErrorReporter.php';
require_once __DIR__ . '/../../app/Processos/ProcessoAudit.php';

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
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

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
        TenantGuard::assertProcessoAcessivel($ctx, $processoId);

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

        // Registra em processo_history via ProcessoAudit (ganha ip/ua/request_id
        // + snapshot da conta autora — diff vs INSERT direto que faltavam esses campos).
        ProcessoAudit::log(
            $processoId,
            'Prazo criado',
            "Prazo criado: {$descricao}" . (isset($input['data_limite']) ? " (vence em {$input['data_limite']})" : '')
        );

        // P0 LGPD: dispara apenas para webhooks do tenant DONO do processo
        $procAcc = $pdo->prepare("SELECT account_id FROM processos WHERE id = ? LIMIT 1");
        $procAcc->execute([$processoId]);
        $ownerAcc = (int)($procAcc->fetchColumn() ?: $ctx->getAccountId());
        WebhookDispatcher::fire($ownerAcc, 'processo.prazo_created', WebhookDispatcher::buildPayload('processo.prazo_created', [
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
        if (!$prevPrazo) { http_response_code(404); echo json_encode(['error' => 'Prazo não encontrado']); exit; }
        TenantGuard::assertProcessoAcessivel($ctx, (int)$prevPrazo['processo_id']);

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

        // Auditoria server-side via ProcessoAudit (ganha trilha forense completa).
        $auditPid = (int)$prevPrazo['processo_id'];
        if (isset($input['status']) && $input['status'] !== $prevPrazo['status']) {
            $acao = $input['status'] === 'concluido' ? 'Prazo concluído' : 'Prazo atualizado';
            ProcessoAudit::log(
                $auditPid, $acao,
                "{$acao}: {$prevPrazo['descricao']} (de '{$prevPrazo['status']}' para '{$input['status']}')"
            );
        }
        if (isset($input['descricao']) && $input['descricao'] !== $prevPrazo['descricao']) {
            ProcessoAudit::log(
                $auditPid, 'Prazo editado',
                "Prazo renomeado: '{$prevPrazo['descricao']}' → '{$input['descricao']}'"
            );
        }
        if (isset($input['data_limite']) && $input['data_limite'] !== $prevPrazo['data_limite']) {
            ProcessoAudit::log(
                $auditPid, 'Prazo editado',
                "Data do prazo '{$prevPrazo['descricao']}': "
                . ($prevPrazo['data_limite'] ?: '∅') . ' → '
                . ($input['data_limite'] ?: '∅')
            );
        }

        $eventKey = (isset($input['status']) && $input['status'] === 'concluido')
            ? 'processo.prazo_completed' : 'processo.prazo_updated';
        // P0 LGPD: tenant dono do processo (não da sessão)
        $procAcc = $pdo->prepare("SELECT account_id FROM processos WHERE id = ? LIMIT 1");
        $procAcc->execute([(int)$prevPrazo['processo_id']]);
        $ownerAcc = (int)($procAcc->fetchColumn() ?: $ctx->getAccountId());
        WebhookDispatcher::fire($ownerAcc, $eventKey, WebhookDispatcher::buildPayload($eventKey, [
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

        // Valida tenant antes de deletar
        $row = $pdo->prepare("SELECT processo_id, descricao FROM processo_prazos WHERE id = :id LIMIT 1");
        $row->execute(['id' => $id]);
        $prazoInfo = $row->fetch(PDO::FETCH_ASSOC);
        $procId   = (int)($prazoInfo['processo_id'] ?? 0);
        $prazoDesc = (string)($prazoInfo['descricao'] ?? '');
        if ($procId) TenantGuard::assertProcessoAcessivel($ctx, $procId);

        $pdo->prepare("DELETE FROM processo_prazos WHERE id = :id")->execute(['id' => $id]);
        // Auditoria server-side: registra exclusão (via helper para trilha forense)
        if ($procId) {
            ProcessoAudit::log($procId, 'Prazo excluído', "Prazo removido: {$prazoDesc}");
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
