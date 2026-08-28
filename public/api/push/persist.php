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

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\TenantGuard;
use App\Processos\ProcessoAudit;
use App\Core\Database;
use App\Processos\PushEvent;
use App\Processos\PushTodayCache;
use App\Processos\PushEventUserStatus;
use App\Processos\Monitor\PublicationHasher;

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
        // Etapa 10 add-on Monitoramentos: propaga monitor_id quando a
        // publicação veio de busca automática (cron). Útil pra ROI:
        // "quantas intimações vieram do monitor X neste mês".
        $monitorIdOrigem = null;
        if ($cached && !empty($cached['monitor_id'])) {
            $monitorIdOrigem = (int) $cached['monitor_id'];
        } elseif (!empty($item['monitor_id'])) {
            // permite caller passar explicitamente (raro)
            $monitorIdOrigem = (int) $item['monitor_id'];
        }

        $eventId = PushEvent::upsert([
            'account_id'              => $accountId,
            'monitor_id'              => $monitorIdOrigem,
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
                    $boards = \App\Tarefas\TaskBoard::findForUser($userId, $accountId);
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
                            $ev = \App\Processos\PushEvent::findByIdForAccount($eventId, $accountId);
                            $titulo = 'Intimação ' . ($ev['tribunal'] ?? '') . ' — '
                                    . ($ev['numero_processo_mascara'] ?: $ev['numero_processo'] ?: 'sem processo');
                            $desc = "Origem: intimação automática\n"
                                  . "Órgão: " . ($ev['orgao'] ?? '—') . "\n"
                                  . "Tipo: " . ($ev['tipo_comunicacao'] ?? '—') . "\n"
                                  . "Data: " . ($ev['data_disponibilizacao'] ?? '—') . "\n\n"
                                  . mb_substr($ev['conteudo'] ?? '', 0, 1000);

                            $taskId = \App\Tarefas\Task::create([
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
            // Validar acesso ao processo (proteção cross-tenant). Antes filtrava
            // só account_id=:acc (achado ALTA #13), o que dava 403 ao vincular um
            // processo de filial. assertProcessoAcessivel usa o escopo canônico
            // getAccessibleAccountIds('processos') + resource_shares — coerente com
            // create_prazo (abaixo) e com /api/processes.php. Aborta 403 se sem acesso.
            $pdo = Database::getConnection();
            TenantGuard::assertProcessoAcessivel($ctx, $procId);
            // Vincula a intimação específica
            $pdo->prepare('UPDATE push_events SET processo_id = :p WHERE id = :id AND account_id = :acc')
                ->execute(['p' => $procId, 'id' => $eventId, 'acc' => $accountId]);

            // Pega o numero_processo do evento pra registrar auto-link
            $ev = \App\Processos\PushEvent::findByIdForAccount($eventId, $accountId);
            $numProc = $ev['numero_processo'] ?? '';

            // Auditoria no histórico do processo: origem + OAB + número + publicação.
            // Tudo o que advogado precisa pra rastrear "de onde veio essa intimação".
            $origem = strtoupper((string)($ev['source_id'] ?? '?'));   // DJEN | AASP
            $oab    = trim((string)($ev['advogado_oab'] ?? ''));
            $advNome= trim((string)($ev['advogado_nome'] ?? ''));
            $dataPub= $ev['data_publicacao'] ?? $ev['data_disponibilizacao'] ?? '';
            $numMask= $ev['numero_processo_mascara'] ?? $numProc;
            $tribunal = $ev['tribunal'] ?? '';
            $bits = ["origem {$origem}"];
            if ($oab !== '')     $bits[] = "OAB {$oab}" . ($advNome !== '' ? " ({$advNome})" : '');
            if ($numMask !== '') $bits[] = "número {$numMask}";
            if ($tribunal !== '')$bits[] = "tribunal {$tribunal}";
            if ($dataPub !== '') $bits[] = "publicação {$dataPub}";
            $bits[] = "push_event #{$eventId}";
            ProcessoAudit::log(
                $procId,
                'Intimação vinculada',
                'Intimação vinculada ao processo — ' . implode(' · ', $bits)
            );

            if ($numProc !== '') {
                // Salva mapping permanente — futuras intimações com mesmo número auto-vinculam
                \App\Processos\PushProcessoLink::linkProcesso($accountId, $numProc, $procId, $userId);
                // Aplica retroativo: outras intimações passadas com mesmo número ganham vínculo
                $retroCount = \App\Processos\PushProcessoLink::aplicarRetroativo($accountId, $numProc, $procId);
                $resultado['retroativo_aplicado'] = $retroCount;
                if ($retroCount > 0) {
                    // Loga o lote retroativo separadamente pra ficar claro no histórico.
                    ProcessoAudit::log(
                        $procId,
                        'Intimações auto-vinculadas (retroativo)',
                        "{$retroCount} intimação(ões) anterior(es) com número {$numMask} foram auto-vinculadas a este processo"
                    );
                }
            }
            $resultado['processo_id'] = $procId;
            break;

        case 'unlink_process':
            // Remove vínculo da intimação específica + remove auto-link futuro
            $pdo = Database::getConnection();
            $ev = \App\Processos\PushEvent::findByIdForAccount($eventId, $accountId);
            $numProc = $ev['numero_processo'] ?? '';
            $procIdAntes = (int)($ev['processo_id'] ?? 0);   // pra logar no processo CORRETO
            $pdo->prepare('UPDATE push_events SET processo_id = NULL WHERE id = :id AND account_id = :acc')
                ->execute(['id' => $eventId, 'acc' => $accountId]);
            if ($numProc !== '') {
                \App\Processos\PushProcessoLink::unlinkProcesso($accountId, $numProc);
            }
            // Audit no processo que PERDEU a intimação
            if ($procIdAntes > 0) {
                $origem  = strtoupper((string)($ev['source_id'] ?? '?'));
                $numMask = $ev['numero_processo_mascara'] ?? $numProc;
                ProcessoAudit::log(
                    $procIdAntes,
                    'Intimação desvinculada',
                    "Intimação desvinculada — origem {$origem} · número {$numMask} · push_event #{$eventId}"
                );
            }
            $resultado['unlinked'] = true;
            break;

        case 'create_task':
            // Cria tarefa standalone (sem precisar setar prazo primeiro).
            // Campos: extra.titulo, extra.descricao, extra.prazo_data, extra.responsavel_id
            $ev = \App\Processos\PushEvent::findByIdForAccount($eventId, $accountId);
            if (!$ev) {
                http_response_code(404);
                echo json_encode(['error' => 'Evento não encontrado']);
                exit;
            }
            $boards = \App\Tarefas\TaskBoard::findForUser($userId, $accountId);
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

            $taskId = \App\Tarefas\Task::create([
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
            $ev = \App\Processos\PushEvent::findByIdForAccount($eventId, $accountId);
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
            // Confirma acesso ao processo (achado ALTA #13). Mesmo motivo do
            // link_process: a intimação pode estar vinculada a um processo de
            // filial, então usamos o escopo canônico (matriz + filiais + shares)
            // via assertProcessoAcessivel em vez de account_id=:acc próprio.
            $pdo = Database::getConnection();
            TenantGuard::assertProcessoAcessivel($ctx, $procId);

            $descricao = trim((string)($extra['descricao'] ?? ''));
            if ($descricao === '') {
                $descricao = ($ev['tipo_comunicacao'] ?? 'Intimação') . ' — '
                           . ($ev['orgao'] ?? '') . ' ('
                           . ($ev['tribunal'] ?? '') . ')';
            }
            $dataLim = isset($extra['data_limite']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $extra['data_limite'])
                ? $extra['data_limite'] : null;
            // Aceita responsavel_id (user id) — preferencial — ou responsavel (string legacy).
            // Quando vem id, valida que o user é acessível pelo tenant (matriz + filiais ativas).
            $respNome = '';
            $respId   = (int)($extra['responsavel_id'] ?? 0);
            if ($respId > 0) {
                $accessIds = $ctx->getAccessibleAccountIds();
                if ($accessIds) {
                    $ph = implode(',', array_fill(0, count($accessIds), '?'));
                    $st = $pdo->prepare("SELECT nome FROM users WHERE id = ? AND account_id IN ($ph) AND deleted_at IS NULL LIMIT 1");
                    $st->execute(array_merge([$respId], $accessIds));
                    $respNome = (string)($st->fetchColumn() ?: '');
                }
                if ($respNome === '') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Responsável inválido ou fora do seu workspace.']);
                    exit;
                }
            } else {
                $respNome = trim((string)($extra['responsavel'] ?? ''));
            }
            if ($respNome === '') {
                // Default: nome do user logado
                $st = $pdo->prepare('SELECT nome FROM users WHERE id = :u LIMIT 1');
                $st->execute(['u' => $userId]);
                $respNome = (string)($st->fetchColumn() ?: 'Sem responsável');
            }
            $prioridade = in_array($extra['prioridade'] ?? '', ['baixa','media','alta'], true)
                ? $extra['prioridade'] : 'alta';
            // Observação: anexa um resumo da intimação de origem (tribunal/órgão/data/trecho)
            // pra que o advogado tenha contexto direto no card do prazo, sem ter que
            // voltar pra aba Intimações. O push_event_id fica como rodapé discreto
            // pra rastreabilidade técnica (debug/auditoria).
            $observacao = trim((string)($extra['observacao'] ?? ''));

            $origemPartes = [];
            $headLine = trim(
                ($ev['tribunal'] ?? '')
                . (!empty($ev['tipo_comunicacao']) ? ' · ' . $ev['tipo_comunicacao'] : '')
                . (!empty($ev['data_disponibilizacao']) ? ' · disponibilizado em ' . date('d/m/Y', strtotime($ev['data_disponibilizacao'])) : '')
            );
            if ($headLine !== '') $origemPartes[] = $headLine;
            if (!empty($ev['orgao']))           $origemPartes[] = 'Órgão: ' . $ev['orgao'];
            if (!empty($ev['numero_processo_mascara']) || !empty($ev['numero_processo'])) {
                $origemPartes[] = 'Processo: ' . ($ev['numero_processo_mascara'] ?? $ev['numero_processo']);
            }
            // Trecho do conteúdo (limita pra não estourar — TEXT mas o card só mostra ~2 linhas)
            $trecho = trim((string)($ev['resumo'] ?? ''));
            if ($trecho === '') $trecho = trim((string)($ev['conteudo'] ?? ''));
            if ($trecho !== '') {
                // Normaliza whitespace e corta em 240 chars (com reticências)
                $trecho = preg_replace('/\s+/u', ' ', $trecho);
                if (mb_strlen($trecho) > 240) $trecho = mb_substr($trecho, 0, 237) . '…';
                $origemPartes[] = 'Trecho: "' . $trecho . '"';
            }

            $obsExtra = "— Origem (intimação automática) —\n" . implode("\n", $origemPartes)
                      . "\n[ref: push_event #{$eventId}]";
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
    \App\Core\ErrorReporter::handle($e);
}
