<?php
/**
 * push/persist.php — Persistir publicação ao interagir.
 *
 * POST { _csrf, payload: {...item normalizado...}, action: 'read'|'unread'|'favorite'|'deadline'|'comment'|'link_process', extra: {...} }
 *
 * Idempotente via hash_conteudo. Cria/atualiza push_events + aplica ação em push_event_user_status.
 *
 * Ações:
 *   read       → push_event_user_status.lida = 1
 *   unread     → push_event_user_status.lida = 0
 *   favorite   → toggle push_event_user_status.favorita
 *   deadline   → setPrazo(extra.data) ou clear se data=null
 *   comment    → setComentario(extra.texto)
 *   link_process → vincular push_event.processo_id = extra.processo_id (validar tenant)
 */
ob_start();
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../../app/Models/Database.php';
require_once __DIR__ . '/../../../app/Models/Account.php';
require_once __DIR__ . '/../../../app/Models/ResourceShare.php';
require_once __DIR__ . '/../../../app/Models/PushEvent.php';
require_once __DIR__ . '/../../../app/Models/PushTodayCache.php';
require_once __DIR__ . '/../../../app/Models/PushEventUserStatus.php';
require_once __DIR__ . '/../../../app/Models/PushProcessoLink.php';
require_once __DIR__ . '/../../../app/Models/Task.php';
require_once __DIR__ . '/../../../app/Models/TaskBoard.php';
require_once __DIR__ . '/../../../app/Helpers/AccountContext.php';
require_once __DIR__ . '/../../../app/Helpers/ErrorReporter.php';
require_once __DIR__ . '/../../../app/Services/Push/PublicationHasher.php';

use App\Helpers\AccountContext;
use App\Models\Database;
use App\Models\PushEvent;
use App\Models\PushTodayCache;
use App\Models\PushEventUserStatus;
use App\Services\Push\PublicationHasher;

session_start(['read_and_close' => true]);
$csrfSession = $_SESSION['csrf_token'] ?? '';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($payload['_csrf']) || $payload['_csrf'] !== $csrfSession) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF inválido']);
    exit;
}

try {
    $ctx       = AccountContext::fromSession();
    $accountId = $ctx->getAccountId();
    $userId    = (int)$_SESSION['user_id'];

    $action = trim((string)($payload['action'] ?? ''));
    $extra  = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
    $allowedActions = ['read','unread','favorite','deadline','comment','link_process','unlink_process','create_task','create_prazo'];
    if (!in_array($action, $allowedActions, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida. Use: ' . implode(', ', $allowedActions)]);
        exit;
    }

    // ── Resolver event_id ──────────────────────────────────────────────────────
    // Dois caminhos:
    //   A) payload.event_id já existe (interação subsequente)
    //   B) payload.payload contém item normalizado → upsert push_events
    $eventId = (int)($payload['event_id'] ?? 0);

    if (!$eventId) {
        $item = is_array($payload['payload'] ?? null) ? $payload['payload'] : null;
        if (!$item || empty($item['hash_conteudo'])) {
            http_response_code(400);
            echo json_encode(['error' => 'event_id ou payload (com hash_conteudo) obrigatório']);
            exit;
        }

        // Se veio do cache, podemos enriquecer com payload_original do banco
        $cached = PushTodayCache::findByHash($accountId, $item['hash_conteudo']);
        if ($cached && !empty($cached['payload_original'])) {
            $decoded = json_decode($cached['payload_original'], true);
            if (is_array($decoded)) $item['payload_original'] = $decoded;
        }

        $eventId = PushEvent::upsert([
            'account_id'              => $accountId,
            'source_id'               => $item['source_id']             ?? 'djen',
            'tipo_evento'             => 'publicacao',
            'titulo'                  => $item['titulo']                ?? null,
            'resumo'                  => $item['resumo']                ?? null,
            'conteudo'                => $item['conteudo']              ?? null,
            'data_evento'             => date('Y-m-d H:i:s'),
            'data_disponibilizacao'   => $item['data_disponibilizacao'] ?? date('Y-m-d'),
            'data_publicacao'         => $item['data_publicacao']       ?? null,
            'tribunal'                => $item['tribunal']              ?? '',
            'orgao'                   => $item['orgao']                 ?? null,
            'id_orgao'                => $item['id_orgao']              ?? null,
            'numero_processo'         => $item['numero_processo']       ?? null,
            'numero_processo_mascara' => $item['numero_processo_mascara'] ?? null,
            'tipo_comunicacao'        => $item['tipo_comunicacao']      ?? null,
            'meio'                    => $item['meio']                  ?? null,
            'meio_completo'           => $item['meio_completo']         ?? null,
            'classe_nome'             => $item['classe_nome']           ?? null,
            'classe_codigo'           => $item['classe_codigo']         ?? null,
            'url_origem'              => $item['url_origem']            ?? null,
            'numero_comunicacao'      => $item['numero_comunicacao']    ?? null,
            'hash_externo'            => $item['hash_externo']          ?? null,
            'hash_conteudo'           => $item['hash_conteudo'],
            'payload_original'        => $item['payload_original']      ?? null,
            'origem_busca'            => $cached ? 'cache_hoje' : 'manual',
            'status'                  => 'ativo',
        ]);
    } else {
        // Validar que event_id pertence ao tenant
        $ev = PushEvent::findByIdForAccount($eventId, $accountId);
        if (!$ev) {
            http_response_code(404);
            echo json_encode(['error' => 'Evento não encontrado nesta conta']);
            exit;
        }
    }

    // ── Aplicar ação ───────────────────────────────────────────────────────────
    $resultado = ['ok' => true, 'event_id' => $eventId, 'action' => $action];

    switch ($action) {
        case 'read':
            PushEventUserStatus::setLida($eventId, $userId, $accountId, true);
            break;
        case 'unread':
            PushEventUserStatus::setLida($eventId, $userId, $accountId, false);
            break;
        case 'favorite':
            $novo = PushEventUserStatus::toggleFavorita($eventId, $userId, $accountId);
            $resultado['favorita'] = $novo;
            break;
        case 'deadline':
            $data = isset($extra['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $extra['data']) ? $extra['data'] : null;
            PushEventUserStatus::setPrazo($eventId, $userId, $accountId, $data);
            $resultado['prazo_data'] = $data;

            // Opcional: criar tarefa no módulo Tarefas
            if (!empty($extra['create_task']) && $data) {
                try {
                    $boards = \App\Models\TaskBoard::findForUser($userId, $accountId);
                    if (empty($boards)) {
                        $resultado['task_warning'] = 'Nenhum board encontrado para criar tarefa. Crie um board primeiro em /tarefas.';
                    } else {
                        $boardId = (int)$boards[0]['id'];
                        $pdo = Database::getConnection();
                        $colStmt = $pdo->prepare('SELECT id FROM task_columns WHERE board_id = :b ORDER BY ordem, id LIMIT 1');
                        $colStmt->execute(['b' => $boardId]);
                        $colId = (int)$colStmt->fetchColumn();
                        if (!$colId) {
                            $resultado['task_warning'] = 'Board sem colunas. Configure colunas em /tarefas.';
                        } else {
                            $ev = \App\Models\PushEvent::findByIdForAccount($eventId, $accountId);
                            $titulo = 'Intimação ' . ($ev['tribunal'] ?? '') . ' — '
                                    . ($ev['numero_processo_mascara'] ?: $ev['numero_processo'] ?: 'sem processo');
                            $desc = "Origem: intimação automática\n"
                                  . "Órgão: " . ($ev['orgao'] ?? '—') . "\n"
                                  . "Tipo: " . ($ev['tipo_comunicacao'] ?? '—') . "\n"
                                  . "Data: " . ($ev['data_disponibilizacao'] ?? '—') . "\n\n"
                                  . mb_substr($ev['conteudo'] ?? '', 0, 1000);

                            $taskId = \App\Models\Task::create([
                                'board_id'       => $boardId,
                                'column_id'      => $colId,
                                'titulo'         => mb_substr($titulo, 0, 200),
                                'descricao'      => $desc,
                                'prioridade'     => 'alta',
                                'prazo'          => $data . ' 18:00:00',
                                'prazo_tipo'     => 'fatal',
                                'responsavel_id' => $userId,
                                'criado_por_id'  => $userId,
                                'push_event_id'  => $eventId,
                            ]);
                            $resultado['task_id'] = $taskId;
                            $resultado['task_board_id'] = $boardId;
                        }
                    }
                } catch (\Throwable $te) {
                    error_log('[push/persist deadline create_task] ' . $te->getMessage());
                    $resultado['task_warning'] = 'Tarefa não criada: ' . $te->getMessage();
                }
            }
            break;
        case 'comment':
            $texto = isset($extra['texto']) ? trim(mb_substr((string)$extra['texto'], 0, 2000)) : null;
            PushEventUserStatus::setComentario($eventId, $userId, $accountId, $texto);
            $resultado['comentario'] = $texto;
            break;
        case 'link_process':
            $procId = (int)($extra['processo_id'] ?? 0);
            if ($procId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'extra.processo_id inválido']);
                exit;
            }
            // Validar que processo pertence à conta (proteção cross-tenant)
            $pdo = Database::getConnection();
            $st  = $pdo->prepare('SELECT id FROM processos WHERE id = :id AND account_id = :acc LIMIT 1');
            $st->execute(['id' => $procId, 'acc' => $accountId]);
            if (!$st->fetchColumn()) {
                http_response_code(403);
                echo json_encode(['error' => 'Processo não pertence a esta conta']);
                exit;
            }
            // Vincula a intimação específica
            $pdo->prepare('UPDATE push_events SET processo_id = :p WHERE id = :id AND account_id = :acc')
                ->execute(['p' => $procId, 'id' => $eventId, 'acc' => $accountId]);

            // Pega o numero_processo do evento pra registrar auto-link
            $ev = \App\Models\PushEvent::findByIdForAccount($eventId, $accountId);
            $numProc = $ev['numero_processo'] ?? '';
            if ($numProc !== '') {
                // Salva mapping permanente — futuras intimações com mesmo número auto-vinculam
                \App\Models\PushProcessoLink::linkProcesso($accountId, $numProc, $procId, $userId);
                // Aplica retroativo: outras intimações passadas com mesmo número ganham vínculo
                $retroCount = \App\Models\PushProcessoLink::aplicarRetroativo($accountId, $numProc, $procId);
                $resultado['retroativo_aplicado'] = $retroCount;
            }
            $resultado['processo_id'] = $procId;
            break;

        case 'unlink_process':
            // Remove vínculo da intimação específica + remove auto-link futuro
            $pdo = Database::getConnection();
            $ev = \App\Models\PushEvent::findByIdForAccount($eventId, $accountId);
            $numProc = $ev['numero_processo'] ?? '';
            $pdo->prepare('UPDATE push_events SET processo_id = NULL WHERE id = :id AND account_id = :acc')
                ->execute(['id' => $eventId, 'acc' => $accountId]);
            if ($numProc !== '') {
                \App\Models\PushProcessoLink::unlinkProcesso($accountId, $numProc);
            }
            $resultado['unlinked'] = true;
            break;

        case 'create_task':
            // Cria tarefa standalone (sem precisar setar prazo primeiro).
            // Campos: extra.titulo, extra.descricao, extra.prazo_data, extra.responsavel_id
            $ev = \App\Models\PushEvent::findByIdForAccount($eventId, $accountId);
            if (!$ev) {
                http_response_code(404);
                echo json_encode(['error' => 'Evento não encontrado']);
                exit;
            }
            $boards = \App\Models\TaskBoard::findForUser($userId, $accountId);
            if (empty($boards)) {
                http_response_code(409);
                echo json_encode(['error' => 'Nenhum board de tarefas encontrado. Crie um em /tarefas primeiro.']);
                exit;
            }
            $boardId = (int)$boards[0]['id'];
            $pdo = Database::getConnection();
            $colStmt = $pdo->prepare('SELECT id FROM task_columns WHERE board_id = :b ORDER BY ordem, id LIMIT 1');
            $colStmt->execute(['b' => $boardId]);
            $colId = (int)$colStmt->fetchColumn();
            if (!$colId) {
                http_response_code(409);
                echo json_encode(['error' => 'Board sem colunas. Configure em /tarefas.']);
                exit;
            }

            // Título e descrição: extra > sugestão automática
            $titulo = trim((string)($extra['titulo'] ?? ''));
            if ($titulo === '') {
                $titulo = 'Intimação ' . ($ev['tribunal'] ?? '') . ' — '
                        . ($ev['numero_processo_mascara'] ?: $ev['numero_processo'] ?: 'sem processo');
            }
            $desc = trim((string)($extra['descricao'] ?? ''));
            if ($desc === '') {
                $desc = "Origem: intimação automática\n"
                      . "Órgão: " . ($ev['orgao'] ?? '—') . "\n"
                      . "Tipo: " . ($ev['tipo_comunicacao'] ?? '—') . "\n"
                      . "Data: " . ($ev['data_disponibilizacao'] ?? '—') . "\n\n"
                      . mb_substr($ev['conteudo'] ?? '', 0, 1500);
            }
            // Responsável padrão = user logado se não especificado
            $respId = (int)($extra['responsavel_id'] ?? $userId);
            // Validar que responsável pertence ao tenant (proteção cross-tenant)
            $st  = $pdo->prepare('SELECT id FROM users WHERE id = :u AND account_id = :acc LIMIT 1');
            $st->execute(['u' => $respId, 'acc' => $accountId]);
            if (!$st->fetchColumn()) {
                $respId = $userId;  // fallback se não bate
            }
            // Prazo: extra.prazo_data (Y-m-d) → ISO datetime
            $prazoData = isset($extra['prazo_data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $extra['prazo_data'])
                ? $extra['prazo_data'] . ' 18:00:00'
                : null;

            $taskId = \App\Models\Task::create([
                'board_id'       => $boardId,
                'column_id'      => $colId,
                'titulo'         => mb_substr($titulo, 0, 200),
                'descricao'      => $desc,
                'prioridade'     => $extra['prioridade'] ?? 'alta',
                'prazo'          => $prazoData,
                'prazo_tipo'     => 'fatal',
                'responsavel_id' => $respId,
                'criado_por_id'  => $userId,
                'push_event_id'  => $eventId,
            ]);
            $resultado['task_id']        = $taskId;
            $resultado['task_board_id']  = $boardId;
            $resultado['responsavel_id'] = $respId;
            break;

        case 'create_prazo':
            // Cria prazo processual em processo_prazos (não kanban tasks).
            // Requer que a intimação esteja vinculada a um processo (processo_id obrigatório).
            // extra: { descricao, data_limite (Y-m-d), responsavel (nome string), prioridade (baixa|media|alta), observacao }
            $ev = \App\Models\PushEvent::findByIdForAccount($eventId, $accountId);
            if (!$ev) {
                http_response_code(404);
                echo json_encode(['error' => 'Evento não encontrado']);
                exit;
            }
            $procId = (int)($ev['processo_id'] ?? 0);
            if ($procId <= 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Intimação precisa estar vinculada a um processo. Vincule primeiro.']);
                exit;
            }
            // Confirma que o processo pertence ao tenant
            $pdo = Database::getConnection();
            $stProc = $pdo->prepare('SELECT id FROM processos WHERE id = :id AND account_id = :acc LIMIT 1');
            $stProc->execute(['id' => $procId, 'acc' => $accountId]);
            if (!$stProc->fetchColumn()) {
                http_response_code(403);
                echo json_encode(['error' => 'Processo não pertence a esta conta']);
                exit;
            }

            $descricao = trim((string)($extra['descricao'] ?? ''));
            if ($descricao === '') {
                $descricao = ($ev['tipo_comunicacao'] ?? 'Intimação') . ' — '
                           . ($ev['orgao'] ?? '') . ' ('
                           . ($ev['tribunal'] ?? '') . ')';
            }
            $dataLim = isset($extra['data_limite']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $extra['data_limite'])
                ? $extra['data_limite'] : null;
            $respNome = trim((string)($extra['responsavel'] ?? ''));
            if ($respNome === '') {
                // Default: nome do user logado
                $st = $pdo->prepare('SELECT nome FROM users WHERE id = :u LIMIT 1');
                $st->execute(['u' => $userId]);
                $respNome = (string)($st->fetchColumn() ?: 'Sem responsável');
            }
            $prioridade = in_array($extra['prioridade'] ?? '', ['baixa','media','alta'], true)
                ? $extra['prioridade'] : 'alta';
            // Observação: anexa origem (push_event_id) pra rastreabilidade
            $observacao = trim((string)($extra['observacao'] ?? ''));
            $obsExtra = "Origem: intimação automática (push_event_id={$eventId})";
            $observacao = $observacao !== '' ? $observacao . "\n\n" . $obsExtra : $obsExtra;

            // Garante que processo_prazos existe (idempotente — endpoint /api/processo_prazos.php já cria)
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

            $stmt = $pdo->prepare(
                'INSERT INTO processo_prazos
                   (processo_id, descricao, data_limite, responsavel, status, prioridade, observacao)
                 VALUES
                   (:p, :d, :dl, :r, "pendente", :pr, :o)'
            );
            $stmt->execute([
                'p'  => $procId,
                'd'  => mb_substr($descricao, 0, 255),
                'dl' => $dataLim,
                'r'  => mb_substr($respNome, 0, 150),
                'pr' => $prioridade,
                'o'  => $observacao,
            ]);
            $resultado['prazo_id']    = (int)$pdo->lastInsertId();
            $resultado['processo_id'] = $procId;
            break;
    }

    echo json_encode($resultado);

} catch (\Throwable $e) {
    \App\Helpers\ErrorReporter::handle($e);
}
